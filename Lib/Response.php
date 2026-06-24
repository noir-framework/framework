<?php

/**
 * @noinspection UnknownInspectionInspection
 * @noinspection PhpUndefinedClassInspection
 * @noinspection PhpUndefinedNamespaceInspection
 * @noinspection PhpUnused
 */

declare(strict_types=1);

namespace Noirapi\Lib;

use Exception;
use JsonException;
use LaLit\Array2XML;
use Noirapi\Helpers\RestMessage;
use RuntimeException;
use SimpleXMLElement;
use stdClass;

use function gettype;
use function is_array;
use function is_callable;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;

class Response
{
    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
    private int $status = 200;
    private int $csv_maxmem = 1024 * 1024; //1 MB
    private string|array|object $body = '';
    private string $contentType = self::TYPE_HTML;
    private string $xml_root = '<root/>';
    private array $headers = [];
    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
    private array $cookies = [];
    private array $headerCallback = [];
    private bool $csv_header = true;

    public const string TYPE_JSON = 'application/json';
    public const string TYPE_XML = 'text/xml';
    public const string TYPE_TEXT = 'text/plain';
    public const string TYPE_HTML = 'text/html; charset=utf-8';
    public const string TYPE_PDF = 'application/pdf';
    public const string TYPE_CSV = 'text/csv';
    public const string TYPE_CSS = 'text/css';
    public const string TYPE_JS = 'text/javascript';
    public const string TYPE_RAW = 'application/octet-stream';
    public const string TYPE_ZIP = 'application/zip';

    /** @psalm-suppress PossiblyUnusedProperty */
    public ?string $initiator_class = null;
    /** @psalm-suppress PossiblyUnusedProperty */
    public ?string $initiator_method = null;
    public ?int $initiator_line = null;
    public string $csv_separator = ",";
    public string $csv_enclosure = "\"";
    public string $csv_escape = "\\";
    /** @psalm-suppress PossiblyUnusedProperty */
    public string $csv_eol = "\n";
    public string $csv_utf8_bom = "\xEF\xBB\xBF";

    /**
     * @param mixed $body
     * @return $this
     */
    public function setBody(mixed $body): Response
    {
        if ($body === null) {
            $body = '';
        } elseif (is_float($body) || is_int($body)) {
            $body = (string)$body;
        } elseif ($body === true) {
            $body = 'true';
        } elseif ($body === false) {
            $body = 'false';
        } elseif (is_resource($body)) {
            throw new RuntimeException('Invalid body type: resource');
        } elseif (is_callable($body)) {
            throw new RuntimeException('Invalid body type: callable');
        }

        $this->body = $body;

        return $this;
    }


    /**
     * @param mixed $body
     * @return $this
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function appendBody(mixed $body): Response
    {
        if (gettype($body) !== gettype($this->body)) {
            throw new RuntimeException('Invalid body type: ' . gettype($body) . ' for response->body type: ' . gettype($this->body)); //phpcs:ignore
        }

        if (is_array($this->body)) {
            $this->body = array_merge($this->body, $body);

            return $this;
        }

        if (is_object($this->body)) {
            $this->body = $this->objectMerge($this->body, $body);

            return $this;
        }

        $this->body .= $body;

        return $this;
    }

    /**
     * @return string
     * @throws JsonException
     * @throws RuntimeException
     * @throws Exception
     * @noinspection PhpUndefinedClassInspection
     */
    public function getBody(): string
    {
        if (is_string($this->body)) {
            return $this->body;
        }

        if (is_object($this->body)) {
            if (method_exists($this->body, '__toString')) {
                return $this->body->__toString();
            }

            if (method_exists($this->body, 'toJson')) {
                return $this->body->toJson();
            }

            if (method_exists($this->body, 'toArray')) {
                $this->body = $this->body->toArray();
            } else {
                $this->body = $this->object2array($this->body);
            }
        }

        if ($this->contentType === self::TYPE_HTML && is_array($this->body)) {
            return implode(PHP_EOL, $this->body);
        }

        if ($this->contentType === self::TYPE_JSON) {
            return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        }

        if ($this->contentType === self::TYPE_XML) {
            if (class_exists(Array2XML::class)) {
                return Array2XML::createXML($this->xml_root, $this->body)->saveXML();
            }

            return $this->array2xml($this->body)->saveXML();
        }

        if ($this->contentType === self::TYPE_CSV) {
            return $this->toCsv($this->body);
        }

        throw new RuntimeException('Invalid body type: ' . gettype($this->body) . ' for content type: ' . $this->contentType); //phpcs:ignore
    }

    /**
     * @return string|array|object
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getRawBody(): string|array|object
    {
        return $this->body;
    }

    /**
     * @return RestMessage
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getRestMessage(): RestMessage
    {
        if ($this->body instanceof RestMessage) {
            return $this->body;
        }

        throw new RuntimeException('Response body is not a RestMessage');
    }

    /**
     * @param int $status
     * @return $this
     */
    public function withStatus(int $status): Response
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @param string $contentType
     * @return $this
     */
    public function setContentType(string $contentType): Response
    {
        $this->contentType = $contentType;
        $this->addHeader('Content-Type', $contentType);

        return $this;
    }

