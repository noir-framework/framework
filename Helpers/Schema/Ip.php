<?php

/**
 * @noinspection PhpMissingReturnTypeInspection
 * @noinspection PhpUnused
 * @noinspection UnknownInspectionInspection
 * @noinspection ReturnTypeCanBeDeclaredInspection
 * @noinspection ContractViolationInspection
 */

declare(strict_types=1);

namespace Noirapi\Helpers\Schema;

use Nette\Schema\Context;
use Nette\Schema\Message;
use Nette\Schema\Schema;
use Override;

/** @psalm-api  */
class Ip implements Schema
{
    private bool $required = false;
    private bool $nullable = false;
    private string $from = 'string';
    private string $to = 'string';

    /**
     * @psalm-external-mutation-free
     */
    public function fromBin(): self
    {
        $this->from = 'bin';

        return $this;
    }

    /**
     * @return self
     *
     * @psalm-external-mutation-free
     */
    public function fromString(): self
    {
        $this->from = 'string';

        return $this;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function fromLong(): self
    {
        $this->from = 'long';

        return $this;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function toBin(): self
    {
        $this->to = 'bin';

        return $this;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function toString(): self
    {
        $this->to = 'string';

        return $this;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function toLong(): self
    {
        $this->to = 'long';

        return $this;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function required(bool $state = true): self
    {
        $this->required = $state;

        return $this;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function nullable(bool $state = true): self
    {
        $this->nullable = $state;

        return $this;
    }

    /**
     * @param mixed $value
     * @param Context $context
     * @return int|string|null
     */
    #[Override]
    public function normalize(mixed $value, Context $context)
    {

        // '0' is a valid long ip address
        /** @noinspection TypeUnsafeComparisonInspection */
        if ($this->nullable && (empty($value) && $value != '0')) {
            return null;
        }

        /** @noinspection TypeUnsafeComparisonInspection */
        if ($this->required && (empty($value) && $value != '0')) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The mandatory option %path% is empty.', Message::MissingItem);

            return null;
        }

        switch ($this->from) {
            case 'string':
                $from = $value;

                break;

            case 'long':
                if (! ctype_digit($value)) {
                    /** @noinspection UnusedFunctionResultInspection */
                    $context->addError('The option %path% is not valid integer.', Message::TypeMismatch);

                    return null;
                }

                $value = (int) $value;
                $from = long2ip($value);
                if ($value !== ip2long($from)) {
                    /** @noinspection UnusedFunctionResultInspection */
                    $context->addError('The option %path% is not valid (long) ipv4 address.', Message::TypeMismatch);

                    return null;
                }

                break;

            case 'bin':
                $from = inet_ntop($value);
                if ($value !== inet_pton($from)) {
                    /** @noinspection UnusedFunctionResultInspection */
                    $context->addError('The option %path% is not valid (binary) ip address.', Message::TypeMismatch);

                    return null;
                }

                break;
        }

        if (empty($from)) {
            /** @noinspection UnusedFunctionResultInspection */
            /** @noinspection PhpUndefinedVariableInspection */
            $context->addError("The option %path% expects valid ip address. ('$from') given", Message::TypeMismatch);

            return null;
        }

        if (! filter_var($from, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError("The option %path% expects valid ip (ipv4|ipv6) address. ('$from') given", Message::TypeMismatch); //phpcs:ignore

            return null;
        }

        switch ($this->to) {
            case 'string':
                $to = $from;

                break;

            case 'long':
                if (str_contains($from, ':')) {
                    /** @noinspection UnusedFunctionResultInspection */
                    $context->addError('The option %path% unable to convert ipv6 address to long', Message::TypeMismatch); //phpcs:ignore

                    return null;
                }
                $to = ip2long($from);

                break;

            case 'bin':
                $to = inet_pton($from);

                break;
        }

        /**
         * @noinspection PhpUndefinedVariableInspection
         * @phpstan-ignore-next-line
         */
        if (empty($to) && $to !== 0) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The option %path% unable to produce valid ip address', Message::TypeMismatch);

            return null;
        }

        return is_string($to) || is_int($to) ? $to : null;
    }

    /**
     * @param mixed $value
     * @param mixed $base
     *
     * @return mixed
     *
     * @psalm-pure
     */
    #[Override]
    public function merge(mixed $value, mixed $base): mixed
    {
        return $value;
    }

    /**
     * @param mixed $value
     * @param Context $context
     *
     * @return mixed
     *
     * @psalm-pure
     */
    #[Override]
    public function complete(mixed $value, Context $context): mixed
    {
        return $value;
    }

    /** @noinspection ReturnTypeCanBeDeclaredInspection */
    #[Override]
    public function completeDefault(Context $context)
    {
        if ($this->required) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The mandatory option %path% is missing.', Message::MissingItem);
        }

        return null;
    }
}
