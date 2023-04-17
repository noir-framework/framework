<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 * @noinspection PhpUnused
 * @noinspection UnknownInspectionInspection
 * @noinspection ReturnTypeCanBeDeclaredInspection
 * @noinspection ContractViolationInspection
 */
declare(strict_types=1);

namespace noirapi\helpers\Schema;

use Nette\Schema\Context;
use Nette\Schema\Message;
use Nette\Schema\Schema;

class Url implements Schema {

    private bool $required = false;
    private bool $nullable = false;

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
     * @param $value
     * @param Context $context
     * @return false|mixed|null
     */
    public function normalize($value, Context $context)
    {

        if($this->nullable && empty($value)) {
            return null;
        }

        if(!$this->nullable && empty($value)) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError("The option %path% requires valid url address", Message::PATTERN_MISMATCH);
            return false;
        }

        /** @noinspection BypassedUrlValidationInspection */
        $ret = filter_var($value, FILTER_VALIDATE_URL);

        if($ret === false) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError("The option %path% requires valid url address", Message::PATTERN_MISMATCH);
            return false;
        }

        return $ret;

    }

    /**
     * @param $value
     * @param $base
     * @return mixed
     */
    public function merge($value, $base)
    {
        return $value;
    }

    /**
     * @param $value
     * @param Context $context
     * @return mixed
     */
    public function complete($value, Context $context)
    {
        return $value;
    }

    /** @noinspection ReturnTypeCanBeDeclaredInspection */
    public function completeDefault(Context $context)
    {
        if ($this->required) {
            /** @noinspection UnusedFunctionResultInspection */
            $context->addError('The mandatory option %path% is missing.', Message::MISSING_ITEM);
        }
        return null;
    }

}
