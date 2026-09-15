<?php

/**
 * @noinspection PhpUndefinedNamespaceInspection
 * @noinspection PhpUndefinedClassInspection
 * @noinspection PhpMultipleClassDeclarationsInspection
 */

declare(strict_types=1);

namespace Noirapi\Lib;

use BackedEnum;
use FastRoute\Dispatcher;
use Noirapi\Config;
use Noirapi\Exceptions\InternalServerError;
use Noirapi\Exceptions\LoginException;
use Noirapi\Exceptions\MessageException;
use Noirapi\Exceptions\NotFoundException;
use Noirapi\Exceptions\RestException;
use Noirapi\Helpers\Utils;
use Noirapi\Lib\Attributes\AutoWire;
use Noirapi\Lib\Attributes\NotFound;
use Noirapi\Lib\Tracy\GenericPanel;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Swoole\Http\Server;
use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;

use ReflectionNamedType;
use function in_array;
use function strlen;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") this is the framework's
 * central dispatcher - matching a request to a controller method necessarily
 * touches routing, reflection, the DI attributes, and every exception type
 * a controller can throw.
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") same rationale as above.
 */
class Route
{
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection
     */
    private Request $request;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection
     */
    private Response $response;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection
     */
    private array $server;

    /**
     * @param array $server
     * @param array $get
     * @param array $post
     * @param array $files
     * @param array $cookies
     * @return self
     */
    public static function fromGlobals(array $server, array $get, array $post, array $files, array $cookies): self
    {
        $self = new self();

        $self->request = Request::fromGlobals($server, $get, $post, $files, $cookies);
        $self->server = $server;

        return $self;
    }

    /**
     * @param array $server
     * @param array $get
     * @param array $post
     * @param array $files
     * @param array $cookies
     * @return self
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function fromSwoole(array $server, array $get, array $post, array $files, array $cookies): self
    {
        $self = new self();
        $self->request = Request::fromSwoole($server, $get, $post, $files, $cookies);
        $self->server = Request::swooleUpperCase($server);

        return $self;
    }

    /**
     * @param Server $server
     * @return void
     * @noinspection PhpUndefinedClassInspection
     * @psalm-suppress PossiblyUnusedMethod
     * @psalm-suppress UndefinedClass
     */
    public function setSwoole(Server $server): void
    {
        $this->request->swoole = $server;
    }

    /**
     * @return Response
     * @throws Throwable
     */
    public function serve(): Response
    {
        $dev = $this->isDevRequest();

        $this->response = new Response();

        $route = new \App\Route();

        $pos = strpos($this->request->uri, '?');
        $uri = $pos !== false ? substr($this->request->uri, 0, $pos) : $this->request->uri;
        $uri = rawurldecode($uri);

        $languages = Config::get('languages') ?? [];
        $uri = $this->stripLanguageFromUri($uri, $languages);

        /** @psalm-suppress RedundantCondition */
        if ($this->request->language === null && ! empty($languages)) {
            $this->redirectToDetectedLanguage($uri, $languages, $dev);

            return $this->response;
        }

        $this->request->url_no_lang = $uri;

        $this->request->route = $route->process($this->request->method, $uri);

        switch ($this->request->route[0]) {
            case Dispatcher::FOUND:
                $this->request->controller = Utils::getClassName($this->request->route[1][0]);
                $this->request->function = $this->request->route[1][1];
                $this->dispatchFoundRoute();

                break;

            case Dispatcher::NOT_FOUND:
                $this->response = self::handleErrors(404, '404 Not found', $this);

                break;

            case Dispatcher::METHOD_NOT_ALLOWED:
                $this->response = self::handleErrors(405, '405 Method not allowed', $this);

                break;

            default:
                $this->response = self::handleErrors(500, 'Internal server error', $this);
        }

        return $this->response;
    }

    /**
     * @return bool
     *
     * @psalm-suppress MissingPureAnnotation Psalm and PHPStan disagree on the purity
     * of Config::get(); leaving unannotated satisfies both.
     */
    private function isDevRequest(): bool
    {
        if (Config::get('dev') !== null) {
            return (bool)Config::get('dev');
        }

        if (Config::get('dev_ips') !== null) {
            return in_array($this->server['REMOTE_ADDR'], Config::get('dev_ips'), true);
        }

        return false;
    }

    /**
     * If $uri starts with a known language code, records it on the request and
     * strips it from the returned uri.
     *
     * @param string $uri
     * @param array $languages
     * @return string
     */
    private function stripLanguageFromUri(string $uri, array $languages): string
    {
        foreach (array_keys($languages) as $code) {
            // Condition like /en,
            if ($uri === '/' . $code) {
                $this->request->language = $code;

                return '/';
            }

            // Condition like /en/
            if (str_starts_with($uri, '/' . $code . '/')) {
                $this->request->language = $code;

                return substr($uri, strlen($code) + 1);
            }
        }

        return $uri;
    }

