<?php

declare(strict_types=1);

namespace Noirapi\Auth\Providers;

use Noirapi\Auth\Contracts\AuthProviderInterface;
use Noirapi\Helpers\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Magic-link (passwordless email) provider.
 *
 * Responsibility: sending the magic-link email only.
 * Token generation and verification live in the app-layer AuthGateway
 * (which has access to the database).
 */
class MagicLinkProvider implements AuthProviderInterface
{
    public function __construct(
        private readonly string $mailDsn,
        private readonly string $mailFrom,
        private readonly string $appUrl,
    ) {}

    public function getName(): string  { return 'magic_link'; }
    public function getLabel(): string { return 'Magic Link'; }
    public function getIcon(): string  { return 'bi-envelope-at'; }

    /**
     * Send the magic-link sign-in email.
     *
     * @throws RuntimeException | TransportExceptionInterface
     */
    public function sendEmail(
        string $toEmail,
        string $toName,
        string $token,
        int    $ttlMinutes = 15,
    ): void {
        $url  = rtrim($this->appUrl, '/') . '/auth/magic-link/' . $token;
        $mail = new Mail($this->mailDsn);
        $mail->new($this->mailFrom, $toEmail, 'Your sign-in link')
            ->setTemplate('magic-link', [
                'name'       => $toName,
                'magicUrl'   => $url,
                'ttlMinutes' => $ttlMinutes,
            ])
            ->send();
    }
}
