<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises the shipped Test SMS result renderer against hostile provider text.
 *
 * @since 5.16.0
 */
final class TestSmsResultRenderingTest extends TestCase
{
    public function testProviderValuesRenderAsLiteralTextWithoutElementsOrHandlers(): void
    {
        $rendererPath = dirname(__DIR__, 2) . '/src/templates/settings/_components/_test-result-renderer.twig';
        $renderer = file_get_contents($rendererPath);
        self::assertIsString($renderer);
        self::assertStringNotContainsString('innerHTML', $renderer);

        $script = $this->domHarness() . "\n" . $renderer . "\n" . $this->maliciousPayloadAssertions();
        $process = new Process(['node', '--eval', $script], dirname(__DIR__, 2));
        $process->setTimeout(10);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($result['payloadsVisibleAsText']);
        self::assertSame([], $result['unexpectedElements']);
        self::assertSame([], $result['eventHandlers']);
        self::assertTrue($result['payloadDidNotExecute']);
        self::assertSame(1, $result['staticSpanCount']);
        self::assertTrue($result['normalSuccessPresentation']);
        self::assertTrue($result['normalFailurePresentation']);
        self::assertTrue($result['caughtErrorPresentation']);
        self::assertTrue($result['apiKeyPresentation']);
    }

    public function testTestSmsTemplateHasNoDynamicInnerHtmlSink(): void
    {
        $templatePath = dirname(__DIR__, 2) . '/src/templates/settings/test.twig';
        $template = file_get_contents($templatePath);
        self::assertIsString($template);

        self::assertStringNotContainsString('.innerHTML', $template);
        self::assertStringContainsString("settings/_components/_test-result-renderer", $template);
        self::assertStringContainsString("view.registerAssetBundle('lindemannrock\\\\smsmanager\\\\web\\\\assets\\\\encoding\\\\EncodingAsset')", $template);
        self::assertStringContainsString('window.lrSmsEncoding.analyze(message)', $template);
        self::assertStringContainsString('const isRtl = rtlLanguages.includes(lang)', $template);
        self::assertStringContainsString('unit-count', $template);
        self::assertStringContainsString('sms-count', $template);
    }

    private function domHarness(): string
    {
        return <<<'JS'
class FakeTextNode {
    constructor(value) {
        this.tagName = '#TEXT';
        this.textContent = String(value);
        this.children = [];
        this.attributes = {};
    }
}

class FakeElement {
    constructor(tagName) {
        this.tagName = String(tagName).toUpperCase();
        this.children = [];
        this.attributes = {};
        this.style = {};
        this.className = '';
        this._textContent = '';
    }

    set textContent(value) {
        this._textContent = String(value ?? '');
        this.children = [];
    }

    get textContent() {
        return this._textContent + this.children.map((child) => child.textContent).join('');
    }

    appendChild(child) {
        this.children.push(child);
        return child;
    }

    replaceChildren(...children) {
        this._textContent = '';
        this.children = children;
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }
}

global.window = {};
global.document = {
    createElement: (tagName) => new FakeElement(tagName),
    createTextNode: (value) => new FakeTextNode(value),
};

function walk(node, visitor) {
    visitor(node);
    for (const child of node.children || []) {
        walk(child, visitor);
    }
}
JS;
    }

