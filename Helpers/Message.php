<?php

declare(strict_types=1);

namespace Noirapi\Helpers;

use Nette\Schema\ValidationException;

/**
 * @psalm-api
 * @psalm-suppress MissingConstructor
 */
class Message
{
    public string $message;
    public MessageType $type = MessageType::Info;
    public int $timeout_ms = 5000;
    public bool $html = false;

    /**
     * @param string $message
     * @param string|MessageType|null $type
     * @return Message
     */
    public static function new(string $message, string|MessageType|null $type = null): Message
    {

        $static = new self();

        $static->message = $message;
        if ($type instanceof MessageType) {
            $static->type = $type;
        } elseif ($type !== null) {
            $static->type = MessageType::tryFrom($type) ?? MessageType::Info;
        }

        return $static;
    }

    public static function fromSchema(ValidationException $e, ?string $type = null): Message
    {

        return self::new(SchemaHelper::message($e), $type);
    }


    /**
     * @param int $timeout_ms
     * @return $this
     * @noinspection PhpUnused
     */
    public function timeout(int $timeout_ms): Message
    {
        $this->timeout_ms = $timeout_ms;

        return $this;
    }

    /**
     * @return $this
     */
    public function html(): Message
    {
        $this->html = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function primary(): Message
    {
        $this->type = MessageType::Primary;

        return $this;
    }

    /**
     * @return $this
     */
    public function secondary(): Message
    {
        $this->type = MessageType::Secondary;

        return $this;
    }

    /**
     * @return $this
     * @noinspection PhpUnused
     */
    public function light(): Message
    {
        $this->type = MessageType::Light;

        return $this;
    }

    /**
     * @return $this
     * @noinspection PhpUnused
     */
    public function dark(): Message
    {
        $this->type = MessageType::Dark;

        return $this;
    }

    /**
     * @return $this
     */
    public function danger(): Message
    {
        $this->type = MessageType::Danger;

        return $this;
    }

    /**
     * @return $this
     */
    public function success(): Message
    {
        $this->type = MessageType::Success;

        return $this;
    }

    /**
     * @return $this
     */
    public function warning(): Message
    {
        $this->type = MessageType::Warning;

        return $this;
    }

    /**
     * @return $this
     */
    public function info(): Message
    {
        $this->type = MessageType::Info;

        return $this;
    }
}
