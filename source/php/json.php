<?php

declare(strict_types=1);

require_once __DIR__ . '/file.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/utils.php';

class Json
{
    private const DEFAULT_MAX_BYTES = 1048576; // 1 MB

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
     * @throws RuntimeException if the input cannot be read or exceeds limit.
     */
    public static function getRequestBody(int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        $body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($body === false) {
            throw new RuntimeException("Failed to read request body.");
        }

        if (strlen($body) > $maxBytes) {
            throw new RuntimeException("Request body exceeds {$maxBytes} bytes.");
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
            Logger::warning("decodePostJSON called but Content-Type is not application/json: {$contentType}");
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

    /**
     * Encode data as JSON string for output.
     * @param mixed $data
     * @param bool  $pretty  Pretty-print JSON for readability.
     * @throws RuntimeException if encoding fails.
     */
    public static function encodeJSON(mixed $data, bool $pretty = false): string
    {
        $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode($data, $options | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("JSON encode failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Sends a JSON response with appropriate headers and HTTP status code.
     */
    public static function sendJSON(mixed $data, int $statusCode = 200, bool $pretty = false): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo self::encodeJSON($data, $pretty);
        exit;
    }

    /**
     * Sends a JSON error response and exits.
     */
    public static function sendJSONError(string $message, int $statusCode = 400, ?array $extraData = null): void
    {
        $payload = ['error' => $message];
        if ($extraData) {
            $payload = array_merge($payload, $extraData);
        }
        self::sendJSON($payload, $statusCode);
    }



    /**
     * Safely encodes data as JSON and writes it to a file.
     * @param string $filePath   Relative or absolute path within FTP root.
     * @param mixed  $data       Data to encode as JSON (array, object, etc.).
     * @param bool   $pretty     Whether to pretty-print JSON for readability.
     * @param int    $dirMode    Directory permissions (default: 0775).
     * @param int    $fileMode   File permissions (default: 0664).
     * @return int   Number of bytes written.
     * @throws RuntimeException if encoding or writing fails.
     */
    public static function safeWriteJSON(
        string $filePath,
        mixed $data,
        bool $pretty = false,
        int $dirMode = 0775,
        int $fileMode = 0664
    ): int {
        // Encode JSON with your Json utility (throws on error)
        $jsonString = Json::encodeJSON($data, $pretty);

        // Resolve paths and prepare directory
        $absPath = File::ftpDir($filePath);
        File::prepareDir($filePath, $dirMode);

        $dir = dirname($absPath);
        $tempFile = tempnam($dir, 'tmpjson_');
        if ($tempFile === false) {
            throw new \RuntimeException("Failed to create temporary file in '{$dir}'");
        }

        // Write to temp file with locking
        $bytes = false;
        $fh = @fopen($tempFile, 'wb');
        if ($fh === false) {
            unlink($tempFile);
            throw new \RuntimeException("Failed to open temp file '{$tempFile}' for writing");
        }

        if (flock($fh, LOCK_EX)) {
            $bytes = fwrite($fh, $jsonString);
            fflush($fh);      // Flush PHP buffers
            @fsync($fh);      // Ensure OS flush
            flock($fh, LOCK_UN);
        }
        fclose($fh);

        if ($bytes === false) {
            unlink($tempFile);
            $error = error_get_last()['message'] ?? 'unknown error';
            Logger::error("safeWriteJSON: Failed to write to temp file '{$tempFile}': {$error}");
            throw new \RuntimeException("Failed to write JSON to {$filePath}: {$error}");
        }

        // Set correct permissions before rename
        @chmod($tempFile, $fileMode);

        // Atomic rename
        if (!@rename($tempFile, $absPath)) {
            unlink($tempFile);
            $error = error_get_last()['message'] ?? 'unknown error';
            Logger::error("safeWriteJSON: Failed to rename temp file to '{$absPath}': {$error}");
            throw new \RuntimeException("Failed to replace file '{$absPath}': {$error}");
        }

        Logger::debug("Atomically wrote {$bytes} bytes of JSON to file: {$absPath}");
        return $bytes;
    }

    /**
     * Safely reads and decodes JSON from a file, ignoring temporary atomic swap files.
     * @param string $filePath Relative or absolute path within FTP root.
     * @param bool   $throwOnError Whether to throw if JSON is invalid or file missing (default true).
     * @param int    $maxBytes Max bytes to read (default 1MB).
     * @return array Decoded JSON as an associative array. Empty array if file missing/invalid and $throwOnError is false.
     * @throws RuntimeException|InvalidArgumentException on read/decode errors if $throwOnError is true.
     */
    public static function safeReadJSON(
        string $filePath,
        bool $throwOnError = true,
        int $maxBytes = self::DEFAULT_MAX_BYTES
    ): array {
        $absPath = File::ftpDir($filePath);

        // Find and delete any temp files from incomplete atomic writes
        $dir = dirname($absPath);
        foreach (glob($dir . '/tmpjson_*') ?: [] as $tmp) {
            Logger::debug("safeReadJSON: Removing stale temp file '{$tmp}'");
            @unlink($tmp);
        }

        // Check main file exists
        if (!is_file($absPath)) {
            Logger::warning("safeReadJSON: File not found '{$absPath}'");
            if ($throwOnError) {
                throw new RuntimeException("JSON file not found: {$absPath}");
            }
            return [];
        }

        // Open and lock for reading
        $handle = @fopen($absPath, 'rb');
        if (!$handle) {
            $error = error_get_last()['message'] ?? 'unknown error';
            Logger::error("safeReadJSON: Failed to open '{$absPath}': {$error}");
            if ($throwOnError) {
                throw new RuntimeException("Unable to open JSON file '{$absPath}': {$error}");
            }
            return [];
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            Logger::error("safeReadJSON: Failed to acquire shared lock on '{$absPath}'");
            if ($throwOnError) {
                throw new RuntimeException("Unable to lock JSON file '{$absPath}' for reading.");
            }
            return [];
        }

        // Read with size limit
        $contents = stream_get_contents($handle, $maxBytes + 1);
        flock($handle, LOCK_UN);
        fclose($handle);

        if ($contents === false) {
            Logger::error("safeReadJSON: Failed to read from '{$absPath}'");
            if ($throwOnError) {
                throw new RuntimeException("Unable to read data from JSON file '{$absPath}'");
            }
            return [];
        }

        if (strlen($contents) > $maxBytes) {
            Logger::error("safeReadJSON: File '{$absPath}' exceeds {$maxBytes} bytes");
            if ($throwOnError) {
                throw new RuntimeException("JSON file too large: {$absPath}");
            }
            return [];
        }

        $trimmed = trim($contents);
        if ($trimmed === '') {
            return [];
        }

        try {
            return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Logger::error("safeReadJSON: JSON decode failed for '{$absPath}': " . $e->getMessage());
            if ($throwOnError) {
                throw new InvalidArgumentException("Invalid JSON in file '{$absPath}': " . $e->getMessage(), 0, $e);
            }
            return [];
        }
    }

    /**
     * Safely reads a JSON file, updates its data in a callback, and writes it back atomically.
     * @param string   $filePath Path within FTP root (relative or absolute).
     * @param callable $updater  Function that receives the current array and must return the updated array.
     * @param bool     $pretty   Whether to pretty-print JSON.
     * @param int      $dirMode  Directory permissions (default 0775).
     * @param int      $fileMode File permissions (default 0664).
     * @throws RuntimeException|InvalidArgumentException if read/write fails.
     */
    public static function safeUpdateJSON(
        string $filePath,
        callable $updater,
        bool $pretty = false,
        int $dirMode = 0775,
        int $fileMode = 0664
    ): void {
        // 1. Read current data safely (no exception if missing, start empty)
        $data = [];
        try {
            $data = self::safeReadJSON($filePath, throwOnError: false);
        } catch (\Throwable $e) {
            Logger::warning("safeUpdateJSON: Starting with empty data for '{$filePath}': " . $e->getMessage());
        }

        // 2. Update via callback
        try {
            $result = $updater($data);
            if (!is_array($result)) {
                throw new \RuntimeException("Updater callback must return an array, got " . gettype($result));
            }
        } catch (\Throwable $e) {
            Logger::error("safeUpdateJSON: Updater callback failed for '{$filePath}': " . $e->getMessage());
            throw $e;
        }

        // 3. Atomically write back updated data
        self::safeWriteJSON($filePath, $result, $pretty, $dirMode, $fileMode);

        Logger::debug("safeUpdateJSON: Updated JSON file '{$filePath}' successfully");
    }

    /**
     * Safely updates multiple JSON files atomically (per file) as a single "transaction".
     * @param array    $filePaths   Array of file paths relative to FTP root.
     * @param callable $updater     Callback receives an array of [filePath => dataArray],
     *                              must return same structure with updated arrays.
     * @param bool     $pretty      Pretty-print JSON.
     * @param int      $dirMode     Directory permissions (default: 0775).
     * @param int      $fileMode    File permissions (default: 0664).
     * @param bool $dryRun If true, does not write changes — just logs the intended differences.
     * @throws RuntimeException|InvalidArgumentException
     */
    public static function safeUpdateMultipleJSON(
        array $filePaths,
        callable $updater,
        bool $pretty = false,
        int $dirMode = 0775,
        int $fileMode = 0664,
        bool $dryRun = false
    ): void {
        $absPaths = [];
        $handles  = [];
        $dataMap  = [];

        try {
            // 1. Resolve paths & lock all files
            foreach ($filePaths as $relative) {
                $abs = File::ftpDir($relative);
                File::prepareDir($relative, $dirMode);

                // Clean up stale tmpjson_* files from aborted writes
                $dir = dirname($abs);
                foreach (glob($dir . '/tmpjson_*') ?: [] as $tmp) {
                    Logger::debug("safeUpdateMultipleJSON: Removing stale temp file '{$tmp}'");
                    @unlink($tmp);
                }

                // Open for read/write, create if non-existent
                $fh = @fopen($abs, 'c+');
                if (!$fh) {
                    throw new \RuntimeException("Failed to open file '{$abs}' for locking");
                }

                if (!flock($fh, LOCK_EX)) {
                    fclose($fh);
                    throw new \RuntimeException("Could not obtain exclusive lock on '{$abs}'");
                }

                $absPaths[$relative] = $abs;
                $handles[$relative]  = $fh;

                // Read current JSON (if any)
                $contents = stream_get_contents($fh);
                rewind($fh);
                $contents = trim($contents);
                $dataMap[$relative] = $contents !== ''
                    ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR)
                    : [];

                // Reset pointer for overwrite later
                ftruncate($fh, 0);
                rewind($fh);
            }

            // 2. Let the updater modify all datasets
            $updatedMap = $updater($dataMap);
            if (!is_array($updatedMap) || array_diff_key($dataMap, $updatedMap)) {
                throw new \RuntimeException("Updater must return an array with the same keys as given.");
            }

            // 3. If dry-run, just log changes & quit
            if ($dryRun) {
                foreach ($updatedMap as $rel => $arr) {
                    $diff = Utils::arrayDiffRecursive($dataMap[$rel], $arr);
                    if (empty($diff)) {
                        Logger::info("DRY-RUN: No changes for '{$rel}'");
                    } else {
                        Logger::info("DRY-RUN: Changes for '{$rel}':\n" . json_encode($diff, JSON_PRETTY_PRINT));
                    }
                }
                Logger::info("safeUpdateMultipleJSON: Dry-run completed for " . count($filePaths) . " files — no changes written.");
                return;
            }

            // 4. Write each file atomically via temp+rename
            foreach ($updatedMap as $rel => $arr) {
                $abs = $absPaths[$rel];

                $jsonString = Json::encodeJSON($arr, $pretty);

                $dir = dirname($abs);
                $tmp = tempnam($dir, 'tmpjson_');
                if ($tmp === false) {
                    throw new \RuntimeException("Failed to create temporary file in {$dir}");
                }

                if (file_put_contents($tmp, $jsonString, LOCK_EX) === false) {
                    @unlink($tmp);
                    throw new \RuntimeException("Failed to write temp file for {$abs}");
                }

                @chmod($tmp, $fileMode);
                if (!@rename($tmp, $abs)) {
                    @unlink($tmp);
                    throw new \RuntimeException("Failed to rename temp file to {$abs}");
                }
            }

            Logger::debug("safeUpdateMultipleJSON: Updated " . count($filePaths) . " files successfully.");

        } catch (\Throwable $e) {
            Logger::error("safeUpdateMultipleJSON failed: " . $e->getMessage());
            throw $e;
        } finally {
            // 5. Release all locks and close handles
            foreach ($handles as $fh) {
                @flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }
}