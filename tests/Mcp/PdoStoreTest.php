<?php

declare(strict_types=1);

namespace Noirapi\Tests\Mcp;

use Noirapi\Config;
use Noirapi\Lib\Mcp\OAuth\PdoStore;
use Noirapi\Lib\Model;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The SQL Store on SQLite (the same statements as on MySQL): apps, codes, connections, rotation, revocation.
 */
#[CoversClass(PdoStore::class)]
final class PdoStoreTest extends TestCase
{
    private string $file = '';

    #[Override]
    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'mcp-store-');
        unlink($this->file);
        Config::set('db', ['sqlite' => ['dsn' => $this->file]]);
        Model::flushPdoCache();
        $pdo = new Model()->db->getConnection()->getPDO();
        $pdo->exec('CREATE TABLE oauth_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT NOT NULL UNIQUE,'
            . ' secret_hash TEXT, auth_method TEXT NOT NULL, name TEXT NOT NULL, redirect_uris TEXT NOT NULL,'
            . ' created_at TEXT NOT NULL, approved_at TEXT, fetched_at TEXT)');
        $pdo->exec('CREATE TABLE oauth_codes (code_hash TEXT PRIMARY KEY, client_id INTEGER NOT NULL, user_id TEXT NOT'
            . ' NULL, redirect_uri TEXT NOT NULL, challenge TEXT NOT NULL, scope TEXT NOT NULL, resource TEXT NOT NULL,'
            . ' expires_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE oauth_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL,'
            . ' user_id TEXT NOT NULL, scope TEXT NOT NULL, resource TEXT NOT NULL, access_hash TEXT NOT NULL UNIQUE,'
            . ' access_expires TEXT NOT NULL, refresh_hash TEXT NOT NULL UNIQUE, refresh_expires TEXT NOT NULL,'
            . ' created_at TEXT NOT NULL, used_at TEXT)');
    }

    #[Override]
    protected function tearDown(): void
    {
        Model::flushPdoCache();
        Config::set('db', null);
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testClientsCodesAndConnections(): void
    {
        $store = new PdoStore();
        $registered = $store->registerClient('App', ['https://a.example/cb'], 'client_secret_basic');
        $client = $store->client($registered['client_id']);
        self::assertNotNull($client);
        self::assertSame(['https://a.example/cb'], $client->redirectUris);
        self::assertTrue($client->secretMatches($registered['secret']));
        self::assertFalse($client->secretMatches('wrong'));
        self::assertNull($client->fetchedAt);

        $document = $store->saveDocumentClient('https://d.example/m.json', 'Doc', ['https://d.example/cb']);
        self::assertSame('none', $document->authMethod);
        self::assertNotNull($document->fetchedAt);
        self::assertSame($document->id, $store->saveDocumentClient('https://d.example/m.json', 'Doc 2', [])->id);
        self::assertSame('Doc 2', $store->client('https://d.example/m.json')?->name);

        $code = ['client' => $client->id, 'subject' => '42', 'redirect_uri' => 'https://a.example/cb',
            'challenge' => 'c', 'scope' => 's', 'resource' => 'https://r.test/mcp'];
        $store->addCode('h1', $code, 300);
        self::assertSame($code, $store->takeCode('h1'));
        self::assertNull($store->takeCode('h1'), 'single use');
        $store->addCode('h2', $code, -1);
        self::assertNull($store->takeCode('h2'), 'expired');

        $store->createGrant($client->id, '42', 's', 'https://r.test/mcp', 'a1', 'r1', 3600, 86_400);
        $grant = $store->grantByAccess('a1');
        self::assertSame(['42', 's', 'https://r.test/mcp'], [$grant?->subject, $grant?->scope, $grant?->resource]);
        self::assertSame($grant?->id, $store->grantByRefresh('r1')?->id);
        self::assertNotNull($grant);
        $store->touch($grant);
        self::assertTrue($store->rotate($grant->id, 'r1', 'a2', 'r2', 3600, 86_400));
        self::assertFalse($store->rotate($grant->id, 'r1', 'a3', 'r3', 3600, 86_400), 'the old refresh token lost');
        self::assertNull($store->grantByAccess('a1'));
        self::assertSame('App', $store->grants('42')[0]->clientName);

        $store->revoke('r2', $client->id + 99);
        self::assertNotNull($store->grantByAccess('a2'), 'only the app holding the token revokes it');
        $store->revoke('r2', $client->id);
        self::assertNull($store->grantByAccess('a2'));

        $store->createGrant($client->id, '42', 's', 'https://r.test/mcp', 'a4', 'r4', 3600, 86_400);
        $id = $store->grants('42')[0]->id;
        self::assertFalse($store->removeGrant($id, '7'));
        self::assertTrue($store->removeGrant($id, '42'));
        self::assertSame([], $store->grants('42'));
    }
}
