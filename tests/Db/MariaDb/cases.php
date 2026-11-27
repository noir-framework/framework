<?php

/*
 * MariaDB expressions whose answers on SQLite (MariaDB dialect) must equal MariaDB's own, recorded in expected.json
 * by MariaDbParityTest (NOIRAPI_TEST_MARIADB=… records them again). Session time zone UTC on both sides. No "now":
 * those are tested against a fixed clock.
 */

declare(strict_types=1);

$dates = ["'2026-01-31'", "'2024-02-29'", "'2026-12-31 23:59:59'", "'2026-03-01 00:00:00.250000'", "'1999-12-31'",
    "'2027-01-01'", "'2026-11-27 16:05:09'", "'2021-01-03'", "'2020-12-31'", "'2026-06-15 12:00:00'"];

$parts = ['YEAR', 'QUARTER', 'MONTH', 'DAY', 'DAYOFMONTH', 'HOUR', 'MINUTE', 'SECOND', 'MICROSECOND', 'DAYOFWEEK',
    'WEEKDAY', 'DAYOFYEAR', 'WEEKOFYEAR', 'MONTHNAME', 'DAYNAME', 'LAST_DAY', 'TO_DAYS', 'TO_SECONDS'];
$intervals = ['1 DAY', '-1 DAY', '1 MONTH', '13 MONTH', '-1 MONTH', '1 YEAR', '1 QUARTER', '2 WEEK', '1 HOUR', '90 MINUTE',
    '3661 SECOND', '1.5 SECOND', '1500 MICROSECOND', "'1:30' HOUR_MINUTE", "'1-2' YEAR_MONTH", "'2 3:04:05' DAY_SECOND",
    "'1.5' SECOND_MICROSECOND", "'-1 1' DAY_HOUR", "'5' DAY_SECOND", '1.5 DAY', "'10' DAY"];

$cases = [];
foreach ($dates as $d) {
    foreach ($parts as $f) {
        $cases[] = "$f($d)";
    }
    for ($mode = 0; $mode <= 7; $mode++) {
        $cases[] = "WEEK($d, $mode)";
        $cases[] = "YEARWEEK($d, $mode)";
    }
    $cases[] = "DATE_FORMAT($d, '%a %b %c %D %d %e %f %H %h %I %i %j %k %l %M %m %p %r %S %s %T %U %u %V %v %W %w %X %x %Y %y %%')";
}

foreach ($intervals as $interval) {
    foreach (["'2026-01-31'", "'2024-02-29'", "'2026-12-31 23:59:59'", "'2026-03-01 00:00:00.250000'"] as $d) {
        $cases[] = "$d + INTERVAL $interval";
        $cases[] = "DATE_SUB($d, INTERVAL $interval)";
    }
}

