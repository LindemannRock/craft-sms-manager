<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\services;

use craft\base\Component;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\events\RegisterIntegrationsEvent;
use lindemannrock\smsmanager\integrations\IntegrationInterface;

/**
 * Integrations Service
 *
 * Manages registered integrations and provides usage tracking.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class IntegrationsService extends Component
{
    use LoggingTrait;

    /**
     * Event fired when collecting registered integrations
     */
    public const EVENT_REGISTER_INTEGRATIONS = 'registerIntegrations';

    /**
     * @var array<string, array{handle: string, name: string, class: class-string}>|null Cached integrations
     */
    private ?array $integrations = null;

    /**
     * @var array<string, IntegrationInterface> Cached integration instances
     */
    private array $instances = [];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('sms-manager');
    }

    /**
     * Get all registered integrations
     *
     * @return array<string, array{handle: string, name: string, class: class-string}>
     */
    public function getRegisteredIntegrations(): array
    {
        if ($this->integrations !== null) {
            return $this->integrations;
        }

        $event = new RegisterIntegrationsEvent();
        $this->trigger(self::EVENT_REGISTER_INTEGRATIONS, $event);

        $this->integrations = $event->integrations;

        return $this->integrations;
    }

    /**
     * Get an integration instance by handle
     *
     * @param string $handle Integration handle
     * @return IntegrationInterface|null
     */
    public function getIntegration(string $handle): ?IntegrationInterface
    {
        if (isset($this->instances[$handle])) {
            return $this->instances[$handle];
        }

        $integrations = $this->getRegisteredIntegrations();

        if (!isset($integrations[$handle])) {
            return null;
        }

        $class = $integrations[$handle]['class'];

        if (!class_exists($class)) {
            $this->logWarning('Integration class not found', [
                'handle' => $handle,
                'class' => $class,
            ]);
            return null;
        }

        $instance = new $class();

        if (!$instance instanceof IntegrationInterface) {
            $this->logWarning('Integration class does not implement IntegrationInterface', [
                'handle' => $handle,
                'class' => $class,
            ]);
            return null;
        }

        $this->instances[$handle] = $instance;

        return $instance;
    }

    /**
     * Get all usages of a provider across all integrations
     *
     * @param int $providerId The provider ID to check
     * @return array<array{plugin: string, pluginName: string, label: string, editUrl: string|null}>
     */
    public function getProviderUsages(int $providerId): array
    {
        $usages = [];
        $integrations = $this->getRegisteredIntegrations();

        foreach ($integrations as $handle => $integration) {
            $instance = $this->getIntegration($handle);
            if ($instance === null) {
                continue;
            }

            $integrationUsages = $instance->getProviderUsages($providerId);
            foreach ($integrationUsages as $usage) {
                $usages[] = [
                    'plugin' => $handle,
                    'pluginName' => $integration['name'],
                    'label' => $usage['label'],
                    'editUrl' => $usage['editUrl'] ?? null,
                ];
            }
        }

        return $usages;
    }

    /**
     * Get all usages of a sender ID across all integrations
     *
     * @param int $senderIdId The sender ID to check
     * @return array<array{plugin: string, pluginName: string, label: string, editUrl: string|null}>
     */
    public function getSenderIdUsages(int $senderIdId): array
    {
        $usages = [];
        $integrations = $this->getRegisteredIntegrations();

        foreach ($integrations as $handle => $integration) {
            $instance = $this->getIntegration($handle);
            if ($instance === null) {
                continue;
            }

            $integrationUsages = $instance->getSenderIdUsages($senderIdId);
            foreach ($integrationUsages as $usage) {
                $usages[] = [
                    'plugin' => $handle,
                    'pluginName' => $integration['name'],
                    'label' => $usage['label'],
                    'editUrl' => $usage['editUrl'] ?? null,
                ];
            }
        }

        return $usages;
    }

    /**
     * Check if a provider is in use by any integration
     *
     * @param int $providerId The provider ID to check
     * @return bool
     */
    public function isProviderInUse(int $providerId): bool
    {
        return count($this->getProviderUsages($providerId)) > 0;
    }

    /**
     * Check if a sender ID is in use by any integration
     *
     * @param int $senderIdId The sender ID to check
     * @return bool
     */
    public function isSenderIdInUse(int $senderIdId): bool
    {
        return count($this->getSenderIdUsages($senderIdId)) > 0;
    }
}
