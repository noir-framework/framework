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
    public ?AuthMethod $method    = null;
    public ?string     $email     = null;
    public ?string     $name      = null;
    public ?string     $avatarUrl = null;
}
