<?php

/*
 * MariaDB string, logic and pattern expressions whose answers on SQLite (MariaDB dialect) must equal MariaDB's own,
 * recorded in expected-strings.json by MariaDbParityTest with utf8mb4_general_ci (MariaDB's common default). Literals
 * are written as MariaDB reads them: backslash escapes, double-quoted strings.
 */

declare(strict_types=1);

return [
    // literals and operators
    "'it\\'s'", "'a\\\\b'", "'tab\\there'", "'100\\%'", "\"double\"", "'say ''hi'''", "\"it's\"", "_utf8mb4'x'",
    "1 <=> 1", "1 <=> NULL", "NULL <=> NULL", "1 || 0", "0 || 0", "1 && 1", "1 && 0", "'x' || 'y'",
    // CONCAT & co.
    "CONCAT('a', 'b', 'c')", "CONCAT('a', NULL)", "CONCAT('a', 1, 2.5)", "CONCAT()", "CONCAT_WS(',', 'a', NULL, 'b')",
    "CONCAT_WS(NULL, 'a', 'b')", "CONCAT_WS('-', 1, 2)",
    "LEFT('héllo', 2)", "LEFT('abc', 0)", "LEFT('abc', -1)", "RIGHT('héllo', 3)", "RIGHT('abc', 10)",
    "LPAD('hi', 5, 'xy')", "LPAD('hello', 3, 'x')", "LPAD('hi', 5, '')", "RPAD('hi', 5, '.')", "RPAD('é', 3, 'ü')",
    "REPEAT('ab', 3)", "REPEAT('ab', 0)", "REPEAT('ab', -1)", "SPACE(3)", "REVERSE('héllo')",
    "LOWER('ÀÉÎ ИВАН')", "UPPER('àéî иван')", "LCASE('ABC')", "UCASE('abc')",
    "LENGTH('héllo')", "LENGTH(123)", "CHAR_LENGTH('héllo')", "CHARACTER_LENGTH('иван')", "OCTET_LENGTH('é')",
    "FORMAT(1234567.891, 2)", "FORMAT(1234.5, 0)", "FORMAT(-1234.565, 2)", "FORMAT(0.5, 0)", "FORMAT(1000, 3)",
    // search
    "SUBSTRING('héllo', 2)", "SUBSTRING('héllo', 2, 3)", "SUBSTRING('héllo', -3)", "SUBSTRING('héllo', -3, 2)",
    "SUBSTRING('héllo', 0)", "SUBSTRING('héllo', 10)", "SUBSTRING('héllo', 2, 0)", "SUBSTR('abc', 2)", "MID('abcdef', 3, 2)",
    "SUBSTRING_INDEX('www.example.com', '.', 2)", "SUBSTRING_INDEX('www.example.com', '.', -2)",
    "SUBSTRING_INDEX('a.b', '.', 0)", "SUBSTRING_INDEX('a.b', 'x', 1)",
    "LOCATE('b', 'abcb')", "LOCATE('B', 'abcb')", "LOCATE('b', 'abcb', 3)", "LOCATE('z', 'abc')", "LOCATE('', 'abc')",
    "LOCATE('é', 'café')", "INSTR('foobar', 'BAR')", "INSTR('foobar', 'x')",
    "FIND_IN_SET('b', 'a,b,c')", "FIND_IN_SET('B', 'a,b,c')", "FIND_IN_SET('d', 'a,b,c')", "FIND_IN_SET('a,b', 'a,b,c')",
    "FIND_IN_SET('', 'a,,c')", "FIND_IN_SET(NULL, 'a')",
    "STRCMP('a', 'b')", "STRCMP('B', 'a')", "STRCMP('abc', 'ABC')", "STRCMP('é', 'e')",
    "FIELD('b', 'a', 'b', 'c')", "FIELD('B', 'a', 'b')", "FIELD('z', 'a')", "FIELD(NULL, 'a')", "FIELD(2, 1, 2, 3)",
    "FIELD('2', 1, 2)", "ELT(2, 'a', 'b', 'c')", "ELT(0, 'a')", "ELT(4, 'a')",
    // logic
    "IF(1, 'y', 'n')", "IF(0, 'y', 'n')", "IF(NULL, 'y', 'n')", "IF('2abc', 'y', 'n')", "IF('abc', 'y', 'n')",
    "IF('0.0', 'y', 'n')", "IF(0.1, 'y', 'n')",
    "LEAST('10', '9')", "LEAST(10, '9')", "LEAST(10, 9)", "LEAST(1.5, 2)", "LEAST('b', 'A', 'c')", "LEAST(1, NULL)",
    "GREATEST('10', '9')", "GREATEST(10, '9')", "GREATEST('apple', 'Banana')", "GREATEST(2, 7.5, 3)", "GREATEST(NULL, 1)",
    "ISNULL(NULL)", "ISNULL(0)", "ISNULL('')",
    // encoding
    "MD5('abc')", "SHA1('abc')", "SHA('abc')", "SHA2('abc', 256)", "SHA2('abc', 512)", "SHA2('abc', 0)", "SHA2('abc', 123)",
    "HEX(255)", "HEX('abc')", "HEX('é')", "UNHEX('616263')", "UNHEX('6')", "UNHEX('zz')", "TO_BASE64('abc')",
    "FROM_BASE64('YWJj')", "FROM_BASE64('!!!')", "ASCII('a')", "ASCII('')", "ASCII(2)", "ORD('é')", "ORD('a')",
    // LIKE and REGEXP
    "'abc' LIKE 'ABC'", "'Иван' LIKE 'иван%'", "'café' LIKE 'CAFE'", "'é' LIKE 'e'", "'abc' LIKE 'a_c'",
    "'abc' LIKE 'a\\\\_c'", "'a_c' LIKE 'a\\\\_c'", "'a%c' LIKE 'a!%c' ESCAPE '!'", "'abc' LIKE 'a!%c' ESCAPE '!'",
    "'abc ' LIKE 'abc'", "'' LIKE '%'", "NULL LIKE 'a'", "'a' NOT LIKE 'b'", "'ab\\nc' LIKE 'ab%'",
    "'Hello' REGEXP '^h'", "'Hello' REGEXP 'x'", "'abc123' REGEXP '[0-9]+'", "'Hello' RLIKE 'L{2}'", "NULL REGEXP 'a'",
    "'Ивaн' REGEXP 'ИВ'",
];
