<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Driver-portable SQL fragments.
 *
 * The application is developed against PostgreSQL, deployed against MySQL, and
 * tested against SQLite (see phpunit.xml). Any raw SQL that uses vendor-specific
 * functions must go through here so all three stay in sync.
 *
 * Column names passed to these helpers are always code-controlled literals,
 * never user input, so they are safe to interpolate.
 */
class DbExpr
{
    /**
     * A SQL expression formatting a date/timestamp column as 'YYYY-MM',
     * suitable for grouping rows by calendar month.
     *
     * Postgres: TO_CHAR   MySQL: DATE_FORMAT   SQLite: strftime
     */
    public static function yearMonth(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            'sqlsrv' => "FORMAT({$column}, 'yyyy-MM')",
            default => "TO_CHAR({$column}, 'YYYY-MM')",
        };
    }
}
