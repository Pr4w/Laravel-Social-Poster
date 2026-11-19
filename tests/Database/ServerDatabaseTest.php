<?php

use Illuminate\Support\Facades\DB;

/*
 * The CI database jobs set DB_CONNECTION (mysql, mariadb, pgsql) to run the
 * idempotency tests on each dialect. If the suite quietly fell back to
 * SQLite, those jobs would pass without testing anything.
 */
it('runs on the database DB_CONNECTION names', function () {
    $requested = getenv('DB_CONNECTION');
    $requested = is_string($requested) && $requested !== '' ? $requested : 'sqlite';

    expect(DB::connection()->getDriverName())->toBe($requested);
});
