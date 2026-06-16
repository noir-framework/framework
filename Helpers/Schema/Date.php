<?php

/** @noinspection ContractViolationInspection */

declare(strict_types=1);

namespace Noirapi\Helpers\Schema;

use DateTimeZone;
use Nette\Schema\Context;
use Override;

/** @psalm-api  */
class Date extends DateTime
{
    public function __construct(string $format = 'Y-m-d', ?DateTimeZone $timeZone = null)
    {
        parent::__construct($format, $timeZone, 'date');
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
        $result = parent::normalize($value, $context);

        // Reset time to midnight when no explicit setTime() override was given,
        // because createFromFormat('Y-m-d', ...) inherits the current wall-clock time.
        if ($result instanceof \DateTime && $this->time === null) {
            $result->setTime(0, 0, 0);
        }

        return $result;
    }
}
