<?php

namespace App\Services\Portfolio;

/**
 * Aritmética decimal con bcmath. Los importes de inversión mezclan cantidades
 * de cripto con 8-10 decimales y precios grandes: con float se pierden céntimos.
 */
final class Dec
{
    public const SCALE = 12;

    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            $value = sprintf('%.12F', $value);
        }

        return (string) $value;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        if (self::isZero($b)) {
            return '0';
        }

        return bcdiv($a, $b, self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function isZero(string $a): bool
    {
        return bccomp($a, '0', self::SCALE) === 0;
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? $a : $b;
    }

    public static function neg(string $a): string
    {
        return bcmul($a, '-1', self::SCALE);
    }

    public static function abs(string $a): string
    {
        return self::cmp($a, '0') < 0 ? self::neg($a) : $a;
    }

    /**
     * Redondeo mitad hacia fuera del cero (como en una factura).
     */
    public static function round(string $a, int $decimals = 2): string
    {
        $offset = '0.' . str_repeat('0', $decimals) . '5';
        $rounded = self::cmp($a, '0') < 0
            ? bcsub($a, $offset, $decimals)
            : bcadd($a, $offset, $decimals);

        return $rounded === '-0.' . str_repeat('0', $decimals) ? '0.' . str_repeat('0', $decimals) : $rounded;
    }
}
