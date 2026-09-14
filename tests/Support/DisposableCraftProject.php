<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Support;

use PDO;

/**
 * Owns one disposable MySQL Craft project for the complete behavioral suite.
 *
 * @since 5.16.0
 */
final class DisposableCraftProject
{
    public const SOURCE_VENDOR_ENV = 'SMS_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT';

    public const FAILURE_STAGE_ENV = 'SMS_MANAGER_FIXTURE_FAIL_STAGE';

    private const DATABASE_PREFIX = 'sms_manager_qg_';

    private const PLUGIN_HANDLES = ['logging-library', 'sms-manager'];

    private string $runId;

    private string $projectRoot;

    private string $databaseName;

    private string $vendorRoot;

    private string $securityKey;

    private bool $databaseCreated = false;

    private bool $grantCreated = false;

    /** @var resource|null */
    private $activeProcess = null;

    /** @var list<array{command: list<string>, exitCode: int, stdout: string, stderr: string}> */
    private array $commands = [];

    public function __construct(private readonly string $packageRoot)
    {
        $this->runId = bin2hex(random_bytes(8));
        $this->projectRoot = sys_get_temp_dir() . '/sms-manager-fixture-' . $this->runId;
        $this->databaseName = self::DATABASE_PREFIX . $this->runId;
        $this->vendorRoot = $this->resolveVendorRoot();
        $this->securityKey = bin2hex(random_bytes(32));
    }

