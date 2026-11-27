<?php

/*
 * GROUP_CONCAT on one table created on MariaDB and SQLite: rows must match (expected-groups.json).
 */

declare(strict_types=1);

return [
    'setup' => [
        'CREATE TABLE fruit (id INT PRIMARY KEY, kind VARCHAR(10), name VARCHAR(20), qty INT)',
        "INSERT INTO fruit VALUES (1, 'b', 'pear', 3), (2, 'a', 'apple', 1), (3, 'a', 'Banana', 2), (4, 'b', NULL, 5),"
            . " (5, 'a', 'apple', 4), (6, 'c', 'kiwi', NULL)",
    ],
    'queries' => [
        'SELECT kind, GROUP_CONCAT(name) FROM fruit GROUP BY kind ORDER BY kind',
        'SELECT GROUP_CONCAT(name ORDER BY name) FROM fruit',
        'SELECT GROUP_CONCAT(name ORDER BY name DESC SEPARATOR \'; \') FROM fruit',
        'SELECT GROUP_CONCAT(DISTINCT name ORDER BY name) FROM fruit',
        'SELECT kind, GROUP_CONCAT(name, \':\', qty ORDER BY qty SEPARATOR \'|\') FROM fruit GROUP BY kind ORDER BY kind',
        'SELECT GROUP_CONCAT(qty ORDER BY kind, qty DESC) FROM fruit',
        'SELECT GROUP_CONCAT(id ORDER BY id LIMIT 2) FROM fruit',
        'SELECT GROUP_CONCAT(name) FROM fruit WHERE id > 100',
        'SELECT GROUP_CONCAT(DISTINCT kind ORDER BY kind DESC SEPARATOR \'\') FROM fruit',
    ],
];
