<?php

declare(strict_types=1);

namespace Noirapi\Tests\Mcp;

use Noirapi\Config;
use Noirapi\Lib\Http\PublicAddress;
use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Mcp\Mcp;
use Noirapi\Lib\Mcp\OAuth\Authorization;
use Noirapi\Lib\Mcp\OAuth\Client;
use Noirapi\Lib\Mcp\OAuth\ClientDocuments;
use Noirapi\Lib\Mcp\OAuth\MemoryStore;
use Noirapi\Lib\Mcp\OAuth\OAuthConfig;
use Noirapi\Lib\Mcp\OAuth\OAuthError;
use Noirapi\Lib\Mcp\OAuth\Registration;
use Noirapi\Lib\Mcp\OAuth\Tokens;
use Noirapi\Lib\Request;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The MCP server's OAuth 2.1 authorization server: metadata, dynamic registration, client metadata documents, the
 * consent flow with PKCE, the token endpoint (codes, rotating refresh tokens), revocation and the bearer check.
 */
#[CoversClass(OAuthConfig::class)]
#[CoversClass(Registration::class)]
#[CoversClass(Authorization::class)]
#[CoversClass(Tokens::class)]
#[CoversClass(ClientDocuments::class)]
#[CoversClass(Client::class)]
#[CoversClass(MemoryStore::class)]
#[CoversClass(PublicAddress::class)]
final class OAuthTest extends TestCase
{
    private const string VERIFIER = 'a-verifier-of-at-least-forty-three-characters-0123456789';

    private TestApplication $app;
    private Request $request;

