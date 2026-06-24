<?php

/**
 * @noinspection PhpUnused
 * @noinspection UnusedFunctionResultInspection
 * @noinspection ContractViolationInspection
 */

declare(strict_types=1);

namespace Noirapi\Helpers\Schema;

use DateTimeZone;
use Exception;
use Nette\Schema\Context;
use Nette\Schema\Message;
use Nette\Schema\Schema;
use Override;

use function gettype;
use function is_string;

/** @psalm-api  */
class DateTime implements Schema
{
    protected bool $required = false;
    protected string $name;
    protected bool $nullable = false;
    protected string $format;
    protected ?DateTimeZone $timeZone;
    /** @psalm-suppress PropertyNotSetInConstructor  */
    protected string $output_format;

    protected \DateTime|null $date = null;
    protected \DateTime|null $time = null;
    protected ?string $default = null;

    /**
     * @param string $format
     * @param DateTimeZone|null $timeZone
     * @param string $name
     * @throws Exception
     */
    public function __construct(string $format = 'Y-m-d H:i:s', ?DateTimeZone $timeZone = null, string $name = 'DateTime') //phpcs:ignore
    {
        $this->format = $format;
        $this->timeZone = $timeZone ?? new DateTimeZone(date_default_timezone_get());
        $this->name = $name;
    }

    public function setTime(string $time, string $format): self
    {
        $this->time = \DateTime::createFromFormat($format, $time, $this->timeZone);

        return $this;
    }

    /**
     * @param string $date
     * @param string $format
     * @return $this
     */
    public function setDate(string $date, string $format): self
    {
        $this->date = \DateTime::createFromFormat($format, $date, $this->timeZone);

        return $this;
    }

    /**
     * @param string $value date string in the schema's own format
     * @return $this
     */
    public function default(string $value): self
    {
        $this->default = $value;

        return $this;
    }

    /**
     * @param bool $state
     * @return $this
     */
    public function required(bool $state = true): self
    {
        $this->required = $state;

        return $this;
    }

    /**
     * @param bool $state
     * @return $this
     */
    public function nullable(bool $state = true): self
    {
        $this->nullable = $state;

        return $this;
    }

    /**
     * @param string $format
     * @return $this
     */
    public function format(string $format): self
    {
        $this->output_format = $format;

        return $this;
    }

    /**
     * @param mixed $value
     * @param Context $context
     * @return string|\DateTime|null
     * @psalm-suppress MissingParamType
     */
    #[Override]
    public function normalize(mixed $value, Context $context): string|null|\DateTime
    {

        if ($this->nullable && empty($value)) {
            return null;
        }

        if (! is_string($value) && empty($value)) {
            $type = gettype($value);
            $context->addError("The option %path% expects $this->name($this->format), $type($value) given.", Message::PatternMismatch); //phpcs:ignore

            return null;
        }

        if (! $this->nullable && $value === '') {
            $context->addError("The option %path% expects not-nullable $this->name, nothing given.", Message::PatternMismatch); //phpcs:ignore

            return null;
        }

        $normalized = null;

        if ($value !== '') {
            $normalized = \DateTime::createFromFormat($this->format, $value, $this->timeZone);
        }

        if (! empty($this->date)) {
            if (! empty($normalized)) {
                $normalized->setDate((int)$this->date->format('Y'), (int)$this->date->format('m'), (int)$this->date->format('d')); //phpcs:ignore
            } else {
                $normalized = $this->date;
            }
        }

        if (isset($this->time)) {
            if (! empty($normalized)) {
                $normalized->setTime((int)$this->time->format('H'), (int)$this->time->format('i'), (int)$this->time->format('s')); //phpcs:ignore
            } else {
                $normalized = $this->time;
            }
        }

        if (empty($normalized)) {
            $context->addError("The option %path% expects $this->name to match pattern '$this->format', '$value' given.", Message::PatternMismatch); //phpcs:ignore

            return null;
        }

        if (! empty($this->output_format)) {
            return $normalized->format($this->output_format);
        }

        return $normalized;
    }

    /**
     * @param $value
     * @param $base
     * @return mixed
     * @psalm-suppress MissingParamType
     */
    #[Override]
    public function merge($value, $base): mixed
    {
        return $value;
    }

    /**
     * @param $value
     * @param Context $context
     * @return mixed
     * @psalm-suppress MissingParamType
     */
    #[Override]
    public function complete($value, Context $context): mixed
    {
        return $value;
    }

    /**
     * @noinspection ReturnTypeCanBeDeclaredInspection
     * @noinspection PhpMissingReturnTypeInspection
     */
    #[Override]
    public function completeDefault(Context $context)
    {
        if ($this->required) {
            $context->addError('The mandatory option %path% is missing.', Message::MissingItem);

            return null;
        }

        if ($this->default !== null) {
            return $this->normalize($this->default, $context);
        }

        return null;
    }
}
