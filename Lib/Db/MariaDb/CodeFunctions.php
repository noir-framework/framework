<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * MariaDB's encoding functions for SQLite (docs/sqlite-mariadb.md): MD5(), SHA1()/SHA(), SHA2(), HEX() (a number's
 * hexadecimal, a text's bytes; SQLite's hex() takes a number as its text), UNHEX(), TO_BASE64() (lines of 76),
 * FROM_BASE64(), ASCII(), ORD().
 *
 * @psalm-api
 */
final class CodeFunctions
{
    private const array SHA2 = [0 => 'sha256', 224 => 'sha224', 256 => 'sha256', 384 => 'sha384', 512 => 'sha512'];

    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     * @psalm-pure
     */
    public static function definitions(): array
    {
        return [
            'MD5' => [self::md5(...), [1], true],
            'SHA1' => [self::sha1(...), [1], true],
            'SHA' => [self::sha1(...), [1], true],
            'SHA2' => [self::sha2(...), [2], true],
            'HEX' => [self::hex(...), [1], true],
            'UNHEX' => [self::unhex(...), [1], true],
            'TO_BASE64' => [self::toBase64(...), [1], true],
            'FROM_BASE64' => [self::fromBase64(...), [1], true],
            'ASCII' => [self::ascii(...), [1], true],
            'ORD' => [self::ord(...), [1], true],
        ];
    }

    /** @psalm-pure */
    private static function md5(mixed $value): ?string
    {
        $text = Values::text($value);

        return $text === null ? null : md5($text);
    }

    /** @psalm-pure */
    private static function sha1(mixed $value): ?string
    {
        $text = Values::text($value);

        return $text === null ? null : sha1($text);
    }

    /** @psalm-pure */
    private static function sha2(mixed $value, mixed $bits): ?string
    {
        $text = Values::text($value);
        $algorithm = is_numeric($bits) ? (self::SHA2[(int) $bits] ?? null) : null;

        return $text === null || $algorithm === null ? null : hash($algorithm, $text);
    }

    /** @psalm-pure */
    private static function hex(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return strtoupper(dechex((int) round($value)));
        }
        $text = Values::text($value);

        return $text === null ? null : strtoupper(bin2hex($text));
    }

    /** @psalm-pure */
    private static function unhex(mixed $value): ?string
    {
        $text = Values::text($value);
        if ($text === null || preg_match('/^[0-9a-fA-F]*$/', $text) !== 1) {
            return null;
        }
        $bytes = hex2bin(strlen($text) % 2 === 1 ? '0' . $text : $text);

        return $bytes === false ? null : $bytes;
    }

    /** @psalm-pure */
    private static function toBase64(mixed $value): ?string
    {
        $text = Values::text($value);

        return $text === null ? null : rtrim(chunk_split(base64_encode($text), 76, "\n"), "\n");
    }

    /** @psalm-pure */
    private static function fromBase64(mixed $value): ?string
    {
        $text = Values::text($value);
        $bytes = $text === null ? false : base64_decode(preg_replace('/\s+/', '', $text) ?? '', true);

        return $bytes === false ? null : $bytes;
    }

    /** @psalm-pure */
    private static function ascii(mixed $value): ?int
    {
        $text = Values::text($value);

        return $text === null ? null : ($text === '' ? 0 : ord($text[0]));
    }

    /**
     * ORD(): the first character's bytes as one number (MariaDB's multi-byte rule).
     *
     * @psalm-pure
     */
    private static function ord(mixed $value): ?int
    {
        $text = Values::text($value);
        if ($text === null || $text === '') {
            return $text === null ? null : 0;
        }
        $code = 0;
        foreach (str_split(mb_substr($text, 0, 1)) as $byte) {
            $code = $code * 256 + ord($byte);
        }

        return $code;
    }
}
