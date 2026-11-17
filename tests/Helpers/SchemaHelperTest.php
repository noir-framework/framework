<?php

declare(strict_types=1);

namespace Noirapi\Tests\Helpers;

use Nette\Schema\Expect;
use Nette\Schema\Message;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use Noirapi\Helpers\SchemaHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaHelper::class)]
final class SchemaHelperTest extends TestCase
{
    private static function failure(): ValidationException
    {
        try {
            new Processor()->process(
                Expect::structure(['email' => Expect::email()->required(), 'name' => Expect::string()->required()]),
                ['email' => 'x']
            );
        } catch (ValidationException $exception) {
            return $exception;
        }

        self::fail('validation passed');
    }

    public function testFieldAndCodeTexts(): void
    {
        SchemaHelper::set([
            'email'               => 'Enter a valid e-mail',
            Message::MissingItem  => ['name' => 'Name is required'],
            '_default'            => 'Check the form',
        ]);
        self::assertSame('Enter a valid e-mail', SchemaHelper::message(self::failure()));

        SchemaHelper::set([Message::MissingItem => ['name' => 'Name is required'], '_default' => 'Check the form']);
        self::assertSame('Name is required', SchemaHelper::message(self::failure()));
    }

    /** Regression: message() used array_shift() on the paths, so the second call returned _default. */
    public function testASecondCallGivesTheSameText(): void
    {
        SchemaHelper::set(['email' => 'Enter a valid e-mail', '_default' => 'Check the form']);
        $failure = self::failure();

        self::assertSame('Enter a valid e-mail', SchemaHelper::message($failure));
        self::assertSame('Enter a valid e-mail', SchemaHelper::message($failure));
        self::assertSame(['email'], $failure->getMessageObjects()[0]->path, 'the exception is not modified');
    }
}
