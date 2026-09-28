<?php

declare(strict_types=1);

namespace Noirapi\Auth;

use Noirapi\Auth\Contracts\AuthProviderInterface;
use Noirapi\Auth\Providers\GitHubProvider;
use Noirapi\Auth\Providers\GoogleProvider;
use Noirapi\Auth\Providers\MagicLinkProvider;
use Noirapi\Auth\Providers\PasswordProvider;
use Noirapi\Auth\Providers\TotpProvider;
use RobThree\Auth\TwoFactorAuthException;
use RuntimeException;

/**
 * Registry of all configured authentication providers.
 *
 * Holds OAuth providers, the password provider, magic-link provider,
 * and TOTP provider. The app-layer AuthGateway uses this as its source
 * of configured providers.
 *
 * Usage:
 *   $manager  = AuthManager::fromConfig(Config::get('auth') ?? [], Config::get('mail') ?? [], $appUrl);
 *   $provider = $manager->get('google');
 *   $url      = $provider->getRedirectUrl();
 *
 * @psalm-api
 */
class AuthManager
{
    /** @var array<string, AuthProviderInterface> */
    private array $providers = [];

    private ?PasswordProvider $passwordProvider = null;
    private ?MagicLinkProvider $magicLinkProvider = null;
    private ?TotpProvider $totpProvider = null;

    /**
     * @psalm-mutation-free
     */
    public function __construct()
    {
    }

    /* ── OAuth provider registry ─────────────────────────────── */

    /**
     * @psalm-external-mutation-free
     */
    public function register(AuthProviderInterface $provider): void
    {
        $this->providers[$provider->getName()] = $provider;
    }

    /**
     * @throws RuntimeException  if the provider is not registered
     *
     * @psalm-mutation-free
     */
    public function get(string $name): AuthProviderInterface
    {
        return $this->providers[$name]
            ?? throw new RuntimeException("OAuth provider '$name' is not configured.");
    }

    /**
     * @psalm-mutation-free
     */
    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * All registered OAuth providers, in registration order.
     *
     * @return AuthProviderInterface[]
     *
     * @psalm-mutation-free
     */
    public function getAll(): array
    {
        return array_values($this->providers);
    }

    /* ── Password provider ───────────────────────────────────── */

    /**
     * @psalm-external-mutation-free
     * @noinspection PhpUnused
     */
    public function setPasswordProvider(PasswordProvider $provider): void
    {
        $this->passwordProvider = $provider;
    }

    /** @noinspection PhpUnused */
    public function getPasswordProvider(): ?PasswordProvider
    {
        return $this->passwordProvider;
    }

    /**
     * @psalm-mutation-free
     * @noinspection PhpUnused
     */
    public function hasPasswordProvider(): bool
    {
        return $this->passwordProvider !== null;
    }

    /* ── Magic-link provider ─────────────────────────────────── */

    /**
     * @psalm-external-mutation-free
     */
    public function setMagicLinkProvider(MagicLinkProvider $provider): void
    {
        $this->magicLinkProvider = $provider;
    }

    /** @noinspection PhpUnused */
    public function getMagicLinkProvider(): ?MagicLinkProvider
    {
        return $this->magicLinkProvider;
    }

    /**
     * @psalm-mutation-free
     * @noinspection PhpUnused
     */
    public function hasMagicLinkProvider(): bool
    {
        return $this->magicLinkProvider !== null;
    }

    /* ── TOTP provider ───────────────────────────────────────── */

    /**
     * @psalm-external-mutation-free
     */
    public function setTotpProvider(TotpProvider $provider): void
    {
        $this->totpProvider = $provider;
    }

    /**
     * @throws RuntimeException if auth.totp.issuer is not set in config
     *
     * @psalm-mutation-free
     * @noinspection PhpUnused
     */
    public function getTotpProvider(): TotpProvider
    {
        return $this->totpProvider
            ?? throw new RuntimeException('TOTP issuer is not configured. Set auth.totp.issuer in config.');
    }

    /* ── Factory ─────────────────────────────────────────────── */

    /**
     * Build an AuthManager from config sections.
     *
     * NEON example:
     *   auth:
     *     totp:
     *       issuer: 'My App'   # label shown in authenticator apps (default: 'PMX')
     *     google:
     *       client_id:     'xxx'
     *       client_secret: 'yyy'
     *       redirect_uri:  'https://app.com/auth/oauth/google/callback'
     *     github:
     *       client_id:     'xxx'
     *       client_secret: 'yyy'
     *       redirect_uri:  'https://app.com/auth/oauth/github/callback'
     *     magic_link:
     *       enabled: true    # also requires mail.dsn to be set
     *
     * @param array<string,mixed> $config Contents of Config::get('auth') ?? []
     * @param array<string,mixed> $mailConfig Contents of Config::get('mail')  ?? []
     * @param string $appUrl Base URL used in magic-link generation
     * @return AuthManager
     * @throws TwoFactorAuthException
     * @noinspection PhpUnused
     */
    public static function fromConfig(
        array $config,
        array $mailConfig = [],
        string $appUrl = '',
    ): self {
        $manager = new self();

        if (! empty($config['totp']['issuer'])) {
            $manager->setTotpProvider(new TotpProvider((string) $config['totp']['issuer']));
        }

        if (self::validOAuth($config, 'google')) {
            $manager->register(new GoogleProvider(
                (string) $config['google']['client_id'],
                (string) $config['google']['client_secret'],
                (string) $config['google']['redirect_uri'],
            ));
        }

        if (self::validOAuth($config, 'github')) {
            $manager->register(new GitHubProvider(
                (string) $config['github']['client_id'],
                (string) $config['github']['client_secret'],
                (string) $config['github']['redirect_uri'],
            ));
        }

        /* Magic link — only enabled when mail.dsn is configured */
        $mailDsn = $mailConfig['dsn'] ?? null;
        if (! empty($config['magic_link']['enabled']) && $mailDsn !== null) {
            $manager->setMagicLinkProvider(new MagicLinkProvider(
                mailDsn:  (string) $mailDsn,
                mailFrom: (string) ($mailConfig['from'] ?? 'no-reply@' . parse_url($appUrl, PHP_URL_HOST)),
                appUrl:   $appUrl,
            ));
        }

        return $manager;
    }

    /** Returns true when an OAuth provider block has all three required keys. */
    private static function validOAuth(array $config, string $provider): bool
    {
        return ! empty($config[$provider]['client_id'])
            && ! empty($config[$provider]['client_secret'])
            && ! empty($config[$provider]['redirect_uri']);
    }
}
