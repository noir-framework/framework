<?php

declare(strict_types=1);

namespace Noirapi\Auth\Providers;

use Closure;
use Noirapi\Auth\AuthMethod;
use Noirapi\Auth\AuthResult;
use Noirapi\Auth\KnownDevices;
use Noirapi\Auth\Contracts\AuthProviderInterface;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Exceptions\TooManyRequestsException;
use Noirapi\Lib\RateLimit\RateLimit;
use Override;

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
 *
 * @psalm-api
 */
readonly class PasswordProvider implements AuthProviderInterface
{
    /**
     * What an unknown e-mail's password is checked against: a bcrypt hash of a random string, at PHP 8.4's
     * PASSWORD_DEFAULT cost (12), so it takes as long as checking a real password_hash() and the response time does
     * not tell which e-mails have accounts.
     */
    public const string DUMMY_HASH = '$2y$12$7QE0Lx7OQXH4K0pnwnMmv.6U7oKlgLsfwL1pEQ5PBdC/FGdEfCDOO';

    /**
     * @param Closure(string $email): ?array{'password':string, 'name':?string, 'avatar_url':?string} $userLookup
     * @param string|null $dummyHash instead of DUMMY_HASH, for apps whose hashes have another algorithm or cost
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private Closure $userLookup,
        private ?string $dummyHash = null,
    ) {
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function getName(): string
    {
        return 'password';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function getLabel(): string
    {
        return 'Password';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function getIcon(): string
    {
        return 'bi-lock';
    }

    /**
     * Verify credentials and return an AuthResult on success, null on failure.
     */
    public function verify(string $email, string $password): ?AuthResult
    {
        $data = ($this->userLookup)($email);

        if ($data === null) {
            /* Timing-safe: still check a hash of the same cost, so the time does not reveal unknown e-mails */
            /** @psalm-suppress UnusedFunctionCall Result intentionally discarded - only the CPU cost matters here. */
            password_verify($password, $this->dummyHash ?? self::DUMMY_HASH);

            return null;
        }

        if (! password_verify($password, $data['password'])) {
            return null;
        }

        return new AuthResult(
            method:    AuthMethod::Password,
            email:     $email,
            name:      $data['name'] ?? null,
            avatarUrl: $data['avatar_url'] ?? null,
        );
    }

    /**
     * verify(), counting the attempt against the rate limits first, and throwing when one is used up:
     *
     * - `login` (5 a minute, then 15 minutes blocked) per client IP, and per e-mail from that IP;
     * - `login_email` (20 in 15 minutes, no block) per e-mail from every IP together, against guessing spread over
     *   many addresses. A browser whose $deviceCookie (KnownDevices::COOKIE) knows the e-mail skips it, so a
     *   stranger who knows an e-mail cannot keep its owner locked out. Call KnownDevices::remember() after each
     *   successful sign-in to make the browser known.
     *
     * Limit keys hold hashes, not e-mail addresses.
     *
     * @throws TooManyRequestsException
     * @throws ConfigException
     */
    public function verifyThrottled(string $email, string $password, string $clientIp, ?string $deviceCookie = null): ?AuthResult
    {
        $normalised = strtolower(trim($email));
        $login = RateLimit::for('login');
        $login->check('ip:' . $clientIp);
        $login->check('email-ip:' . hash('sha256', $normalised . '|' . $clientIp));
        if (! KnownDevices::knows($deviceCookie, $normalised)) {
            RateLimit::for('login_email')->check('email:' . hash('sha256', $normalised));
        }

        return $this->verify($email, $password);
    }
}
