<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$vendorRoot = null;
foreach ([$packageRoot . '/vendor', dirname($packageRoot, 2) . '/vendor'] as $candidate) {
    $resolved = realpath($candidate);
    if ($resolved !== false && is_file($resolved . '/autoload.php') && is_file($resolved . '/bin/phpstan')) {
        $vendorRoot = $resolved;
        break;
    }
}
if ($vendorRoot === null) {
    fwrite(STDERR, "PHPStan is unavailable. Run composer install before committing.\n");
    exit(2);
}

$probePath = sys_get_temp_dir() . '/sms-manager-quality-platform-' . bin2hex(random_bytes(8)) . '.php';
$probe = <<<'PHP'
<?php
use PHPUnit\Framework\Attributes\CoversClass;
#[CoversClass(stdClass::class)]
#[CoversClass(RuntimeException::class)]
final class SmsManagerRepeatableAttributeProbe {}
PHP;
$status = 0;

try {
    if (file_put_contents($probePath, $probe) === false) {
        throw new RuntimeException('Unable to create the quality-platform probe.');
    }
    $command = [PHP_BINARY, $vendorRoot . '/bin/phpstan', 'analyse', '--no-progress', '--no-ansi', '--error-format=raw', '--level=5', '--autoload-file=' . $vendorRoot . '/autoload.php', $probePath];
    passthru(implode(' ', array_map('escapeshellarg', $command)), $status);
    if ($status !== 0) {
        fwrite(STDERR, "The installed PHPStan/PHPUnit toolchain rejected the compatibility probe.\n");
    }
} finally {
    if (is_file($probePath) && !unlink($probePath) && $status === 0) {
        $status = 1;
        fwrite(STDERR, "Unable to remove the quality-platform probe.\n");
    }
}

if ($status === 0) {
    fwrite(STDOUT, 'Standalone quality-platform probe passed under PHP ' . PHP_VERSION . ".\n");
}
exit($status);
