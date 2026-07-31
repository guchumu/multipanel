<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Parses phpMyAdmin INSERT statements from SQL dumps.
 */
final class SqlInsertParser
{
    public const VERSION = '3.1';

    /** @return array{file_bytes: int, has_servers_marker: bool, has_users_marker: bool, parser: string} */
    public static function probe(string $sql): array
    {
        $normalized = self::normalizeSql($sql);

        return [
            'file_bytes' => strlen($normalized),
            'has_servers_marker' => self::findInsertPos($normalized, 'servers') !== null,
            'has_users_marker' => self::findInsertPos($normalized, 'users') !== null,
            'parser' => self::VERSION,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function extractTable(string $sql, string $table): array
    {
        $sql = self::normalizeSql($sql);
        $result = [];
        $offset = 0;

        while (($insert = self::findInsertPos($sql, $table, $offset)) !== null) {
            [$pos, $markerLen] = $insert;
            $cursor = self::skipWhitespace($sql, $pos + $markerLen);

            $columns = null;
            if (($sql[$cursor] ?? '') === '(') {
                $columnsEnd = self::findClosingParen($sql, $cursor);
                if ($columnsEnd === null) {
                    break;
                }

                $columns = array_map(
                    static fn (string $c) => trim($c, " `\t\n\r"),
                    explode(',', substr($sql, $cursor + 1, $columnsEnd - $cursor - 1))
                );
                $cursor = self::skipWhitespace($sql, $columnsEnd + 1);
            }

            if (stripos($sql, 'VALUES', $cursor) !== $cursor) {
                $valuesPos = stripos($sql, 'VALUES', $cursor);
                if ($valuesPos === false) {
                    $offset = $pos + 1;
                    continue;
                }
                $cursor = self::skipWhitespace($sql, $valuesPos + 6);
            } else {
                $cursor = self::skipWhitespace($sql, $cursor + 6);
            }

            $statementEnd = self::findStatementEnd($sql, $cursor);
            if ($statementEnd === null) {
                break;
            }

            $valuesBlock = substr($sql, $cursor, $statementEnd - $cursor);
            $rows = self::extractRows($valuesBlock);

            if ($columns === null && $rows !== []) {
                $columns = array_map('strval', range(0, count(self::parseRow($rows[0])) - 1));
            }

            if ($columns === null) {
                $offset = $statementEnd + 1;
                continue;
            }

            foreach ($rows as $row) {
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
    public static function extractRows(string $valuesBlock): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\n|\r/', $valuesBlock) as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, '(')) {
                continue;
            }

            $line = rtrim($line, ',;');
            if (str_starts_with($line, '(') && str_ends_with($line, ')')) {
                $rows[] = substr($line, 1, -1);
            }
        }

        if ($rows !== []) {
            return $rows;
        }

        return self::splitRows($valuesBlock);
    }

    /** @return array{0: int, 1: int}|null */
    private static function findInsertPos(string $sql, string $table, int $offset = 0): ?array
    {
        $needles = [
            'INSERT INTO `' . $table . '`',
            'INSERT INTO ' . $table . ' ',
            'INSERT INTO ' . $table . '(',
        ];

        $best = null;
        foreach ($needles as $needle) {
            $pos = stripos($sql, $needle, $offset);
            if ($pos !== false && ($best === null || $pos < $best[0])) {
                $best = [$pos, strlen($needle)];
            }
        }

        return $best;
    }

    private static function normalizeSql(string $sql): string
    {
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }

        return str_replace("\r\n", "\n", $sql);
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

            if ($inString) {
                if ($escape) {
                    $current .= $ch;
                    $escape = false;
                    continue;
                }

                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }

                if ($ch === "'") {
                    $inString = false;
                    continue;
                }

                $current .= $ch;
                continue;
            }

            if ($ch === "'") {
                $inString = true;
                $current = '';
                continue;
            }

            if ($ch === ',') {
                $values[] = self::castToken(trim($current));
                $current = '';
                continue;
            }

            if (!ctype_space($ch)) {
                $current .= $ch;
            }
        }

        $values[] = self::castToken(trim($current));

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
