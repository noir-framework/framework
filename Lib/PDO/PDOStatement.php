<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

use Noirapi\Lib\Db\MariaDb\DecimalRows;
use Override;
use PDOStatement as NativePdoStatement;
use RuntimeException;

use function is_array;
use function is_string;

/**
 * @psalm-suppress MissingTemplateParam
 * @psalm-api
 */
class PDOStatement extends NativePdoStatement
{
    /**
     * The connection that logs this statement's queries.
     */
    protected \PDO&LoggedPdo $pdo;

    /**
     * For binding simulations purposes.
     */
    protected array $bindings = [];

    /** setFetchMode()'s mode and arguments */
    private ?int $fetchMode = null;
    /** @var list<mixed> */
    private array $fetchArgs = [];
    /** @var array<int|string, int>|null this result's DECIMAL columns (MariaDB dialect only) */
    private ?array $decimals = null;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @psalm-mutation-free
     */
    protected function __construct(\PDO&LoggedPdo $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param int|string $param
     * @param mixed $var
     * @param int $type
     * @param int|null $maxLength
     * @param mixed $driverOptions
     * @return bool
     * @psalm-suppress PossiblyNullArgument
     */
    #[Override]
    public function bindParam(int|string $param, mixed &$var, int $type = \PDO::PARAM_STR, ?int $maxLength = null, mixed $driverOptions = null): bool //phpcs:ignore
    {
        $this->bindings[$param] = $var;

        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    /**
     * @param int|string $param
     * @param mixed $value
     * @param int $type
     * @return bool
     */
    #[Override]
    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }

    /**
     * @param array|null $params
     * @return bool
     */
    #[Override]
    public function execute(?array $params = null): bool
    {

        if (is_array($params)) {
            $this->bindings = $params;
        }

        $start = microtime(true);
        $result = parent::execute($params);

        $this->pdo->addLog(
            $this->produceStatementWithBindingsInForLogging($this->bindings, $this->queryString),
            microtime(true) - $start
        );

        return $result;
    }

    /**
     * @psalm-impure
     */
    #[Override]
    public function setFetchMode(int $mode, mixed ...$args): true
    {
        $this->fetchMode = $mode;
        $this->fetchArgs = array_values($args);
        parent::setFetchMode($mode, ...$args);

        return true;
    }

    /**
     * @psalm-impure
     */
    #[Override]
    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $decimals = $this->decimals();
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if ($decimals === [] || $row === false) {
            return $row;
        }

        return $this->effectiveMode($mode) === \PDO::FETCH_COLUMN
            ? DecimalRows::all([$row], \PDO::FETCH_COLUMN, [0], $decimals)[0]
            : DecimalRows::row($row, $decimals);
    }

    /**
     * @return array<array-key, mixed>
     * @psalm-impure
     */
    #[Override]
    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $decimals = $this->decimals();
        if ($decimals === []) {
            return parent::fetchAll($mode, ...$args);
        }

        $effective = $this->effectiveMode($mode);
        $arguments = $mode === \PDO::FETCH_DEFAULT ? $this->fetchArgs : array_values($args);
        $flags = \PDO::FETCH_GROUP | \PDO::FETCH_UNIQUE | \PDO::FETCH_CLASSTYPE | \PDO::FETCH_PROPS_LATE;
        if (($effective & ~$flags) === \PDO::FETCH_FUNC && is_callable($args[0] ?? null)) {
            return parent::fetchAll($mode, DecimalRows::callback($args[0], $decimals));
        }

        return DecimalRows::all(parent::fetchAll($mode, ...$args), $effective, $arguments, $decimals);
    }

    /**
     * @psalm-impure
     */
    #[Override]
    public function fetchColumn(int $column = 0): mixed
    {
        $decimals = $this->decimals();
        $value = parent::fetchColumn($column);

        return isset($decimals[$column]) && $value !== false ? DecimalRows::all([$value], \PDO::FETCH_COLUMN, [$column], $decimals)[0] : $value;
    }

    /**
     * @template T of object
     * @param class-string<T>|null $class
     * @param array<array-key, mixed> $ctorArgs
     * @return T|false
     * @psalm-impure
     */
    #[Override]
    public function fetchObject(?string $class = \stdClass::class, array $ctorArgs = []): object|false
    {
        $decimals = $this->decimals();
        $row = parent::fetchObject($class, $ctorArgs);

        return $decimals === [] || $row === false ? $row : DecimalRows::row($row, $decimals);
    }

    /**
     * The DECIMAL columns of this result when the connection speaks MariaDB (docs/sqlite-mariadb.md), else none.
     *
     * @return array<int|string, int>
     * @psalm-impure
     */
    private function decimals(): array
    {
        if (! $this->pdo instanceof Sqlite || ! $this->pdo->isMariaDb()) {
            return [];
        }

        return $this->decimals ??= DecimalRows::columns($this);
    }

    /**
     * The fetch mode a call with $mode uses: its own, else setFetchMode()'s, else the connection's default.
     *
     * @psalm-impure
     */
    private function effectiveMode(int $mode): int
    {
        if ($mode !== \PDO::FETCH_DEFAULT) {
            return $mode;
        }

        return $this->fetchMode ?? (int) $this->pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE);
    }

    /**
     * @param array $bindings
     * @param string $query
     * @return string
     */
    private function produceStatementWithBindingsInForLogging(array $bindings, string $query): string
    {

        $result = $query;

        foreach ($bindings as $value) {
            $valueForPresentation = $this->formatBoundValueForLog($value);
            $result = preg_replace('/\?/', $valueForPresentation, $result, 1);

            if ($result === null) {
                throw new RuntimeException('Failed to replace placeholder with value');
            }
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function formatBoundValueForLog(mixed $value): string
    {

        $result = $value;

        if ($value === null) {
            $result = 'null';
        } elseif (is_string($value)) {
            $result = $this->pdo->quote($value);
        } elseif ($value === false) {
            $result = '0';
        } elseif ($value === true) {
            $result = '1';
        }

        return (string)$result;
    }
}
