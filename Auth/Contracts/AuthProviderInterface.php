<?php

declare(strict_types=1);

namespace Noirapi\Auth\Contracts;

/**
 * Common interface for all authentication providers.
 * Implemented by OAuthProvider, MagicLinkProvider, EmailPasswordProvider, etc.
 */
interface AuthProviderInterface
{
    /** Internal key used in config and DB e.g. 'google', 'github', 'email' */
    public function getName(): string;

    /** Human-readable label shown on buttons  e.g. 'Google', 'GitHub' */
    public function getLabel(): string;

    /** Bootstrap Icons class for the button icon  e.g. 'bi-google', 'bi-github' */
    public function getIcon(): string;
}
