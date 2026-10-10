<?php

declare(strict_types=1);

namespace App\Core\Migrations;

/**
 * What a migration leaves behind in the schema, so a fresh installation can
 * check that the base schema already contains it instead of assuming so.
 *
 * Every migration is folded into database/members/001_schema.sql in the same
 * change (see the migrations README), so a database built from that file
 * already holds the migrations' tables and columns. "Already holds" is checked,
 * not trusted: tools/install-database.php compares these expectations with the
 * real database and records a migration as included only when all of them are
 * there.
 *
 * Only structure that can be checked is understood: CREATE TABLE, and ALTER
 * TABLE clauses that ADD, MODIFY or CHANGE a column (with an ENUM's values when
 * the new type is one) or add or drop an index or key. Anything else — data
 * changes, views, drops of tables or columns — makes the migration
 * unverifiable, and the installer stops rather than guess.
 */
final class SchemaExpectations
{
    /**
     * @return array{
     *   checks: list<array{table:string,column?:string,enum?:list<string>}>,
     *   unverifiable: list<string>
     * }
     */
    public static function of(string $sql): array
    {
        $checks = [];
        $unverifiable = [];
        foreach (SqlStatements::parse($sql) as $statement) {
            $s = trim($statement);
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $s, $m) === 1) {
                $checks[] = ['table' => $m[1]];
                continue;
            }
            if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $s, $m) === 1) {
                $table = $m[1];
                foreach (self::clauses($m[2]) as $clause) {
                    $check = self::alterClause($table, $clause);
                    if ($check === false) {
                        $unverifiable[] = self::summary($s);
                        continue 2;
                    }
                    if ($check !== null) {
                        $checks[] = $check;
                    }
                }
                continue;
            }
            $unverifiable[] = self::summary($s);
        }

        return ['checks' => $checks, 'unverifiable' => $unverifiable];
    }

    /**
     * One ALTER TABLE clause: an expectation, null for a clause with nothing to
     * check (an index), or false when it cannot be checked.
     *
     * @return array{table:string,column?:string,enum?:list<string>}|null|false
     */
    private static function alterClause(string $table, string $clause): array|null|false
    {
        $c = trim($clause);
        if (preg_match('/^(ADD|DROP)\s+(UNIQUE\s+)?(KEY|INDEX|CONSTRAINT|PRIMARY|FOREIGN|FULLTEXT)\b/i', $c) === 1) {
            return null;
        }
        if (preg_match('/^ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s+(.*)$/is', $c, $m) === 1
            || preg_match('/^MODIFY\s+(?:COLUMN\s+)?`?(\w+)`?\s+(.*)$/is', $c, $m) === 1
            || preg_match('/^CHANGE\s+(?:COLUMN\s+)?`?\w+`?\s+`?(\w+)`?\s+(.*)$/is', $c, $m) === 1) {
            $check = ['table' => $table, 'column' => $m[1]];
            if (preg_match('/^ENUM\s*\((.*?)\)/is', trim($m[2]), $e) === 1) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $e[1], $values);
                $check['enum'] = $values[1];
            }

            return $check;
        }

        return false;
    }

    /**
     * Split the body of an ALTER TABLE on top-level commas.
     *
     * @return list<string>
     */
    private static function clauses(string $body): array
    {
        $out = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $ch = $body[$i];
            if ($quote !== null) {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $length) {
                    $current .= $body[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $out[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $out[] = $current;
        }

        return $out;
    }

    private static function summary(string $statement): string
    {
        $flat = preg_replace('/\s+/', ' ', $statement) ?? $statement;

        return strlen($flat) > 80 ? substr($flat, 0, 77) . '...' : $flat;
    }
}
