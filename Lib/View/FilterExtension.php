<?php
/**
 * @noinspection PhpUndefinedClassInspection
 * @noinspection PhpUnused
 */

declare(strict_types=1);

namespace Noirapi\Lib\View;

use App\Lib\Filters;
use JsonException;
use Latte\Extension;
use Override;
use ReflectionClass;
use ReflectionException;

/** @psalm-api  */
class FilterExtension extends Extension
{
    /** @var list<class-string> */
    private array $extraSources = [];

    /**
     * Register an additional class whose static public methods become Latte filters.
     * Call before the Latte engine processes any template.
     *
     * @param class-string $class
     */
    public function addFilterSource(string $class): void
    {
        if (!in_array($class, $this->extraSources, true)) {
            $this->extraSources[] = $class;
        }
    }

    /**
     * @return array[]
     * @throws ReflectionException
     */
    #[Override]
    public function getFilters(): array
    {
        $res = $this->getMethods(__CLASS__);

        if (class_exists(Filters::class)) {
            $res = array_merge($res, $this->getMethods(Filters::class));
        }

        foreach ($this->extraSources as $source) {
            if (class_exists($source)) {
                $res = array_merge($res, $this->getMethods($source));
            }
        }

        return $res;
    }

    /**
     * @param string $string
     * @return string
     */
    public static function urlencode(string $string): string
    {
        return urlencode($string);
    }

    /**
     * @param string $string
     * @return string
     */
    public static function urldecode(string $string): string
    {
        return urldecode($string);
    }

    /**
     * @param string $string
     * @return string
     */
    public static function html_entity_decode(string $string): string //phpcs:ignore
    {
        return html_entity_decode($string);
    }

    /**
     * @param string $date
     * @param string $format
     * @return string
     */
    public static function date_format(string $date, string $format): string //phpcs:ignore
    {
        return date($format, strtotime($date));
    }

    /**
     * @param int|bool|string $bool
     * @return int
     */
    public static function inverse(int|bool|string $bool): int
    {
        /** @noinspection InArrayCanBeUsedInspection */
        if ($bool === 1 || $bool === true || $bool === '1') {
            return 0;
        }

        return 1;
    }

    /**
     * @param string $data
     * @return string
     */
    public static function base64_encode(string $data): string //phpcs:ignore
    {
        return base64_encode($data);
    }

    /**
     * @param object|array $data
     * @return string
     * @throws JsonException
     */
    public static function json_prettify(object|array $data): string //phpcs:ignore
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * @psalm-return array<string, array{0:class-string, 1:string}>
     * @return array<string, array{0:class-string, 1:string}>
     * @throws ReflectionException
     */
    private function getMethods(string $class): array
    {
        $ref = new ReflectionClass($class);
        $methods = array_filter(
            $ref->getMethods(),
            static fn ($m) => $m->getDeclaringClass()->getName() === $class && $m->isPublic() && $m->isStatic()
        );

        $res = [];

        array_walk($methods, static function ($method) use (&$res, $class) {
            $res[$method->getName()] = [$class, $method->getName()];
        });

        return $res;
    }
}
