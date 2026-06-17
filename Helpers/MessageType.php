<?php

declare(strict_types=1);

namespace Noirapi\Helpers;

enum MessageType: string
{
    case Success   = 'success';
    case Danger    = 'danger';
    case Warning   = 'warning';
    case Info      = 'info';
    case Primary   = 'primary';
    case Secondary = 'secondary';
    case Light     = 'light';
    case Dark      = 'dark';
}
