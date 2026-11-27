<?php

/*
 * INSERT IGNORE, ON DUPLICATE KEY UPDATE, INSERT … SET, REPLACE on one table created on MariaDB and SQLite: the rows
 * after every statement must match (expected-upserts.json). `people` has a primary key and a unique e-mail.
 */

declare(strict_types=1);

return [
    'setup' => [
        'CREATE TABLE people (id INT PRIMARY KEY, email VARCHAR(50) UNIQUE, name VARCHAR(50), visits INT NOT NULL DEFAULT 0)',
        "INSERT INTO people VALUES (1, 'a@x', 'Ann', 1), (2, 'b@x', 'Bob', 1)",
        // a duplicate key is skipped, the new row goes in
        "INSERT IGNORE INTO people VALUES (1, 'zz@x', 'Dup', 9), (3, 'c@x', 'Cid', 1)",
        // INTO is optional in MariaDB; a duplicate unique e-mail is skipped too
        "INSERT IGNORE people (id, email, name) VALUES (4, 'a@x', 'Ann again')",
        // update on the primary key, VALUES(col) is the new row's value
        "INSERT INTO people (id, email, name, visits) VALUES (1, 'a@x', 'Ann', 1) ON DUPLICATE KEY UPDATE visits = visits + VALUES(visits)",
        // update on the unique e-mail, several rows, a constant
        "INSERT INTO people (id, email, name) VALUES (9, 'b@x', 'Robert'), (5, 'e@x', 'Eve') ON DUPLICATE KEY UPDATE name = VALUES(name), visits = 7",
        // INSERT … SET
        "INSERT INTO people SET id = 6, email = 'f@x', name = 'Fay'",
        "INSERT INTO people SET id = 6, email = 'f@x', name = 'Fay' ON DUPLICATE KEY UPDATE visits = visits + 10",
        // VALUE instead of VALUES, LOW_PRIORITY
        "INSERT LOW_PRIORITY INTO people (id, email, name) VALUE (7, 'g@x', 'Gus')",
        // INSERT … SELECT … ON DUPLICATE KEY UPDATE
        "INSERT INTO people (id, email, name, visits) SELECT id, email, name, 100 FROM (SELECT id, email, name FROM people WHERE id IN (2, 3)) AS src ON DUPLICATE KEY UPDATE visits = visits + VALUES(visits)",
        // REPLACE deletes and inserts
        "REPLACE INTO people (id, email, name) VALUES (7, 'g2@x', 'Gus 2')",
        // UPDATE IGNORE: the duplicate e-mail is skipped, the row stays
        "UPDATE IGNORE people SET email = 'a@x' WHERE id = 5",
        "UPDATE IGNORE people SET name = 'Eve 2' WHERE id = 5",
        "DELETE IGNORE FROM people WHERE id = 6",
    ],
    'queries' => [
        'SELECT id, email, name, visits FROM people ORDER BY id',
    ],
];
