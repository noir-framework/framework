<?php

/**
 * @noinspection PhpUnused
 * @noinspection ContractViolationInspection
 * @noinspection UnknownInspectionInspection
 */

declare(strict_types=1);

namespace Noirapi\Helpers\Schema;

use Nette\Schema\Context;
use Nette\Schema\Message;
use Nette\Schema\Schema;
use Override;

/** @psalm-api  */
class Domain implements Schema
{
    private bool $wildcard = false;
    private bool $required = false;

    /**
     * @param bool $required
     *
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    /**
     * @param bool $wildcard
     *
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function wildcard(bool $wildcard = true): self
    {
        $this->wildcard = $wildcard;

        return $this;
    }

    /**
     * @param $value
     * @param Context $context
     * @return mixed|string|null
     * @psalm-suppress MissingParamType
     */
    #[Override]
    public function normalize($value, Context $context): mixed
    {

        $value = trim($value);

        if ($this->wildcard && $value[0] === '.') {
            $res = filter_var(substr($value, 1), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

            if ($res === false) {
                /** @noinspection UnusedFunctionResultInspection */
                $context->addError('The option %path% requires valid wildcard hostname', Message::PatternMismatch);

                return null;
            }

            return '.' . $res;
        }

        $res = filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

        if ($res === false) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The option %path% requires valid hostname', Message::PatternMismatch);

            return null;
        }

        return $res;
    }

    /**
     * @param $value
     * @param $base
     *
     * @return mixed
     *
     * @psalm-suppress MissingParamType
     *
     * @psalm-pure
     */
    #[Override]
    public function merge($value, $base): mixed
    {
        return $value;
    }

    /**
     * @param $value
     * @param Context $context
     *
     * @return mixed
     *
     * @psalm-suppress MissingParamType
     *
     * @psalm-pure
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
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The mandatory option %path% is missing.', Message::MissingItem);
        }

        return null;
    }
}
