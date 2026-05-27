<?php

declare(strict_types=1);

namespace Noirapi\Auth;

/**
 * Resolve the best avatar URL for a user.
 *
 * Iterates over the user's linked OAuth accounts and returns the first
 * non-empty avatar_url. If none are found it calls the $fallback callable
 * (e.g. return a local identicon path).
 */
class AvatarResolver
{
    /**
     * @param iterable<object|array<string,mixed>> $oauthAccounts
     *        Each item must expose avatar_url as a property or array key.
     * @param callable(): string $fallback
     *        Invoked when no remote avatar exists; should return a local URL.
     */
    public static function resolve(iterable $oauthAccounts, callable $fallback): string
    {
        foreach ($oauthAccounts as $account) {
            $url = is_array($account)
                ? ($account['avatar_url'] ?? null)
                : ($account->avatar_url ?? null);

            if (!empty($url)) {
                return (string) $url;
            }
        }

        return $fallback();
    }
}
