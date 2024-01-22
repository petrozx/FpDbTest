<?php
namespace FpDbTest;

use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;
    private const SKIP_MARKER = 'SKIP_BLOCK';

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $index = 0;
        $blockMarkers = [];
        $skipBlock = false;
        $query = preg_replace_callback('/\?(d|f|a|#)?|{|}/', function ($matches) use (&$args, &$index, &$blockMarkers, &$skipBlock) {
            switch ($matches[0]) {
                case '{':
                    array_push($blockMarkers, $index);
                    $skipBlock = false;
                    return $matches[0];
                case '}':
                    if ($skipBlock) {
                        $skipBlock = false;
                        array_pop($blockMarkers);
                        return 'SKIP_BLOCK_END';
                    }
                    array_pop($blockMarkers);
                    return $matches[0];
                default:
                    $value = $args[$index++] ?? null;
                    if ($value === self::SKIP_MARKER) {
                        $skipBlock = true;
                        return 'NULL';
                    }
                    return $this->formatSpecifier($matches[1] ?? '', $value);
            }
        }, $query);

        $query = preg_replace('/{[^{}]*SKIP_BLOCK_END/', '', $query);
        $query = str_replace(['{', '}'], '', $query);
        return $query;
    }

    private function formatSpecifier($specifier, $value): string
    {
        switch ($specifier) {
            case 'd':
                return is_null($value) ? 'NULL' : (int)$value;
            case 'f':
                return is_null($value) ? 'NULL' : (float)$value;
            case 'a':
                return $this->formatArray($value);
            case '#':
                return $this->formatIdentifiers($value);
            default:
                return $this->formatValue($value);
        }
    }

    private function formatArray($value): string
    {
        if (!is_array($value)) return 'NULL';

        if ($this->isAssoc($value)) {
            return implode(', ', array_map(function ($k, $v) {
                return $this->escapeIdentifier($k) . ' = ' . $this->formatValue($v);
            }, array_keys($value), $value));
        } else {
            return implode(', ', array_map([$this, 'formatValue'], $value));
        }
    }

    private function isAssoc(array $arr): bool
    {
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function formatIdentifiers($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map([$this, 'escapeIdentifier'], $value));
        }

        return $this->escapeIdentifier($value);
    }

    private function formatValue($value): string
    {
        if (is_null($value)) return 'NULL';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value) || is_float($value)) return (string)$value;

        return "'" . $this->mysqli->real_escape_string((string)$value) . "'";
    }

    private function escapeIdentifier($value): string
    {
        return "`" . str_replace("`", "``", $value) . "`";
    }

    public function skip()
    {
        return self::SKIP_MARKER;
    }
}
