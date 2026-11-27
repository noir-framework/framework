<?php

/*
 * MariaDB JSON and GROUP_CONCAT expressions whose answers on SQLite (MariaDB dialect) must equal MariaDB's own,
 * recorded in expected-json.json by MariaDbParityTest. MariaDB returns parts of a document as written in it, so the
 * documents here have their own spacing on purpose.
 */

declare(strict_types=1);

$doc = "'{\"a\": 1, \"b\" : [1, 2,3], \"c\":{\"d\":\"x\\\\\"y\",\"e\":null}, \"f\": true, \"g\": 1.50, \"h\": \"ü\"}'";

return [
    // reading
    "JSON_EXTRACT($doc, '$.a')", "JSON_EXTRACT($doc, '$.b')", "JSON_EXTRACT($doc, '$.b[1]')", "JSON_EXTRACT($doc, '$.c')",
    "JSON_EXTRACT($doc, '$.c.d')", "JSON_EXTRACT($doc, '$.c.e')", "JSON_EXTRACT($doc, '$.f')", "JSON_EXTRACT($doc, '$.g')",
    "JSON_EXTRACT($doc, '$.h')", "JSON_EXTRACT($doc, '$.zz')", "JSON_EXTRACT($doc, '$')", "JSON_EXTRACT($doc, '$.a', '$.f')",
    "JSON_EXTRACT($doc, '$.b[*]')", "JSON_EXTRACT($doc, '$.*')", "JSON_EXTRACT($doc, '$.b[9]')", "JSON_EXTRACT('[1, [2, 3]]', '$[1][0]')",
    "JSON_EXTRACT('{\"a b\": 5}', '$.\"a b\"')", "JSON_EXTRACT('not json', '$.a')", "JSON_EXTRACT(NULL, '$.a')",
    "JSON_UNQUOTE(JSON_EXTRACT($doc, '$.c.d'))", "JSON_UNQUOTE('\"a\\\\nb\"')", "JSON_UNQUOTE('abc')", "JSON_UNQUOTE('\"ü\"')",
    "JSON_VALUE($doc, '$.a')", "JSON_VALUE($doc, '$.c.d')", "JSON_VALUE($doc, '$.b')", "JSON_VALUE($doc, '$.f')",
    "JSON_VALUE($doc, '$.c.e')", "JSON_VALUE($doc, '$.g')", "JSON_VALUE($doc, '$.zz')",
    "JSON_QUERY($doc, '$.b')", "JSON_QUERY($doc, '$.c')", "JSON_QUERY($doc, '$.a')",
    "JSON_LENGTH($doc)", "JSON_LENGTH($doc, '$.b')", "JSON_LENGTH($doc, '$.a')", "JSON_LENGTH($doc, '$.zz')", "JSON_LENGTH('[]')",
    "JSON_TYPE($doc)", "JSON_TYPE(JSON_EXTRACT($doc, '$.b'))", "JSON_TYPE(JSON_EXTRACT($doc, '$.a'))",
    "JSON_TYPE(JSON_EXTRACT($doc, '$.g'))", "JSON_TYPE(JSON_EXTRACT($doc, '$.f'))", "JSON_TYPE(JSON_EXTRACT($doc, '$.c.e'))",
    "JSON_TYPE(JSON_EXTRACT($doc, '$.h'))",
    "JSON_KEYS($doc)", "JSON_KEYS($doc, '$.c')", "JSON_KEYS($doc, '$.b')",
    "JSON_CONTAINS($doc, '1', '$.a')", "JSON_CONTAINS($doc, '2', '$.b')", "JSON_CONTAINS($doc, '[1, 3]', '$.b')",
    "JSON_CONTAINS($doc, '[1, 5]', '$.b')", "JSON_CONTAINS($doc, '{\"d\": \"x\\\\\"y\"}', '$.c')", "JSON_CONTAINS('[1, 2]', '1')",
    "JSON_CONTAINS_PATH($doc, 'one', '$.a', '$.zz')", "JSON_CONTAINS_PATH($doc, 'all', '$.a', '$.zz')",
    "JSON_CONTAINS_PATH($doc, 'all', '$.a', '$.c.d')",
    "JSON_VALID('{\"a\": 1}')", "JSON_VALID('{a: 1}')", "JSON_VALID('')", "JSON_VALID('1')", "JSON_VALID(NULL)",
    // building
    "JSON_OBJECT('a', 1, 'b', 'x', 'c', NULL, 'd', 1.5)", "JSON_OBJECT()", "JSON_ARRAY(1, 'two', NULL, 3.25)", "JSON_ARRAY()",
    "JSON_OBJECT('n', JSON_ARRAY(1, 2), 'o', JSON_OBJECT('k', 'v'))", "JSON_ARRAY(JSON_OBJECT('a', 1), 'q\"uote')",
    "JSON_QUOTE('a\"b')", "JSON_QUOTE('ü')", "JSON_QUOTE(NULL)",
    "JSON_SET($doc, '$.a', 2)", "JSON_SET($doc, '$.new', 'v')", "JSON_SET('{}', '$.a', 1)", "JSON_SET('[1, 2]', '$[1]', 9)",
    "JSON_SET('[1, 2]', '$[5]', 9)", "JSON_SET('{\"a\":1}', '$.a', JSON_ARRAY(1))",
    "JSON_INSERT('{\"a\":1}', '$.a', 2)", "JSON_INSERT('{\"a\":1}', '$.b', 2)",
    "JSON_REPLACE('{\"a\":1}', '$.a', 2)", "JSON_REPLACE('{\"a\":1}', '$.b', 2)",
    "JSON_REMOVE($doc, '$.b')", "JSON_REMOVE('[1, 2, 3]', '$[1]')", "JSON_REMOVE('{\"a\":1}', '$.zz')",
    "JSON_ARRAY_APPEND('[1, 2]', '$', 3)", "JSON_ARRAY_APPEND('{\"a\": [1]}', '$.a', 'x')",
    "JSON_MERGE_PATCH('{\"a\": 1, \"b\": 2}', '{\"b\": null, \"c\": 3}')",
];
