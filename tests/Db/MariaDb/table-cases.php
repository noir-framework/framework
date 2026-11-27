<?php

/*
 * One table, created and filled the same way on MariaDB and on SQLite (MariaDB dialect), and queries whose rows must
 * be the same on both (recorded in expected-tables.json by MariaDbParityTest). Values chosen to round: 2.675 into
 * DECIMAL(15,2) is 2.68 on MariaDB, 0.33333 into DECIMAL(10,4) is 0.3333, and 0.10 + 0.20 must be 0.30.
 */

declare(strict_types=1);

return [
    'setup' => [
        'CREATE TABLE money (id INT PRIMARY KEY, amount DECIMAL(15,2), qty INT, price DECIMAL(10,4), note VARCHAR(20))',
        "INSERT INTO money VALUES (1, 50.00, 2, 0.1, 'a'), (2, 0.10, 3, 0.2, 'b'), (3, 0.20, NULL, 1.2345, NULL),"
            . " (4, -12.35, 1, NULL, 'a'), (5, NULL, 4, 2, 'b'), (6, 2.675, 5, 0.33333, 'c'), (7, 1000000.5, 1, 0, 'c')",
    ],
    'queries' => [
        'SELECT id, amount, price, qty FROM money ORDER BY id',
        'SELECT SUM(amount), AVG(amount), MIN(amount), MAX(amount) FROM money',
        'SELECT SUM(price), AVG(price), MIN(price), MAX(price) FROM money',
        'SELECT SUM(qty), AVG(qty), MIN(qty), MAX(qty) FROM money',
        'SELECT note, SUM(amount), COUNT(*) FROM money GROUP BY note ORDER BY note',
        'SELECT m.amount, m.price FROM money m WHERE m.id = 2',
        'SELECT SUM(m.amount) AS total, AVG(m.price) AS average FROM money AS m WHERE m.id <= 3',
        'SELECT SUM(amount) FROM money WHERE id > 100',
        'SELECT AVG(amount) FROM money WHERE id > 100',
        'SELECT amount FROM money WHERE amount = 50 ORDER BY id',
        'SELECT id FROM money WHERE amount > 0.15 ORDER BY id',
        'SELECT id, amount FROM money ORDER BY amount DESC, id LIMIT 3',
        'SELECT SUM(amount) FROM money WHERE id IN (2, 3)',
        'SELECT COUNT(amount), COUNT(*), SUM(id) FROM money',
    ],
];
