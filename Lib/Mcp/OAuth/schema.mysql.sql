-- The OAuth 2.1 authorization server of a noirapi MCP endpoint (Noirapi\Lib\Mcp\OAuth\PdoStore, prefix oauth_).
-- Only SHA-256 hashes of client secrets, codes and tokens are stored.
--
-- oauth_clients: the apps. Dynamic registration (RFC 7591): a random 32-hex client_id, a secret unless the app is a
-- public client; deleted a day after registration when no account approved it. Client ID Metadata Documents: the
-- client_id is the document's https URL, fetched_at the last fetch (public clients, kept).
-- oauth_codes: authorization codes, single use, short-lived.
-- oauth_grants: one row per connection (an account's approval of an app): the current access and refresh token.
-- Deleting the row ends the connection. user_id holds the account's subject as the project knows it.
-- data (codes and grants): what the consent page attached to the connection (Authorization::approve()'s $data, JSON,
-- at most 4 KB); null when none. It travels with the code into the grant and never reaches the app.
--
-- Re-runnable. A project that created the tables with a 32-character client_id (an older schema) widens it
-- with the two ALTER statements at the end.

CREATE TABLE IF NOT EXISTS `oauth_clients` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` varchar(255) NOT NULL COMMENT 'random 32-hex, or a metadata document URL',
    `secret_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 of the secret; null for a public client',
    `auth_method` enum('none', 'client_secret_post', 'client_secret_basic') NOT NULL DEFAULT 'none',
    `name` varchar(100) NOT NULL,
    `redirect_uris` text NOT NULL COMMENT 'JSON list',
    `created_at` datetime NOT NULL COMMENT 'UTC',
    `approved_at` datetime DEFAULT NULL COMMENT 'UTC, the first approval',
    `fetched_at` datetime DEFAULT NULL COMMENT 'UTC, the last metadata document fetch',
    PRIMARY KEY (`id`),
    UNIQUE KEY `client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_codes` (
    `code_hash` char(64) NOT NULL,
    `client_id` bigint UNSIGNED NOT NULL COMMENT 'oauth_clients.id',
    `user_id` varchar(255) NOT NULL COMMENT 'the account subject',
    `redirect_uri` varchar(500) NOT NULL,
    `challenge` varchar(128) NOT NULL COMMENT 'PKCE S256',
    `scope` varchar(255) NOT NULL,
    `resource` varchar(255) NOT NULL,
    `data` text DEFAULT NULL COMMENT 'JSON, the consent page data',
    `expires_at` datetime NOT NULL COMMENT 'UTC',
    PRIMARY KEY (`code_hash`),
    KEY `expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_grants` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` bigint UNSIGNED NOT NULL COMMENT 'oauth_clients.id',
    `user_id` varchar(255) NOT NULL COMMENT 'the account subject',
    `scope` varchar(255) NOT NULL,
    `resource` varchar(255) NOT NULL,
    `access_hash` char(64) NOT NULL,
    `access_expires` datetime NOT NULL COMMENT 'UTC',
    `refresh_hash` char(64) NOT NULL,
    `refresh_expires` datetime NOT NULL COMMENT 'UTC',
    `created_at` datetime NOT NULL COMMENT 'UTC',
    `used_at` datetime DEFAULT NULL COMMENT 'UTC, the last MCP call',
    `data` text DEFAULT NULL COMMENT 'JSON, the consent page data',
    PRIMARY KEY (`id`),
    UNIQUE KEY `access` (`access_hash`),
    UNIQUE KEY `refresh` (`refresh_hash`),
    KEY `user` (`user_id`),
    KEY `client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tables created before Client ID Metadata Document support (a char(32) client_id, no fetched_at):
ALTER TABLE `oauth_clients` MODIFY `client_id` varchar(255) NOT NULL;
ALTER TABLE `oauth_clients` ADD COLUMN IF NOT EXISTS `fetched_at` datetime DEFAULT NULL AFTER `approved_at`;
-- Tables created before 2.5 (no data column):
ALTER TABLE `oauth_codes` ADD COLUMN IF NOT EXISTS `data` text DEFAULT NULL AFTER `resource`;
ALTER TABLE `oauth_grants` ADD COLUMN IF NOT EXISTS `data` text DEFAULT NULL AFTER `used_at`;
