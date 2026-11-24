<?php

declare(strict_types=1);

namespace Noirapi\Tests\Mcp;

use InvalidArgumentException;
use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Mcp\Mcp;
use Noirapi\Lib\Mcp\OAuth\Authorization;
use Noirapi\Lib\Mcp\OAuth\Grant;
use Noirapi\Lib\Request;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a consent page attaches to a connection (Authorization::approve()'s $data): it reaches Grant::$data through
 * the code, survives refresh-token rotation, is listed with the grant, and never reaches the app.
 */
#[CoversClass(Authorization::class)]
#[CoversClass(Grant::class)]
final class ConnectionDataTest extends TestCase
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

    public function testConsentDataTravelsWithTheConnection(): void
    {
        [$client, $redirect] = $this->registered();
        $authorization = Mcp::authorization($this->request);
        $known = $authorization->client($client, $redirect);
        $request = $authorization->request($known, $this->authorize($client, $redirect));
        $tokens = Mcp::tokens($this->request);

        $back = $authorization->approve($request, '1', null, ['companies' => [3, 7]]);
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
        $pair = $tokens->token(['grant_type' => 'authorization_code', 'code' => $query['code'], 'client_id' => $client,
            'code_verifier' => self::VERIFIER], null);
        self::assertArrayNotHasKey('companies', $pair, 'the data never reaches the app');
        self::assertSame(['companies' => [3, 7]], $tokens->authenticate('Bearer ' . $pair['access_token'])?->data);

        $next = $tokens->token(['grant_type' => 'refresh_token', 'refresh_token' => $pair['refresh_token'],
            'client_id' => $client], null);
        self::assertSame(['companies' => [3, 7]], $tokens->authenticate('Bearer ' . $next['access_token'])?->data);
        self::assertSame(['companies' => [3, 7]], $this->app->memory->grants('1')[0]->data, 'listed with the grant');

        // Another connection of the same app and account carries its own data (or none).
        $other = $authorization->approve($request, '1');
        parse_str((string) parse_url($other, PHP_URL_QUERY), $query);
        $plain = $tokens->token(['grant_type' => 'authorization_code', 'code' => $query['code'], 'client_id' => $client,
            'code_verifier' => self::VERIFIER], null);
        self::assertSame([], $tokens->authenticate('Bearer ' . $plain['access_token'])?->data);

        foreach ([['x' => str_repeat('a', Authorization::MAX_DATA)], ['x' => NAN]] as $bad) {
            try {
                $authorization->approve($request, '1', null, $bad);
                self::fail('accepted data that is too large or not JSON');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
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
