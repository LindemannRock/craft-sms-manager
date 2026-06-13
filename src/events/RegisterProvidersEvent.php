<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\events;

use lindemannrock\smsmanager\providers\ProviderInterface;
use yii\base\Event;

/**
 * Register Providers Event
 *
 * Fired when SMS Manager collects its provider types. The two built-in
 * providers are seeded into {@see $types} before the event fires, so a
 * listener can add its own provider type (or reshape the built-ins) before
 * the registry is finalized.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.14.0
 */
class RegisterProvidersEvent extends Event
{
    /**
     * @var array<class-string<ProviderInterface>> Provider classes, seeded with the built-ins
     */
    public array $types = [];

    /**
     * Register a provider type
     *
     * @param class-string<ProviderInterface> $class Provider class that implements ProviderInterface
     */
    public function register(string $class): void
    {
        $this->types[] = $class;
    }
}
