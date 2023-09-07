<?php

namespace SharperClaws\Helpers;

/**
 * Sanitization helpers.
 *
 * @since 2.0.0
 */
class SanitizeHelper
{
    /**
     * Sanitizes some text.
     *
     * Forked from WordPress' sanitize_text_field() function.
     *
     * @param string $text
     *
     * @return string
     */
    public static function text(string $text) : string
    {
        return static::_text($text);
    }

    /**
     * Sanitizes some textarea text (keeps newlines).
     *
     * Forked from WordPress' sanitize_textarea() function.
     *
     * @param string $text
     * @return string
     */
    public static function textarea(string $text) : string
    {
        return static::_text($text, true);
    }

    /**
     * Sanitizes a key by stripping all but alphanumeric, underscores, and hyphens.
     *
     * Forked from WordPress' sanitize_key() function.
     *
     * @param string $key
     * @return string
     */
    public static function key(string $key) : string
    {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }

    /**
     * Strips tags and all whitespace.
     *
     * Forked from WordPress' wp_strip_all_tags() function.
     *
     * @param string $text
     * @return string
     */
    public static function strip_all_tags(string $text) : string
    {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
        return trim(strip_tags($text));
    }

    /**
     * Helper for stripping screwey stuff from strings.
     *
     * Works for text and textarea, the latter by enabling $keep_newlines.
     *
     * Forked from WordPress' _sanitize_text_fields() function.
     *
     * @param string $text
     * @return string
     */
    private static function _text(string $text, bool $keep_newlines = false) : string
    {
        if (str_contains($text, '<')) {
            $text = static::_handle_less_than($text);
            $text = static::strip_all_tags($text);

            /*
             * Use HTML entities in a special case to make sure that
             * later newline stripping stages cannot lead to a functional tag.
             */
            $text = str_replace("<\n", "&lt;\n", $text);
        }

        if (! $keep_newlines) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
        }
        $text = trim( $text );

        // Remove percent-encoded characters.
        $found = false;
        while ( preg_match('/%[a-f0-9]{2}/i', $text, $match)) {
            $text = str_replace($match[0], '', $text);
            $found = true;
        }

        if ( $found ) {
            // Strip out the whitespace that may now exist after removing percent-encoded characters.
            $text = trim(preg_replace('/ +/', ' ', $text));
        }

        return $text;
    }

    private static function _handle_less_than(string $text) : string
    {
        return preg_replace_callback('%<[^>]*?((?=<)|>|$)%', 'wp_pre_kses_less_than_callback', $text);
    }
}
