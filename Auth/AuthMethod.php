<?php

declare(strict_types=1);

namespace Noirapi\Auth;

enum AuthMethod: string
{
    case Password  = 'password';
    case OAuth     = 'oauth';
    case MagicLink = 'magic_link';
}
