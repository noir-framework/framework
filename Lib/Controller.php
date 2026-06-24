<?php

/**
 * @noinspection PhpUndefinedNamespaceInspection
 * @noinspection PhpUndefinedClassInspection
 * @noinspection PhpParamsInspection
 * @noinspection PhpMissingFieldTypeInspection
 */

declare(strict_types=1);

namespace Noirapi\Lib;

use Laminas\Permissions\Acl\Acl;
use Noirapi\Config;
use Noirapi\Exceptions\LoginException;
use Noirapi\Exceptions\MessageException;
use Noirapi\Exceptions\UnableToForwardException;
use Noirapi\Helpers\Message;
use Noirapi\Helpers\RestMessage;
use Noirapi\Helpers\Utils;
use Noirapi\Lib\Tracy\PDOBarPanel;
use Throwable;
use Tracy\Debugger;
use function get_class;
use function in_array;
use function strlen;

class Controller
{
    public Request $request;
    public Response $response;
    public array $server;
    /** @var Model|null */
    public $model;
    public ?View $view = null;
    public ?bool $dev = null;
    /** @var mixed|non-empty-array<array-key, true>|null */
    public static $panels;

    public static string $model_path = 'App\\Models\\';

    /**
     * Controller constructor.
     * @param Request $request
     * @param Response $response
     * @param array $server
     */
    public function __construct(Request $request, Response $response, array $server)
    {
        $this->request = $request;
        $this->response = $response;
        $this->server = $server;

        if ($this->dev === null) {
            $this->dev = is_bool(Config::get('dev')) || (Config::get('dev_ips') !== null
                    && in_array($this->server[ 'REMOTE_ADDR' ], Config::get('dev_ips'), true));
        }

        $db = Config::get('db');

        //automatic model loading only for the first driver
        if ($db !== null) {
            $driver = array_key_first($db);
            $params = $db[$driver];

            if ($this->model === null) {
                $model = self::$model_path . Utils::getClassName(get_class($this));
                if (class_exists($model) && is_subclass_of($model, Model::class)) {
                    $this->model = new $model($driver, $params);
                } else {
                    $this->model = new Model($driver, $params);
                }
            }
        }

        // We need this when we are moving across domains
        if (isset($this->request->get['message'], $this->request->get['type'])) {
            $allowedTypes = ['success', 'danger', 'warning', 'info', 'primary', 'secondary', 'light', 'dark'];
            $type    = in_array($this->request->get['type'], $allowedTypes, true) ? $this->request->get['type'] : 'info';
            $message = strip_tags((string) $this->request->get['message']);
            if ($message !== '') {
                $this->message($message, $type);
            }
        }
    }

    public function __destruct()
    {
        if ($this->dev) {
            //PDO Panel must take into account all the created PDO connections, this can be done at destruction time
            $data = Model::tracyGetPdo();
            if (count($data) > 0) {
                $panel = new PDOBarPanel($data);
                Model::flushPdoCache();
                Debugger::getBar()->addPanel($panel);
            }
            Route::handleRouteUrlDebugBar($this->request, $this->response, $this->server);
        }
    }

    /**
     * @param string|null $location
     * @param int $status
     * @param bool $skip_lang
     * @return Response
     * @throws UnableToForwardException
     */
    public function forward(?string $location = null, int $status = 302, bool $skip_lang = false): Response
    {
        if ($status !== 302 && $status !== 301) {
            throw new UnableToForwardException('Unable to forward with status code: ' . $status);
        }

        if ($location === null) {
            $location = $this->referer();
        }

        if (! $skip_lang && $this->request->language !== null && str_starts_with($location, '/')) {
            if ($location === '/') {
                $location = '/' . $this->request->language;
            }
            if ($location !== '/' . $this->request->language) {
                $location = '/' . $this->request->language . $location;
            }

            return $this->response->withStatus($status)->withLocation($location);
        }

        return $this->response->withStatus($status)->withLocation($location);
    }

