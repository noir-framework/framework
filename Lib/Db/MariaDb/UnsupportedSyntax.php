<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use RuntimeException;

/**
 * MariaDB SQL the SQLite rewriter will not guess at (docs/sqlite-mariadb.md says what it handles).
 *
 * @psalm-api
 */
final class UnsupportedSyntax extends RuntimeException
{
}