    private function maliciousPayloadAssertions(): string
    {
        return <<<'JS'
const payload = '<section id="injected">element</section><script>global.compromised=true</script><img src=x onerror="global.compromised=true"><span title="x" onmouseover="global.compromised=true">attribute</span>';
const copy = {
    successTitle: 'SMS Sent Successfully',
    successMessage: 'The test SMS was sent successfully.',
    failureTitle: 'SMS Sending Failed',
    failureMessage: 'The test SMS failed to send.',
    errorTitle: 'Error',
    provider: 'Provider',
    senderId: 'Sender ID',
    recipient: 'Recipient',
    messageId: 'Message ID',
    executionTime: 'Execution Time',
    providerResponse: 'Provider Response',
    unknown: 'Unknown',
    unknownError: 'Unknown error',
    notAvailable: 'N/A',
    usingDevelopmentApiKey: 'Using Development API Key',
    usingMainApiKey: 'Using Main API Key',
    noDevelopmentApiKey: 'No development API key configured',
    allowedCountries: 'Allowed Countries',
    allCountries: 'All Countries',
};

const successTitle = document.createElement('h3');
const successContent = document.createElement('div');
window.lrSmsTestResults.renderSuccess(successTitle, successContent, {
    providerName: 'Normal Provider',
    senderIdName: 'Normal Sender',
    senderIdValue: 'SenderValue',
    recipient: '+971500000000',
    messageId: payload,
    executionTime: 42,
    response: payload,
}, copy);

const failureTitle = document.createElement('h3');
const failureContent = document.createElement('div');
window.lrSmsTestResults.renderFailure(failureTitle, failureContent, {
    providerName: 'Normal Provider',
    senderIdName: 'Normal Sender',
    error: payload,
}, copy);

const caughtTitle = document.createElement('h3');
const caughtContent = document.createElement('div');
window.lrSmsTestResults.renderCaughtError(caughtTitle, caughtContent, payload, copy);

const apiKeyContent = document.createElement('span');
window.lrSmsTestResults.renderApiKeyInfo(apiKeyContent, {
    isDevelopment: true,
    hasDevelopmentKey: true,
    mainKey: 'MAIN-' + payload,
    developmentKey: 'DEV-' + payload,
    countries: ['AE ' + payload, 'KW'],
}, copy);

const roots = [successTitle, successContent, failureTitle, failureContent, caughtTitle, caughtContent, apiKeyContent];
const unexpectedElements = [];
const eventHandlers = [];
let staticSpanCount = 0;
for (const root of roots) {
    walk(root, (node) => {
        if (['SCRIPT', 'IMG', 'SECTION', 'SVG'].includes(node.tagName)) {
            unexpectedElements.push(node.tagName);
        }
        if (node.tagName === 'SPAN' && node !== apiKeyContent) {
            staticSpanCount++;
        }
        for (const name of Object.keys(node.attributes || {})) {
            if (name.toLowerCase().startsWith('on')) {
                eventHandlers.push(name);
            }
        }
        for (const name of Object.keys(node)) {
            if (name.toLowerCase().startsWith('on') && typeof node[name] === 'function') {
                eventHandlers.push(name);
            }
        }
    });
}

const output = {
    payloadsVisibleAsText:
        successContent.textContent.includes(payload) &&
        failureContent.textContent.includes(payload) &&
        caughtContent.textContent.includes(payload) &&
        apiKeyContent.textContent.includes(payload),
    unexpectedElements,
    eventHandlers,
    payloadDidNotExecute: global.compromised !== true,
    staticSpanCount,
    normalSuccessPresentation:
        successTitle.textContent === copy.successTitle &&
        successContent.textContent.includes(copy.successMessage) &&
        successContent.textContent.includes(copy.messageId + ':') &&
        successContent.textContent.includes('42ms') &&
        successContent.children.some((child) => child.tagName === 'DETAILS'),
    normalFailurePresentation:
        failureTitle.textContent === copy.failureTitle &&
        failureContent.textContent.includes(copy.failureMessage) &&
        failureContent.textContent.includes(copy.provider + ':'),
    caughtErrorPresentation:
        caughtTitle.textContent === copy.errorTitle && caughtContent.textContent === payload,
    apiKeyPresentation:
        apiKeyContent.textContent.includes(copy.usingDevelopmentApiKey + ':') &&
        apiKeyContent.textContent.includes(copy.allowedCountries + ':'),
};

process.stdout.write(JSON.stringify(output));
JS;
    }
}
