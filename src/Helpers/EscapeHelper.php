<?php

namespace SharperClaws\Helpers;

/**
 * Escape helpers.
 *
 * @since 2.0.0
 */
class EscapeHelper
{
    /**
     * SQL escape helper.
     *
     * Forked from WordPress' wpdb::_escape() method.
     *
     * @param array<string, mixed>|string $data
     * @return array<string, mixed>|string
     */
    public static function sql(array|string $data) : array|string
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = static::sql($value);
                } else {
                    $data[$key] = static::real_escape($value);
                }
            }
        } else {
            $data = static::real_escape($data);
        }

        return $data;
    }

    /**
     * Escapes a LIKE operator value.
     *
     * Forked from WordPress' wpdb:esc_like() method.
     *
     * @param string $text
     * @return string
     */
    public static function like(string $text) : string
    {
        return addcslashes( $text, '_%\\' );
    }

    /**
     * Real escape, using mysqli_real_escape_string() or mysql_real_escape_string().
     *
     * Forked from WordPress' wpdb::_real_escape() method.
     *
     * @param string $data
     * @return string
     */
    public static function real_escape(string $data) : string
    {
        $escaped = addslashes($data);

        return static::add_placeholder_escape($escaped);
    }


    /**
     * Adds a placeholder escape string, to escape anything that resembles a printf() placeholder.
     *
     * Forked from WordPress' wpdb::add_placeholder_escape() method.
     *
     * @param string $sql SQL.
     * @return string
     */
    public static function add_placeholder_escape(string $sql) : string
    {
        return str_replace('%', static::placeholder_escape(), $sql);
    }

    /**
     * Replace % placeholders with a hash.
     *
     * Forked from WordPress' wpdb::placeholder_escape() method.
     *
     * @return string
     */
    public static function placeholder_escape() : string
    {
        static $placeholder;

        if (!$placeholder) {
            // If ext/hash is not present, compat.php's hash_hmac() does not support sha256.
            $algo = function_exists('hash') ? 'sha256' : 'sha1';
            $salt = (string)rand();

            $placeholder = '{'.hash_hmac($algo, uniqid($salt, true), $salt).'}';
        }

        return $placeholder;
    }

}
