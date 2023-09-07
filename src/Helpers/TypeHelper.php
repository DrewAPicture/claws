<?php

namespace SharperClaws\Helpers;

/**
 * Helper methods for coercing types.
 *
 * @since 2.0.0
 */
class TypeHelper
{
    /**
     * Helper to ensure an integer value.
     *
     * @param mixed $value
     * @return int
     */
    public static function int($value) : int
    {
        return intval($value);
    }

    /**
     * Helper to ensure a string value.
     *
     * @param mixed $value
     * @return string
     */
    public static function string($value) : string
    {
        return (string) $value;
    }

    /**
     * Helper to ensure a float value.
     *
     * @param mixed $value
     * @return float
     */
    public static function float($value) : float
    {
        return floatval($value);
    }
}