    /**
     * Detects the visitor's preferred language and redirects to the
     * language-prefixed uri, mutating $this->response.
     *
     * @param string $uri
     * @param array $languages
     * @param bool $dev
     * @return void
     */
    private function redirectToDetectedLanguage(string $uri, array $languages, bool $dev): void
    {
        $detected = null;
        $detector = Config::get('language_detector');
        if ($detector !== null && class_exists($detector)) {
            $ip = $this->server['HTTP_X_FORWARDED_FOR'] ?? $this->server['REMOTE_ADDR'] ?? '';
            $ip = explode(',', $ip)[0];
            $detected = (new $detector())->detect(trim($ip));
            if (! isset($languages[$detected])) {
                $detected = null;
            }
        }

        $this->redirect('/' . ($detected ?? Config::get('default_language') ?? 'en') . $uri, 307);

        if ($dev) {
            self::handleRouteUrlDebugBar($this->request, $this->response, $this->server);
        }
    }

    /**
     * Instantiates the matched controller, resolves #[AutoWire] parameters and
     * invokes the matched method, mutating $this->response on the way. Split
     * out of serve() to keep the dispatcher's own complexity manageable.
     *
     * @return void
     * @throws Throwable
     */
    private function dispatchFoundRoute(): void
    {
        try {
            /** @var Controller $controller */
            $controller = new $this->request->route[1][0]($this->request, $this->response, $this->server);
            $method = $this->request->route[1][1];
            $args = $this->request->route[2];

            /** @noinspection PhpUnhandledExceptionInspection */
            $reflection = new ReflectionMethod($controller, $method);

            $realArgs = [];
            if (count($reflection->getAttributes()) > 0) {
                $realArgs = $this->resolveAutoWiredArgs($reflection, $controller, $args);
                if ($realArgs === false) {
                    return;
                }
            }

            $controller->$method(...array_merge($args, $realArgs));
        } catch (LoginException $exception) {
            if ($exception->getCode() === 403) {
                $this->response->withStatus(403)
                    ->setContentType(Response::TYPE_JSON)
                    ->setBody(['forward' => $exception->getMessage()]);
            } else {
                $this->response->withStatus($exception->getCode())
                    ->withLocation($exception->getMessage());
            }
        } catch (RestException $exception) {
            $this->response->withStatus($exception->getCode())
                ->setContentType(Response::TYPE_JSON)
                ->setBody($exception->getMessage());
        } catch (MessageException $exception) {
            $this->response->withStatus($exception->getCode())
                ->setBody($exception->getMessage());
        } catch (InternalServerError $exception) {
            $this->response = self::handleErrors(500, $exception->getMessage() ?? 'Internal server error', $this); //phpcs:ignore
        } catch (NotFoundException $exception) {
            $this->response = self::handleErrors(404, $exception->getMessage() ?? '404 Not found', $this);
        } catch (Throwable $exception) {
            $this->response = ExceptionRenderer::render($exception, $this->response);
        }
    }

    /**
     * Resolves #[AutoWire] parameters for the matched controller method against
     * the route's $args, mutating both $args (consumed keys removed) and
     * $this->response (on failure). Returns the resolved args keyed by
     * parameter name, or false if resolution failed and the caller must abort
     * (the response has already been set to an error state).
     *
     * @param ReflectionMethod $reflection
     * @param Controller $controller
     * @param array $args
     * @return array|false
     */
    private function resolveAutoWiredArgs(ReflectionMethod $reflection, Controller $controller, array &$args): array|false
    {
        $realArgs = [];
        $parameters = $reflection->getParameters();

        if (isset($reflection->getAttributes(NotFound::class)[0])) {
            $message = $reflection->getAttributes(NotFound::class)[0]->newInstance();
        } else {
            $message = null;
        }

        foreach ($reflection->getAttributes(AutoWire::class) as $attribute) {
            /**
             * @var AutoWire $instance
             * @psalm-suppress UnnecessaryVarAnnotation
             */
            $instance = $attribute->newInstance();
            $param = array_shift($parameters);

            // If the parameter is not a built-in type, we will try to resolve it
            $paramType = $param->getType();
            if (! $paramType instanceof ReflectionNamedType || $paramType->isBuiltin()) {
                continue;
            }

            $type = $paramType->getName();
            $typeReflection = new ReflectionClass($type);

            foreach ($args as $key => $value) {
                $key_modified = str_ends_with($key, '_id') ? substr($key, 0, -3) : $key;
                if ($param->getName() !== $key_modified) {
                    continue;
                }

                $resolved = $this->resolveAutoWiredValue($param, $type, $typeReflection, $instance, $value, $controller);
                if ($resolved['status'] === 'skip') {
                    continue;
                }

                if ($resolved['status'] === 'not_found') {
                    $this->abortAutoWire($controller, $message);

                    return false;
                }

                unset($args[$key]);
                $realArgs[$param->getName()] = $resolved['value'];
            }
        }

        return $realArgs;
    }

