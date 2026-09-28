<?php

declare(strict_types=1);

namespace Noirapi\Auth\Providers;

use Closure;
use Noirapi\Auth\Contracts\AuthProviderInterface;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Exceptions\TooManyRequestsException;
use Noirapi\Helpers\Mail;
use Noirapi\Helpers\Utils;
use Noirapi\Lib\RateLimit\RateLimit;
use Noirapi\Lib\Security\Signer;
use Override;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

use function count;
use function ctype_digit;
use function explode;
use function is_string;

/**
 * Magic-link (passwordless email) sign-in with stateless, signed tokens. No table needed.
 *
 * The token carries the email and an expiry, signed with `app_secret_key` together with a
 * fingerprint the app supplies for that user: any value that changes when the user signs in,
 * such as the last-login timestamp. Signing in therefore invalidates every link issued
 * before, which makes links single-use without storing them.
 *
 *     $magic = $authManager->getMagicLinkProvider();
 *
 *     // "email me a link"
 *     $magic->send($email, $name, fingerprint: (string) $user->last_login_at, clientIp: $request->clientIp);
 *
 *     // GET /auth/magic-link/{token}
 *     $email = $magic->verify($token, fn (string $email): ?string => $users->lastLoginAt($email));
 *     if ($email !== null) { ...sign in, update last_login_at, Session::regenerate()... }
 *
 * The email template is app/templates/magic-link.latte when the app has one, else the bundled
 * Templates/mail/magic-link.latte (variables: name, magicUrl, ttlMinutes).
 *
 * @psalm-api
 */
readonly class MagicLinkProvider implements AuthProviderInterface
{
    private const string PURPOSE = 'magic-link';
    public const int DEFAULT_TTL = 900;

    /**
     * @param string $mailDsn
     * @param string $mailFrom
     * @param string $appUrl base URL for the links
     * @param int $ttl link lifetime in seconds
     * @param Signer|null $signer defaults to Signer::fromConfig() (app_secret_key), on first use
     * @param string $path the sign-in route, followed by /{token}
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private string $mailDsn,
        private string $mailFrom,
        private string $appUrl,
        private int $ttl = self::DEFAULT_TTL,
        private ?Signer $signer = null,
        private string $path = '/auth/magic-link',
    ) {
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function getName(): string
    {
        return 'magic_link';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function getLabel(): string
    {
        return 'Magic Link';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function getIcon(): string
    {
        return 'bi-envelope-at';
    }

    /**
     * A signed token for $email, valid for the TTL and until $fingerprint changes.
     *
     * @throws ConfigException without app_secret_key
     */
    public function issue(string $email, string $fingerprint, ?int $now = null): string
    {
        $email = self::normalize($email);
        $payload = $email . '|' . (($now ?? time()) + $this->ttl);

        return Utils::base64UrlEncode($payload) . '.' . $this->signer()->sign(self::PURPOSE, $payload . '|' . $fingerprint); //phpcs:ignore
    }

    /**
     * The email the token was issued for, or null when it is malformed, expired, tampered
     * with, or the user's fingerprint has changed since (e.g. they signed in).
     *
     * @param Closure(string): ?string $fingerprint the user's current fingerprint, null when no such user
     * @throws ConfigException without app_secret_key
     */
    public function verify(string $token, Closure $fingerprint, ?int $now = null): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        try {
            $payload = Utils::base64UrlDecode($parts[0]);
        } catch (RuntimeException) {
            return null;
        }

        // The local part of an email may contain "|": the expiry is after the last one.
        $separator = strrpos($payload, '|');
        $email = $separator === false ? '' : substr($payload, 0, $separator);
        $expires = $separator === false ? '' : substr($payload, $separator + 1);
        if ($email === '' || ! ctype_digit($expires) || (int) $expires < ($now ?? time())) {
            return null;
        }

        $current = $fingerprint($email);
        if (! is_string($current) || ! $this->signer()->verify(self::PURPOSE, $payload . '|' . $current, $parts[1])) {
            return null;
        }

        return $email;
    }

    /**
     * The sign-in URL for a token.
     *
     * @psalm-mutation-free
     */
    public function url(string $token): string
    {
        return rtrim($this->appUrl, '/') . '/' . trim($this->path, '/') . '/' . $token;
    }

    /**
     * Issues a token and emails the link, rate-limited per email and per client IP by the
     * `magic_link` limit (default 3 per 15 minutes).
     *
     * @throws TooManyRequestsException
     * @throws ConfigException
     * @throws RuntimeException | TransportExceptionInterface
     */
    public function send(string $toEmail, string $toName, string $fingerprint, string $clientIp = ''): void
    {
        $limiter = RateLimit::for('magic_link');
        $limiter->check('email:' . self::normalize($toEmail));
        if ($clientIp !== '') {
            $limiter->check('ip:' . $clientIp);
        }

        $this->sendEmail($toEmail, $toName, $this->issue($toEmail, $fingerprint), intdiv($this->ttl, 60));
    }

    /**
     * Emails the sign-in link for an already issued token.
     *
     * @throws RuntimeException | TransportExceptionInterface
     * @noinspection PhpUnused
     */
    public function sendEmail(
        string $toEmail,
        string $toName,
        string $token,
        int $ttlMinutes = 15,
    ): void {
        $mail = new Mail($this->mailDsn);
        $mail->new($this->mailFrom, $toEmail, 'Your sign-in link')
            ->setTemplate('magic-link', [
                'name'       => $toName,
                'magicUrl'   => $this->url($token),
                'ttlMinutes' => $ttlMinutes,
            ])
            ->send();
    }

    /**
     * @throws ConfigException
     *
     * @psalm-external-mutation-free
     */
    private function signer(): Signer
    {
        return $this->signer ?? Signer::fromConfig();
    }

    /**
     * @psalm-pure
     */
    private static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
