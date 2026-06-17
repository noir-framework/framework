<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Latte\Bridges\Tracy\TracyExtension;
use Latte\Engine;
use Latte\Essential\TranslatorExtension;
use Latte\Feature;
use Nette\Neon\Exception;
use Noirapi\Config;
use Noirapi\Exceptions\FileNotFoundException;
use Noirapi\Helpers\DummyTranslator;
use Noirapi\Helpers\EasyTranslator;
use Noirapi\Helpers\Template;
use Noirapi\Interfaces\Translator;
use Noirapi\Lib\View\FilterExtension;
use Noirapi\Lib\View\LatteLoader;
use Noirapi\Lib\View\Layout;
use Noirapi\Lib\View\Macros;
use RuntimeException;
use stdClass;
use function count;

class View
{
    public Request $request;
    public Layout $layout;
    public Translator $translator;
    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
    public Response $response;
    public Engine $latte;
    public static string $template_dir_prefix = '/';

    private stdClass $params;
    private ?string $template = null;
    private bool $dev;
    private ?string $layout_file = null;
    private const string LATTE_EXT = '.latte';
    private static string $uri;

    /**
     * View constructor.
     * @param Request $request
     * @param Response $response
     * @param bool $dev
     * @throws FileNotFoundException
     * @throws Exception
     */
    public function __construct(Request $request, Response $response, bool $dev = false)
    {
        $this->request = $request;
        $this->response = $response;
        $this->params = new stdClass();
        $this->dev = $dev;

        $this->latte = new Engine();
        $this->latte->setCacheDirectory(Config::getTemp());

        //enable regeneration of the template files
        $this->latte->setAutoRefresh();

        $this->latte->addExtension(new FilterExtension());
        $this->latte->addExtension(new Macros());
        $this->latte->addFunction('renderTemplate', static function ($template, array $data) {
            $render = new Template();
            $render->setTemplate($template);

            return $render->print($data);
        });

        //Handle the case where the template name is double underscore, search for it in the layouts
        $this->latte->setLoader(new LatteLoader());

        /**
         * @noinspection PhpUndefinedClassInspection
         * @noinspection RedundantSuppression
         * @noinspection PhpUndefinedNamespaceInspection
         */
        if (class_exists(\App\Lib\Macros::class)) {
            /**
             * @noinspection PhpParamsInspection
             * @noinspection RedundantSuppression
             */
            $this->latte->addExtension(new \App\Lib\Macros());
        }

        $languages = Config::get('languages') ?? [];
        if (is_array($languages) && ! empty($languages)) {
            if ($this->request->language === null) {
                $this->request->language = Config::get('default_language') ?? 'en';
            }
            $this->translator = new EasyTranslator($this->request->language, $this->request->controller, $this->request->function); //phpcs:ignore
        } else {
            $this->translator = new DummyTranslator();
        }

        $extension = new TranslatorExtension(
            [$this->translator, 'translate'],
            $this->request->language
        );

        $this->latte->addExtension($extension);
        $this->addParam('languages', $languages);

        $this->layout = new Layout($this->translator);

        $layout_file = Config::get('layout');

        if ($layout_file !== null) {
            $this->setLayout($layout_file);
        }

        if ($dev) {
            $this->latte->addExtension(new TracyExtension());
        }

        $this->latte->setFeature(Feature::StrictParsing);
        $this->latte->setFeature(Feature::StrictTypes);

        self::$uri = $request->uri;
    }

    /**
     * @param array $params
     * @return Response
     * @throws FileNotFoundException
     */
    public function display(array $params = []): Response
    {

        if ($this->dev) {
            $bt = debug_backtrace();
            $this->setFromBackTrace($bt);
        }

        if ($this->template === null) {
            $this->setTemplate($this->request->function);
        }

        $layout = $this->layout_file ?? $this->template ?? throw new RuntimeException('No layout|template set for display'); //phpcs:ignore

        if ($this->request->ajax) {
            $layout = $this->template ?? throw new RuntimeException('No template set for ajax request');
        }

        $this->mergeParams($this->request, 'request');

        $this->mergeParams($params);

        $message = Session::get('message');

        if (! empty($message)) {
            $this->mergeParams(['message' => $message]);
            Session::remove('message');
        }

        $this->mergeParams([
            'template' => $this->template,
        ]);

        $this->mergeParams($this->layout, 'layout');

        $html = $this->latte->renderToString($layout, $this->params);

        // For PJAX responses the layout (<head>) is skipped, so prepend any
        // page-specific <link> tags so pjax.js can inject them into <head>.
        if ($this->request->ajax && !empty($this->layout->params['top-css'])) {
            $links = implode('', array_map(
                static fn (string $css) => '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES) . '">',
                $this->layout->params['top-css']
            ));
            $html = $links . $html;
        }

        return $this->response->setBody($html);
    }