    /**
     * @return Response
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function ok(): Response
    {
        return $this->response->withStatus(200);
    }

    /**
     * @return Response
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function notFound(): Response
    {
        return $this->response->withStatus(404);
    }

    /**
     * @return Response
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function internalServerError(): Response
    {
        return $this->response->withStatus(500);
    }

    /**
     * @param string|Message $text
     * @param string|null $type
     * @param string|null $translation_key
     * @param mixed ...$translation_args
     * @return $this
     */
    public function message(string|Message $text, ?string $type = null, ?string $translation_key = null, ...$translation_args): self //phpcs:ignore
    {
        Session::remove('message');

        if ($translation_key !== null || $this->view?->translator !== null) {
            try {
                if ($text instanceof Message) {
                    $text->message = $this->view?->translator
                        ->translate($text->message, $translation_key, $translation_args);
                } else {
                    $text = Message::new($this->view?->translator
                        ->translate($text, $translation_key, $translation_args), $type ?? 'danger');
                }
            } catch (Throwable) {
                // Do nothing
            }
        }

        if ($text instanceof Message) {
            Session::set('message', null, $text);
        } else {
            Session::set('message', null, Message::new($text, $type ?? 'danger'));
        }

        if ($this->dev) {
            $bt = debug_backtrace();
            $caller = array_shift($bt);

            $this->response->initiator_class = $caller['class'] ?? null;
            $this->response->initiator_method = $caller['function'] ?? null;
            $this->response->initiator_line = $caller['line'] ?? null;
        }

        return $this;
    }

    /**
     * @param bool $same_domain
     * @return string
     * @noinspection PhpUnused
     */
    public function referer(bool $same_domain = true): string
    {
        if (isset($this->server['HTTP_REFERER'])) {
            $url = str_replace('@', '', $this->server['HTTP_REFERER']);
            if (empty($url)) {
                return '/';
            }

            $orig_url = filter_var($url, FILTER_SANITIZE_URL);
            if ($orig_url === false) {
                return '/';
            }

            /** @psalm-suppress PossiblyInvalidCast */
            $url = parse_url((string)preg_replace('/\s+/', '', $orig_url));
            if ($url === false) {
                return '/';
            }

            if (! isset($url['host'])) {
                return $orig_url;
            }

            if ($url['host'] === $this->server['HTTP_HOST']) {
                $url['path'] = $url['path'] ?? '/';

                foreach (Config::get('languages') ?? [] as $code => $_) {
                    // Condition like /en
                    if ($url['path'] === '/' . $code) {
                        return '/' . $code;
                    }
                    // Condition like /en
                    if (str_starts_with($url['path'], '/' . $code . '/')) {
                        $path = substr($url['path'], strlen($code) + 1);

                        return $path . (! isset($url['query']) ? '' : '?' . ($url['query']));
                    }
                }

                return ($this->request->language === null ? '' : '/' . $this->request->language) . $url['path'] . (! isset($url['query']) ? '' : '?' . ($url['query'])); //phpcs:ignore
            }

            if ($same_domain) {
                return '/';
            }

            if (isset($url['scheme']) && ($url['scheme'] === 'http' || $url['scheme'] === 'https')) {
                $url['path'] = $url['path'] ?? '/';

                /** @noinspection BypassedUrlValidationInspection */
                if (filter_var($url['scheme'] . '://' . $url['host'] . $url['path'] . '?' . ($url['query'] ?? ''), FILTER_VALIDATE_URL)) { //phpcs:ignore
                    if (isset($url['query'])) {
                        return $url['scheme'] . '://' . $url['host'] . $url['path'] . '?' . $url['query'];
                    }

                    return $url['scheme'] . '://' . $url['host'] . $url['path'];
                }
            }

            return '/';
        }

        return '/';
    }

    /**
     * @param bool $status
     * @param object|array|string $message
     * @param string|null $next
     * @param string|null $message_tag
     * @return Response
     * @noinspection PhpUnused
     */
    public function restMessage(bool $status, object|array|string $message, ?string $next = null, ?string $message_tag = null): Response //phpcs:ignore
    {
        return $this->response->setBody(
            RestMessage::new(
                ok: $status,
                message: $message instanceof Message ? $message->message : $message,
                next: $next,
                message_tag: $message_tag
            )
        );
    }

    /**
     * @param Acl $acl
     * @return void
     * @throws LoginException
     * @throws MessageException
     */
    public function hasResource(Acl $acl): void
    {
        if (! $acl->hasResource(static::class)) {
            if ($this->request->ajax) {
                throw new MessageException('Page not Found', 404);
            }

            $this->message('Page not found', 'danger');

            throw new LoginException('/', 301);
        }
    }

    /**
     * @param Acl $acl
     * @return void
     * @throws LoginException
     * @throws MessageException
     */
    public function isAllowed(Acl $acl): void
    {
        if (! $acl->isAllowed($this->request->role, static::class)) {
            if ($this->request->ajax) {
                throw new MessageException('Please Login', 403);
            }

            if ($this->request->method === 'POST') {
                $login = $this->referer();
            } else {
                $login = $this->request->uri;
            }

            Session::set('return', null, $login);

            $this->message('Please login', 'success');

            throw new LoginException('/', 301);
        }
    }
}
