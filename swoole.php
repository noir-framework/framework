<?php /** @noinspection PhpUndefinedNamespaceInspection */
/** @noinspection PhpUndefinedClassInspection */
declare(strict_types=1);

use Noirapi\Config;
use Noirapi\Lib\Route;
use Swoole\Http\Server;

if(! extension_loaded('swoole')) {
    throw new RuntimeException('Swoole extension is mandatory');
}

/** @psalm-suppress MissingFile */
include(__DIR__ . '/include.php');

$listen_ip = Config::get('swoole.listen_ip') ?? '127.0.0.1';
$listen_port = Config::get('swoole.listen_port') ?? 9400;

$server = new Swoole\Http\Server($listen_ip, $listen_port);
$static_files = Config::get('swoole.static_files');
$server->set([
    'worker_num'      => Config::get('swoole.workers') ?? 1,
    'task_worker_num' => Config::get('swoole.task_workers') ?? 1,
]);

if(! empty($static_files)) {
    $server->set([
        'document_root'         => Config::getWwwRoot(),
        'enable_static_handler' => true,
    ]);
}

$server->on('start', function () use ($listen_ip, $listen_port) {
    /**
     * @noinspection HttpUrlsUsage
     */
    echo "Swoole http server is started at http://$listen_ip:$listen_port\n";
});

$server->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($server) {

    $request->server['headers'] = $request->header;
    $route = Route::fromSwoole($request->server, $request->get ?? [], $request->post ?? [], $request->files ?? [], $request->cookie ?? []);
    $route->setSwoole($server);

    //TODO set http hostname, https and cooke_domain like kernel.php

    $app = $route->serve();

    $response->setStatusCode($app->getStatus());

    foreach ($app->getHeaders() as $key => $value) {
        $response->header($key, $value);
    }

    foreach ($app->getCookies() as $cookie) {

        /**
         * @psalm-suppress InvalidNamedArgument
         * @psalm-suppress TooFewArguments
         */
        $response->setCookie(
            name: $cookie['key'],
            value: $cookie['value'],
            expires: $cookie['expire'],
            domain: Config::$config,
            secure: $cookie['secure'],
            httponly: $cookie['httponly'],
            samesite: $cookie['samesite']
        );

    }

    $response->end($app->getBody());

});

$server->on('Task', static function (Server $server, int $task_id, int $reactorId, mixed $data) {

    if(isset($data[ 'class' ], $data[ 'params' ])) {
        echo "Begin task: \t" . $task_id . "\t" . $data['class'] . "\n";
        $class = new $data['class']();
        /** @psalm-suppress InvalidFunctionCall $class */
        $res = $class($data['params']);
        $server->finish($res);
        echo "End task: \t" . $task_id . "\t" . $data['class'] . "\n";
    } else {
        echo 'No class found in task' . PHP_EOL;
    }

});

$server->start();
