<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\models;

use Craft;

/**
 * Provider-owned account identity and active lane for shared admission.
 * Credentials stay private; only keyed identities enter guard storage.
 *
 * @since 5.18.1
 */
final class TransportContext
{
    /** @param array<string, string> $accounts Resolved account identities by main/development lane. */
    public function __construct(
        public readonly string $providerType,
        private readonly string $identityNamespace,
        private readonly array $accounts,
        public readonly string $activeLane = 'main',
    ) {
        if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $providerType)
            || !preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $identityNamespace)
            || !in_array($activeLane, ['main', 'development'], true)
            || array_diff(array_keys($accounts), ['main', 'development'])) {
            throw new \InvalidArgumentException('Invalid transport context.');
        }
    }

    /** @return array<string, string> Safe lane to private storage key mapping. */
    public function scopes(): array
    {
        $secret = Craft::$app->getConfig()->getGeneral()->securityKey;
        if ($secret === '') {
            throw new \RuntimeException('Transport identity unavailable.');
        }
        $scopes = ['transport' => 'transport'];
        foreach ($this->accounts as $lane => $identity) {
            if ($identity !== '') {
                $scopes[$lane] = 'account:' . hash_hmac('sha256', 'sms-manager:' . $this->identityNamespace . "-account\0" . $identity, $secret);
            }
        }
        return $scopes;
    }

    public function accountKey(): string
    {
        return $this->scopes()[$this->activeLane] ?? throw new \RuntimeException('Transport account unavailable.');
    }
}
