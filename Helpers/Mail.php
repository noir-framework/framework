<?php

/**
 * @noinspection TypoSafeNamingInspection
 * @noinspection TransitiveDependenciesUsageInspection
 * @noinspection PhpUnused
 * @noinspection PhpUndefinedClassInspection
 * @noinspection PhpUndefinedNamespaceInspection
 */

declare(strict_types=1);

namespace Noirapi\Helpers;

use Html2Text\Html2Text;
use Latte\Engine;
use Noirapi\Config;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

use function is_string;

/** @psalm-api */
class Mail
{
    private Email $message;
    private string $body = '';
    private string $error = '';
    private bool $debug;
    private string $debug_data = '';
    private string $dsn;

    public function __construct(string $dsn, bool $debug = false)
    {
        $this->debug = $debug;
        $this->message = new Email();
        $this->dsn = $dsn;
    }

    /**
     * @param string|array $from
     * @param array|string $to
     * @param string $subject
     * @return Mail
     */
    public function new(string|array $from, array|string $to, string $subject): self
    {
        if (is_string($from)) {
            $this->message->from($from);
        } else {
            $this->message->from(new Address($from[0], $from[1]));
        }

        if (is_string($to)) {
            $this->message->to($to);
        } else {
            foreach ($to as $address) {
                $this->message->addTo($address);
            }
        }

        $this->message->subject($subject);
        $this->message->priority(Email::PRIORITY_HIGHEST);

        return $this;
    }

    /**
     * @param array $cc
     * @return $this
     */
    public function setCC(array $cc): self
    {
        foreach ($cc as $address) {
            $this->message->addCc($address);
        }

        return $this;
    }

    /**
     * @param array $bcc
     * @return $this
     */
    public function setBCC(array $bcc): self
    {
        foreach ($bcc as $address) {
            $this->message->addBcc($address);
        }

        return $this;
    }

    /**
     * @param string $template
     * @param array $params
     * @return Mail
     */
    public function setTemplate(string $template, array $params): self
    {

        $file = Config::getAppRoot() . "/templates/$template.latte";
        if (! is_readable($file)) {
            throw new RuntimeException('Unable to load template: ' . $file);
        }

        $latte = new Engine();
        $latte->setCacheDirectory(Config::getTemp());
        $this->body = $latte->renderToString($file, $params);

        return $this;
    }

    /**
     * @param string $email
     * @return $this
     */
    public function setReplyTo(string $email): self
    {
        $this->message->replyTo($email);

        return $this;
    }

    /**
     * @param string $body
     * @return $this
     */
    public function setBody(string $body): self
    {

        $this->body = $body;

        return $this;
    }

    /**
     * @param string $key
     * @param string $value
     * @return Mail
     */
    public function addHeader(string $key, string $value): self
    {

        $this->message->getHeaders()->addTextHeader($key, $value);

        return $this;
    }

    /**
     * @param string $data
     * @param string $filename
     * @param string|null $mime_type
     * @return Mail
     */
    public function attach(string $data, string $filename, ?string $mime_type = null): self
    {

        $this->message->attach($data, $filename, $mime_type);

        return $this;
    }

    /**
     * @param string $file
     * @param string $name
     * @param string|null $mime_type
     * @return Mail
     */
    public function attachFile(string $file, string $name, ?string $mime_type = null): self
    {

        if (! is_readable($file)) {
            throw new RuntimeException("Unable to open $file");
        }

        $this->message->attachFromPath($file, $name, $mime_type);

        return $this;
    }

    /**
     * @param string $data
     * @param string $filename
     * @param string|null $mime_type
     * @return Mail
     */
    public function embed(string $data, string $filename, ?string $mime_type = null): self
    {

        $this->message->embed($data, $filename, $mime_type);

        return $this;
    }

    /**
     * @param string $file
     * @param string $name
     * @param string|null $mime_type
     * @return Mail
     */
    public function embedFile(string $file, string $name, ?string $mime_type = null): self
    {

        if (! is_readable($file)) {
            throw new RuntimeException("Unable to open $file");
        }

        $this->message->embedFromPath($file, $name, $mime_type);

        return $this;
    }

    /**
     * @return bool
     * @throws TransportExceptionInterface
     */
    public function send(): bool
    {

        $this->message->html($this->body);
        /** @noinspection PhpUndefinedClassInspection */
        $text = new Html2Text($this->body);

        $search = [
            '/^\t+/m',
            '/^\s+/m',
        ];

        $replace = [
            '',
            '',
        ];

        $this->message->text(preg_replace($search, $replace, $text->getText()) ?? '');

        // This is used for testing!!!
        if (str_starts_with($this->dsn, 'null://')) {
            $res = Transport::fromDsn($this->dsn)->send($this->message);
            /** @noinspection NullPointerExceptionInspection */
            $message_id = $res->getMessageId();

            file_put_contents(Config::getTemp() . "/mail-$message_id.eml", $this->message->toString());
            file_put_contents(Config::getTemp() . "/mail-$message_id.txt", $this->message->getTextBody());

            return true;
        }

        try {
            $res = Transport::fromDsn($this->dsn)->send($this->message);
        } catch (TransportExceptionInterface $e) {
            $this->error = $e->getMessage();
            $this->debug_data = $this->getDebug();

            return false;
        }

        if ($res === null) {
            $this->error = 'No response from server';

            return false;
        }

        if ($this->debug) {
            $this->debug_data = $res->getDebug();
        }

        return true;
    }

    /**
     * @return $this
     */
    public function noResponders(): self
    {
        $this->addHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

        return $this;
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return string
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * @return string
     * @noinspection GetSetMethodCorrectnessInspection
     */
    public function getDebug(): string
    {
        return $this->debug_data;
    }
}
