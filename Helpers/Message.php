<?php

declare(strict_types=1);

namespace Noirapi\Helpers;

use Nette\Schema\ValidationException;

/** @psalm-api */
class Message
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public string      $message    = '',
        public MessageType $type       = MessageType::Info,
        public int         $timeout_ms = 5000,
        public bool        $html       = false,
    ) {}

    /**
     * @param string $message
     * @param string|MessageType|null $type
     *
     * @return Message
     *
     * @psalm-suppress MissingPureAnnotation Psalm and PHPStan disagree on the purity
     * of the Message constructor call; leaving unannotated satisfies both.
     */
    public static function new(string $message, string|MessageType|null $type = null): Message
    {
        $resolvedType = $type instanceof MessageType
            ? $type
            : (MessageType::tryFrom($type ?? '') ?? MessageType::Info);

        return new self($message, $resolvedType);
    }

    /**
     * @psalm-external-mutation-free
     * @psalm-suppress ImpureMethodCall self::new() is intentionally left without a
     * purity annotation - see the @psalm-suppress note on its declaration.
     */
    public static function fromSchema(ValidationException $e, ?string $type = null): Message
    {

        return self::new(SchemaHelper::message($e), $type);
    }


    /**
     * @param int $timeout_ms
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public function timeout(int $timeout_ms): Message
    {
        $this->timeout_ms = $timeout_ms;

        return $this;
    }

    /**
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function html(): Message
    {
        $this->html = true;

        return $this;
    }

    /**
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function primary(): Message
    {
        $this->type = MessageType::Primary;

        return $this;
    }

    /**
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function secondary(): Message
    {
        $this->type = MessageType::Secondary;

        return $this;
    }

    /**
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public function light(): Message
    {
        $this->type = MessageType::Light;

        return $this;
    }

    /**
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @psalm-external-mutation-free
     */
    public function dark(): Message
    {
        $this->type = MessageType::Dark;

        return $this;
    }

    /**
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function danger(): Message
    {
        $this->type = MessageType::Danger;

        return $this;
    }

    /**
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function success(): Message
    {
        $this->type = MessageType::Success;

        return $this;
    }

    /**
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function warning(): Message
    {
        $this->type = MessageType::Warning;

        return $this;
    }

    /**
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function info(): Message
    {
        $this->type = MessageType::Info;

        return $this;
    }
}
