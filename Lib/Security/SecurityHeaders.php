<?php

declare(strict_types=1);

namespace Noirapi\Lib\Security;

use Noirapi\Config;
use Noirapi\Lib\Response;
use Random\RandomException;

use function is_array;
use function is_string;

/**
 * Security response headers, opt-in with a `security_headers` block (`security_headers: true`
 * gives the defaults). Each header can be overridden, or dropped with `false`:
 *
 *     security_headers:
 *         content_type_options: nosniff
 *         frame_options: SAMEORIGIN                 # DENY | SAMEORIGIN
 *         referrer_policy: strict-origin-when-cross-origin
 *         permissions_policy: 'camera=(), microphone=(), geolocation=()'
 *         cross_origin_opener_policy: same-origin
 *         hsts: 'max-age=31536000; includeSubDomains'   # HTTPS responses only
 *         csp: default                              # or a directive map, see below
 *         csp_report_only: false                    # send Content-Security-Policy-Report-Only
 *         headers: {X-Robots-Tag: noindex}          # any extra headers
 *
 * The CSP is only sent when `csp` is set. `csp: default` is DEFAULT_CSP; a map lists directives
 * with their sources, where `nonce` stands for this request's nonce:
 *
 *         csp:
 *             default-src: [self]
 *             script-src: [self, nonce, https://cdn.example.com]
 *             img-src: [self, 'data:']
 *
 * `self`, `none`, `unsafe-inline`, `unsafe-eval`, `strict-dynamic` and the other CSP keywords may
 * be written without their quotes. Views get the nonce as $nonce (the {nonce} tag prints the
 * attribute), and headers the app set itself are never overwritten.
 *
 * @psalm-api
 */
final class SecurityHeaders
{
    public const array DEFAULTS = [
        'content_type_options'       => 'nosniff',
        'frame_options'              => 'SAMEORIGIN',
        'referrer_policy'            => 'strict-origin-when-cross-origin',
        'permissions_policy'         => 'camera=(), microphone=(), geolocation=(), payment=()',
        'cross_origin_opener_policy' => 'same-origin',
        'hsts'                       => 'max-age=31536000; includeSubDomains',
    ];

    public const array DEFAULT_CSP = [
        'default-src'     => ['self'],
        'script-src'      => ['self', 'nonce'],
        'style-src'       => ['self', 'nonce'],
        'img-src'         => ['self', 'data:'],
        'font-src'        => ['self', 'data:'],
        'connect-src'     => ['self'],
        'object-src'      => ['none'],
        'base-uri'        => ['self'],
        'form-action'     => ['self'],
        'frame-ancestors' => ['self'],
    ];

    private const array HEADER_NAMES = [
        'content_type_options'       => 'X-Content-Type-Options',
        'frame_options'              => 'X-Frame-Options',
        'referrer_policy'            => 'Referrer-Policy',
        'permissions_policy'         => 'Permissions-Policy',
        'cross_origin_opener_policy' => 'Cross-Origin-Opener-Policy',
    ];

    private const array KEYWORDS = [
        'self', 'none', 'unsafe-inline', 'unsafe-eval', 'unsafe-hashes', 'strict-dynamic',
        'report-sample', 'wasm-unsafe-eval', 'inline-speculation-rules',
    ];

    private static ?string $nonce = null;

    /**
     * @psalm-external-mutation-free
     */
    public static function enabled(): bool
    {
        $config = Config::get('security_headers');

        return $config === true || is_array($config);
    }

    /**
     * This request's CSP nonce (base64, 128 bits), created on first use.
     *
     * @throws RandomException
     */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Forgets the nonce. Route calls it at the start of every request (Swoole workers serve many).
     *
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$nonce = null;
    }

    /**
     * Whether the configured CSP uses the nonce, i.e. whether views need $nonce.
     *
     * @psalm-external-mutation-free
     */
    public static function usesNonce(): bool
    {
        return in_array('nonce', array_merge(...array_values(self::cspDirectives())), true);
    }

    /**
     * The headers for one response.
     *
     * @return array<string, string>
     * @throws RandomException
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") the request's scheme, not a behaviour switch.
     */
    public static function headers(bool $https): array
    {
        $config = self::config();
        $headers = [];

        foreach (self::HEADER_NAMES as $key => $name) {
            if (is_string($config[$key])) {
                $headers[$name] = $config[$key];
            }
        }

        if ($https && is_string($config['hsts'])) {
            $headers['Strict-Transport-Security'] = $config['hsts'];
        }

        $csp = self::csp();
        if ($csp !== '') {
            $name = ($config['csp_report_only'] ?? false) === true
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $headers[$name] = $csp;
        }

        foreach (is_array($config['headers'] ?? null) ? $config['headers'] : [] as $name => $value) {
            if (is_string($value)) {
                $headers[(string) $name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Adds the headers to $response, keeping any the app already set.
     *
     * @throws RandomException
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") the request's scheme, not a behaviour switch.
     */
    public static function apply(Response $response, bool $https): void
    {
        $existing = array_change_key_case($response->getHeaders());

        foreach (self::headers($https) as $name => $value) {
            if (! isset($existing[strtolower($name)])) {
                $response->addHeader($name, $value);
            }
        }
    }

    /**
     * The Content-Security-Policy value, '' when no `csp` is configured.
     *
     * @throws RandomException
     */
    public static function csp(): string
    {
        $parts = [];

        foreach (self::cspDirectives() as $directive => $sources) {
            $sources = array_map(self::source(...), $sources);
            $parts[] = trim($directive . ' ' . implode(' ', $sources));
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, list<string>>
     *
     * @psalm-external-mutation-free
     */
    private static function cspDirectives(): array
    {
        $csp = self::config()['csp'] ?? null;
        if ($csp === 'default' || $csp === true) {
            $csp = self::DEFAULT_CSP;
        }

        $directives = [];
        foreach (is_array($csp) ? $csp : [] as $directive => $sources) {
            $sources = is_array($sources) ? $sources : [$sources];
            $directives[(string) $directive] = array_values(array_map(strval(...), array_filter($sources, is_scalar(...)))); //phpcs:ignore
        }

        return $directives;
    }

    /**
     * @throws RandomException
     */
    private static function source(string $source): string
    {
        $bare = strtolower(trim($source, "'"));

        return match (true) {
            $bare === 'nonce' => "'nonce-" . self::nonce() . "'",
            in_array($bare, self::KEYWORDS, true) => "'" . $bare . "'",
            default => $source,
        };
    }

    /**
     * @return array<string, mixed>
     *
     * @psalm-external-mutation-free
     */
    private static function config(): array
    {
        $config = Config::get('security_headers');

        return array_replace(self::DEFAULTS, is_array($config) ? $config : []);
    }
}
