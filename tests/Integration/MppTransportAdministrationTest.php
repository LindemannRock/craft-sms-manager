<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use Craft;
use craft\console\Request;
use craft\console\User;
use craft\db\Query;
use craft\web\View;
use lindemannrock\smsmanager\controllers\ProvidersController;
use lindemannrock\smsmanager\providers\MppSmsProvider;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Holds are visible and only explicitly released by provider administrators.
 *
 * @since 5.18.0
 */
final class MppTransportAdministrationTest extends TestCase
{
    #[DataProvider('releasePermissions')]
    public function testReleaseRequiresPostPermissionAndConfirmation(bool $post, bool $allowed, string $confirmed, int $expectedStatus): void
    {
        $provider = $this->seedProvider(['type' => 'mpp-sms', 'settings' => json_encode(['apiKey' => 'synthetic-admin-account'])]);
        $guard = SmsManager::$plugin->transport;
        $guard->send((new MppSmsProvider())->getTransportContext(['apiKey' => 'synthetic-admin-account']), static fn(): array => [
            'success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account',
        ]);
        $hold = $guard->status((new MppSmsProvider())->getTransportContext($provider->getSettingsArray()))['holds'][0];
        $request = Craft::$app->get('request');
        $user = Craft::$app->get('user');
        $queue = (new Query())->from('{{%queue}}')->all();
        try {
            Craft::$app->set('request', new HoldReleaseRequest($post, [
                'handle' => $provider->handle, 'lane' => 'main', 'token' => $hold['token'], 'confirmed' => $confirmed,
            ]));
            Craft::$app->set('user', new HoldReleaseUser($allowed));
            $controller = new HoldReleaseController('providers', SmsManager::$plugin);
            self::assertTrue($controller->enableCsrfValidation);
            $status = 200;
            try {
                $response = $controller->actionReleaseTransportHold();
                self::assertTrue($response->data['success']);
            } catch (HttpException $exception) {
                $status = $exception->statusCode;
            }
            self::assertSame($expectedStatus, $status);
            self::assertCount($status === 200 ? 0 : 1, $guard->status((new MppSmsProvider())->getTransportContext($provider->getSettingsArray()))['holds']);
            self::assertSame($queue, (new Query())->from('{{%queue}}')->all());
        } finally {
            Craft::$app->set('request', $request);
            Craft::$app->set('user', $user);
        }
    }

    public static function releasePermissions(): iterable
    {
        yield 'GET' => [false, true, '1', 405];
        yield 'unauthorized' => [true, false, '1', 403];
        yield 'unconfirmed' => [true, true, '', 400];
        yield 'authorized confirmed POST' => [true, true, '1', 200];
    }

    public function testFrameworkRejectsMissingCsrfBeforeReleaseAction(): void
    {
        $original = Craft::$app->get('request');
        $request = new HoldReleaseCsrfRequest();
        try {
            Craft::$app->set('request', $request);
            $controller = new HoldReleaseController('providers', SmsManager::$plugin);
            try {
                $controller->beforeAction($controller->createAction('release-transport-hold'));
                self::fail('Missing CSRF must stop the action.');
            } catch (\yii\web\BadRequestHttpException $exception) {
                self::assertSame(400, $exception->statusCode);
                self::assertTrue($request->validated);
            }
        } finally {
            Craft::$app->set('request', $original);
        }
    }

    public function testDevelopmentAccountStatusUsesItsOwnLane(): void
    {
        $guard = SmsManager::$plugin->transport;
        $guard->send((new MppSmsProvider())->getTransportContext(['apiKey' => 'synthetic-dev']), static fn(): array => ['success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account']);
        $status = $guard->status((new MppSmsProvider())->getTransportContext(['apiKey' => 'synthetic-main', 'devApiKey' => 'synthetic-dev']));
        self::assertCount(1, $status['holds']);
        self::assertSame('development', $status['holds'][0]['lane']);
        self::assertTrue($status['holds'][0]['active']);
        self::assertNull($status['holds'][0]['recoveryAt']);
        self::assertInstanceOf(\DateTime::class, $status['holds'][0]['activatedAt']);
    }