    /**
     * Resolves a single #[AutoWire] parameter's value, either from a backed
     * enum or via the attribute's configured callable.
     *
     * @param ReflectionParameter $param
     * @param string $type
     * @param ReflectionClass $typeReflection
     * @param AutoWire $instance
     * @param mixed $value
     * @param Controller $controller
     * @return array{status: 'ok'|'skip'|'not_found', value?: mixed}
     *
     * @psalm-suppress MissingPureAnnotation this calls into arbitrary model/
     * callable code via $controller->model and call_user_func(), which is not
     * pure by definition.
     */
    private function resolveAutoWiredValue(
        ReflectionParameter $param,
        string $type,
        ReflectionClass $typeReflection,
        AutoWire $instance,
        mixed $value,
        Controller $controller,
    ): array {
        if ($typeReflection->isEnum() && $typeReflection->implementsInterface(BackedEnum::class)) {
            $result = $type::tryFrom($value);

            return $result === null ? ['status' => 'not_found'] : ['status' => 'ok', 'value' => $result];
        }

        if (is_string($instance->callable)) {
            $result = $controller->model?->{$instance->callable}($value);
        } elseif (is_array($instance->callable)) {
            $result = call_user_func($instance->callable, $value);
        } else {
            return ['status' => 'skip'];
        }

        if ($result === null && ! $param->allowsNull()) {
            return ['status' => 'not_found'];
        }

        return ['status' => 'ok', 'value' => $result];
    }

    /**
     * @param Controller $controller
     * @param mixed $message #[NotFound] attribute instance, or null
     * @return void
     */
    private function abortAutoWire(Controller $controller, mixed $message): void
    {
        $controller->message($message !== null ? $message->message : 'Not Found', 'danger'); //phpcs:ignore
        $this->response->withStatus($message !== null ? $message->status : 301)
            ->withLocation($controller->referer());
    }

    /**
     * @param int $status_code
     * @param string $defaultText
     * @param Route $instance
     * @return Response
     */
    private static function handleErrors(int $status_code, string $defaultText, self $instance): Response
    {

        /** @psalm-suppress UndefinedClass */
        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        if (class_exists(\App\Lib\ErrorHandler::class)) {
            try {
                /** @noinspection PhpFullyQualifiedNameUsageInspection */
                return \App\Lib\ErrorHandler::handle($status_code, $defaultText, $instance);
            } catch (Throwable $e) {
                Debugger::log($e, ILogger::EXCEPTION);
            }
        } else {
            $function = 'e' . $status_code;

            /** @psalm-suppress UndefinedClass */
            /** @noinspection PhpFullyQualifiedNameUsageInspection */
            if (class_exists(\App\Controllers\Errors::class) && method_exists(\App\Controllers\Errors::class, $function)) { // phpcs:ignore
                $instance->request->controller = 'Errors';
                $instance->request->function = $function;

                try {
                    /** @noinspection PhpFullyQualifiedNameUsageInspection */
                    /** @noinspection PhpParenthesesCanBeOmittedForNewCallInspection */
                    return (new \App\Controllers\Errors($instance->request, $instance->response, $instance->server))->$function(); // phpcs:ignore
                } catch (LoginException $e) {
                    $response = new Response();
                    if ($e->getCode() === 301) {
                        $response->withStatus(301)
                            ->withLocation($e->getMessage());
                    } else {
                        $response->withStatus(403)
                            ->setBody($e->getMessage());
                    }

                    return $response;
                } catch (Throwable $e) {
                    Debugger::log($e, ILogger::EXCEPTION);
                }
            }
        }

        $response = new Response();
        $response->setBody($defaultText);
        $response->withStatus($status_code);

        return $response;
    }

    /**
     * @return Response
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * @return Request
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @return array
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getServer(): array
    {
        return $this->server;
    }

    /**
     * @param string $location
     * @param int $status
     *
     * @return void
     *
     * @psalm-external-mutation-free
     */
    private function redirect(string $location, int $status = 302): void
    {

        // Attach get to current future location
        if (! empty($this->request->get)) {
            $location .= '?' . http_build_query($this->request->get);
        }

        $this->response->withLocation($location)->withStatus($status);
    }

    /**
     * @param Request $request
     * @param Response $response
     * @param array $server
     * @return void
     */
    public static function handleRouteUrlDebugBar(Request $request, Response $response, array $server): void
    {

        /** @noinspection HttpUrlsUsage */
        $host = ($request->https ? 'https://' : 'http://') . Config::$config;

        $urls = [];
        $urls['uri'] = $host . $request->uri;

        $ref = $server[ 'HTTP_REFERER' ] ?? '';

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        if (! str_starts_with($ref, 'http')) {
            $urls['ref'] = $host . $ref;
        } elseif ($ref !== '') {
            $urls['ref'] = $ref;
        }

        $location = $response->getLocation();
        if ($location !== null) {
            if (str_starts_with($location, 'http')) {
                $urls['fwd'] = $location;
            } else {
                $urls['fwd'] = $host . $location;
            }
        }

        $panel = new GenericPanel('url', $urls);
        Debugger::getBar()->addPanel($panel);
    }
}
