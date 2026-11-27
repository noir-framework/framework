<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * Text compared as MariaDB's utf8mb4_general_ci does it: case-insensitive in every script (mb_strtolower), Latin
 * letters without their accents (é = e, ß = s), trailing spaces ignored in comparisons (PAD SPACE, not in LIKE).
 * Accented letters beyond Latin-1 and Latin Extended-A keep their accents.
 *
 * @psalm-api
 */
final class Collation
{
    /** lowercase accented Latin letters => their base letter (generated from Unicode's decompositions) */
    private const array ACCENTS = [
        'à' => 'a',
        'á' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'ä' => 'a',
        'å' => 'a',
        'ç' => 'c',
        'è' => 'e',
        'é' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'ì' => 'i',
        'í' => 'i',
        'î' => 'i',
        'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ö' => 'o',
        'ù' => 'u',
        'ú' => 'u',
        'û' => 'u',
        'ü' => 'u',
        'ý' => 'y',
        'ÿ' => 'y',
        'ā' => 'a',
        'ă' => 'a',
        'ą' => 'a',
        'ć' => 'c',
        'ĉ' => 'c',
        'ċ' => 'c',
        'č' => 'c',
        'ď' => 'd',
        'ē' => 'e',
        'ĕ' => 'e',
        'ė' => 'e',
        'ę' => 'e',
        'ě' => 'e',
        'ĝ' => 'g',
        'ğ' => 'g',
        'ġ' => 'g',
        'ģ' => 'g',
        'ĥ' => 'h',
        'ĩ' => 'i',
        'ī' => 'i',
        'ĭ' => 'i',
        'į' => 'i',
        'ĵ' => 'j',
        'ķ' => 'k',
        'ĺ' => 'l',
        'ļ' => 'l',
        'ľ' => 'l',
        'ń' => 'n',
        'ņ' => 'n',
        'ň' => 'n',
        'ō' => 'o',
        'ŏ' => 'o',
        'ő' => 'o',
        'ŕ' => 'r',
        'ŗ' => 'r',
        'ř' => 'r',
        'ś' => 's',
        'ŝ' => 's',
        'ş' => 's',
        'š' => 's',
        'ţ' => 't',
        'ť' => 't',
        'ũ' => 'u',
        'ū' => 'u',
        'ŭ' => 'u',
        'ů' => 'u',
        'ű' => 'u',
        'ų' => 'u',
        'ŵ' => 'w',
        'ŷ' => 'y',
        'ź' => 'z',
        'ż' => 'z',
        'ž' => 'z',
        'ß' => 's',
    ];

    /**
     * @psalm-pure
     */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
    }

    /**
     * -1, 0 or 1, as MariaDB orders two strings.
     *
     * @psalm-pure
     */
    public static function compare(string $first, string $second): int
    {
        return strcmp(rtrim(self::fold($first), ' '), rtrim(self::fold($second), ' ')) <=> 0;
    }
}
