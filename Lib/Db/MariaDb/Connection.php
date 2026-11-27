<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use Noirapi\Database\Connection as DatabaseConnection;
use Noirapi\Database\Schema\Compiler as SchemaCompiler;
use Noirapi\Database\Schema\Compiler\MySQL as MySQLSchemaCompiler;
use Noirapi\Database\SQL\Compiler as SqlCompiler;
use Noirapi\Database\SQL\Compiler\MySQL as MySQLCompiler;
use Override;

/**
 * A SQLite connection in the MariaDB dialect, for noirapi/database: the query builder writes MySQL SQL (backtick
 * names, DATE_ADD … INTERVAL, CONCAT), like the app's own SQL, and the connection's rewriter turns both into SQLite
 * (docs/sqlite-mariadb.md). The driver stays 'sqlite', so what depends on it (stream(), transactions) is SQLite's.
 *
 * @psalm-api
 */
class Connection extends DatabaseConnection
{
    /**
     * @psalm-impure
     */
    #[Override]
    public function getCompiler(): SqlCompiler
    {
        if ($this->compiler === null) {
            $this->compiler = new MySQLCompiler();
            $this->compiler->setOptions($this->compilerOptions);
        }

        return $this->compiler;
    }

    /**
     * @psalm-impure
     */
    #[Override]
    public function schemaCompiler(): SchemaCompiler
    {
        if ($this->schemaCompiler === null) {
            $this->schemaCompiler = new MySQLSchemaCompiler($this);
            $this->schemaCompiler->setOptions($this->schemaCompilerOptions);
        }

        return $this->schemaCompiler;
    }
}
