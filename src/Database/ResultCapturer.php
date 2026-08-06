<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Database;

use Illuminate\Support\Str;
use PDOStatement;
use Stringable;
use UnitEnum;

/**
 * Builds a truncated, redacted snapshot of a SQL result for logging.
 *
 * Never mutates the live result returned to the application.
 */
final class ResultCapturer
{
    /**
     * @return array<string, mixed>|null
     */
    public static function capture(mixed $result): ?array
    {
        if ($result instanceof PDOStatement) {
            return [
                'skipped' => 'cursor',
                'reason' => 'Cursor/PDO statement results are not materialized for capture.',
            ];
        }

        if (is_bool($result)) {
            return ['ok' => $result];
        }

        if (is_int($result)) {
            return ['affected_rows' => $result];
        }

        if (is_float($result) || is_string($result) || $result === null) {
            return [
                'value' => self::normalizeScalar($result, self::maxColumnLength()),
            ];
        }

        if (! is_array($result)) {
            return [
                'skipped' => 'unsupported',
                'reason' => 'Unsupported result type: '.get_debug_type($result),
            ];
        }

        if ($result === []) {
            return [
                'rows' => [],
                'row_count' => 0,
                'returned_rows' => 0,
                'truncated' => false,
            ];
        }

        // Multiple result sets: [ [row, ...], [row, ...], ... ]
        if (self::looksLikeResultSets($result)) {
            return self::captureResultSets($result);
        }

        // Single row object/array from atypical callers.
        if (self::isRow($result)) {
            return self::captureRows([$result]);
        }

        return self::captureRows($result);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<string, mixed>
     */
    private static function captureRows(array $rows): array
    {
        $maxRows = self::maxRows();
        $maxColumnLength = self::maxColumnLength();
        $maxPayloadBytes = self::maxPayloadBytes();

        $total = count($rows);
        $slice = array_slice($rows, 0, $maxRows);
        $normalized = [];

        foreach ($slice as $row) {
            $normalized[] = self::normalizeRow($row, $maxColumnLength);
        }

        $normalized = self::redact($normalized);
        $truncated = $total > count($normalized);

        while (
            $normalized !== []
            && $maxPayloadBytes > 0
            && self::payloadBytes(['rows' => $normalized]) > $maxPayloadBytes
        ) {
            array_pop($normalized);
            $truncated = true;
        }

        return [
            'rows' => $normalized,
            'row_count' => $total,
            'returned_rows' => count($normalized),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<int, mixed>  $sets
     * @return array<string, mixed>
     */
    private static function captureResultSets(array $sets): array
    {
        $capturedSets = [];
        $truncated = false;

        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }

            $captured = self::captureRows($set);
            $capturedSets[] = $captured;
            $truncated = $truncated || ($captured['truncated'] ?? false);
        }

        return [
            'result_sets' => $capturedSets,
            'set_count' => count($capturedSets),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<int, mixed>  $result
     */
    private static function looksLikeResultSets(array $result): bool
    {
        if ($result === [] || ! array_is_list($result)) {
            return false;
        }

        $first = $result[0];

        // [[...rows...], ...] — first element is a list of rows (or empty set)
        return is_array($first) && ($first === [] || array_is_list($first));
    }

    private static function isRow(mixed $value): bool
    {
        if ($value instanceof \stdClass) {
            return true;
        }

        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeRow(mixed $row, int $maxColumnLength): array
    {
        if ($row instanceof \stdClass) {
            $row = (array) $row;
        }

        if (! is_array($row)) {
            return ['value' => self::normalizeScalar($row, $maxColumnLength)];
        }

        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = self::normalizeValue($value, $maxColumnLength);
        }

        return $out;
    }

    private static function normalizeValue(mixed $value, int $maxColumnLength): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[is_int($k) ? $k : (string) $k] = self::normalizeValue($v, $maxColumnLength);
            }

            return $out;
        }

        if ($value instanceof \stdClass) {
            return self::normalizeRow($value, $maxColumnLength);
        }

        return self::normalizeScalar($value, $maxColumnLength);
    }

    private static function normalizeScalar(mixed $value, int $maxColumnLength): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if (is_resource($value)) {
            return '[resource]';
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            if (is_object($value)) {
                return '[object '.get_debug_type($value).']';
            }

            return '[unserializable '.get_debug_type($value).']';
        }

        if ($maxColumnLength > 0 && strlen($value) > $maxColumnLength) {
            return substr($value, 0, $maxColumnLength).'…';
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private static function redact(array $data): array
    {
        $keys = config('context-logging.database.redact_fields', []);
        $redactValue = (string) config(
            'context-logging.database.redact_value',
            config('context-logging.http.redact_value', '[redacted]')
        );

        if ($keys === []) {
            return $data;
        }

        return self::redactRecursive($data, $keys, $redactValue);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @param  array<int, string>  $sensitiveKeys
     * @return array<int|string, mixed>
     */
    private static function redactRecursive(array $data, array $sensitiveKeys, string $redactValue): array
    {
        $result = [];

        foreach ($data as $k => $v) {
            $keyLower = strtolower((string) $k);
            $match = false;

            foreach ($sensitiveKeys as $sensitive) {
                $sensitive = strtolower((string) $sensitive);
                if ($keyLower === $sensitive || Str::contains($keyLower, $sensitive)) {
                    $match = true;
                    break;
                }
            }

            if ($match) {
                $result[$k] = $redactValue;
                continue;
            }

            $result[$k] = is_array($v)
                ? self::redactRecursive($v, $sensitiveKeys, $redactValue)
                : $v;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function payloadBytes(array $payload): int
    {
        $json = json_encode($payload);

        return is_string($json) ? strlen($json) : PHP_INT_MAX;
    }

    private static function maxRows(): int
    {
        return max(0, (int) config('context-logging.database.max_rows', 20));
    }

    private static function maxColumnLength(): int
    {
        return max(0, (int) config('context-logging.database.max_column_length', 500));
    }

    private static function maxPayloadBytes(): int
    {
        return max(0, (int) config('context-logging.database.max_payload_bytes', 65536));
    }
}
