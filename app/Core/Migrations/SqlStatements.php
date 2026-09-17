<?php

declare(strict_types=1);

namespace App\Core\Migrations;

/**
 * Cutting a migration file into the statements it contains.
 *
 * This lived inside tools/migrate.php, which is a script rather than a class,
 * so nothing could test it. It got a bug that only surfaced when a migration
 * happened to put a semicolon inside a column COMMENT — the statement was cut
 * in half and the remainder handed to the server as SQL.
 *
 * Splitting SQL correctly is not obvious enough to leave untested. It lives
 * here so it can be.
 */
final class SqlStatements
{
    /**
     * The statements in one migration file, comments removed.
     *
     * @return list<string>
     */
    public static function parse(string $sql): array
    {
        // Comments go BEFORE splitting. Migration 001 documents roles in a
        // comment containing a semicolon ("...leader; scope_ministry_id
        // required"); splitting first tears the comment in half and the tail —
        // which no longer starts with "--" — gets executed.
        $clean = [];
        foreach (explode("\n", $sql) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $clean[] = rtrim(self::stripTrailingComment($line));
        }

        $out = [];
        foreach (self::splitOnTerminators(implode("\n", $clean)) as $chunk) {
            $stmt = trim($chunk);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }

        return $out;
    }

    /**
     * Split on semicolons that actually terminate a statement.
     *
     * @return list<string>
     */
    public static function splitOnTerminators(string $sql): array
    {
        $out = [];
        $buf = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];

            if ($c === "'" && !$inDouble && !$inBacktick) {
                // A doubled quote inside a string is an escaped quote, not the
                // end of one: 'it''s' is a single literal.
                if ($inSingle && $i + 1 < $len && $sql[$i + 1] === "'") {
                    $buf .= "''";
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
            } elseif ($c === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($c === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            } elseif ($c === '\\' && $inSingle && $i + 1 < $len) {
                // Backslash escape inside a string: take the next byte as-is.
                $buf .= $c . $sql[$i + 1];
                $i++;
                continue;
            } elseif ($c === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $out[] = $buf;
                $buf = '';
                continue;
            }

            $buf .= $c;
        }
        $out[] = $buf;

        return $out;
    }

    /** Drop a trailing "--" comment, but only outside a quoted string. */
    public static function stripTrailingComment(string $line): string
    {
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === "'" && !$inDouble && !$inBacktick) {
                $inSingle = !$inSingle;
            } elseif ($c === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($c === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            } elseif ($c === '-' && $i + 1 < $len && $line[$i + 1] === '-'
                && !$inSingle && !$inDouble && !$inBacktick) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }
}
