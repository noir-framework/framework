<?php

/**
 * @noinspection PhpUndefinedNamespaceInspection
 * @noinspection PhpUndefinedClassInspection
 */

declare(strict_types=1);

namespace Noirapi\Lib;

use RuntimeException;
use Swoole\Http\Server;

/** @psalm-api  */
class Tasks
{
    private array $tasks = [];
    /**
     * @var Server
     * @psalm-suppress UndefinedClass
     */
    private Server $server;
    private int $timeout;
    private string $taskNamespace;

    /**
     * @param Server $server
     * @param int $timeout
     * @param string $taskNamespace
     * @psalm-suppress UndefinedClass
     */
    public function __construct(Server $server, int $timeout = 300, string $taskNamespace = 'App\\Tasks\\')
    {
        $this->server = $server;
        $this->timeout = $timeout;
        $this->taskNamespace = $taskNamespace;
    }

    /**
     * @param string $class
     * @param array $params
     * @return self
     */
    public function add(string $class, array $params): self
    {
        $task = $this->taskNamespace . $class;
        if (! class_exists($task)) {
            throw new RuntimeException("Task class $class not found");
        }

        $this->tasks[] = [
            'class'  => $task,
            'params' => $params,
        ];

        return $this;
    }

    /**
     * @return mixed
     * @psalm-suppress UndefinedClass
     */
    public function run(): mixed
    {

        return $this->server->taskWaitMulti($this->tasks, $this->timeout);
    }
}
