<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\events;

use yii\base\Event;

/**
 * Register Integrations Event
 *
 * Allows plugins to register themselves as SMS Manager integrations.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class RegisterIntegrationsEvent extends Event
{
    /**
     * @var array<array{handle: string, name: string, class: class-string}> Registered integrations
     */
    public array $integrations = [];

    /**
     * Register an integration
     *
     * @param string $handle Plugin handle (e.g., 'formie-sms')
     * @param string $name Display name (e.g., 'Formie SMS')
     * @param string $class Integration class that implements IntegrationInterface
     */
    public function register(string $handle, string $name, string $class): void
    {
        $this->integrations[$handle] = [
            'handle' => $handle,
            'name' => $name,
            'class' => $class,
        ];
    }
}
