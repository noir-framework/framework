<?php

declare(strict_types=1);

namespace Noirapi\Auth;

/**
 * Generic authentication result — provider-agnostic identity data.
 *
 * OAuthResult extends this class and adds provider-specific fields.
 * All auth flows (password, magic link, OAuth) produce one of these.
 */
class AuthResult
{
    /** 'password' | 'oauth' | 'magic_link' */
    public string  $method    = 'unknown';

    public ?string $email     = null;
    public ?string $name      = null;
    public ?string $avatarUrl = null;
}