    public function testStatusTemplateShowsSafeHoldsAndHidesUnauthorizedRelease(): void
    {
        $guard = SmsManager::$plugin->transport;
        $guard->send((new MppSmsProvider())->getTransportContext(['apiKey' => 'synthetic-main-account']), static fn(): array => ['success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account']);
        $guard->send((new MppSmsProvider())->getTransportContext(['apiKey' => 'synthetic-dev-account']), static fn(): array => ['success' => false, 'failureCode' => 'ip-blocked', 'failureScope' => 'transport', 'retryAfterSeconds' => 660]);
        $status = $guard->status((new MppSmsProvider())->getTransportContext(['apiKey' => 'synthetic-main-account', 'devApiKey' => 'synthetic-dev-account']));
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            foreach ([false, true] as $allowed) {
                $html = $view->renderTemplate('sms-manager/providers/_transport-status', ['transportStatus' => $status, 'providerHandle' => 'synthetic-provider', 'canRelease' => $allowed]);
                foreach (['Transport Status', 'Account unavailable', 'IP blocked', 'Main account', 'All accounts', 'Manual release required', 'On hold'] as $label) {
                    self::assertStringContainsString($label, $html);
                }
                self::assertSame($allowed ? 2 : 0, substr_count($html, 'data-action="release-transport-hold"'));
                self::assertStringNotContainsString('synthetic-main-account', $html);
                self::assertStringNotContainsString('scopeKey', $html);
                self::assertStringNotContainsString('192.0.2.1', $html);
            }
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    public function testTransportNoticeAppearsOnlyWhenSendingNeedsAttention(): void
    {
        $guard = SmsManager::$plugin->transport;
        $context = (new MppSmsProvider())->getTransportContext(['apiKey' => 'synthetic-notice']);
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            $render = static fn(array $status): string => $view->renderTemplate('sms-manager/_components/_transport-notice', ['transportStatus' => $status]);
            self::assertSame('', trim($render($guard->status($context))));
            $guard->send($context, static fn(): array => ['success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account']);
            $html = $render($guard->status($context));
            self::assertStringContainsString('Sending is on hold for one or more accounts.', $html);
            self::assertStringNotContainsString('synthetic-notice', $html);
            $html = $render(['available' => false, 'state' => 'unavailable', 'holds' => []]);
            self::assertStringContainsString('Transport status is unavailable. Sending remains blocked until guard storage is available.', $html);
            self::assertSame('', trim($render(['available' => true, 'state' => 'not-configured', 'holds' => []])));
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    public function testRowMenuOffersOnlyAuthorizedActiveHoldsWithoutSending(): void
    {
        $provider = $this->seedProvider(['type' => 'mpp-sms', 'settings' => json_encode(['apiKey' => 'synthetic-menu-main', 'devApiKey' => 'synthetic-menu-dev'])]);
        $guard = SmsManager::$plugin->transport;
        foreach (['synthetic-menu-main', 'synthetic-menu-dev'] as $key) {
            $guard->send((new MppSmsProvider())->getTransportContext(['apiKey' => $key]), static fn(): array => ['success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account']);
        }
        $status = $guard->statusForProvider($provider);
        self::assertCount(2, $status['holds']);
        $before = \lindemannrock\smsmanager\tests\Support\TransportIsolation::$interceptedRequests;
        $queue = (new Query())->from('{{%queue}}')->all();
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $twig = $view->getTwig();
        $originalUser = $twig->getGlobals()['currentUser'] ?? null;
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            $template = $twig->load('sms-manager/providers/index');
            foreach ([false, true] as $allowed) {
                $user = $this->createStub(\craft\elements\User::class);
                $user->method('can')->willReturn($allowed);
                $twig->addGlobal('currentUser', $user);
                foreach ([$status['holds'], [$status['holds'][0]]] as $holds) {
                    $html = $template->renderBlock('rowActions', [
                        'item' => $provider, 'defaultProviderHandle' => '', 'isDefaultFromConfig' => false,
                        'transportStatuses' => [$provider->handle => ['state' => 'on-hold', 'holds' => $holds]],
                    ]);
                    self::assertSame($allowed ? 1 : 0, substr_count($html, 'Release hold'));
                    self::assertSame($allowed && count($holds) === 1 ? 1 : 0, substr_count($html, 'data-action="release-transport-hold"'));
                    if ($allowed && count($holds) === 1) {
                        $hold = $holds[0];
                        self::assertStringContainsString('data-token="' . $hold['token'] . '"', $html);
                        self::assertStringContainsString('data-lane="' . $hold['lane'] . '"', $html);
                    } elseif ($allowed) {
                        self::assertStringContainsString('#sms-transport-status', $html);
                    }
                    self::assertStringNotContainsString('synthetic-menu-main', $html);
                    self::assertStringNotContainsString('synthetic-menu-dev', $html);
                }
            }
            foreach (['available', 'unavailable', 'not-configured'] as $state) {
                $html = $template->renderBlock('rowActions', [
                    'item' => $provider, 'defaultProviderHandle' => '', 'isDefaultFromConfig' => false,
                    'transportStatuses' => [$provider->handle => ['state' => $state, 'holds' => []]],
                ]);
                self::assertStringNotContainsString('data-action="release-transport-hold"', $html);
            }
            self::assertEquals($status, $guard->statusForProvider($provider));
            self::assertSame($queue, (new Query())->from('{{%queue}}')->all());
            self::assertSame($before, \lindemannrock\smsmanager\tests\Support\TransportIsolation::$interceptedRequests);
        } finally {
            $twig->addGlobal('currentUser', $originalUser);
            $view->setTemplateMode($mode);
        }
    }
}

final class HoldReleaseRequest extends Request
{
    public function __construct(private bool $post, private array $body)
    {
        parent::__construct();
    }

    public function validateCsrfToken($clientSuppliedToken = null): bool
    {
        return true;
    }

    public function getIsPost(): bool
    {
        return $this->post;
    }

    public function getAcceptsJson(): bool
    {
        return true;
    }

    public function getBodyParam($name, $defaultValue = null): mixed
    {
        return $this->body[$name] ?? $defaultValue;
    }

    public function getRequiredBodyParam(string $name): mixed
    {
        return $this->body[$name];
    }
}

final class HoldReleaseUser extends User
{
    public function __construct(private bool $allowed)
    {
        parent::__construct();
    }

    public function checkPermission(string $permissionName): bool
    {
        return $this->allowed && $permissionName === 'smsManager:editProviders';
    }

    public function getId(): ?int
    {
        return 1;
    }
}

final class HoldReleaseController extends ProvidersController
{
    public function asJson($data): Response
    {
        return new Response(['data' => $data]);
    }
}

final class HoldReleaseCsrfRequest extends \craft\web\Request
{
    public bool $validated = false;

    public function getIsLivePreview(): bool
    {
        return false;
    }

    public function getIsConsoleRequest(): bool
    {
        return false;
    }

    public function validateCsrfToken($clientSuppliedToken = null): bool
    {
        $this->validated = true;
        return false;
    }
}
