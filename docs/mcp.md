# MCP server

`Noirapi\Lib\Mcp` turns a project into a [Model Context Protocol](https://modelcontextprotocol.io) server that
Claude Code, Claude Desktop, claude.ai, the mobile apps and other MCP clients can connect to. The project supplies
its tools and says which account a token belongs to. The framework handles the rest: the protocol in every
supported revision, the OAuth 2.1 sign-in, errors and rate limits.

- **Transport:** Streamable HTTP, stateless. Each POST carries one JSON-RPC message and gets one JSON answer. There
  are no sessions and no server-sent events.
- **Revisions:** `2026-07-28` (modern) and `2025-11-25`, `2025-06-18`, `2025-03-26` (legacy), all on one URL.
  This is the spec's "dual-era" server.
- **Sign-in:** OAuth 2.1 with PKCE (S256 only), served by the framework:
  - metadata: RFC 9728 protected resource, RFC 8414 authorization server, with `iss` in the response (RFC 9207);
  - client registration: dynamic (RFC 7591) and Client ID Metadata Documents;
  - tokens: refresh tokens rotate on every use; revocation follows RFC 7009;
  - audience: every token is bound to the MCP URL (RFC 8707).
- **The project provides:** the tools, the account check and the consent page. It renders the consent page behind
  its own login.

## Set up

1. **Implement `McpApplication`:**

   ```php
   final class McpServer implements McpApplication
   {
       public function server(): ServerInfo
       {
           return new ServerInfo('my-app', 'My App', '1.0.0', 'Start with list_things.', sameToolsForEveryone: true);
       }

       public function oauth(Request $request): OAuthConfig
       {
           return new OAuthConfig(OAuthConfig::issuerFor($request), ['things'], 'My App', '/account/connect');
       }

       public function store(): Store
       {
           return $this->store ??= new PdoStore();          // tables from Lib/Mcp/OAuth/schema.mysql.sql
       }

       public function account(string $subject): ?object  // null: the token stops working at once
       {
           return new Users()->findActive((int) $subject);
       }

       public function tools(object $account, Grant $grant): ToolProvider
       {
           // $grant->scope: the scopes this connection was approved for, space-separated (also clientName, id).
           return new MyTools($account, scopes: explode(' ', $grant->scope));
       }
   }
   ```

2. **Register the routes** in `App\Route`: `Noirapi\Lib\Mcp\Routes::register($r, McpServer::class);`. This adds:
   - `POST /mcp` (GET and DELETE answer 405);
   - `/.well-known/oauth-protected-resource[/mcp]` and `/.well-known/oauth-authorization-server`;
   - `/oauth/register`, `/oauth/token` and `/oauth/revoke`.

   If `App\Route` uses FastRoute's `cachedDispatcher` (or `simpleDispatcher` with a `cacheFile`), the registration
   doesn't run once the cache exists. Then also name the class in the config, as
   `mcp: { application: App\Services\McpServer }`, or call `Mcp::use(McpServer::class)` in `App\Route`'s
   constructor.

   Two things a project's route tests must allow for:
   - `/mcp` is registered with an array of methods (`['POST', 'GET', 'DELETE']`), so a test that walks
     `addRoute()` must not cast `$httpMethod` to a string.
   - GET and DELETE on `/mcp` answer 405 before any sign-in check, so a "no credentials gives 401/403" test must
     accept 405 for them.

3. **Add the consent page** at `OAuthConfig::$authorizationPath`, behind the project's login. Its flow:
   1. Get the authorization server with `$server = Mcp::authorization($this->request)`.
   2. Call `$client = $server->client($data['client_id'] ?? null, $data['redirect_uri'] ?? null)`. On an
      `OAuthError`, show the error on the page; the redirect URI isn't trusted yet.
   3. Call `$request = $server->request($client, $data)`. On an `OAuthError`, send the browser to
      `$server->refusal(...)`.
   4. Show the page, with `$request->fields()` as hidden inputs of the approve/decline form.
   5. On approval, send the browser to `$server->approve($request, (string) $user->id)`. When the user declines,
      send it to `$server->refusal($request->redirectUri, $request->state, 'access_denied')`.

   To let the user grant less than the app asked for (a "read only" choice), pass the scope:
   `$server->approve($request, $subject, 'things:read')`. It may only narrow `$request->scope`: every scope must be
   one the app asked for, or one a requested scope includes through `OAuthConfig::$impliedScopes` (for example
   `['things' => ['things:read']]`); anything wider throws an `OAuthError` (`invalid_scope`).

4. **Create the tables** from `Lib/Mcp/OAuth/schema.mysql.sql`.

5. **Configure** (optional):
   - `public_url`: the issuer, when the request's own host isn't the public one (behind a proxy).
   - Rate limits `mcp` (per account), `oauth_register` and `oauth_token` (per client IP): each one applies when
     `rate_limits` defines it.

An account's "My apps" page can use `Mcp::application()->store()->grants($subject)` and `removeGrant($id, $subject)`.

## Tools

A `Tool` has a name, title and description, an input schema (an object schema, JSON Schema 2020-12) and the hints
`readOnlyHint`, `destructiveHint`, `idempotentHint` and `openWorldHint`. Return them in a fixed order.

`call()` answers a `ToolResult`:

- `ToolResult::json($data)`: structured content plus the same data as text;
- `ToolResult::text()`, `ToolResult::image()`;
- `ToolResult::error($reason)` for a failure the model can act on;
- throw `UnknownTool` for a name you don't have.

Any other exception becomes an `isError` result saying "internal error", and is logged.

## Protocol behaviour

| | 2026-07-28 (`MCP-Protocol-Version: 2026-07-28`) | 2025 revisions (or no header) |
| --- | --- | --- |
| Start | `server/discover`: supported versions, capabilities, instructions, `serverInfo` | `initialize`: version negotiation, `serverInfo`, instructions |
| Per request | `params._meta` with the protocol version (must match the header) and `clientCapabilities`; `Mcp-Method` header; `Mcp-Name` on `tools/call` | nothing extra |
| Results | `resultType: "complete"`, `_meta.io.modelcontextprotocol/serverInfo`; lists carry `ttlMs` and `cacheScope` | as in 2025 |
| Header or `_meta` problem | 400, JSON-RPC -32020 (header mismatch) or -32602 | not applicable |
| Unknown method | 404, -32601 | 200, -32601 |
| `ping` | removed (404) | `{}` |

On every request:

- **`initialize`** always selects the legacy era.
- **An unsupported `MCP-Protocol-Version`** gets 400 with -32022. Its `data.supported` lists the versions served,
  so a client can retry with one of them.
- **No valid token** gets 401 with `WWW-Authenticate: Bearer resource_metadata="..."` (how an app starts the
  sign-in).
- **A browser `Origin`** other than the issuer's own (or `OAuthConfig::$allowedOrigins`) gets 403. A request
  without an `Origin` passes: native apps and server-to-server calls send none.

## Security notes

- **Hashes only.** Client secrets, codes and tokens are stored as SHA-256 hashes. Codes are single use; refresh
  tokens rotate, and of two refreshes with one token only one wins.
- **Credentials.** Tokens are accepted in the Authorization header only, never in the URL.
- **Metadata documents** (a client_id that is an https URL) are fetched without redirects, within 5 s and 5 KB, and
  only from a public address. The address is resolved and pinned, so DNS rebinding can't reach inside. Only public
  clients may register this way. Documents are fetched again after a day.
- **Account check.** `account()` runs on every MCP request and before a token is issued or refreshed: disabling an
  account ends its access at once.

## Tests

`MemoryStore` is a `Store` in memory. `new Server($app, $app->oauth($request), Mcp::tokens($request))->handle($request,
new Response())` runs one request in-process (see `tests/Mcp/` in the framework).
