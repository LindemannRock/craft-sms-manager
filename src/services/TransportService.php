<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\services;

use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use lindemannrock\smsmanager\helpers\SmsPrivacyHelper;
use lindemannrock\smsmanager\models\TransportContext;
use lindemannrock\smsmanager\providers\TransportProviderInterface;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\base\Component;
use yii\mutex\DbMutex;
use yii\mutex\MysqlMutex;
use yii\mutex\PgsqlMutex;

/**
 * Durable, serialized provider admission shared by every sender in an installation.
 *
 * @since 5.19.0
 */
class TransportService extends Component
{
    public const TABLE = '{{%smsmanager_transportguards}}';
    public const RELEASE_TABLE = '{{%smsmanager_transportreleases}}';

    /**
     * Run at most one transport call after durable admission. No queue ownership.
     *
     * @param callable(): array $transport
     */
    public function send(TransportContext $context, callable $transport): array
    {
        try {
            return Craft::$app->getDb()->useMaster(fn(): array => $this->admit($context, $transport));
        } catch (\Throwable) {
            return $this->blocked($context, 'guard-unavailable', 'transport');
        }
    }

    private function admit(TransportContext $context, callable $transport): array
    {
        $attempted = false;
        try {
            // Never let an enclosing transaction roll back a transport decision.
            if (Craft::$app->getDb()->getTransaction()?->getIsActive()) {
                return $this->blocked($context, 'guard-unavailable', 'transport');
            }
            $mutex = $this->mutex();
            if (!$mutex->acquire('sms-manager:transport:' . $context->providerType, 0)) {
                return $this->blocked($context, 'guard-busy', 'transport', 1);
            }
            try {
                $now = $this->now();
                $account = $context->accountKey();
                $result = null;
                foreach (['transport', $account] as $key) {
                    $row = $this->row($context, $key);
                    if ($row !== null && $this->active($row, $now)) {
                        $result = $this->blocked($context, 'cooldown-active', $key === 'transport' ? 'transport' : 'account', $row['blockedUntil'] === null ? null : max(0, (int)$row['blockedUntil'] - $now));
                        break;
                    }
                }
                if ($result === null) {
                    // Commit before HTTP. Death, timeout, or persistence failure leaves
                    // an explicit manual hold; no lease expiry can admit another worker.
                    $token = StringHelper::UUID();
                    $this->put($context, 'transport', 'admission-uncertain', $now, null, $token);
                    try {
                        $attempted = true;
                        $result = SmsPrivacyHelper::sanitizeProviderResult($transport(), $context->providerType);
                    } catch (\Throwable $exception) {
                        $result = SmsPrivacyHelper::failureResultFromThrowable($context->providerType, $exception);
                    }
                    $hold = SmsPrivacyHelper::isHoldResult($result);
                    if ($hold) {
                        $until = $result['retryAfterSeconds'] === null ? null : $this->now() + $result['retryAfterSeconds'];
                        $this->put($context, $result['failureScope'] === 'account' ? $account : 'transport', $result['failureCode'], $this->now(), $until, StringHelper::UUID());
                    }
                    if (!$hold || $result['failureScope'] !== 'transport') {
                        // Transport exceptions do not prove a provider-wide outage.
                        // Never retry the uncertain message here.
                        Craft::$app->getDb()->createCommand()->delete(self::TABLE, [
                            'providerType' => $context->providerType, 'scopeKey' => 'transport', 'token' => $token,
                        ])->execute();
                    }
                }
            } finally {
                if (!$mutex->release('sms-manager:transport:' . $context->providerType)) {
                    throw new \RuntimeException('provider admission unlock failed.');
                }
            }
            return $result;
        } catch (\Throwable) {
            // Never log SQL/exception text: it may contain private identity.
            if ($attempted) {
                try {
                    $this->put($context, 'transport', 'admission-uncertain', $this->now(), null, StringHelper::UUID());
                } catch (\Throwable) {
                    // The pre-transport marker remains when completion cannot persist.
                }
            }
            return $this->blocked($context, $attempted ? 'transport-uncertain' : 'guard-unavailable', 'transport');
        }
    }

    /** Safe current-provider projection. Private identity never reaches the view. */
    public function status(TransportContext $context): array
    {
        try {
            return Craft::$app->getDb()->useMaster(function() use ($context): array {
                $now = $this->now();
                $holds = [];
                foreach ($context->scopes() as $lane => $key) {
                    $row = $this->row($context, $key);
                    if ($row === null) {
                        continue;
                    }
                    $holds[] = [
                        'scope' => $key === 'transport' ? 'transport' : 'account',
                        'lane' => $lane,
                        'type' => in_array($row['failureCode'], ['account-unavailable', 'ip-blocked', 'rate-limited', 'provider-unavailable', 'manual-hold', 'admission-uncertain'], true) ? $row['failureCode'] : 'admission-uncertain',
                        'activatedAt' => new \DateTime('@' . (int)$row['activatedAt']),
                        'recoveryAt' => $row['blockedUntil'] === null ? null : new \DateTime('@' . (int)$row['blockedUntil']),
                        'active' => $this->active($row, $now),
                        'token' => $row['token'],
                    ];
                }
                return ['available' => true, 'state' => array_filter($holds, fn(array $hold): bool => $hold['active']) ? 'on-hold' : 'available', 'holds' => $holds];
            });
        } catch (\Throwable) {
            return ['available' => false, 'state' => 'unavailable', 'holds' => []];
        }
    }

