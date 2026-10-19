<?php

namespace Madeit\Inpost;

defined('ABSPATH') || exit;

/**
 * Pure logic of the plugin: shipping cost, pickup point validation and text templates. No WordPress needed.
 */
final class Calculator
{
    /**
     * Cost of delivery to a parcel locker. Free from the threshold.
     */
    public static function shippingCost(float $cost, float $freeFrom, float $cartTotal): float
    {
        if ($freeFrom > 0 && round($cartTotal, 2) >= round($freeFrom, 2)) {
            return 0.0;
        }

        return max(0.0, round($cost, 2));
    }

    /**
     * InPost point codes look like WAW01M, KRA010 or POP-WAW12.
     */
    public static function isValidPointName(string $name): bool
    {
        return preg_match('/^[A-Z0-9][A-Z0-9-]{2,19}$/', $name) === 1;
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function fillTemplate(string $template, array $values): string
    {
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs['{'.$key.'}'] = $value;
        }

        return strtr($template, $pairs);
    }
}
