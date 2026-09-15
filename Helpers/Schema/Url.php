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
class Url implements Schema
{
    private bool $required = false;
    private bool $nullable = false;
    private bool $https = false;

    /**
     * @param bool $state
     *
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function required(bool $state = true): self
    {
        $this->required = $state;

        return $this;
    }

    /**
     * @param bool $state
     *
     * @return $this
     *
     * @psalm-external-mutation-free
     */
    public function nullable(bool $state = true): self
    {
        $this->nullable = $state;

        return $this;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function require_https(bool $state = true): self //phpcs:ignore
    {
        $this->https = $state;

        return $this;
    }

    /**
     * @param $value
     * @param Context $context
     * @return false|mixed|null
     * @noinspection HttpUrlsUsage
     * @psalm-suppress MissingParamType
     */
    #[Override]
    public function normalize($value, Context $context)
    {

        if ($this->nullable && empty($value)) {
            return null;
        }

        if (! $this->nullable && empty($value)) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The option %path% requires valid url address', Message::PatternMismatch);

            return false;
        }

        if ($this->https) {
            if (! str_starts_with($value, 'https://')) {
                /** @noinspection UnusedFunctionResultInspection */
                $context->addError('The option %path% requires valid https:// url scheme', Message::TypeMismatch);

                return false;
            }
        } elseif (! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://')) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The option %path% requires valid http(s):// url scheme', Message::PatternMismatch);

            return false;
        }


        /** @noinspection BypassedUrlValidationInspection */
        $ret = filter_var($value, FILTER_VALIDATE_URL);

        if ($ret === false) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The option %path% requires valid url address', Message::PatternMismatch);

            return false;
        }

        return $ret;
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
    public function merge($value, $base)
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
    public function complete($value, Context $context)
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