    #[Override]
    protected function setUp(): void
    {
        $this->app = new TestApplication();
        Mcp::use($this->app);
        $this->request = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'app.test',
            'REMOTE_ADDR' => '10.0.0.1'], [], [], [], [], new TrustedProxies());
    }

    public function testMetadata(): void
    {
        $config = $this->app->oauth($this->request);
        $server = $config->serverMetadata();

        self::assertSame('https://app.test', $server['issuer']);
        self::assertSame('https://app.test/connect', $server['authorization_endpoint']);
        self::assertSame(['S256'], $server['code_challenge_methods_supported']);
        self::assertTrue($server['authorization_response_iss_parameter_supported']);
        self::assertTrue($server['client_id_metadata_document_supported']);
        self::assertSame(['tools', 'extra'], $server['scopes_supported']);

        $resource = $config->resourceMetadata();
        self::assertSame('https://app.test/mcp', $resource['resource']);
        self::assertSame(['https://app.test'], $resource['authorization_servers']);
        self::assertSame('https://app.test/docs', $resource['resource_documentation']);
    }

    public function testRegistration(): void
    {
        $registration = Mcp::registration($this->request);
        $public = $registration->register([
            'client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'],
            'application_type' => 'web',
        ]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $public['client_id']);
        self::assertArrayNotHasKey('client_secret', $public);
        self::assertSame('tools extra', $public['scope']);

        $confidential = $registration->register(['redirect_uris' => ['http://127.0.0.1:3118/cb']]);
        self::assertSame('client_secret_basic', $confidential['token_endpoint_auth_method']);
        self::assertSame('Unnamed app', $confidential['client_name']);
        self::assertIsString($confidential['client_secret']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badRegistrations(): iterable
    {
        yield 'no redirect' => [[], 'invalid_redirect_uri'];
        yield 'http off loopback' => [['redirect_uris' => ['http://evil.example/cb']], 'invalid_redirect_uri'];
        yield 'a fragment' => [['redirect_uris' => ['https://a.example/cb#x']], 'invalid_redirect_uri'];
        yield 'six URIs' => [['redirect_uris' => array_fill(0, 6, 'https://a.example/cb')], 'invalid_redirect_uri'];
        yield 'an unknown auth method' => [
            ['redirect_uris' => ['https://a.example/cb'], 'token_endpoint_auth_method' => 'private_key_jwt'],
            'invalid_client_metadata',
        ];
        yield 'no code grant' => [
            ['redirect_uris' => ['https://a.example/cb'], 'grant_types' => ['client_credentials']],
            'invalid_client_metadata',
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('badRegistrations')]
    public function testBadRegistrationsAreRefused(array $metadata, string $error): void
    {
        try {
            Mcp::registration($this->request)->register($metadata);
            self::fail('accepted');
        } catch (OAuthError $e) {
            self::assertSame($error, $e->error);
        }
    }

    public function testTheWholeFlowWithPkce(): void
    {
        [$client, $redirect] = $this->registered();
        $authorization = Mcp::authorization($this->request);
        $known = $authorization->client($client, $redirect);
        $request = $authorization->request($known, $this->authorize($client, $redirect) + ['scope' => 'tools other']);
        self::assertSame('tools', $request->scope, 'an unknown scope is left out');
        self::assertSame('https://app.test/mcp', $request->resource);

        $back = $authorization->approve($request, '1');
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
        self::assertStringStartsWith($redirect . '?', $back);
        self::assertSame('xyz', $query['state']);
        self::assertSame('https://app.test', $query['iss']);

        $tokens = Mcp::tokens($this->request);
        $pair = $tokens->token(['grant_type' => 'authorization_code', 'code' => $query['code'], 'client_id' => $client,
            'code_verifier' => self::VERIFIER, 'redirect_uri' => $redirect, 'resource' => 'https://app.test/mcp'], null);
        self::assertSame('Bearer', $pair['token_type']);
        self::assertSame(3600, $pair['expires_in']);
        self::assertSame('tools', $pair['scope']);
        self::assertSame('1', $tokens->authenticate('Bearer ' . $pair['access_token'])?->subject);

        try {
            $tokens->token(['grant_type' => 'authorization_code', 'code' => $query['code'], 'client_id' => $client,
                'code_verifier' => self::VERIFIER], null);
            self::fail('a code worked twice');
        } catch (OAuthError $e) {
            self::assertSame('invalid_grant', $e->error);
        }

        $next = $tokens->token(['grant_type' => 'refresh_token', 'refresh_token' => $pair['refresh_token'],
            'client_id' => $client], null);
        self::assertNull($tokens->authenticate('Bearer ' . $pair['access_token']), 'the old access token is replaced');
        self::assertNotNull($tokens->authenticate('Bearer ' . $next['access_token']));
        try {
            $tokens->token(['grant_type' => 'refresh_token', 'refresh_token' => $pair['refresh_token'],
                'client_id' => $client], null);
            self::fail('a refresh token worked twice');
        } catch (OAuthError $e) {
            self::assertSame('invalid_grant', $e->error);
        }

        $tokens->revoke(['token' => $next['refresh_token'], 'client_id' => $client], null);
        self::assertNull($tokens->authenticate('Bearer ' . $next['access_token']));
    }

    public function testNoTokensWithoutAValidCode(): void
    {
        [$client, $redirect] = $this->registered();
        $authorization = Mcp::authorization($this->request);
        $tokens = Mcp::tokens($this->request);
        $code = function (string $subject) use ($authorization, $client, $redirect): string {
            $request = $authorization->request($authorization->client($client, $redirect), $this->authorize($client, $redirect));
            parse_str((string) parse_url($authorization->approve($request, $subject), PHP_URL_QUERY), $query);

            return (string) $query['code'];
        };
        $cases = [
            'invalid_grant'  => ['code' => $code('1'), 'code_verifier' => str_repeat('b', 43)],
            'invalid_target' => ['code' => $code('1'), 'code_verifier' => self::VERIFIER, 'resource' => 'https://x.test/mcp'],
            'invalid_grant ' => ['code' => $code('2'), 'code_verifier' => self::VERIFIER],
        ];
        foreach ($cases as $error => $fields) {
            try {
                $tokens->token($fields + ['grant_type' => 'authorization_code', 'client_id' => $client], null);
                self::fail('tokens for ' . $error);
            } catch (OAuthError $e) {
                self::assertSame(trim($error), $e->error);
            }
        }
    }

    public function testAClientSecretByPostOrBasic(): void
    {
        $registered = Mcp::registration($this->request)->register(['redirect_uris' => ['https://a.example/cb']]);
        $tokens = Mcp::tokens($this->request);
        $id = (string) $registered['client_id'];
        $secret = (string) $registered['client_secret'];

        foreach ([['client_id' => $id], ['client_id' => $id, 'client_secret' => 'wrong']] as $fields) {
            try {
                $tokens->revoke($fields + ['token' => 'x'], null);
                self::fail('no secret accepted');
            } catch (OAuthError $e) {
                self::assertSame(['invalid_client', 401], [$e->error, $e->status]);
            }
        }
        $tokens->revoke(['token' => 'x', 'client_id' => $id, 'client_secret' => $secret], null);
        $tokens->revoke(['token' => 'x'], 'Basic ' . base64_encode($id . ':' . $secret));
        self::addToAssertionCount(2);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function badAuthorizations(): iterable
    {
        yield 'no PKCE' => [['code_challenge' => ''], 'invalid_request'];
        yield 'plain PKCE' => [['code_challenge_method' => 'plain'], 'invalid_request'];
        yield 'a token response' => [['response_type' => 'token'], 'unsupported_response_type'];
        yield 'another resource' => [['resource' => 'https://other.test/mcp'], 'invalid_target'];
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('badAuthorizations')]
    public function testBadAuthorizationRequests(array $override, string $error): void
    {
        [$client, $redirect] = $this->registered();
        $authorization = Mcp::authorization($this->request);
        try {
            $authorization->request($authorization->client($client, $redirect), $override + $this->authorize($client, $redirect));
            self::fail('accepted');
        } catch (OAuthError $e) {
            self::assertSame($error, $e->error);
        }
    }

    public function testTheAppAndRedirectComeFirst(): void
    {
        [$client] = $this->registered('http://127.0.0.1/callback');
        $authorization = Mcp::authorization($this->request);

        self::assertSame($client, $authorization->client($client, 'http://127.0.0.1:53682/callback')->clientId);
        foreach (
            [[str_repeat('0', 32), 'http://127.0.0.1/callback'], [$client, 'https://evil.example/cb'],
            [$client, null], ['not-an-id', 'x']] as [$id, $uri]
        ) {
            try {
                $authorization->client($id, $uri);
                self::fail('accepted ' . json_encode([$id, $uri]));
            } catch (OAuthError $e) {
                self::assertContains($e->error, ['invalid_client', 'invalid_request']);
            }
        }
    }

    public function testClientMetadataDocuments(): void
    {
        $url = 'https://client.example/oauth/metadata.json';
        $documents = static fn (array $document): ClientDocuments => new ClientDocuments(
            new MemoryStore(),
            static fn (string $fetched): ?string => $fetched === $url ? (string) json_encode($document) : null,
        );
        $valid = ['client_id' => $url, 'client_name' => 'Doc app', 'redirect_uris' => ['https://client.example/cb']];

        $client = $documents($valid)->client($url);
        self::assertSame(['Doc app', ['https://client.example/cb'], 'none'], [$client->name, $client->redirectUris,
            $client->authMethod]);
        self::assertTrue($client->isDocument());

        foreach (
            [
            ['client_id' => 'https://other.example/m.json'] + $valid,
            ['token_endpoint_auth_method' => 'client_secret_basic'] + $valid,
            ['redirect_uris' => ['http://evil.example/cb']] + $valid,
            ['redirect_uris' => []] + $valid,
            ] as $bad
        ) {
            try {
                $documents($bad)->client($url);
                self::fail('accepted ' . json_encode($bad));
            } catch (OAuthError $e) {
                self::assertSame('invalid_client', $e->error);
            }
        }

        foreach (
            ['https://client.example/m.json' => true, 'https://client.example/' => false,
            'http://client.example/m.json' => false, 'https://client.example:8443/m.json' => false,
            'https://u:p@client.example/m.json' => false, 'https://client.example/m.json#x' => false] as $id => $is
        ) {
            self::assertSame($is, ClientDocuments::isDocumentUrl($id), $id);
        }
    }

    public function testAConsentPageMayOnlyNarrowTheScope(): void
    {
        $config = new OAuthConfig(
            'https://app.test',
            ['statements', 'statements:read', 'extra'],
            'Test',
            '/connect',
            impliedScopes: ['statements' => ['statements:read']]
        );
        self::assertTrue($config->narrows('statements statements:read', 'statements:read'));
        self::assertTrue($config->narrows('statements', 'statements:read'), 'included in a requested scope');
        self::assertTrue($config->narrows('statements extra', 'extra'));
        self::assertFalse($config->narrows('statements:read', 'statements'), 'wider than asked');
        self::assertFalse($config->narrows('statements', 'extra'), 'not asked');
        self::assertFalse($config->narrows('statements', 'statements:write'), 'not a scope of this server');
        self::assertFalse($config->narrows('statements', ''), 'nothing');

        [$client, $redirect] = $this->registered();
        $authorization = Mcp::authorization($this->request);
        $request = $authorization->request($authorization->client($client, $redirect), ['scope' => 'tools extra']
            + $this->authorize($client, $redirect));
        self::assertSame('tools extra', $request->scope);

        $tokens = Mcp::tokens($this->request);
        $narrow = $authorization->approve($request, '1', 'extra');
        parse_str((string) parse_url($narrow, PHP_URL_QUERY), $query);
        $pair = $tokens->token(['grant_type' => 'authorization_code', 'code' => $query['code'], 'client_id' => $client,
            'code_verifier' => self::VERIFIER], null);
        self::assertSame('extra', $pair['scope']);
        self::assertSame('extra', $tokens->authenticate('Bearer ' . $pair['access_token'])?->scope);

        $plain = $authorization->request($authorization->client($client, $redirect), ['scope' => 'extra']
            + $this->authorize($client, $redirect));
        try {
            $authorization->approve($plain, '1', 'tools extra');
            self::fail('a consent page widened the scope');
        } catch (OAuthError $e) {
            self::assertSame('invalid_scope', $e->error);
        }
    }

    public function testTheConfigCanNameTheApplication(): void
    {
        $registry = new ReflectionProperty(Mcp::class, 'application');
        $registry->setValue(null, null);
        Config::set('mcp', ['application' => TestApplication::class]);
        try {
            self::assertInstanceOf(TestApplication::class, Mcp::application());
        } finally {
            Config::set('mcp', null);
        }
    }

    public function testPublicAddresses(): void
    {
        foreach (
            ['127.0.0.1', '10.1.1.1', '169.254.169.254', '100.64.0.1', '::1', '::ffff:10.0.0.1', 'fd00::1',
            'fe80::1', '64:ff9b::a9fe:a9fe', 'not-an-ip'] as $ip
        ) {
            self::assertFalse(PublicAddress::isPublic($ip), $ip);
        }
        foreach (['93.184.215.14', '1.1.1.1', '2606:4700::6810:84e5', '64:ff9b::808:808'] as $ip) {
            self::assertTrue(PublicAddress::isPublic($ip), $ip);
        }
        self::assertSame(['10.0.0.1'], PublicAddress::resolve('10.0.0.1'));
    }

    /**
     * A registered public app: [client_id, redirect URI].
     *
     * @return array{string, string}
     */
    private function registered(string $redirect = 'https://client.example/cb'): array
    {
        $client = Mcp::registration($this->request)->register([
            'redirect_uris' => [$redirect], 'token_endpoint_auth_method' => 'none',
        ]);

        return [(string) $client['client_id'], $redirect];
    }

    /**
     * @return array<string, string>
     */
    private function authorize(string $client, string $redirect): array
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');

        return [
            'response_type' => 'code', 'client_id' => $client, 'redirect_uri' => $redirect,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz',
        ];
    }
}
