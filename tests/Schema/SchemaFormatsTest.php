<?php

declare(strict_types=1);

namespace Noirapi\Tests\Schema;

use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;
use Nette\Schema\ValidationException;
use Noirapi\Helpers\Schema\Date;
use Noirapi\Helpers\Schema\DateTime;
use Noirapi\Helpers\Schema\Ip;
use Noirapi\Helpers\Schema\Json;
use Noirapi\Helpers\Schema\Time;
use Noirapi\Helpers\Schema\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaFormatsTest extends TestCase
{
    private static function process(Schema $schema, mixed $value): mixed
    {
        return new Processor()->process(Expect::structure(['field' => $schema]), ['field' => $value])->field;
    }

    private static function fails(Schema $schema, mixed $value): bool
    {
        try {
            self::process($schema, $value);
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    /**
     * @return iterable<string, array{Schema, mixed, mixed}>
     */
    public static function valid(): iterable
    {
        yield 'ip v4' => [new Ip(), '192.168.1.1', '192.168.1.1'];
        yield 'ip v6' => [new Ip(), '::1', '::1'];
        yield 'ip to long' => [new Ip()->toLong(), '10.0.0.1', 167772161];
        yield 'ip from long' => [new Ip()->fromLong(), '167772161', '10.0.0.1'];
        yield 'ip to bin' => [new Ip()->toBin(), '10.0.0.1', "\x0a\x00\x00\x01"];
        yield 'url' => [new Url(), 'https://example.com/a?b=1', 'https://example.com/a?b=1'];
        yield 'url nullable' => [new Url()->nullable(), '', null];
        yield 'json nullable' => [new Json()->nullable(), '', null];
    }

    #[DataProvider('valid')]
    public function testValid(Schema $schema, mixed $value, mixed $expected): void
    {
        self::assertSame($expected, self::process($schema, $value));
    }

    /**
     * @return iterable<string, array{Schema, mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'ip' => [new Ip(), '999.1.1.1'];
        yield 'ip required' => [new Ip()->required(), ''];
        yield 'ip v6 to long' => [new Ip()->toLong(), '::1'];
        yield 'url scheme' => [new Url(), 'ftp://example.com'];
        yield 'url invalid' => [new Url(), 'http://'];
        yield 'url empty' => [new Url(), ''];
        yield 'json invalid' => [new Json(), '{nope'];
        yield 'json empty' => [new Json(), ''];
    }

    #[DataProvider('invalid')]
    public function testInvalid(Schema $schema, mixed $value): void
    {
        self::assertTrue(self::fails($schema, $value));
    }

    public function testJson(): void
    {
        $decoded = self::process(new Json(), '{"a": [1, 2]}');

        self::assertSame([1, 2], $decoded->a);
    }

    public function testDateAndTime(): void
    {
        $date = self::process(new Date(), '2024-02-29');
        self::assertInstanceOf(\DateTime::class, $date);
        self::assertSame('2024-02-29 00:00:00', $date->format('Y-m-d H:i:s'));

        self::assertSame('13:45:00', self::process(new Time(), '13:45:00')->format('H:i:s'));
        self::assertSame('2024-01-02 03:04:05', self::process(new DateTime(), '2024-01-02 03:04:05')->format('Y-m-d H:i:s')); //phpcs:ignore
        self::assertSame('02.01.2024', self::process(new DateTime('Y-m-d')->format('d.m.Y'), '2024-01-02'));
    }

    public function testImpossibleDatesAreRejected(): void
    {
        self::assertTrue(self::fails(new Date(), '2023-02-29'));
        self::assertTrue(self::fails(new Date(), '2024-13-01'));
        self::assertTrue(self::fails(new Date(), 'yesterday'));
        self::assertTrue(self::fails(new Time(), '25:00:00'));
    }
}
