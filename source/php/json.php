<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class Json
{
    private const DEFAULT_MAX_BYTES = 52428800; // 50 MB

    /**
     * Get the CONTENT_TYPE string from the request.
     * @return string The CONTENT_TYPE string.
     */
    private static function getContentType(): string
    {
        return strtolower(trim($_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? ''));
    }

    /**
     * Checks if the current request is sending JSON.
     */
    public static function isJSONRequest(): bool
    {
        return str_starts_with(self::getContentType(), 'application/json');
    }

    /**
     * Reads the raw request body safely with a byte limit.
     * @throws ApiException if the input cannot be read or exceeds limit.
     */
    public static function getRequestBody(int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        $body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($body === false) {
            throw new ApiException("Failed to read request body.");
        }

        if (strlen($body) > $maxBytes) {
            throw new ApiException("Request body exceeds $maxBytes bytes.");
        }

        return $body;
    }

    /**
     * Decode JSON from the POST body into an associative array.
     * @param bool $throwOnError Whether to throw on invalid JSON (default: true).
     * @param int $maxBytes Maximum allowed size of input in bytes (default: 1MB).
     * @return array Decoded JSON as array.
     * @throws InvalidArgumentException If the JSON is invalid and $throwOnError is true.
     */
    public static function decodePostJSON(bool $throwOnError = true, int $maxBytes = self::DEFAULT_MAX_BYTES): array {
        // Check Content-Type header
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!self::isJSONRequest())  {
            Logger::warning("decodePostJSON called but Content-Type is not application/json: $contentType");
        }

        $body = trim(self::getRequestBody($maxBytes));
        if ($body === '') {
            return []; // No content
        }

        // Decode
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException $e) {
            Logger::error("JSON decode failed: " . $e->getMessage());
            if ($throwOnError) {
                throw new InvalidArgumentException("Invalid JSON in request: {$e->getMessage()}", 0, $e);
            }

            return [];
        }
    }
}