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

use JsonException;
use Nette\Schema\Context;
use Nette\Schema\Message;
use Nette\Schema\Schema;
use Override;

/** @psalm-api  */
class Json implements Schema
{
    private bool $required = false;
    private bool $nullable = false;

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
     * @param $value
     * @param Context $context
     * @return false|mixed|null
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
            $context->addError('The option %path% requires valid json', Message::PatternMismatch);

            return false;
        }

        try {
            $ret = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The option %path% requires valid json. ' . $e->getMessage(), Message::PatternMismatch);

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


    /**
     * @param Context $context
     * @return null
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