    /**
     * Release only a selected, unchanged hold under the same transport lock.
     * Records actor/time independently of private guard identity. Sends nothing.
     */
    public function release(TransportContext $context, string $lane, string $token, int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        try {
            return Craft::$app->getDb()->useMaster(function() use ($context, $lane, $token, $userId): bool {
                if (Craft::$app->getDb()->getTransaction()?->getIsActive()) {
                    return false;
                }
                $key = $context->scopes()[$lane] ?? null;
                if ($key === null) {
                    return false;
                }
                $mutex = $this->mutex();
                if (!$mutex->acquire('sms-manager:transport:' . $context->providerType, 0)) {
                    return false;
                }
                try {
                    $row = $this->row($context, $key);
                    if ($row === null || !hash_equals($row['token'], $token)) {
                        return false;
                    }
                    return Craft::$app->getDb()->transaction(function() use ($context, $key, $row, $lane, $userId): bool {
                        Craft::$app->getDb()->createCommand()->insert(self::RELEASE_TABLE, [
                            'providerType' => $context->providerType,
                            'scope' => $key === 'transport' ? 'transport' : 'account',
                            'lane' => $lane,
                            'failureCode' => $row['failureCode'],
                            'releasedBy' => $userId,
                            'releasedAt' => $this->now(),
                        ])->execute();
                        Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['providerType' => $context->providerType, 'scopeKey' => $key, 'token' => $row['token']])->execute();
                        return true;
                    });
                } finally {
                    $mutex->release('sms-manager:transport:' . $context->providerType);
                }
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /** Database time avoids disagreement between worker host clocks. */
    protected function now(): int
    {
        $db = Craft::$app->getDb();
        return (int)$db->createCommand($db->getDriverName() === 'mysql' ? 'SELECT UNIX_TIMESTAMP()' : 'SELECT EXTRACT(EPOCH FROM clock_timestamp())')->queryScalar();
    }

    private function mutex(): DbMutex
    {
        $db = Craft::$app->getDb();
        return match ($db->getDriverName()) {
            'mysql' => new MysqlMutex(['db' => $db]),
            'pgsql' => new PgsqlMutex(['db' => $db]),
            default => throw new \RuntimeException('Unsupported provider guard database.'),
        };
    }

    /** Resolve the same provider-owned contract for list, detail and release paths. */
    public function contextForProvider(ProviderRecord $provider): ?TransportContext
    {
        $class = SmsManager::$plugin->providers->getProviderTypes()[$provider->type] ?? null;
        if ($class === null || !is_a($class, TransportProviderInterface::class, true)) {
            return null;
        }
        $instance = SmsManager::$plugin->providers->createProviderByType($provider->type);
        return $instance instanceof TransportProviderInterface
            ? $instance->getTransportContext($provider->getSettingsArray())
            : null;
    }

    public function statusForProvider(ProviderRecord $provider): array
    {
        try {
            $context = $this->contextForProvider($provider);
            return $context !== null ? $this->status($context) : [
                'available' => true, 'state' => 'not-configured', 'holds' => [],
            ];
        } catch (\Throwable) {
            return ['available' => false, 'state' => 'unavailable', 'holds' => []];
        }
    }

    private function row(TransportContext $context, string $key): ?array
    {
        $row = (new Query())->from(self::TABLE)->where(['providerType' => $context->providerType, 'scopeKey' => $key])->one();
        return is_array($row) ? $row : null;
    }

    private function active(array $row, int $now): bool
    {
        return $row['blockedUntil'] === null || (int)$row['blockedUntil'] > $now;
    }

    private function put(TransportContext $context, string $key, string $code, int $at, ?int $until, string $token): void
    {
        Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
            'providerType' => $context->providerType, 'scopeKey' => $key, 'failureCode' => $code, 'activatedAt' => $at,
            'blockedUntil' => $until, 'token' => $token,
        ])->execute();
    }

    private function blocked(TransportContext $context, string $code, string $scope, ?int $seconds = null): array
    {
        return array_replace(SmsPrivacyHelper::failureResult($context->providerType, 'provider'), [
            'failureCode' => $code, 'failureScope' => $scope, 'retryAfterSeconds' => $seconds,
        ]);
    }
}
