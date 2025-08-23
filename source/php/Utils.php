<?php

class Utils
{
    /**
     * Generates a URL-friendly slug from a given string.
     * @param string $text The text to generate from
     * @param string $divider The divider to replace unwanted characters with (default: '-')
     * @return string The slug generated from the string.
     */
    public static function generateSlug(string $text, string $divider = '-', $fallback = 'n-a'): string
    {
        // Replace non-letter or digits by divider.
        $result = preg_replace('~[^\pL\d]+~u', $divider, $text);

        // Trim whitespace.
        $result = trim($result, $divider);

        // Remove duplicate dividers.
        $result = preg_replace('~-+~', $divider, $result);

        // Convert to lowercase.
        $result = mb_strtolower($result, 'UTF-8');

        // Return the fallback if we end up with an empty string.
        return $result ?: $fallback;
    }

    /**
     * Sanitize and truncate a string.
     * @param string $rawString The raw, unclean string.
     * @return string Safe, truncated string.
     */
    public static function sanitizeString(string $rawString, int $maxChars = 0): string
    {
        $result = trim($rawString);

        // Remove any control characters but allow most Unicode letters/numbers/punctuation
        $result = preg_replace('/\p{C}+/u', '', $result);
        //$result = preg_replace('/[^\P{C}]+/u', '', $result);

        // Collapse multiple spaces
        $result = preg_replace('/\s{2,}/', ' ', $result);

        // Truncate
        return $maxChars > 0 ? mb_substr($result, 0, $maxChars) : $result;
    }
}