return array_merge($cases, [
    "INTERVAL 1 DAY + '2026-01-31'",
    "'2026-01-31' + INTERVAL 1 DAY + INTERVAL 2 HOUR",
    "ADDDATE('2026-01-31', 5)", "SUBDATE('2026-01-31', 31)", "ADDDATE('2026-01-31', INTERVAL 1 MONTH)",
    "TIMESTAMPADD(MONTH, 1, '2026-01-31')", "TIMESTAMPADD(MINUTE, 90, '2026-01-31')", "TIMESTAMPADD(WEEK, 1, '2026-01-31')",
    "TIMESTAMPDIFF(MONTH, '2026-01-31', '2026-02-28')", "TIMESTAMPDIFF(MONTH, '2026-01-31', '2026-03-31')",
    "TIMESTAMPDIFF(MONTH, '2026-03-31', '2026-01-31')", "TIMESTAMPDIFF(YEAR, '2024-02-29', '2025-02-28')",
    "TIMESTAMPDIFF(YEAR, '2024-02-29', '2028-02-29')", "TIMESTAMPDIFF(QUARTER, '2026-01-01', '2026-12-31')",
    "TIMESTAMPDIFF(DAY, '2026-01-01 12:00', '2026-01-03 11:59')", "TIMESTAMPDIFF(HOUR, '2026-01-03', '2026-01-01 12:00')",
    "TIMESTAMPDIFF(SECOND, '2026-01-01', '2026-01-01 00:00:01.9')", "TIMESTAMPDIFF(WEEK, '2026-01-01', '2026-02-01')",
    "TIMESTAMPDIFF(MICROSECOND, '2026-01-01', '2026-01-01 00:00:00.5')",
    "DATEDIFF('2026-03-01', '2026-02-01')", "DATEDIFF('2026-01-01 23:59', '2026-01-02 00:00')",
    "TIMEDIFF('2026-01-02 01:00:00', '2026-01-01 00:00:00')", "TIMEDIFF('10:00:00', '12:30:15')",
    "TIMEDIFF('2026-01-01', '10:00:00')", "TIMEDIFF('2026-03-01 00:00:00', '2025-01-01 00:00:00')",
    "ADDTIME('2026-01-31 23:00:00', '02:30:00')", "ADDTIME('10:00:00', '1 02:00:00')", "SUBTIME('2026-01-01 00:00:00', '00:00:01')",
    "SUBTIME('01:00:00', '02:00:00')",
    "EXTRACT(YEAR FROM '2026-11-27 16:05:09')", "EXTRACT(YEAR_MONTH FROM '2026-11-27 16:05:09')",
    "EXTRACT(DAY_SECOND FROM '2026-11-27 16:05:09')", "EXTRACT(HOUR_MINUTE FROM '2026-11-27 16:05:09')",
    "EXTRACT(WEEK FROM '2026-11-27')", "EXTRACT(QUARTER FROM '2026-11-27')", "EXTRACT(MICROSECOND FROM '2026-01-01 00:00:00.123')",
    "EXTRACT(SECOND_MICROSECOND FROM '2026-01-01 00:00:05.123')",
    "STR_TO_DATE('27/11/2026', '%d/%m/%Y')", "STR_TO_DATE('2026-11-27 4:05:09 PM', '%Y-%m-%d %l:%i:%s %p')",
    "STR_TO_DATE('Nov 27, 2026', '%b %d, %Y')", "STR_TO_DATE('November 27 26', '%M %d %y')",
    "STR_TO_DATE('31/02/2026', '%d/%m/%Y')", "STR_TO_DATE('2026', '%Y')", "STR_TO_DATE('16:05', '%H:%i')",
    "STR_TO_DATE('12:00:00 AM', '%r')", "STR_TO_DATE('x', '%Y')", "STR_TO_DATE('2026-11-27 16:05:09.5', '%Y-%m-%d %T.%f')",
    "MAKEDATE(2026, 1)", "MAKEDATE(2026, 365)", "MAKEDATE(2024, 366)", "MAKEDATE(26, 32)", "MAKEDATE(2026, 0)",
    "MAKETIME(12, 15, 30)", "MAKETIME(-1, 0, 0)", "MAKETIME(12, 60, 0)",
    "FROM_DAYS(740000)", "FROM_DAYS(1)", "TO_DAYS('0001-01-01')", "TO_DAYS(20261127)", "TO_DAYS('2026-11-27')",
    "TIME_TO_SEC('01:00:01')", "TIME_TO_SEC('-838:59:59')", "TIME_TO_SEC('2026-01-01 10:00:00')",
    "SEC_TO_TIME(3661)", "SEC_TO_TIME(-90)", "SEC_TO_TIME(4000000)",
    "PERIOD_ADD(202611, 3)", "PERIOD_ADD(2611, -11)", "PERIOD_ADD(9912, 1)", "PERIOD_DIFF(202702, 202611)",
    "PERIOD_DIFF(2601, 9901)",
    "UNIX_TIMESTAMP('2026-11-27 16:05:09')", "UNIX_TIMESTAMP('1970-01-01 00:00:00')", "UNIX_TIMESTAMP('2026-01-01')",
    "FROM_UNIXTIME(1764259509)", "FROM_UNIXTIME(0)", "FROM_UNIXTIME(1764259509, '%Y %D %M %h:%i:%s %x')",
    "DATE_FORMAT('2026-02-30', '%Y')", "DATE_FORMAT(NULL, '%Y')", "YEAR(NULL)", "MONTH('not a date')", "DAY('2026-13-01')",
    "DATE_ADD(NULL, INTERVAL 1 DAY)", "DATE_ADD('2026-01-01', INTERVAL NULL DAY)", "'9999-12-31' + INTERVAL 1 DAY",
    "'0001-01-01' - INTERVAL 1 DAY", "LAST_DAY('2024-02-10')", "LAST_DAY('2026-02-10 10:00:00')",
    "WEEK('2026-12-31')", "YEARWEEK('2026-12-31')", "WEEKOFYEAR('2027-01-01')",
    "DAYOFWEEK(20261127)", "MONTH(20261127143000)",
]);
