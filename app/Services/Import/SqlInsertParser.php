<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Parses phpMyAdmin INSERT statements from SQL dumps.
 */
final class SqlInsertParser
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function extractTable(string $sql, string $table): array
    {
        $result = [];
        $offset = 0;
        $marker = 'INSERT INTO `' . $table . '`';

        while (($pos = stripos($sql, $marker, $offset)) !== false) {
            $cursor = $pos + strlen($marker);
            $cursor = self::skipWhitespace($sql, $cursor);

            if (($sql[$cursor] ?? '') !== '(') {
                $offset = $pos + 1;
                continue;
            }

            $columnsEnd = self::findClosingParen($sql, $cursor);
            if ($columnsEnd === null) {
                break;
            }

            $columns = array_map(
                static fn (string $c) => trim($c, " `\t\n\r"),
                explode(',', substr($sql, $cursor + 1, $columnsEnd - $cursor - 1))
            );

            $valuesPos = stripos($sql, 'VALUES', $columnsEnd);
            if ($valuesPos === false || $valuesPos > $columnsEnd + 32) {
                $offset = $pos + 1;
                continue;
            }

            $valuesStart = self::skipWhitespace($sql, $valuesPos + 6);
            $statementEnd = self::findStatementEnd($sql, $valuesStart);
            if ($statementEnd === null) {
                break;
            }

            $valuesBlock = trim(substr($sql, $valuesStart, $statementEnd - $valuesStart));
            foreach (self::splitRows($valuesBlock) as $row) {
                $values = self::parseRow($row);
                if (count($values) === count($columns)) {
                    $result[] = array_combine($columns, $values);
                }
            }

            $offset = $statementEnd + 1;
        }

        return $result;
    }

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
                if ($depth > 0) {
                    $current .= $ch;
                }
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inString) {
                if ($depth > 0) {
                    $current .= $ch;
                }
                $escape = true;
                continue;
            }

            if ($ch === "'") {
                $inString = !$inString;
                if ($depth > 0) {
                    $current .= $ch;
                }
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

        if ($current !== '') {
            $values[] = self::castToken(trim($current));
        }

        return $values;
    }

    private static function skipWhitespace(string $sql, int $offset): int
    {
        $len = strlen($sql);
        while ($offset < $len && ctype_space($sql[$offset])) {
            $offset++;
        }

        return $offset;
    }

    private static function findClosingParen(string $sql, int $openPos): ?int
    {
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($sql);

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inString) {
                $escape = true;
                continue;
            }

            if ($ch === "'") {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function findStatementEnd(string $sql, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($sql);

        for ($i = $start; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inString) {
                $escape = true;
                continue;
            }

            if ($ch === "'") {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ';' && $depth === 0) {
                return $i;
            }
        }

        return null;
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
}
