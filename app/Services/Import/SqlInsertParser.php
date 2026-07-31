<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Parses phpMyAdmin INSERT statements from SQL dumps.
 */
final class SqlInsertParser
{
    /** @return array<int, string> */
    public static function splitRows(string $valuesBlock): array
    {
        $rows = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($valuesBlock);

        for ($i = 0; $i < $len; $i++) {
            $ch = $valuesBlock[$i];

            if ($escape) {
                $current .= $ch;
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inString) {
                $current .= $ch;
                $escape = true;
                continue;
            }

            if ($ch === "'") {
                $inString = !$inString;
                $current .= $ch;
                continue;
            }

            if (!$inString) {
                if ($ch === '(') {
                    if ($depth === 0) {
                        $current = '';
                    } else {
                        $current .= $ch;
                    }
                    $depth++;
                    continue;
                }

                if ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $rows[] = $current;
                        $current = '';
                        continue;
                    }
                    $current .= $ch;
                    continue;
                }
            }

            if ($depth > 0) {
                $current .= $ch;
            }
        }

        return $rows;
    }

    /** @return array<int, mixed> */
    public static function parseRow(string $row): array
    {
        $values = [];
        $current = '';
        $inString = false;
        $escape = false;
        $len = strlen($row);

        for ($i = 0; $i < $len; $i++) {
            $ch = $row[$i];

            if ($escape) {
                $current .= $ch;
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inString) {
                $current .= $ch;
                $escape = true;
                continue;
            }

            if ($ch === "'") {
                if ($inString) {
                    $values[] = self::unescapeString($current);
                    $current = '';
                }
                $inString = !$inString;
                continue;
            }

            if (!$inString && $ch === ',') {
                $values[] = self::castToken(trim($current));
                $current = '';
                continue;
            }

            if ($inString || !ctype_space($ch)) {
                $current .= $ch;
            }
        }

        if ($current !== '' || str_ends_with($row, ',')) {
            $values[] = self::castToken(trim($current));
        }

        return $values;
    }

    private static function unescapeString(string $value): string
    {
        return str_replace(["\\'", '\\"', '\\\\', "\\r", "\\n"], ["'", '"', '\\', "\r", "\n"], $value);
    }

    private static function castToken(string $token): mixed
    {
        if ($token === '' || strtoupper($token) === 'NULL') {
            return null;
        }

        if (is_numeric($token)) {
            return str_contains($token, '.') ? (float) $token : (int) $token;
        }

        return $token;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function extractTable(string $sql, string $table): array
    {
        if (!preg_match(
            '/INSERT INTO `' . preg_quote($table, '/') . '`\s*\(([^)]+)\)\s*VALUES\s*(.+?);\s*(?:--|\nCREATE|\nINSERT)/s',
            $sql,
            $match
        )) {
            return [];
        }

        $columns = array_map(static fn (string $c) => trim($c, " `\t\n\r"), explode(',', $match[1]));
        $valuesBlock = trim($match[2]);
        $result = [];

        foreach (preg_split('/\r\n|\n|\r/', $valuesBlock) as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, '(')) {
                continue;
            }

            $line = rtrim($line, ',;');
            if (str_ends_with($line, ')')) {
                $line = substr($line, 1, -1);
            } else {
                $line = ltrim($line, '(');
            }

            $values = self::parseRow($line);
            if (count($values) !== count($columns)) {
                continue;
            }

            $result[] = array_combine($columns, $values);
        }

        return $result;
    }
}
