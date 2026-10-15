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
use craft\db\Connection;
use craft\db\Query;
use lindemannrock\smsmanager\services\MppTransportService;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Independent PHP processes share one durable transport admission boundary.
 *
 * @since 5.18.0
 */
final class MppAdmissionConcurrencyTest extends TestCase
{
    #[DataProvider('workerOutcomes')]
    public function testConcurrentAdmissionAndWorkerDeathRemainClosed(bool $kill): void
    {
        $original = Craft::$app->getDb();
        $name = (string)$original->createCommand('SELECT DATABASE()')->queryScalar();
        self::assertMatchesRegularExpression('/^sms_manager_qg_[a-f0-9]{16}$/', $name);
        // A distinct connection sees the real runner-owned tables, not the
        // connection-local shadows used by ordinary tests.
        $db = new Connection(['dsn' => $original->dsn, 'username' => $original->username, 'password' => $original->password]);
        $pipes = [];
        $process = null;
        try {
            Craft::$app->set('db', $db);
            self::assertSame(0, (int)(new Query())->from(MppTransportService::TABLE)->count());
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/Transport/worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $read = [$pipes[1]];
            $write = $except = [];
            self::assertSame(1, stream_select($read, $write, $except, 15));
            $line = fgets($pipes[1]);
            while (is_string($line) && trim($line) === '') {
                $line = fgets($pipes[1]);
            }
            if ($line !== "ADMITTED\n") {
                self::fail('Worker did not enter intercepted transport: ' . $line . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
            }
            $guard = new MppTransportService();
            $requests = 0;
            $transport = static function() use (&$requests): array {
                $requests++;
                return ['success' => true, 'messageId' => null, 'response' => 'OK'];
            };
            self::assertSame('guard-busy', $guard->send('synthetic-concurrent-account', $transport)['failureCode']);
            self::assertSame('guard-busy', $guard->send('synthetic-another-account', $transport)['failureCode']);
            self::assertSame(0, $requests);
            fwrite(STDOUT, "Concurrent child: intercepted=1; real=0; queue-workers=0\n");
            if ($kill) {
                proc_terminate($process, 9);
            } else {
                fwrite($pipes[0], "complete\n");
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $process = null;
            $pipes = [];
            if (!$kill) {
                self::assertStringContainsString('account-unavailable', $output);
                self::assertStringContainsString('intercepted=1; real=0', $errors);
            }
            $settings = ['apiKey' => 'synthetic-concurrent-account'];
            $hold = $guard->status($settings)['holds'][0];
            self::assertSame($kill ? 'admission-uncertain' : 'account-unavailable', $hold['type']);
            self::assertSame('cooldown-active', $guard->send('synthetic-concurrent-account', $transport)['failureCode']);
            self::assertSame(0, $requests);
            self::assertTrue($guard->release($settings, $hold['lane'], $hold['token'], 1));
            self::assertTrue($guard->send('synthetic-concurrent-account', $transport)['success']);
            self::assertSame(1, $requests);
            self::assertSame([], $guard->status($settings)['holds']);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            // All rows in this exclusive disposable fixture were created by
            // this test. Delete them by exact primary key, including failure paths.
            foreach ((new Query())->select('scopeKey')->from(MppTransportService::TABLE)->column() as $key) {
                $db->createCommand()->delete(MppTransportService::TABLE, ['scopeKey' => $key])->execute();
            }
            foreach ((new Query())->select('id')->from(MppTransportService::RELEASE_TABLE)->column() as $id) {
                $db->createCommand()->delete(MppTransportService::RELEASE_TABLE, ['id' => $id])->execute();
            }
            Craft::$app->set('db', $original);
            $db->close();
        }
    }

    public static function workerOutcomes(): iterable
    {
        yield 'classification committed before unlock' => [false];
        yield 'process killed during intercepted HTTP' => [true];
    }
}
