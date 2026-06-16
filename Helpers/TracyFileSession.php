<?php

declare(strict_types=1);

namespace Noirapi\Helpers;

use Override;
use Random\RandomException;
use RuntimeException;
use Tracy\SessionStorage;

class TracyFileSession implements SessionStorage
{
    private const string FILE_PREFIX = 'sess_tracy_';
    private const int COOKIE_LIFETIME = 31_557_600;

    public string $cookieName = 'tracy-session';

    /** probability that the clean() routine is started */
    public float $gcProbability = 0.03;
    private string $dir;

    /** @var resource */
    private $file;
    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
    private array $data = [];

    public function __construct(string $dir)
    {
        $this->dir = $dir;
    }


    /**
     * @return bool
     * @throws RandomException
     */
    #[Override]
    public function isAvailable(): bool
    {
        if (! is_resource($this->file)) {
            $this->open();
        }

        return true;
    }

    /**
     * @return void
     * @throws RandomException
     */
    private function open(): void
    {
        $id = $_COOKIE[$this->cookieName] ?? null;
        if (
            !is_string($id)
            || !preg_match('#^\w{10}\z#i', $id)
            || !($file = @fopen($path = $this->dir . '/' . self::FILE_PREFIX . $id, 'r+b')) // intentionally @
        ) {
            $id = bin2hex(random_bytes(5));
            setcookie($this->cookieName, $id, time() + self::COOKIE_LIFETIME, '/', '', secure: false, httponly: true);

            $file = @fopen($path = $this->dir . '/' . self::FILE_PREFIX . $id, 'c+b'); // intentionally @
            if ($file === false) {
                throw new RuntimeException("Unable to create file '$path'. " . error_get_last()['message']);
            }
        }

        if (!@flock($file, LOCK_EX)) { // intentionally @
            throw new RuntimeException("Unable to acquire exclusive lock on '$path'. " . error_get_last()['message']);
        }

        $this->file = $file;
        $data = @unserialize(stream_get_contents($this->file), ['allowed_classes' => false]); // @ - file may be empty
        $this->data = empty($data) ? [] : $data;

        if (mt_rand() / mt_getrandmax() < $this->gcProbability) {
            $this->clean();
        }
    }


    #[Override]
    public function &getData(): array
    {
        return $this->data;
    }


    public function clean(): void
    {
        $old = strtotime('-1 week');
        foreach (glob($this->dir . '/' . self::FILE_PREFIX . '*') as $file) {
            if (filemtime($file) < $old) {
                unlink($file);
            }
        }
    }

    public function __destruct()
    {
        if (! is_resource($this->file)) {
            return;
        }

        ftruncate($this->file, 0);
        fseek($this->file, 0);
        fwrite($this->file, serialize($this->data));
        flock($this->file, LOCK_UN);
        fclose($this->file);
        $this->file = null;
    }
}
