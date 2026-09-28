<?php

declare(strict_types=1);

namespace Noirapi\Lib\Attributes;

use Attribute;

/**
 * Exempts a controller action (or every action of a controller class) from the CSRF check,
 * for endpoints that must accept foreign POSTs: webhooks, token-authenticated APIs.
 *
 * @psalm-api
 * @psalm-immutable
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class SkipCsrf
{
}
