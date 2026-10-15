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
        $guard = SmsManager::$plugin->mppTransport;
        $guard->send('synthetic-admin-account', static fn(): array => [
            'success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account',
        ]);
        $hold = $guard->status($provider->getSettingsArray())['holds'][0];
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
            self::assertCount($status === 200 ? 0 : 1, $guard->status($provider->getSettingsArray())['holds']);
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
        $guard = SmsManager::$plugin->mppTransport;
        $guard->send('synthetic-dev', static fn(): array => ['success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account']);
        $status = $guard->status(['apiKey' => 'synthetic-main', 'devApiKey' => 'synthetic-dev']);
        self::assertCount(1, $status['holds']);
        self::assertSame('development', $status['holds'][0]['lane']);
        self::assertTrue($status['holds'][0]['active']);
        self::assertNull($status['holds'][0]['recoveryAt']);
        self::assertGreaterThan(0, $status['holds'][0]['activatedAt']);
    }

    public function testStatusTemplateShowsSafeHoldsAndHidesUnauthorizedRelease(): void
    {
        $guard = SmsManager::$plugin->mppTransport;
        $guard->send('synthetic-main-account', static fn(): array => ['success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account']);
        $guard->send('synthetic-dev-account', static fn(): array => ['success' => false, 'failureCode' => 'ip-blocked', 'failureScope' => 'transport', 'retryAfterSeconds' => 660]);
        $status = $guard->status(['apiKey' => 'synthetic-main-account', 'devApiKey' => 'synthetic-dev-account']);
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            foreach ([false, true] as $allowed) {
                $html = $view->renderTemplate('sms-manager/providers/_transport-status', ['transportStatus' => $status, 'providerHandle' => 'synthetic-provider', 'canRelease' => $allowed]);
                foreach (['Transport Status', 'Account unavailable', 'IP blocked', 'Main account', 'All MPP accounts', 'Manual release required', 'Active', 'lr-info-box'] as $label) {
                    self::assertStringContainsString($label, $html);
                }
                self::assertSame($allowed, str_contains($html, 'class="btn mpp-release-hold"'));
                self::assertStringNotContainsString('synthetic-main-account', $html);
                self::assertStringNotContainsString('scopeKey', $html);
                self::assertStringNotContainsString('192.0.2.1', $html);
            }
        } finally {
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
