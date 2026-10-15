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
use craft\helpers\App;
use craft\helpers\StringHelper;
use lindemannrock\smsmanager\helpers\SmsPrivacyHelper;
use yii\base\Component;
use yii\mutex\DbMutex;
use yii\mutex\MysqlMutex;
use yii\mutex\PgsqlMutex;

/**
 * Durable, serialized MPP admission shared by every sender in an installation.
 *
 * @since 5.18.0
 */
class MppTransportService extends Component
{
    public const TABLE = '{{%smsmanager_transportguards}}';
    public const RELEASE_TABLE = '{{%smsmanager_transportreleases}}';
    private const LOCK = 'sms-manager:mpp-admission';

    /**
     * Run at most one transport call after durable admission. No queue ownership.
     *
     * @param callable(): array $transport
     */
    public function send(string $credential, callable $transport): array
    {
        try {
            return Craft::$app->getDb()->useMaster(fn(): array => $this->admit($credential, $transport));
        } catch (\Throwable) {
            return $this->blocked('guard-unavailable', 'transport');
        }
    }

    private function admit(string $credential, callable $transport): array
    {
        $attempted = false;
        try {
            // Never let an enclosing transaction roll back a transport decision.
            if (Craft::$app->getDb()->getTransaction()?->getIsActive()) {
                return $this->blocked('guard-unavailable', 'transport');
            }
            $mutex = $this->mutex();
            if (!$mutex->acquire(self::LOCK, 0)) {
                return $this->blocked('guard-busy', 'transport', 1);
            }
            try {
                $now = $this->now();
                $account = $this->accountKey($credential);
                $result = null;
                foreach (['transport', $account] as $key) {
                    $row = $this->row($key);
                    if ($row !== null && $this->active($row, $now)) {
                        $result = $this->blocked('cooldown-active', $key === 'transport' ? 'transport' : 'account', $row['blockedUntil'] === null ? null : max(0, (int)$row['blockedUntil'] - $now));
                        break;
                    }
                }
                if ($result === null) {
                    // Commit before HTTP. Death, timeout, or persistence failure leaves
                    // an explicit manual hold; no lease expiry can admit another worker.
                    $token = StringHelper::UUID();
                    $this->put('transport', 'admission-uncertain', $now, null, $token);
                    try {
                        $attempted = true;
                        $result = SmsPrivacyHelper::sanitizeProviderResult($transport(), 'mpp-sms');
                    } catch (\Throwable $exception) {
                        $result = SmsPrivacyHelper::failureResultFromThrowable('mpp-sms', $exception);
                    }
                    if ($result['failureCode'] === 'account-unavailable') {
                        $this->put($account, 'account-unavailable', $this->now(), null, StringHelper::UUID());
                    }
                    if ($result['failureCode'] === 'ip-blocked') {
                        $until = $result['retryAfterSeconds'] === null ? null : $this->now() + $result['retryAfterSeconds'];
                        $this->put('transport', 'ip-blocked', $this->now(), $until, StringHelper::UUID());
                    } else {
                        // A request exception has an uncertain delivery outcome but is
                        // not evidence of an MPP account/IP hold. Never retry it here.
                        Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['scopeKey' => 'transport', 'token' => $token])->execute();
                    }
                }
            } finally {
                if (!$mutex->release(self::LOCK)) {
                    throw new \RuntimeException('MPP admission unlock failed.');
                }
            }
            return $result;
        } catch (\Throwable) {
            // Never log SQL/exception text: it may contain private identity.
            if ($attempted) {
                try {
                    $this->put('transport', 'admission-uncertain', $this->now(), null, StringHelper::UUID());
                } catch (\Throwable) {
                    // The pre-transport marker remains when completion cannot persist.
                }
            }
            return $this->blocked($attempted ? 'transport-uncertain' : 'guard-unavailable', 'transport');
        }
    }

    /** Safe current-provider projection. Private identity never reaches the view. */
    public function status(array $settings): array
    {
        try {
            return Craft::$app->getDb()->useMaster(function() use ($settings): array {
                $now = $this->now();
                $holds = [];
                foreach ($this->scopes($settings) as $lane => $key) {
                    $row = $this->row($key);
                    if ($row === null) {
                        continue;
                    }
                    $holds[] = [
                        'scope' => $key === 'transport' ? 'transport' : 'account',
                        'lane' => $lane,
                        'type' => in_array($row['failureCode'], ['account-unavailable', 'ip-blocked', 'admission-uncertain'], true) ? $row['failureCode'] : 'admission-uncertain',
                        'activatedAt' => (int)$row['activatedAt'],
                        'recoveryAt' => $row['blockedUntil'] === null ? null : (int)$row['blockedUntil'],
                        'active' => $this->active($row, $now),
                        'token' => $row['token'],
                    ];
                }
                return ['available' => true, 'holds' => $holds];
            });
        } catch (\Throwable) {
            return ['available' => false, 'holds' => []];
        }
    }

    /**
     * Release only a selected, unchanged hold under the same transport lock.
     * Records actor/time independently of private guard identity. Sends nothing.
     */
    public function release(array $settings, string $lane, string $token, int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        try {
            return Craft::$app->getDb()->useMaster(function() use ($settings, $lane, $token, $userId): bool {
                if (Craft::$app->getDb()->getTransaction()?->getIsActive()) {
                    return false;
                }
                $key = $this->scopes($settings)[$lane] ?? null;
                if ($key === null) {
                    return false;
                }
                $mutex = $this->mutex();
                if (!$mutex->acquire(self::LOCK, 0)) {
                    return false;
                }
                try {
                    $row = $this->row($key);
                    if ($row === null || !hash_equals($row['token'], $token)) {
                        return false;
                    }
                    return Craft::$app->getDb()->transaction(function() use ($key, $row, $lane, $userId): bool {
                        Craft::$app->getDb()->createCommand()->insert(self::RELEASE_TABLE, [
                            'scope' => $key === 'transport' ? 'transport' : 'account',
                            'lane' => $lane,
                            'failureCode' => $row['failureCode'],
                            'releasedBy' => $userId,
                            'releasedAt' => $this->now(),
                        ])->execute();
                        Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['scopeKey' => $key, 'token' => $row['token']])->execute();
                        return true;
                    });
                } finally {
                    $mutex->release(self::LOCK);
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
            default => throw new \RuntimeException('Unsupported MPP guard database.'),
        };
    }

    private function accountKey(string $credential): string
    {
        $secret = Craft::$app->getConfig()->getGeneral()->securityKey;
        if ($secret === '') {
            throw new \RuntimeException('MPP guard identity unavailable.');
        }
        return 'account:' . hash_hmac('sha256', "sms-manager:mpp-account\0" . $credential, $secret);
    }

    private function scopes(array $settings): array
    {
        $main = (string)App::parseEnv($settings['apiKey'] ?? '');
        $dev = (string)App::parseEnv($settings['devApiKey'] ?? '');
        $scopes = ['transport' => 'transport'];
        if ($main !== '') {
            $scopes['main'] = $this->accountKey($main);
        }
        if ($dev !== '' && $dev !== $main) {
            $scopes['development'] = $this->accountKey($dev);
        }
        return $scopes;
    }

    private function row(string $key): ?array
    {
        $row = (new Query())->from(self::TABLE)->where(['scopeKey' => $key])->one();
        return is_array($row) ? $row : null;
    }

    private function active(array $row, int $now): bool
    {
        return $row['blockedUntil'] === null || (int)$row['blockedUntil'] > $now;
    }

    private function put(string $key, string $code, int $at, ?int $until, string $token): void
    {
        Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
            'scopeKey' => $key, 'failureCode' => $code, 'activatedAt' => $at,
            'blockedUntil' => $until, 'token' => $token,
        ])->execute();
    }

    private function blocked(string $code, string $scope, ?int $seconds = null): array
    {
        return array_replace(SmsPrivacyHelper::failureResult('mpp-sms', 'provider'), [
            'failureCode' => $code, 'failureScope' => $scope, 'retryAfterSeconds' => $seconds,
        ]);
    }
}
