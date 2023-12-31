<?php
declare(strict_types=1);

namespace noirapi\lib\View;

class Layout {

    public string $name         = '';
    public string $title        = '';
    public array $breadcrumbs   = [];
    public array $params        = [];

    public function setName(string $name): void {
        $this->name = $name;
    }

    /**
     * @param string|null $title
     * @return $this
     * @noinspection PhpUnused
     */
    public function setTitle(?string $title): static {
        if(empty($title)) {
            $title = '';
        }
        $this->title = $title;

        return $this;
    }

    /**
     * @param string $title
     * @return $this
     * @noinspection PhpUnused
     */
    public function appendTitle(string $title): static {
        $this->title .= $title;

        return $this;
    }

    /**
     * @param int|string $name
     * @param string|null $url
     * @param bool|null $active
     * @return void
     * @noinspection PhpUnused
     */
    public function addBreadCrumb(int|string $name, ?string $url = null, ?bool $active = null): void {

        $key = md5($name);

        $this->breadcrumbs[$key] = [
            'name'      => (string)$name,
            'url'       => $url,
            'active'    => $active
        ];

    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function add(string $key, mixed $value): void {
        $this->params[$key][] = $value;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void {
        $this->params[$key] = $value;
    }

    /**
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     * @noinspection PhpUnused
     */
    public function get(string $key, mixed $default = null): mixed {
        return $this->params[$key] ?? $default ?? null;
    }

    /**
     * @param string $key
     * @return bool
     * @noinspection PhpUnused
     */
    public function exists(string $key): bool {
        return isset($this->params[$key]);
    }

    /**
     * @param string $js
     * @return void
     * @noinspection PhpUnused
     */
    public function addTopJS(string $js): void {
        $this->params['top-js'][] = $js;
    }

    /**
     * @param string $js
     * @return void
     * @noinspection PhpUnused
     */
    public function addBottomJS(string $js): void {
        $this->params['bottom-js'][] = $js;
    }

    /**
     * @param string $css
     * @return void
     * @noinspection PhpUnused
     */
    public function addTopCss(string $css): void {
        $this->params['top-css'][] = $css;
    }

    /**
     * @param string $css
     * @return void
     * @noinspection PhpUnused
     */
    public function addBottomCss(string $css): void {
        $this->params['bottom-css'][] = $css;
    }

    /**
     * @param string $name
     * @return mixed|null
     * @noinspection MagicMethodsValidityInspection
     */
    public function __get(string $name) {
        return $this->params[$name] ?? null;
    }

}