    /** @return array<string, mixed> */
    public function run(array $phpunitArguments = []): array
    {
        $originalFailure = null;
        $phpunit = null;
        try {
            $this->createDatabase();
            $this->createProject();
            $this->installCraft();
            $this->installPlugins();
            $phpunit = $this->runPhpunit($phpunitArguments);
        } catch (\Throwable $exception) {
            $originalFailure = $exception;
        }

        try {
            $cleanup = $this->cleanup();
        } catch (\Throwable $cleanupFailure) {
            if ($originalFailure !== null) {
                throw new \RuntimeException(
                    $originalFailure->getMessage() . '; cleanup also failed: ' . $cleanupFailure->getMessage(),
                    $originalFailure->getCode(),
                    $originalFailure,
                );
            }
            throw $cleanupFailure;
        }
        if ($originalFailure !== null) {
            throw new \RuntimeException(
                $originalFailure->getMessage() . "\nCommands:\n"
                . json_encode($this->commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $originalFailure->getCode(),
                $originalFailure,
            );
        }

        return [
            'runId' => $this->runId,
            'databaseName' => $this->databaseName,
            'phpunit' => $phpunit,
            'commands' => $this->commands,
            'cleanup' => $cleanup,
        ];
    }

    /** @return array{projectRemoved: bool, databaseRemoved: bool, grantRemoved: bool} */
    public function cleanup(): array
    {
        $errors = [];
        if ($this->grantCreated) {
            try {
                $this->adminPdo()->exec("REVOKE ALL PRIVILEGES ON `{$this->databaseName}`.* FROM 'db'@'%'");
                $this->grantCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'grant: ' . $exception->getMessage();
            }
        }
        if ($this->databaseCreated) {
            try {
                $this->adminPdo()->exec("DROP DATABASE `{$this->databaseName}`");
                $this->databaseCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'database: ' . $exception->getMessage();
            }
        }
        if (is_dir($this->projectRoot)) {
            try {
                $this->removeOwnedProjectRoot();
            } catch (\Throwable $exception) {
                $errors[] = 'filesystem: ' . $exception->getMessage();
            }
        }
        if ($this->databaseExists()) {
            $errors[] = 'database: exact run-owned database remains';
        }
        if ($this->grantExists()) {
            $errors[] = 'grant: exact run-owned grant remains';
        }
        if (($_SERVER[self::FAILURE_STAGE_ENV] ?? null) === 'cleanup') {
            $errors[] = 'synthetic cleanup failure';
        }
        if ($errors !== []) {
            throw new \RuntimeException('Disposable cleanup failed: ' . implode('; ', $errors));
        }

        return [
            'projectRemoved' => !file_exists($this->projectRoot),
            'databaseRemoved' => !$this->databaseExists(),
            'grantRemoved' => !$this->grantExists(),
        ];
    }

    public function terminateActiveProcess(): void
    {
        if (is_resource($this->activeProcess)) {
            proc_terminate($this->activeProcess);
        }
    }

    private function createDatabase(): void
    {
        $this->injectFailure('database');
        if (preg_match('/^' . self::DATABASE_PREFIX . '[a-f0-9]{16}$/', $this->databaseName) !== 1
            || $this->databaseName === 'db' || $this->databaseExists() || $this->grantExists()) {
            throw new \RuntimeException('Disposable database boundary is invalid or not fresh.');
        }
        $admin = $this->adminPdo();
        $admin->exec("CREATE DATABASE `{$this->databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        $this->databaseCreated = true;
        $admin->exec("GRANT ALL PRIVILEGES ON `{$this->databaseName}`.* TO 'db'@'%'");
        $this->grantCreated = true;
    }

    private function createProject(): void
    {
        $this->injectFailure('project');
        if (file_exists($this->projectRoot)) {
            throw new \RuntimeException('Disposable project root already exists.');
        }
        foreach (['config', 'storage', 'templates', 'web/cpresources'] as $relative) {
            $path = $this->projectRoot . '/' . $relative;
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException("Unable to create disposable path: {$path}");
            }
        }
        if (!symlink($this->vendorRoot, $this->projectRoot . '/vendor')) {
            throw new \RuntimeException('Unable to link the explicit fixture vendor root.');
        }
        $this->writeFile('bootstrap.php', "<?php\ndefine('CRAFT_BASE_PATH', __DIR__);\ndefine('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');\nrequire CRAFT_VENDOR_PATH . '/autoload.php';\nif (class_exists(Dotenv\\Dotenv::class)) { Dotenv\\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad(); }\n");
        $this->writeFile('craft', "#!/usr/bin/env php\n<?php\nrequire __DIR__ . '/bootstrap.php';\n\$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';\nexit(\$app->run());\n");
        chmod($this->projectRoot . '/craft', 0700);
        $this->writeFile('config/general.php', "<?php\nuse craft\\config\\GeneralConfig;\nreturn GeneralConfig::create()->allowAdminChanges(true)->devMode(false)->omitScriptNameInUrls();\n");
        $this->writeFile('config/app.php', "<?php\nreturn ['id' => 'sms-manager-fixture-{$this->runId}', 'aliases' => ['@root' => dirname(__DIR__), '@webroot' => dirname(__DIR__) . '/web', '@web' => '/'], 'components' => ['assetManager' => ['basePath' => dirname(__DIR__) . '/web/cpresources', 'baseUrl' => '/cpresources']]];\n");
        $this->writeFile('config/db.php', "<?php\nuse craft\\helpers\\App;\nreturn ['dsn' => App::env('CRAFT_DB_DSN'), 'user' => App::env('CRAFT_DB_USER'), 'password' => App::env('CRAFT_DB_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci', 'schema' => '', 'tablePrefix' => ''];\n");
        $this->writeFile('.env', implode("\n", [
            'CRAFT_APP_ID=sms-manager-fixture-' . $this->runId,
            'CRAFT_ENVIRONMENT=test',
            'CRAFT_EDITION=pro',
            'CRAFT_SECURITY_KEY=' . $this->securityKey,
            'CRAFT_DB_DSN=' . $this->fixtureDsn(),
            'CRAFT_DB_USER=db',
            'CRAFT_DB_PASSWORD=db',
            'PRIMARY_SITE_URL=https://sms-manager-fixture.example.test',
            '',
        ]));
    }

    private function installCraft(): void
    {
        $this->injectFailure('install');
        $this->runCommand([PHP_BINARY, $this->projectRoot . '/craft', 'install', '--interactive=0', '--silent-exit-on-exception=0', '--site-name=SMS Manager Fixture', '--site-url=https://sms-manager-fixture.example.test', '--language=en-US', '--username=fixture-admin', '--email=fixture@example.test', '--password=Fixture-SMS-Manager-Password-2026!'], $this->projectRoot);
    }

    private function installPlugins(): void
    {
        $this->injectFailure('plugins');
        foreach (self::PLUGIN_HANDLES as $handle) {
            $this->runCommand([PHP_BINARY, $this->projectRoot . '/craft', 'plugin/install', $handle, '--interactive=0', '--silent-exit-on-exception=0'], $this->projectRoot);
        }
    }

    /** @param list<string> $arguments */
    private function runPhpunit(array $arguments): array
    {
        $this->injectFailure('phpunit');
        return $this->runCommand([PHP_BINARY, $this->vendorRoot . '/bin/phpunit', '--configuration', $this->packageRoot . '/phpunit.xml.dist', '--colors=never', ...$arguments], $this->packageRoot);
    }

    /** @param list<string> $command @return array{command: list<string>, exitCode: int, stdout: string, stderr: string} */
    private function runCommand(array $command, string $cwd): array
    {
        $pipes = [];
        $this->activeProcess = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $this->environment());
        if (!is_resource($this->activeProcess)) {
            throw new \RuntimeException('Unable to start disposable command.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($this->activeProcess);
        $this->activeProcess = null;
        $result = ['command' => $command, 'exitCode' => $status, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
        $this->commands[] = $result;
        fwrite(STDOUT, $result['stdout']);
        fwrite(STDERR, $result['stderr']);
        if ($status !== 0) {
            throw new \RuntimeException('Disposable command failed (' . $status . '): ' . implode(' ', $command), $status);
        }
        return $result;
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [
            'PATH' => (string)($_SERVER['PATH'] ?? '/usr/local/bin:/usr/bin:/bin'),
            'LANG' => (string)($_SERVER['LANG'] ?? 'C.UTF-8'),
            'CRAFT_APP_ID' => 'sms-manager-fixture-' . $this->runId,
            'CRAFT_ENVIRONMENT' => 'test',
            'CRAFT_EDITION' => 'pro',
            'CRAFT_SECURITY_KEY' => $this->securityKey,
            'CRAFT_DB_DSN' => $this->fixtureDsn(),
            'CRAFT_DB_USER' => 'db',
            'CRAFT_DB_PASSWORD' => 'db',
            'PRIMARY_SITE_URL' => 'https://sms-manager-fixture.example.test',
            'CRAFT_TEST_PROJECT_ROOT' => $this->projectRoot,
            self::SOURCE_VENDOR_ENV => $this->vendorRoot,
        ];
    }

    private function fixtureDsn(): string
    {
        return 'mysql:host=' . $this->dbHost() . ';port=3306;dbname=' . $this->databaseName;
    }

    private function adminPdo(): PDO
    {
        return new PDO('mysql:host=' . $this->dbHost() . ';port=3306;charset=utf8mb4', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function dbHost(): string
    {
        $host = $_SERVER['SMS_MANAGER_TEST_DB_HOST'] ?? 'db';
        return is_string($host) && preg_match('/^[A-Za-z0-9_.-]+$/', $host) === 1 ? $host : 'db';
    }

    private function databaseExists(): bool
    {
        $statement = $this->adminPdo()->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name');
        $statement->execute(['name' => $this->databaseName]);
        return (int)$statement->fetchColumn() === 1;
    }

    private function grantExists(): bool
    {
        $statement = $this->adminPdo()->prepare("SELECT COUNT(*) FROM mysql.db WHERE Host = '%' AND Db = :name AND User = 'db'");
        $statement->execute(['name' => $this->databaseName]);
        return (int)$statement->fetchColumn() === 1;
    }

    private function resolveVendorRoot(): string
    {
        $configured = $_SERVER[self::SOURCE_VENDOR_ENV] ?? null;
        if (!is_string($configured) || !str_starts_with($configured, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException(self::SOURCE_VENDOR_ENV . ' must name an absolute vendor root.');
        }
        $resolved = realpath($configured);
        if ($resolved === false || !is_file($resolved . '/autoload.php')) {
            throw new \RuntimeException('The explicit fixture vendor root is invalid.');
        }
        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function writeFile(string $relative, string $contents): void
    {
        $path = $this->projectRoot . '/' . $relative;
        if (!str_starts_with($path, $this->projectRoot . '/')) {
            throw new \LogicException('Refusing a write outside the disposable project.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write {$path}");
        }
    }

    private function removeOwnedProjectRoot(): void
    {
        $expected = '#^' . preg_quote(sys_get_temp_dir(), '#') . '/sms-manager-fixture-[a-f0-9]{16}$#';
        if (preg_match($expected, $this->projectRoot) !== 1) {
            throw new \LogicException('Refusing cleanup outside the disposable project boundary.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                if (!unlink($item->getPathname())) {
                    throw new \RuntimeException('Unable to remove owned fixture file.');
                }
            } elseif (!rmdir($item->getPathname())) {
                throw new \RuntimeException('Unable to remove owned fixture directory.');
            }
        }
        if (!rmdir($this->projectRoot)) {
            throw new \RuntimeException('Unable to remove the disposable project root.');
        }
    }

    private function injectFailure(string $stage): void
    {
        if (($_SERVER[self::FAILURE_STAGE_ENV] ?? null) === $stage) {
            throw new \RuntimeException("Synthetic disposable fixture {$stage} failure.");
        }
    }
}