    /**
     * @param string|null $layout
     * @param string $view
     * @param array $params
     * @return string
     * @throws FileNotFoundException
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function print(?string $layout, string $view, array $params = []): string
    {

        if ($this->dev) {
            $bt = debug_backtrace();
            $this->setFromBackTrace($bt);
        }

        $this->setTemplate($view);
        $params['template'] = $this->template;
        $this->mergeParams($params);

        if ($layout !== null) {
            $this->setLayout($layout);
            $this->setTemplate($view);

            $layout_file = $this->layout_file ?? $this->template ?? throw new RuntimeException('No layout|template set for display'); //phpcs:ignore

            return $this->latte->renderToString($layout_file, $this->params);
        }

        $this->setTemplate($view);

        if ($this->template === null) {
            throw new RuntimeException('No template set for display');
        }

        return $this->latte->renderToString($this->template, $this->params);
    }

    /**
     * @param string $template
     * @param string|null $controller
     * @return self
     * @noinspection PhpUnused
     * @throws FileNotFoundException
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function setTemplate(string $template, ?string $controller = null): self
    {

        $path = $controller === null ? lcfirst($this->request->controller) : lcfirst($controller);

        $file = Config::getViews() . self::$template_dir_prefix . $path . DIRECTORY_SEPARATOR . $template . self::LATTE_EXT;

        if (is_readable($file)) {
            $this->template = $file;

            return $this;
        }

        throw new FileNotFoundException('Unable to find template: ' . $file);
    }

    /**
     * @return string|null
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getTemplate(): ?string
    {
        return $this->template;
    }


    /**
     * @return $this
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function noLayout(): self
    {
        $this->layout_file = null;

        return $this;
    }

    /**
     * @param string|null $layout_file
     * @return self
     * @throws FileNotFoundException
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function setLayout(?string $layout_file = null): self
    {
        if ($layout_file === null) {
            $this->layout_file = null;

            return $this;
        }

        $file = Config::getLayouts() . '/' . $layout_file . self::LATTE_EXT;

        if (is_readable($file)) {
            $this->layout_file = $file;
            $this->layout->setName($layout_file);

            return $this;
        }

        throw new FileNotFoundException('Unable to find layout_file: ' . $file);
    }


    /**
     * @return string|null
     * @noinspection GetSetMethodCorrectnessInspection
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getLayout(): ?string
    {
        return $this->layout_file;
    }

    /**
     * @param string $template
     * @param string|null $controller
     * @return bool
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function templateExists(string $template, ?string $controller = null): bool
    {
        if ($controller === null) {
            $controller = strtolower($this->request->controller);
        }
        $file = Config::getViews() . '/' . strtolower($controller) . DIRECTORY_SEPARATOR . $template . self::LATTE_EXT;

        return is_readable($file);
    }

    /**
     * @param string $layout
     * @return bool
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function layoutExists(string $layout): bool
    {
        $file = Config::getLayouts() . '/' . $layout . self::LATTE_EXT;

        return is_readable($file);
    }

    /**
     * @param string $key
     * @param int|float|string|null $value
     * @return string
     * @psalm-suppress PossiblyUnusedMethod
     * @noinspection PhpUnused
     */
    public static function addUrlVar(string $key, int|float|string|null $value): string
    {
        $params = parse_url(self::$uri, PHP_URL_QUERY);

        $value = (string)$value;

        if (is_string($params)) {
            parse_str($params, $array);

            if (count($array) > 0) {
                $array[$key] = $value;

                return '?' . http_build_query($array);
            }

            return '?' . $key . '=' . $value;
        }

        return '?' . $key . '=' . $value;
    }

    /**
     * @return array
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     *
     * this is used by the system panel
     */
    public function getParams(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array|object $params
     * @param string|null $namespace
     * @return void
     */
    public function mergeParams(array|object $params, ?string $namespace = null): void
    {
        if ($namespace === null) {
            foreach ($params as $key => $value) {
                if (isset($this->params->$key)) {
                    throw new RuntimeException("Duplicate key in view params: $key");
                }

                $this->params->$key = $value;
            }
        } elseif (isset($this->params->$namespace)) {
            throw new RuntimeException("Duplicate key in view params: $namespace");
        } else {
            $this->params->$namespace = $params;
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     * @noinspection PhpUnused
     */
    public function addParam(string $key, mixed $value): void
    {
        $this->params->$key = $value;
    }

    /**
     * @return Response
     * @note this is used by the system panel
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * @param array $bt
     * @return void
     */
    private function setFromBackTrace(array $bt): void
    {

        $caller = array_shift($bt);

        $this->response->initiator_class = $caller['class'] ?? null;
        $this->response->initiator_method = $caller['function'] ?? null;
        $this->response->initiator_line = $caller['line'] ?? null;
    }
}
