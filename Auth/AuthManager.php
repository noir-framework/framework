<?php

declare(strict_types=1);

namespace Noirapi\Auth;

use Noirapi\Auth\Contracts\AuthProviderInterface;
use Noirapi\Auth\Providers\GitHubProvider;
use Noirapi\Auth\Providers\GoogleProvider;
use Noirapi\Auth\Providers\MagicLinkProvider;
use Noirapi\Auth\Providers\PasswordProvider;
use Noirapi\Auth\Providers\TotpProvider;
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
 */
class AuthManager
{
    /** @var array<string, AuthProviderInterface> */
    private array $providers = [];

    private ?PasswordProvider  $passwordProvider = null;
    private ?MagicLinkProvider $magicLinkProvider = null;
    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
    private TotpProvider       $totpProvider;

    public function __construct()
    {
        /* TOTP is always available — default issuer overridden by fromConfig() */
        $this->totpProvider = new TotpProvider('App');
    }

    /* ── OAuth provider registry ─────────────────────────────── */

    public function register(AuthProviderInterface $provider): void
    {
        $this->providers[$provider->getName()] = $provider;
    }

    /**
     * @throws RuntimeException  if the provider is not registered
     */
    public function get(string $name): AuthProviderInterface
    {
        return $this->providers[$name]
            ?? throw new RuntimeException("OAuth provider '$name' is not configured.");
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * All registered OAuth providers, in registration order.
     *
     * @return AuthProviderInterface[]
     */
    public function getAll(): array
    {
        return array_values($this->providers);
    }

    /* ── Password provider ───────────────────────────────────── */

    public function setPasswordProvider(PasswordProvider $provider): void
    {
        $this->passwordProvider = $provider;
    }

    public function getPasswordProvider(): ?PasswordProvider
    {
        return $this->passwordProvider;
    }

    public function hasPasswordProvider(): bool
    {
        return $this->passwordProvider !== null;
    }

    /* ── Magic-link provider ─────────────────────────────────── */

    public function setMagicLinkProvider(MagicLinkProvider $provider): void
    {
        $this->magicLinkProvider = $provider;
    }

    public function getMagicLinkProvider(): ?MagicLinkProvider
    {
        return $this->magicLinkProvider;
    }

    public function hasMagicLinkProvider(): bool
    {
        return $this->magicLinkProvider !== null;
    }

    /* ── TOTP provider ───────────────────────────────────────── */

    public function setTotpProvider(TotpProvider $provider): void
    {
        $this->totpProvider = $provider;
    }

    /**
     * Always returns a TotpProvider — TOTP needs no external credentials.
     * The issuer name is set from config (auth.totp.issuer) or defaults to 'App'.
     */
    public function getTotpProvider(): TotpProvider
    {
        return $this->totpProvider;
    }

    /* ── Factory ─────────────────────────────────────────────── */

    /**
     * Build an AuthManager from config sections.
     *
     * NEON example:
     *   auth:
     *     totp:
     *       issuer: 'My App'   # label shown in authenticator apps (default: 'App')
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
     * @param array<string,mixed> $config      Contents of Config::get('auth') ?? []
     * @param array<string,mixed> $mailConfig  Contents of Config::get('mail')  ?? []
     * @param string              $appUrl      Base URL used in magic-link generation
     */
    public static function fromConfig(
        array  $config,
        array  $mailConfig = [],
        string $appUrl = '',
    ): self {
        $manager = new self();

        /* TOTP — always available; configure the issuer name */
        $issuer = ! empty($config['totp']['issuer']) ? (string) $config['totp']['issuer'] : 'App';
        $manager->setTotpProvider(new TotpProvider($issuer));

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
