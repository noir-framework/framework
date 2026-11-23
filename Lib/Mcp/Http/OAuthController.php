<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp\Http;

use Noirapi\Config;
use Noirapi\Lib\Attributes\SkipCsrf;
use Noirapi\Lib\Controller;
use Noirapi\Lib\Mcp\Mcp;
use Noirapi\Lib\Mcp\OAuth\OAuthError;
use Noirapi\Lib\Mcp\Server;
use Noirapi\Lib\Response;
use Random\RandomException;
use Tracy\Debugger;

/**
 * The OAuth endpoints of the MCP server: the metadata an app discovers (RFC 9728 protected resource, RFC 8414
 * authorization server), dynamic registration (RFC 7591, JSON), the token endpoint and revocation (RFC 7009), both
 * form-encoded. Rate limits `oauth_register` and `oauth_token` (per client IP) apply when the config defines them.
 * The consent page is the project's own (Mcp::authorization()).
 *
 * @psalm-api the router calls it (Routes::register())
 */
#[SkipCsrf]
final class OAuthController extends Controller
{
    public function protectedResource(): Response
    {
        return $this->answer(200, Mcp::application()->oauth($this->request)->resourceMetadata());
    }

    public function authorizationServer(): Response
    {
        return $this->answer(200, Mcp::application()->oauth($this->request)->serverMetadata());
    }

    public function register(): Response
    {
        $limited = Server::limited($this->response, 'oauth_register', $this->request->clientIp);
        if ($limited !== null) {
            return $limited;
        }
        /** @var mixed $metadata the decoded JSON body */
        $metadata = json_decode($this->request->body(), true);

        try {
            if (! is_array($metadata)) {
                throw new OAuthError('invalid_client_metadata', 'The body must be a JSON object');
            }

            return $this->answer(201, Mcp::registration($this->request)->register($metadata));
        } catch (OAuthError $e) {
            return $this->failure($e);
        }
    }

    /**
     * @throws RandomException
     */
    public function token(): Response
    {
        $limited = Server::limited($this->response, 'oauth_token', $this->request->clientIp);
        if ($limited !== null) {
            return $limited;
        }

        try {
            $tokens = Mcp::tokens($this->request)->token($this->request->post, $this->request->header('Authorization'));

            return $this->answer(200, $tokens)->addHeader('Pragma', 'no-cache');
        } catch (OAuthError $e) {
            return $this->failure($e);
        }
    }

    public function revoke(): Response
    {
        $limited = Server::limited($this->response, 'oauth_token', $this->request->clientIp);
        if ($limited !== null) {
            return $limited;
        }

        try {
            Mcp::tokens($this->request)->revoke($this->request->post, $this->request->header('Authorization'));

            return $this->answer(200, []);
        } catch (OAuthError $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function answer(int $status, array $body): Response
    {
        Debugger::enable(Debugger::Production, Config::getLogs());

        return Server::json($this->response, $status, $body === [] ? ['ok' => true] : $body);
    }

    /** An OAuth error answer (RFC 6749 section 5.2). */
    private function failure(OAuthError $error): Response
    {
        $response = $this->answer($error->status, [
            'error'             => $error->error,
            'error_description' => $error->getMessage(),
        ]);

        return $error->status === 401 ? $response->addHeader('WWW-Authenticate', 'Basic realm="oauth"') : $response;
    }
}
