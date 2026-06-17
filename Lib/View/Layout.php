<?php

declare(strict_types=1);

namespace Noirapi\Lib\View;

use Noirapi\Interfaces\Translator;

use function is_string;

class Layout
{
    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
    public string $name = '';
    public string $title = '';
    public array $breadcrumbs = [];
    public array $params = [
        'top-css'    => [],
        'top-js'     => [],
        'bottom-css' => [],
        'bottom-js'  => [],
    ];
    public array $head = [];

    private Translator $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @param string $line
     * @return void
     * @noinspection PhpUnused
     */
    public function addToHead(string $line): void
    {
        $this->head[] = $line;
    }

    /**
     * @param string $name
     * @return void
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string|null $title
     * @return $this
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function setTitle(?string $title): static
    {
        if ($title === null) {
            $title = '';
        }
        $this->title = $this->translator->translate($title);

        return $this;
    }

    /**
     * @param string $title
     * @return $this
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function appendTitle(string $title): static
    {
        $this->title .= $this->translator->translate($title);

        return $this;
    }

    /**
     * @param int|string $name
     * @param string|null $url
     * @param bool|null $active
     * @return void
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function addBreadCrumb(int|string $name, ?string $url = null, ?bool $active = null): void
    {

        $key = ! is_string($name) ? (string)$name : $name;

        $this->breadcrumbs[$key] = [
            'name'   => is_string($name) ? $this->translator->translate($name) : (string)$name,
            'url'    => $url !== null ? $this->translator->translate($url) : null,
            'active' => $active,
        ];
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function add(string $key, mixed $value): void
    {
        $this->params[$key][] = is_string($value) ? $this->translator->translate($value) : $value;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function set(string $key, mixed $value): void
    {
        $this->params[$key] = is_string($value) ? $this->translator->translate($value) : $value;
    }

    /**
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default ?? null;
    }

    /**
     * @param string $key
     * @return bool
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function exists(string $key): bool
    {
        return isset($this->params[$key]);
    }

    /**
     * @param string $js
     * @return void
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function addTopJS(string $js): void
    {
        if (!in_array($js, $this->params['top-js'], true)) {
            $this->params['top-js'][] = $js;
        }
    }

    /**
     * @param string $js
     * @return void
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function addBottomJS(string $js): void
    {
        if (!in_array($js, $this->params['bottom-js'], true)) {
            $this->params['bottom-js'][] = $js;
        }
    }

    /**
     * @param string $css
     * @return void
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function addTopCss(string $css): void
    {
        if (!in_array($css, $this->params['top-css'], true)) {
            $this->params['top-css'][] = $css;
        }
    }

    /**
     * @param string $css
     * @return void
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function addBottomCss(string $css): void
    {
        if (!in_array($css, $this->params['bottom-css'], true)) {
            $this->params['bottom-css'][] = $css;
        }
    }

    /**
     * @param string $name
     * @return mixed|null
     * @noinspection MagicMethodsValidityInspection
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __get(string $name)
    {
        return $this->params[$name] ?? null;
    }

    /**
     * @return bool
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function hasTitle(): bool
    {
        return $this->title !== '';
    }
}
