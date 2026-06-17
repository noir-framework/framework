<?php

declare(strict_types=1);

namespace Noirapi\Auth\Providers;

use Noirapi\Auth\AuthMethod;
use Noirapi\Auth\AuthResult;
use Noirapi\Auth\Contracts\AuthProviderInterface;

/**
 * Password-based authentication provider.
 *
 * All DB knowledge is injected via $userLookup so this class has
 * no framework dependency and can be unit-tested in isolation.
 *
 * Inject example (in AuthGateway):
 *   new PasswordProvider(function (string $email): ?array {
 *       $user = (new Users())->getUserByEmail($email);
 *       if ($user === null) return null;
 *       return [
 *           'password'   => $user->password,
 *           'name'       => $user->name,
 *           'avatar_url' => $user->avatar_url,
 *       ];
 *   });
 */
class PasswordProvider implements AuthProviderInterface
{
    /**
     * @param \Closure(string $email): ?array{'password':string, 'name':?string, 'avatar_url':?string} $userLookup
     */
    public function __construct(
        private readonly \Closure $userLookup,
    ) {}

    public function getName(): string  { return 'password'; }
    public function getLabel(): string { return 'Password'; }
    public function getIcon(): string  { return 'bi-lock'; }

    /**
     * Verify credentials and return an AuthResult on success, null on failure.
     */
    public function verify(string $email, string $password): ?AuthResult
    {
        $data = ($this->userLookup)($email);

        if ($data === null) {
            /* Timing-safe: still run a hash to prevent user-enumeration via timing */
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');
            return null;
        }

        if (!password_verify($password, $data['password'])) {
            return null;
        }

        $result            = new AuthResult();
        $result->method    = AuthMethod::Password;
        $result->email     = $email;
        $result->name      = $data['name'] ?? null;
        $result->avatarUrl = $data['avatar_url'] ?? null;

        return $result;
    }
}
