<?php
/** @noinspection UnknownInspectionInspection */
declare(strict_types = 1);

namespace noirapi\lib;

use Latte\Engine;
use Latte\Essential\CoreExtension;
use noirapi\Config;
use noirapi\Exceptions\FileNotFoundException;
use noirapi\helpers\Macros;

class View {

    /** @var Request */
    public Request $request;

    /** @var string|null */
    private ?string $template = null;

    /** @var Engine */
    public Engine $latte;

    /** @var string|null */
    private ?string $layout = null;

    /** @var response */
    private response $response;

    /** @var string */
    private const latte_ext = '.latte';

    /** @var array */
    private array $extra_params = [];

    /**
     * View constructor.
     * @param Request $request
     * @param response $response
     * @throws FileNotFoundException
     */
    public function __construct(Request $request, Response $response) {

        $this->request = $request;

        $this->latte = new Engine;
        $this->latte->setTempDirectory(ROOT . '/temp');

        //enable regeneration of the template files
        $this->latte->setAutoRefresh(true);
        $this->latte->addFilterLoader('\\noirapi\\helpers\\Filters::init');

        $this->response = $response;

        $this->latte->addExtension(new Macros());
        $this->latte->addExtension(new \app\lib\Macros());

        $layout = Config::get('layout');

        if($layout) {

            $this->setLayout($layout);

        }

    }

    /**
     * @param array $params
     * @return response
     * @throws FileNotFoundException
     */
    public function display(array $params = []): response {

        if($this->template === null) {
            $this->setTemplate($this->request->function);
        }

        $layout = $this->layout ?? $this->template;

        if(isset($_SERVER['HTTP_X_PJAX'])) {
            $layout = $params['view'];
        }

        if(isset($_SESSION['message'])) {
            $params['message'] = $_SESSION['message'];
            unset($_SESSION['message']);
        }

        $params['view'] = $this->template;

        if(isset($_SESSION['message'])) {
            $params['message'] = $_SESSION['message'];
            unset($_SESSION['message']);
        }

        $params['extra_params'] = $this->extra_params;

        return $this->response->setBody($this->latte->renderToString($layout, array_merge((array)$this->request, $params)));

    }

    /**
     * @param string|null $layout
     * @param string $view
     * @param array $params
     * @return string
     * @throws FileNotFoundException
     */
    public function print(?string $layout, string $view, array $params = []): string {

        if($layout !== null) {
            $this->setLayout($layout);
            $this->setTemplate($view);
            $params['view'] = $this->template;
            return $this->latte->renderToString($this->layout, $params);
        }

        $this->setTemplate($view);

        return $this->latte->renderToString($this->template, $params);

    }

    /**
     * @param string $template
     * @param string|null $controller
     * @return view
     * @noinspection PhpUnused
     * @throws FileNotFoundException
     */
    public function setTemplate(string $template, string $controller = null): view {

        if($controller === null) {
            $controller = $this->request->controller;
        }

        $file = PATH_VIEWS . $controller . DIRECTORY_SEPARATOR . $template . self::latte_ext;

        if(is_readable($file)) {
            $this->template = $file;
            return $this;
        }

        throw new FileNotFoundException('Unable to find template: ' . $file);

    }

    /**
     * @param string|null $layout
     * @return view
     * @throws FileNotFoundException
     * @noinspection PhpUnused
     */
    public function setLayout(?string $layout = null): view {

        if($layout === null) {
            $this->layout = null;
            return $this;
        }

        $file = PATH_LAYOUTS . $layout . self::latte_ext;

        if(is_readable($file)) {
            $this->layout = $file;
            return $this;
        }

        throw new FileNotFoundException('Unable to find layout: ' . $file);

    }

    /**
     * @param $param
     * @param $page
     * @return string
     * @noinspection PhpUnused
     */
    public static function add_url_var($param, $page): string {

        /** @noinspection UriPartExtractionInspection */
        $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);

        if(isset($url['query'])) {

            parse_str($url['query'], $array);

            if(count($array) > 0) {
                $array[$param] = $page;
                return '?' . http_build_query($array);
            }
            return '?' . $param . '=' . $page;
        }
        return '?' . $param . '=' . $page;
    }

    /**
     * @param array $params
     * @return void
     * @noinspection PhpUnused
     */
    public function setLayoutExtraParams(array $params): void {
        $this->extra_params = $params;
    }

}
