<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

const BASELINE_PATH = 'tests/test-convention-baseline.json';

/** @return list<string> */
function identifierSegments(string $identifier): array
{
    $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $identifier) ?? $identifier;
    $segments = preg_split('/[^A-Za-z0-9]+/', $spaced, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($segments) ? array_values($segments) : [];
}

function forbiddenReason(string $identifier): ?string
{
    $segments = array_map(static fn(string $value): string => strtolower($value), identifierSegments($identifier));
    foreach ($segments as $segment) {
        if (preg_match('/^(?:pr|a)\d+$/', $segment) === 1) {
            return 'work-history ID';
        }
        if (in_array($segment, ['audit', 'debt', 'amendment', 'smoke', 'miscellaneous'], true)) {
            return 'work-history label';
        }
    }
    for ($index = 0; $index < count($segments) - 1; $index++) {
        $pair = $segments[$index] . '-' . $segments[$index + 1];
        if (in_array($pair, ['regression-batch', 'fix-batch', 'other-tests'], true)
            || ($segments[$index] === 'batch' && ctype_digit($segments[$index + 1]))) {
            return 'catch-all or batch label';
        }
    }
    return null;
}

/** @return list<array{path: string, kind: string, identifier: string, reason: string}> */
function scanPhp(string $path, string $source): array
{
    $violations = [];
    $tokens = token_get_all($source);
    $declarations = [T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION];
    if (defined('T_ENUM')) {
        $declarations[] = T_ENUM;
    }
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || !in_array($token[0], $declarations, true)) {
            continue;
        }
        for ($next = $index + 1; $next < count($tokens); $next++) {
            $candidate = $tokens[$next];
            if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($token[0] === T_FUNCTION && $candidate === '&') {
                continue;
            }
            if (is_array($candidate) && $candidate[0] === T_STRING && ($reason = forbiddenReason($candidate[1])) !== null) {
                $violations[] = ['path' => $path, 'kind' => $token[0] === T_FUNCTION ? 'function' : 'class', 'identifier' => $candidate[1], 'reason' => $reason];
            }
            break;
        }
    }
    return $violations;
}

/** @return list<array{path: string, kind: string, identifier: string, reason: string}> */
function scanTree(string $packageRoot): array
{
    $violations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageRoot . '/tests', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'js', 'mjs', 'cjs'], true)) {
            continue;
        }
        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($packageRoot) + 1));
        if (($reason = forbiddenReason(pathinfo($path, PATHINFO_FILENAME))) !== null) {
            $violations[] = ['path' => $path, 'kind' => 'path', 'identifier' => pathinfo($path, PATHINFO_FILENAME), 'reason' => $reason];
        }
        $source = file_get_contents($file->getPathname());
        if (!is_string($source)) {
            throw new RuntimeException("Unable to read {$path}.");
        }
        if (strtolower($file->getExtension()) === 'php') {
            array_push($violations, ...scanPhp($path, $source));
        } elseif (preg_match_all('/\b(?:test|it|describe)\s*\(\s*([\'"`])(.+?)\1/s', $source, $matches) > 0) {
            foreach ($matches[2] as $identifier) {
                if (($reason = forbiddenReason($identifier)) !== null) {
                    $violations[] = ['path' => $path, 'kind' => 'javascript-test', 'identifier' => $identifier, 'reason' => $reason];
                }
            }
        }
    }
    usort($violations, static fn(array $left, array $right): int => [$left['path'], $left['kind'], $left['identifier']] <=> [$right['path'], $right['kind'], $right['identifier']]);
    return $violations;
}

$packageRoot = dirname(__DIR__);
$actual = scanTree($packageRoot);
$baseline = json_decode((string)file_get_contents($packageRoot . '/' . BASELINE_PATH), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($baseline) || !array_is_list($baseline)) {
    throw new RuntimeException('Test convention baseline must be a JSON list.');
}
$key = static fn(array $item): string => implode("\0", [$item['path'], $item['kind'], $item['identifier'], $item['reason']]);
$actualKeys = array_fill_keys(array_map($key, $actual), true);
$baselineKeys = array_fill_keys(array_map($key, $baseline), true);
$new = array_values(array_filter($actual, static fn(array $item): bool => !isset($baselineKeys[$key($item)])));
$stale = array_values(array_filter($baseline, static fn(array $item): bool => !isset($actualKeys[$key($item)])));
foreach ($new as $item) {
    fwrite(STDERR, "New forbidden test identifier: {$item['path']} {$item['identifier']} ({$item['reason']})\n");
}
foreach ($stale as $item) {
    fwrite(STDERR, "Stale convention exception: {$item['path']} {$item['identifier']}\n");
}

$testSource = '';
$sourceIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageRoot . '/tests', FilesystemIterator::SKIP_DOTS));
foreach ($sourceIterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $testSource .= (string)file_get_contents($file->getPathname());
    }
}
$prohibited = ['truncateTable(', 'FLUSHDB', 'FLUSHALL', 'docker system prune', "deleteAll(['like'"];
foreach ($prohibited as $needle) {
    if (str_contains($testSource, $needle)) {
        fwrite(STDERR, "Prohibited broad test cleanup pattern: {$needle}\n");
        $new[] = ['pattern' => $needle];
    }
}
$phpunit = (string)file_get_contents($packageRoot . '/phpunit.xml.dist');
foreach (['failOnRisky="true"', 'failOnWarning="true"', 'beStrictAboutOutputDuringTests="true"'] as $required) {
    if (!str_contains($phpunit, $required)) {
        fwrite(STDERR, "Missing PHPUnit lifecycle safeguard: {$required}\n");
        $new[] = ['pattern' => $required];
    }
}
if ($new !== [] || $stale !== []) {
    exit(1);
}
fwrite(STDOUT, sprintf('Test convention and lifecycle guard passed: %d shrinking baseline exceptions, no broad cleanup.\n', count($baseline)));
