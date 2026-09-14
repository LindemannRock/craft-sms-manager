<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\providers;

/**
 * Optional capability for providers that support development sender IDs.
 *
 * Providers that do not implement this interface are treated conservatively
 * as not supporting development sender IDs. Extending {@see BaseProvider}
 * supplies the same safe default and allows an explicit opt-in by overriding
 * {@see supportsDevelopmentSenders()}.
 *
 * @since 5.16.0
 */
interface DevelopmentSenderProviderInterface
{
    /**
     * Whether a sender ID's development flag changes provider behavior.
     */
    public static function supportsDevelopmentSenders(): bool;
}
