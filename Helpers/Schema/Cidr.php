<?php

/** @noinspection ContractViolationInspection */

declare(strict_types=1);

namespace Noirapi\Helpers\Schema;

use Nette\Schema\Context;
use Nette\Schema\Message;
use Nette\Schema\Schema;
use Override;

/** @psalm-api  */
class Cidr implements Schema
{
    private bool $required = false;
    private bool $nullable = false;
    private bool $multiple;

    /**
     * @psalm-mutation-free
     */
    public function __construct(bool $multiple)
    {
        $this->multiple = $multiple;
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
    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    /**
     * @param mixed $value
     * @param Context $context
     * @return mixed
     */
    #[Override]
    public function normalize(mixed $value, Context $context): mixed
    {

        if ($this->required && empty($value)) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The mandatory option %path% is empty.', Message::MissingItem);

            return null;
        }

        if ($this->nullable && empty($value)) {
            return null;
        }

        if ($this->multiple) {
            $parsed = preg_split('/(\r\n|\n|\r)/', $value);
            $cidrs = [];

            foreach ($parsed as $cidr) {
                if (empty(trim($cidr))) {
                    continue;
                }
                if (! $this->validateCidr($cidr)) {
                    /** @noinspection UnusedFunctionResultInspection */
                    $context->addError("Value: ($cidr) is not valid ipv4 or ipv6 cidr", Message::TypeMismatch);

                    return null;
                }
                $cidrs[] = $cidr;
            }

            return $cidrs;
        }

        if ($this->validateCidr($value)) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError("Value: ($value) is not valid ipv4 or ipv6 cidr", Message::TypeMismatch);

            return null;
        }

        return $value;
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


    /**
     * @param Context $context
     * @return null
     */
    #[Override]
    public function completeDefault(Context $context): null
    {
        if ($this->required) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The mandatory option %path% is missing.', Message::MissingItem);
        }

        return null;
    }

    /**
     * @param string $cidr
     *
     * @return bool
     *
     * @psalm-pure
     */
    private function validateCidr(string $cidr): bool
    {

        [$ip, $netmask] = explode('/', $cidr);

        if ($netmask < 0) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $netmask <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $netmask <= 128;
        }

        return false;
    }
}
