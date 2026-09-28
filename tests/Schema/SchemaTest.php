<?php

declare(strict_types=1);

namespace Noirapi\Tests\Schema;

use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;
use Nette\Schema\ValidationException;
use Noirapi\Helpers\Schema\Cidr;
use Noirapi\Helpers\Schema\Domain;
use Noirapi\Helpers\Schema\Numeric;
use Noirapi\Helpers\Schema\Port;
use Noirapi\Helpers\SchemaHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
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
        yield 'numeric' => [new Numeric(), '42', 42];
        yield 'numeric zero' => [new Numeric()->required(), '0', 0];
        yield 'numeric negative' => [new Numeric(), '-5', -5];
        yield 'numeric int' => [new Numeric()->min(1)->max(10), 10, 10];
        yield 'numeric nullable' => [new Numeric()->nullable(), '', null];
        yield 'port' => [new Port()->min(1)->max(65535), '8080', 8080];
        yield 'cidr v4' => [new Cidr(false), '10.0.0.0/8', '10.0.0.0/8'];
        yield 'cidr v6' => [new Cidr(false), ' 2001:db8::/32 ', '2001:db8::/32'];
        yield 'cidr multiple' => [new Cidr(true), "10.0.0.0/8\n\n192.168.1.0/24\r\n", ['10.0.0.0/8', '192.168.1.0/24']];
        yield 'cidr nullable' => [new Cidr(false)->nullable(), '', null];
        yield 'domain' => [new Domain(), ' example.com ', 'example.com'];
        yield 'domain wildcard' => [new Domain()->wildcard(), '.example.com', '.example.com'];
        yield 'domain empty' => [new Domain(), '', null];
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
        yield 'numeric letters' => [new Numeric(), 'abc'];
        yield 'numeric trailing letters' => [new Numeric(), '12abc'];
        yield 'numeric leading letters' => [new Numeric(), 'abc12'];
        yield 'numeric decimal' => [new Numeric(), '1.5'];
        yield 'numeric array' => [new Numeric(), ['1']];
        yield 'numeric below min' => [new Numeric()->min(5), '4'];
        yield 'numeric above max' => [new Numeric()->max(5), '6'];
        yield 'numeric required' => [new Numeric()->required(), ''];
        yield 'port letters' => [new Port(), '80abc'];
        yield 'port range' => [new Port()->min(1)->max(65535), '70000'];
        yield 'cidr no prefix' => [new Cidr(false), '10.0.0.1'];
        yield 'cidr prefix too long' => [new Cidr(false), '10.0.0.0/33'];
        yield 'cidr v6 prefix too long' => [new Cidr(false), '::/129'];
        yield 'cidr garbage' => [new Cidr(false), 'nope/8'];
        yield 'cidr multiple one bad' => [new Cidr(true), "10.0.0.0/8\nbad"];
        yield 'cidr required' => [new Cidr(false)->required(), ''];
        yield 'domain' => [new Domain(), 'not a domain'];
        yield 'domain required' => [new Domain()->required(), ''];
        yield 'domain wildcard off' => [new Domain(), '.example.com'];
    }

    #[DataProvider('invalid')]
    public function testInvalid(Schema $schema, mixed $value): void
    {
        self::assertTrue(self::fails($schema, $value));
    }

    public function testSchemaHelperMessages(): void
    {
        SchemaHelper::set(['field' => 'Please enter a number', '_default' => 'Invalid']);

        try {
            self::process(new Numeric(), 'abc');
            self::fail('expected a ValidationException');
        } catch (ValidationException $exception) {
            self::assertSame('Please enter a number', SchemaHelper::message($exception));
        } finally {
            SchemaHelper::set([]);
        }
    }
}
