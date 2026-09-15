<?php

declare(strict_types=1);

namespace Noirapi\Lib\PDO;

use function is_array;
use function is_string;
use Override;

use PDOStatement as NativePdoStatement;
use RuntimeException;

/**
 * @psalm-suppress MissingTemplateParam
 * @psalm-api
 */
class PDOStatement extends NativePdoStatement
{
    /**
     * PDO instance.
     */
    protected PDO $pdo;

    /**
     * For binding simulations purposes.
     */
    protected array $bindings = [];

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @psalm-mutation-free
     */
    protected function __construct(PDO $pdo)
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
