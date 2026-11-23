<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

use Noirapi\Config;
use Noirapi\Lib\Mcp\OAuth\OAuthConfig;
use Noirapi\Lib\Mcp\OAuth\Tokens;
use Noirapi\Lib\RateLimit\RateLimit;
use Noirapi\Lib\Request;
use Noirapi\Lib\Response;

/**
 * The MCP endpoint over Streamable HTTP, stateless (one JSON-RPC message per POST, one JSON answer, no sessions, no
 * server-sent events), serving both protocol eras on one URL (the spec's dual-era server; Dispatcher has the
 * methods):
 *
 * - modern (MCP-Protocol-Version: 2026-07-28): server/discover, tools/list, tools/call;
 * - legacy (2025-11-25, 2025-06-18, 2025-03-26, or no header): initialize, ping, tools/list, tools/call.
 *
 * An initialize request is always legacy; any other version gets the unsupported-version error listing the supported
 * ones. Every request needs an OAuth access token for this resource and an account the project still allows; a
 * browser Origin other than the issuer's (or OAuthConfig::$allowedOrigins) gets 403; GET and DELETE get 405. Rate
 * limit `mcp` (per account) applies when the config defines it.
 */
final readonly class Server
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(private McpApplication $app, private OAuthConfig $oauth, private Tokens $tokens)
    {
    }

    public function handle(Request $request, Response $response): Response
    {
        if (! $request->isMethod('POST')) {
            return self::json($response, 405, ['error' => 'method_not_allowed', 'error_description' => 'POST only'])
                ->addHeader('Allow', 'POST');
        }
        if (! $this->originAllowed($request->header('Origin'))) {
            return self::json($response, 403, ['error' => 'forbidden', 'error_description' => 'Origin not allowed']);
        }
        $signedIn = $this->signIn($request, $response);
        if ($signedIn instanceof Response) {
            return $signedIn;
        }
        $id = null;
        try {
            $message = Message::parse($request->body());
            $id = $message->id;
            $answer = $this->answer($message, $request, $signedIn);
        } catch (McpError $error) {
            return self::json($response, $error->status, $error->answer($id));
        }

        return $answer === null ? $response->withStatus(202) : self::json($response, 200, $answer);
    }

    /**
     * The answer of the era the request belongs to.
     *
     * @return array<string, mixed>|null
     * @throws McpError
     */
    private function answer(Message $message, Request $request, Dispatcher $dispatcher): ?array
    {
        $version = $request->header(Protocol::HEADER_VERSION);
        if ($message->method === 'initialize' || $version === null || in_array($version, Protocol::LEGACY, true)) {
            return $dispatcher->legacy($message);
        }
        if ($version !== Protocol::MODERN) {
            throw McpError::unsupportedVersion($version);
        }

        return $dispatcher->modern(
            $message,
            $request->header(Protocol::HEADER_METHOD),
            $request->header(Protocol::HEADER_NAME),
        );
    }

    /**
     * The dispatcher for the token's account, or the answer that refuses the request (401, 429).
     */
    private function signIn(Request $request, Response $response): Dispatcher|Response
    {
        $authorization = $request->header('Authorization');
        $grant = $this->tokens->authenticate($authorization);
        $account = $grant === null ? null : $this->app->account($grant->subject);
        if ($grant === null || $account === null) {
            return $this->unauthorized($response, $authorization !== null && $authorization !== '');
        }

        return self::limited($response, 'mcp', 'subject:' . $grant->subject) ?? new Dispatcher($this->app, $account);
    }

    /**
     * No browser Origin, the issuer's own, or one the project allows.
     *
     * @psalm-mutation-free
     */
    private function originAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }
        $issuer = parse_url($this->oauth->issuer);
        $own = is_array($issuer) && isset($issuer['scheme'], $issuer['host'])
            ? $issuer['scheme'] . '://' . $issuer['host'] . (isset($issuer['port']) ? ':' . $issuer['port'] : '')
            : '';

        return in_array(rtrim($origin, '/'), [$own, ...$this->oauth->allowedOrigins], true);
    }

    /**
     * 401 with the protected resource metadata URL (RFC 9728 section 5.1): how an app starts the sign-in.
     */
    private function unauthorized(Response $response, bool $sentToken): Response
    {
        $challenge = 'Bearer resource_metadata="' . $this->oauth->resourceMetadataUrl() . '", scope="'
            . $this->oauth->scope() . '"' . ($sentToken ? ', error="invalid_token"' : '');

        return self::json($response, 401, ['error' => 'invalid_token', 'error_description' => 'Sign in first'])
            ->addHeader('WWW-Authenticate', $challenge);
    }

    /**
     * The 429 answer when rate limit $name (if the config defines it) refuses $key, else null.
     */
    public static function limited(Response $response, string $name, string $key): ?Response
    {
        /** @var mixed $limits */
        $limits = Config::get('rate_limits');
        if (! is_array($limits) || ! isset($limits[$name])) {
            return null;
        }
        $result = RateLimit::for($name)->hit($key);
        if ($result->allowed) {
            return null;
        }

        return self::json($response, 429, ['error' => 'slow_down', 'error_description' => 'Too many requests'])
            ->addHeader('Retry-After', (string) $result->retryAfter());
    }

    /**
     * A compact JSON answer that is never cached.
     *
     * @param array<array-key, mixed> $body
     */
    public static function json(Response $response, int $status, array $body): Response
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $response->withStatus($status)
            ->setContentType(Response::TYPE_JSON)
            ->setBody($json === false ? '{}' : $json)
            ->addHeader('Cache-Control', 'no-store');
    }
}
