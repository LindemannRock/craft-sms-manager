<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

final class CustomerArchiveContract
{
    private const DATABASE_PREFIX = 'sms_manager_archive_';

    private string $runId;

    private string $root;

    private string $databaseName;

    private string $securityKey;

    private bool $databaseCreated = false;

    private bool $grantCreated = false;

    private bool $databaseEverCreated = false;

    private bool $grantEverCreated = false;

    /** @var resource|null */
    private $activeProcess = null;

    /** @var list<array{command: list<string>, status: int}> */
    private array $commands = [];

    public function __construct(private readonly string $packageRoot)
    {
        $this->runId = bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/sms-manager-customer-archive-' . $this->runId;
        $this->databaseName = self::DATABASE_PREFIX . $this->runId;
        $this->securityKey = bin2hex(random_bytes(32));
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $original = null;
        $result = null;
        try {
            $this->createRoot();
            $first = $this->createAndExtractArchive('first');
            $second = $this->createAndExtractArchive('second');
            if ($first['manifest'] !== $second['manifest']) {
                throw new RuntimeException('Repeated customer archives have different member bytes.');
            }
            [$requiredCount, $excludedCount] = $this->assertContents($first['members']);
            $this->assertComposerInstallAndBootstrap($first['extractRoot']);
            $result = [
                'archiveMembers' => count($first['members']),
                'requiredAssertions' => $requiredCount,
                'excludedAssertions' => $excludedCount,
                'deterministicManifest' => hash('sha256', json_encode($first['manifest'], JSON_THROW_ON_ERROR)),
                'composerInstall' => true,
                'craftInstall' => true,
                'pluginBootstrap' => true,
                'databaseName' => $this->databaseName,
            ];
        } catch (Throwable $exception) {
            $original = $exception;
        }

        try {
            $cleanup = $this->cleanup();
        } catch (Throwable $cleanupFailure) {
            if ($original !== null) {
                throw new RuntimeException(
                    $original->getMessage() . '; archive cleanup also failed: ' . $cleanupFailure->getMessage(),
                    $original->getCode(),
                    $original,
                );
            }
            throw $cleanupFailure;
        }
        if ($original !== null) {
            throw new RuntimeException(
                $original->getMessage() . "\nArchive command evidence: "
                . json_encode($this->commands, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $original->getCode(),
                $original,
            );
        }

        $result['cleanup'] = $cleanup;
        return $result;
    }

    /** @return array{rootRemoved: bool, databaseRemoved: bool, grantRemoved: bool} */
    public function cleanup(): array
    {
        $errors = [];
        if ($this->grantCreated) {
            try {
                $this->adminPdo()->exec("REVOKE ALL PRIVILEGES ON `{$this->databaseName}`.* FROM 'db'@'%'");
                $this->grantCreated = false;
            } catch (Throwable $exception) {
                $errors[] = 'grant: ' . $exception->getMessage();
            }
        }
        if ($this->databaseCreated) {
            try {
                $this->adminPdo()->exec("DROP DATABASE `{$this->databaseName}`");
                $this->databaseCreated = false;
            } catch (Throwable $exception) {
                $errors[] = 'database: ' . $exception->getMessage();
            }
        }
        if (is_dir($this->root)) {
            try {
                $this->removeTree($this->root);
            } catch (Throwable $exception) {
                $errors[] = 'filesystem: ' . $exception->getMessage();
            }
        }
        $databaseRemoved = !$this->databaseEverCreated || !$this->databaseExists();
        $grantRemoved = !$this->grantEverCreated || !$this->grantExists();
        if (!$databaseRemoved) {
            $errors[] = 'database: owned database remains';
        }
        if (!$grantRemoved) {
            $errors[] = 'grant: owned grant remains';
        }
        if ($errors !== []) {
            throw new RuntimeException('Customer archive cleanup failed: ' . implode('; ', $errors));
        }
        return [
            'rootRemoved' => !file_exists($this->root),
            'databaseRemoved' => $databaseRemoved,
            'grantRemoved' => $grantRemoved,
        ];
    }

    public function terminateActiveProcess(): void
    {
        if (is_resource($this->activeProcess)) {
            proc_terminate($this->activeProcess);
        }
    }

    private function createRoot(): void
    {
        if (file_exists($this->root) || !mkdir($this->root, 0700, true)) {
            throw new RuntimeException('Unable to create the owned archive root.');
        }
    }

    /** @return array{members: list<string>, manifest: array<string, string>, extractRoot: string} */
    private function createAndExtractArchive(string $name): array
    {
        $outputRoot = $this->root . '/' . $name;
        $extractRoot = $outputRoot . '/package';
        mkdir($outputRoot, 0700, true);
        mkdir($extractRoot, 0700, true);
        $archive = $outputRoot . '/sms-manager.tar';
        $this->runCommand([
            'composer', '--no-plugins', 'archive', '--working-dir=' . $this->packageRoot, '--format=tar',
            '--dir=' . $outputRoot, '--file=sms-manager', '--no-interaction', '--no-ansi',
        ], $this->packageRoot);
        if (!is_file($archive)) {
            throw new RuntimeException('Composer did not create the customer archive.');
        }
        $listing = $this->runCommand(['tar', '-tf', $archive], $this->packageRoot)['stdout'];
        $members = array_values(array_filter(array_map(
            static fn(string $member): string => rtrim($member, '/'),
            preg_split('/\R/', trim($listing)) ?: [],
        ), static fn(string $member): bool => $member !== ''));
        sort($members);
        $this->runCommand(['tar', '-xf', $archive, '-C', $extractRoot], $this->packageRoot);

        $manifest = [];
        foreach ($members as $member) {
            $path = $extractRoot . '/' . $member;
            if (is_file($path)) {
                $digest = hash_file('sha256', $path);
                if (!is_string($digest)) {
                    throw new RuntimeException("Unable to hash archive member {$member}.");
                }
                $manifest[$member] = $digest;
            }
        }
        return ['members' => $members, 'manifest' => $manifest, 'extractRoot' => $extractRoot];
    }

    /** @param list<string> $members @return array{int, int} */
    private function assertContents(array $members): array
    {
        $tracked = preg_split('/\R/', trim($this->runCommand([
            'git', '-c', 'safe.directory=' . $this->packageRoot, 'ls-files', '--', 'src',
        ], $this->packageRoot)['stdout'])) ?: [];
        $required = ['composer.json', 'LICENSE.md', 'README.md', 'CHANGELOG.md', 'SECURITY.md'];
        foreach ($tracked as $path) {
            if ($path === ''
                || str_starts_with($path, 'src/web/assets/analytics/src/')
                || str_starts_with($path, 'src/web/assets/encoding/src/')
                || in_array($path, ['src/web/assets/package.json', 'src/web/assets/package-lock.json'], true)) {
                continue;
            }
            $required[] = $path;
        }
        $required = array_values(array_unique($required));
        sort($required);
        foreach ($required as $path) {
            if (!in_array($path, $members, true)) {
                throw new RuntimeException("Required customer archive member is missing: {$path}");
            }
        }

        $excluded = [
            '.gitattributes', '.gitignore', '.DS_Store', 'CLAUDE.md', '.internal/', '.phpunit.cache/',
            'vendor', 'tests/', '.github/', '.githooks/', 'scripts/', 'docs/', 'ecs.php', 'phpstan.neon',
            'phpunit.xml.dist', 'package.json', 'composer.lock', 'release-please-config.json', '.release-please-manifest.json',
            'src/web/assets/package.json', 'src/web/assets/package-lock.json',
            'src/web/assets/analytics/src/', 'src/web/assets/encoding/src/', 'src/web/assets/node_modules/',
        ];
        foreach ($excluded as $rule) {
            foreach ($members as $member) {
                $matches = str_ends_with($rule, '/') ? str_starts_with($member . '/', $rule) : $member === $rule;
                if ($matches) {
                    throw new RuntimeException("Contributor-only archive member is present: {$member}");
                }
                if (basename($member) === '.DS_Store') {
                    throw new RuntimeException("Finder metadata is present in the archive: {$member}");
                }
            }
        }
        foreach (['src/web/assets/analytics/dist/analytics.js', 'src/web/assets/encoding/dist/encoding.js'] as $asset) {
            if (!in_array($asset, $members, true)) {
                throw new RuntimeException("Published asset is missing: {$asset}");
            }
        }
        return [count($required), count($excluded)];
    }

    private function assertComposerInstallAndBootstrap(string $packageRoot): void
    {
        $projectRoot = $this->root . '/install-project';
        foreach (['config', 'storage', 'templates', 'web', 'composer-home'] as $path) {
            mkdir($projectRoot . '/' . $path, 0700, true);
        }
        $package = json_decode((string)file_get_contents($packageRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $version = $package['version'] ?? null;
        if (!is_string($version) || $version === '') {
            throw new RuntimeException('Archive composer.json has no usable version.');
        }
        $this->write($projectRoot . '/composer.json', json_encode([
            'name' => 'lindemannrock/sms-manager-archive-smoke',
            'type' => 'project',
            'repositories' => [['type' => 'path', 'url' => $packageRoot, 'options' => ['symlink' => false]]],
            'require' => ['lindemannrock/craft-sms-manager' => $version],
            'config' => ['allow-plugins' => ['craftcms/plugin-installer' => true, 'yiisoft/yii2-composer' => true, 'php-http/discovery' => true]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $environment = $this->environment($projectRoot);
        $this->runCommand(['composer', 'validate', '--strict', '--no-check-publish', '--no-check-version', '--no-interaction'], $packageRoot, $environment);
        $this->runCommand(['composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--no-ansi'], $projectRoot, $environment);

        $installedPackage = $projectRoot . '/vendor/lindemannrock/craft-sms-manager';
        if (!is_dir($installedPackage) || is_link($installedPackage)) {
            throw new RuntimeException('Composer did not install a physical customer package copy.');
        }
        foreach (['src/web/assets/analytics/dist/analytics.js', 'src/web/assets/encoding/dist/encoding.js'] as $asset) {
            if (!is_file($installedPackage . '/' . $asset)) {
                throw new RuntimeException("Installed customer package is missing {$asset}.");
            }
        }

        $this->createDatabase();
        $this->writeCraftProject($projectRoot);
        $this->runCommand([PHP_BINARY, $projectRoot . '/craft', 'install', '--interactive=0', '--silent-exit-on-exception=0', '--site-name=SMS Manager Archive', '--site-url=https://archive.example.test', '--language=en-US', '--username=archive-admin', '--email=archive@example.test', '--password=Archive-SMS-Manager-Password-2026!'], $projectRoot, $environment);
        foreach (['logging-library', 'sms-manager'] as $handle) {
            $this->runCommand([PHP_BINARY, $projectRoot . '/craft', 'plugin/install', $handle, '--interactive=0', '--silent-exit-on-exception=0'], $projectRoot, $environment);
        }
        $smoke = <<<'PHP'
<?php
require __DIR__ . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$class = 'lindemannrock\\smsmanager\\SmsManager';
$reflection = new ReflectionClass($class);
$expected = realpath(__DIR__ . '/vendor/lindemannrock/craft-sms-manager');
$actual = realpath(dirname((string)$reflection->getFileName(), 2));
if (!$app instanceof craft\console\Application || $expected === false || $actual !== $expected) {
    fwrite(STDERR, "Archive plugin bootstrap did not resolve the installed package.\n");
    exit(1);
}
fwrite(STDOUT, "Archive plugin bootstrap passed from {$actual}.\n");
PHP;
        $this->write($projectRoot . '/smoke.php', $smoke);
        $this->runCommand([PHP_BINARY, $projectRoot . '/smoke.php'], $projectRoot, $environment);
    }

    private function createDatabase(): void
    {
        if ($this->databaseExists() || $this->grantExists()) {
            throw new RuntimeException('Archive smoke database boundary is not fresh.');
        }
        $admin = $this->adminPdo();
        $admin->exec("CREATE DATABASE `{$this->databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        $this->databaseCreated = true;
        $this->databaseEverCreated = true;
        $admin->exec("GRANT ALL PRIVILEGES ON `{$this->databaseName}`.* TO 'db'@'%'");
        $this->grantCreated = true;
        $this->grantEverCreated = true;
    }

    private function writeCraftProject(string $root): void
    {
        $this->write($root . '/bootstrap.php', "<?php\ndefine('CRAFT_BASE_PATH', __DIR__);\ndefine('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');\nrequire CRAFT_VENDOR_PATH . '/autoload.php';\nif (class_exists(Dotenv\\Dotenv::class)) { Dotenv\\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad(); }\n");
        $this->write($root . '/craft', "#!/usr/bin/env php\n<?php\nrequire __DIR__ . '/bootstrap.php';\n\$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';\nexit(\$app->run());\n");
        chmod($root . '/craft', 0700);
        $this->write($root . '/config/general.php', "<?php\nuse craft\\config\\GeneralConfig;\nreturn GeneralConfig::create()->allowAdminChanges(true)->devMode(false)->omitScriptNameInUrls();\n");
        $this->write($root . '/config/app.php', "<?php\nreturn ['id' => 'sms-manager-archive-{$this->runId}', 'aliases' => ['@root' => dirname(__DIR__), '@webroot' => dirname(__DIR__) . '/web', '@web' => '/']];\n");
        $this->write($root . '/config/db.php', "<?php\nuse craft\\helpers\\App;\nreturn ['dsn' => App::env('CRAFT_DB_DSN'), 'user' => App::env('CRAFT_DB_USER'), 'password' => App::env('CRAFT_DB_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci', 'schema' => '', 'tablePrefix' => ''];\n");
    }

    /** @param list<string> $command @param array<string, string>|null $environment @return array{stdout: string, stderr: string, status: int} */
    private function runCommand(array $command, string $cwd, ?array $environment = null): array
    {
        $pipes = [];
        $this->activeProcess = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
        if (!is_resource($this->activeProcess)) {
            throw new RuntimeException('Unable to start archive contract command.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($this->activeProcess);
        $this->activeProcess = null;
        $this->commands[] = ['command' => $command, 'status' => $status];
        fwrite(STDOUT, (string)$stdout);
        fwrite(STDERR, (string)$stderr);
        if ($status !== 0) {
            throw new RuntimeException('Archive contract command failed (' . $status . '): ' . implode(' ', $command), $status);
        }
        return ['stdout' => (string)$stdout, 'stderr' => (string)$stderr, 'status' => $status];
    }

    /** @return array<string, string> */
    private function environment(string $projectRoot): array
    {
        $environment = [
            'PATH' => (string)($_SERVER['PATH'] ?? '/usr/local/bin:/usr/bin:/bin'),
            'LANG' => (string)($_SERVER['LANG'] ?? 'C.UTF-8'),
            'COMPOSER_HOME' => $projectRoot . '/composer-home',
            'COMPOSER_VENDOR_DIR' => $projectRoot . '/vendor',
            'CRAFT_APP_ID' => 'sms-manager-archive-' . $this->runId,
            'CRAFT_ENVIRONMENT' => 'test',
            'CRAFT_EDITION' => 'pro',
            'CRAFT_SECURITY_KEY' => $this->securityKey,
            'CRAFT_DB_DSN' => 'mysql:host=' . $this->dbHost() . ';port=3306;dbname=' . $this->databaseName,
            'CRAFT_DB_USER' => 'db',
            'CRAFT_DB_PASSWORD' => 'db',
            'PRIMARY_SITE_URL' => 'https://archive.example.test',
        ];
        $composerAuth = $_SERVER['COMPOSER_AUTH'] ?? null;
        if (is_string($composerAuth) && $composerAuth !== '') {
            $environment['COMPOSER_AUTH'] = $composerAuth;
        }
        return $environment;
    }

    private function adminPdo(): \PDO
    {
        return new \PDO('mysql:host=' . $this->dbHost() . ';port=3306;charset=utf8mb4', 'root', 'root', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
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

    private function write(string $path, string $contents): void
    {
        if (!str_starts_with($path, $this->root . '/')) {
            throw new LogicException('Refusing to write outside the owned archive root.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write {$path}");
        }
    }

    private function removeTree(string $root): void
    {
        $expected = '#^' . preg_quote(sys_get_temp_dir(), '#') . '/sms-manager-customer-archive-[a-f0-9]{16}$#';
        if ($root !== $this->root || preg_match($expected, $root) !== 1) {
            throw new LogicException('Refusing cleanup outside the exact archive boundary.');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                if (!unlink($item->getPathname())) {
                    throw new RuntimeException('Unable to remove an owned archive file.');
                }
            } elseif (!rmdir($item->getPathname())) {
                throw new RuntimeException('Unable to remove an owned archive directory.');
            }
        }
        if (!rmdir($root)) {
            throw new RuntimeException('Unable to remove the owned archive root.');
        }
    }
}

$contract = new CustomerArchiveContract(dirname(__DIR__));
if (!function_exists('pcntl_async_signals')) {
    fwrite(STDERR, "The customer archive runner requires PCNTL signal support.\n");
    exit(2);
}
pcntl_async_signals(true);
foreach ([SIGHUP, SIGINT, SIGTERM] as $signal) {
    pcntl_signal($signal, static function(int $received) use ($contract): never {
        $contract->terminateActiveProcess();
        try {
            $contract->cleanup();
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Signal cleanup failed: ' . $exception->getMessage() . PHP_EOL);
        }
        exit(128 + $received);
    });
}

try {
    $result = $contract->run();
    fwrite(STDOUT, 'Customer archive contract passed: ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() > 0 && $exception->getCode() < 256 ? $exception->getCode() : 1);
}