    /**
     * @return string
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * @param string $location
     * @return $this
     */
    public function withLocation(string $location): Response
    {
        $this->headers['Location'] = $location;

        return $this;
    }

    /**
     * @return string|null
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getLocation(): ?string
    {
        return $this->headers['Location'] ?? null;
    }

    /**
     * @param string $key
     * @param string $value
     * @return $this
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function addHeader(string $key, string $value): Response
    {
        $this->headers[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     * @return $this
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function removeHeader(string $key): Response
    {
        unset($this->headers[$key]);

        return $this;
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        $headers = array_merge([], $this->headers);

        foreach ($this->headerCallback as $callback) {
            $res = $callback($this);
            if (is_array($res)) {
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $headers = array_merge($headers, $res);
            }
        }

        return $headers;
    }

    /**
     * @param string $filename
     * @return $this
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function downloadFile(string $filename): Response
    {
        $this->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $this;
    }

    /**
     * @param string $filename
     * @return $this
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function inlineFile(string $filename): Response
    {
        $this->addHeader('Content-Disposition', 'inline; filename="' . $filename . '"');
        $this->addHeader('Content-Transfer-Encoding', 'binary');

        return $this;
    }


    /**
     * @param string $key
     * @param string $value
     * @param int $expire
     * @return $this
     * $expire is the max date in the future supported by php
     */
    public function addCookie(string $key, string $value, int $expire = 2147483647): Response
    {
        $this->cookies[$key] = [
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'strict',
        ];

        return $this;
    }

    /**
     * @param string $key
     * @return $this
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function clearCookie(string $key): Response
    {
        $this->addCookie($key, '', 0);

        return $this;
    }

    /**
     * @return array
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * @param callable $callback
     * @return $this
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function addHeaderCallback(callable $callback): Response
    {
        $this->headerCallback[] = $callback;

        return $this;
    }

    /**
     * @param string $root
     * @return $this
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function setXmlRoot(string $root): Response
    {
        $this->xml_root = $root;

        return $this;
    }

    /**
     * @return $this
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function disableCsvHeader(): Response
    {
        $this->csv_header = false;

        return $this;
    }

    /**
     * @param object $class1
     * @param object $class2
     * @return stdClass
     */
    private function objectMerge(object $class1, object $class2): stdClass
    {
        $object = new stdClass();

        foreach ($class1 as $key => $value) {
            if (is_object($value)) {
                $object->$key = $this->objectMerge($object->$key, $value);
            } else {
                $object->$key = $value;
            }
        }

        foreach ($class2 as $key => $value) {
            if (is_object($value)) {
                $object->$key = $this->objectMerge($object->$key, $value);
            } else {
                $object->$key = $value;
            }
        }

        return $object;
    }

    /**
     * @param array|object $data
     * @return string
     */
    private function toCsv(array|object $data): string
    {
        $csv = $this->csv_utf8_bom;
        $written = 0;

        $fh = fopen('php://temp', 'rwb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open php://temp for CSV generation');
        }

        if ($this->csv_header) {
            fputcsv($fh, array_keys(current((array)$data)), $this->csv_separator, $this->csv_enclosure, $this->csv_escape); //phpcs:ignore
        }

        foreach ($data as $key => $row) {
            $w = fputcsv($fh, (array)$row, $this->csv_separator, $this->csv_enclosure, $this->csv_escape);
            if ($w === false) {
                $error = error_get_last();

                throw new RuntimeException('fputcsv failed on key: ' . $key . ' with error: ' . ($error === null ? 'unknown' : $error['message'])); //phpcs:ignore
            }
            $written += $w;
            if ($written > $this->csv_maxmem) {
                rewind($fh);
                $csv .= stream_get_contents($fh);
                $written = 0;
                ftruncate($fh, 0);
                // Important to avoid memory leaks
                rewind($fh);
            }
        }

        rewind($fh);

        $csv .= stream_get_contents($fh);

        if (empty($csv)) {
            throw new RuntimeException('no csv data found');
        }

        fclose($fh);

        return $csv;
    }

    /**
     * @param array $array
     * @param SimpleXMLElement|null $xml
     * @return SimpleXMLElement
     * @throws Exception
     */
    private function array2xml(array $array, ?SimpleXMLElement $xml = null): SimpleXMLElement
    {
        $xml ?? ($xml = new SimpleXMLElement($this->xml_root));

        foreach ($array as $k => $v) {
            is_array($v)
                ? $this->array2xml($v, $xml->addChild($k))
                : $xml->addChild($k, $v);
        }

        return $xml;
    }

    /**
     * At last this will return an array
     * @param object|array $object
     * @return array
     */
    private function object2array(mixed $object): mixed
    {

        return (is_scalar($object) || is_null($object))
            ? $object
            : array_map([$this, 'object2array'], (array)$object);
    }
}
