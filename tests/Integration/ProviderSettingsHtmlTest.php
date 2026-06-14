<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use Craft;
use craft\web\View;
use lindemannrock\smsmanager\providers\MppSmsProvider;
use lindemannrock\smsmanager\tests\TestCase;

/**
 * Covers provider settings-form rendering — the CP provider edit page renders
 * each provider's own `getSettingsHtml()`, so a provider whose template path is
 * wrong silently produces a blank settings form.
 *
 * @since 5.14.0
 */
final class ProviderSettingsHtmlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
    }

    public function testMppProviderRendersItsOwnSettingsForm(): void
    {
        $html = (new MppSmsProvider())->getSettingsHtml();

        // MPP's own API key field rendered (proves the template path resolves)...
        self::assertStringContainsString('providerSettings-apiKey', $html);
        // ...and the shared allowedCountries select rendered, which means the
        // countryOptions default injected by BaseProvider::renderSettingsTemplate()
        // reached the template.
        self::assertStringContainsString('providerSettings[allowedCountries]', $html);
    }
}
