<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\providers;

use lindemannrock\smsmanager\models\TransportContext;

/**
 * Opt-in shared transport protection for built-in and custom providers.
 * The provider wraps its HTTP operation in transport->send() and returns
 * bounded failure metadata; the shared service owns all guard state.
 *
 * @since 5.18.1
 */
interface TransportProviderInterface
{
    public function getTransportContext(array $settings): TransportContext;
}
