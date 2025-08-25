<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use JetBrains\PhpStorm\NoReturn;

class File {

    public const MIME_TYPES = [
        // text
        'txt'   => 'text/plain',
        'htm'   => 'text/html',
        'html'  => 'text/html',
        'php'   => 'text/html',
        'css'   => 'text/css',
        'csv'   => 'text/csv',
        'ics'   => 'text/calendar',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'xml'   => 'application/xml',

        // flash/video legacy
        'swf'   => 'application/x-shockwave-flash',
        'flv'   => 'video/x-flv',

        // images
        'png'   => 'image/png',
        'jpe'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'jpg'   => 'image/jpeg',
        'gif'   => 'image/gif',
        'bmp'   => 'image/bmp',
        'ico'   => 'image/vnd.microsoft.icon',
        'tiff'  => 'image/tiff',
        'tif'   => 'image/tiff',
        'svg'   => 'image/svg+xml',
        'svgz'  => 'image/svg+xml',
        'webp'  => 'image/webp',

        // archives
        'zip'   => 'application/zip',
        'rar'   => 'application/x-rar-compressed',
        '7z'    => 'application/x-7z-compressed',
        'tar'   => 'application/x-tar',
        'gz'    => 'application/gzip',
        'bz2'   => 'application/x-bzip2',

        // executables/installers
        'exe'   => 'application/x-msdownload',
        'msi'   => 'application/x-msdownload',
        'cab'   => 'application/vnd.ms-cab-compressed',

        // audio
        'mp3'   => 'audio/mpeg',
        'ogg'   => 'audio/ogg',
        'oga'   => 'audio/ogg',
        'wav'   => 'audio/wav',
        'flac'  => 'audio/flac',

        // video
        'mp4'   => 'video/mp4',
        'm4v'   => 'video/x-m4v',
        'mov'   => 'video/quicktime',
        'qt'    => 'video/quicktime',
        'ogv'   => 'video/ogg',
        'webm'  => 'video/webm',

        // adobe
        'pdf'   => 'application/pdf',
        'psd'   => 'image/vnd.adobe.photoshop',
        'ai'    => 'application/postscript',
        'eps'   => 'application/postscript',
        'ps'    => 'application/postscript',

        // MS Office
        'doc'   => 'application/msword',
        'dot'   => 'application/msword',
        'rtf'   => 'application/rtf',
        'xls'   => 'application/vnd.ms-excel',
        'xlt'   => 'application/vnd.ms-excel',
        'ppt'   => 'application/vnd.ms-powerpoint',
        'pps'   => 'application/vnd.ms-powerpoint',

        // office openxml
        'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',

        // open office
        'odt'   => 'application/vnd.oasis.opendocument.text',
        'ods'   => 'application/vnd.oasis.opendocument.spreadsheet',

        // fonts
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject'
    ];

    /**
     * Gets the MIME type for a given filename or extension, with a fallback to finfo_file().
     * @param string      $filenameOrExt  The file name or extension.
     * @param string|null $filePath       Optional absolute path to file (if available) to enable finfo fallback.
     * @param string      $default        MIME type to return if not found.
     * @return string
     */
    public static function getMimeType(string $filenameOrExt, ?string $filePath = null, string $default = 'application/octet-stream'): string
    {
        // Extract extension from filename if possible
        $ext = strtolower(pathinfo($filenameOrExt, PATHINFO_EXTENSION) ?: $filenameOrExt);
        if ($ext === '') {
            $ext = strtolower(ltrim($filenameOrExt, '.')); // handle '.htaccess' style input
        }

        if (isset(self::MIME_TYPES[$ext])) {
            return self::MIME_TYPES[$ext];
        }

        // If filePath is provided and file exists, try finfo
        if ($filePath !== null && is_file($filePath) && extension_loaded('fileinfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($filePath);
            if ($mime !== false && $mime !== '') {
                return $mime;
            }
        }

        return $default;
    }

    /**
     * Resolve a relative FTP path to an absolute filesystem path.
     * @param string $relativePath A relative path (e.g. 'uploads/file.txt').
     * @return string Absolute path within FTP root.
     * @throws ApiException if FTP root is not configured.
     */
    public static function ftpDir(string $relativePath): string {
        Logger::info("Converting relative path [" . $relativePath . "] to absolute path.");

        $ftpRoot = rtrim(Config::getInstance()->get('FTP_ROOT'), '/');
        if (!$ftpRoot) {
            Logger::error("FTP root is not configured.");
            throw new ApiException("FTP root is not configured.");
        }

        Logger::info("FTP root is set to [" . $ftpRoot . "].");

        // Normalize slashes to forward slash for consistent processing
        $ftpRoot = str_replace('\\', '/', $ftpRoot);
        $relativePath = str_replace('\\', '/', $relativePath);

        Logger::info("Normalized FTP root is [" . $ftpRoot . "].");
        Logger::info("Normalized relative path [" . $relativePath . "].");

        // Remove trailing slash from ftpRoot and leading slashes from relativePath
        $ftpRoot = rtrim($ftpRoot, '/');
        $relativePath = ltrim($relativePath, '/');

        Logger::info("Trimmed FTP root is [" . $ftpRoot . "].");
        Logger::info("Trimmed relative path [" . $relativePath . "].");

        // Don't double up on FTP Root
        $path = str_starts_with($relativePath, $ftpRoot) ?
            $relativePath :
            $ftpRoot . '/' . ltrim($relativePath, '/');

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);
            } else {
                $parts[] = $segment;
            }
        }

        $resolvedPath = implode('/', $parts);

        Logger::info("Resolved path [" . $resolvedPath . "].");

        // On Windows, remove leading slash if it exists and root is drive letter
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {

            // Check if root looks like "C:"
            if (preg_match('/^[A-Za-z]:$/', $parts[0])) {

                // Add drive letter back with slash in Windows style
                $resolvedPath = $parts[0] . '/' . implode('/', array_slice($parts, 1));

                Logger::info("Windows resolved path [" . $resolvedPath . "].");
            }
        } else {
            // On non-Windows, prepend root slash
            $resolvedPath = '/' . $resolvedPath;
            Logger::info("Non-Windows resolved path [" . $resolvedPath . "].");
        }

        // Validate that resolved path starts with ftpRoot (both normalized)
        $normalFtpRoot = rtrim(str_replace('\\', '/', $ftpRoot), '/');
        $normalResolved = rtrim(str_replace('\\', '/', $resolvedPath), '/');

        if (!str_starts_with($normalResolved, $normalFtpRoot)) {
            Logger::error("Resolved path escapes FTP root([" . $ftpRoot. "]): [" . $resolvedPath . "]. Relative path is [" . $relativePath . "].");
            throw new ApiException("Resolved path escapes FTP root. [$resolvedPath]");
        }

        // For Windows, optionally convert slashes back to backslashes if needed
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $resolvedPath = str_replace('/', DIRECTORY_SEPARATOR, $resolvedPath);
        }

        Logger::info("Relative path [" . $relativePath . "] converted to absolute path [" . $resolvedPath . "].");
        return $resolvedPath;
    }

    /**
     * Ensures the directory for the given file path exists, creating it if necessary.
     * @param string $filePath  The file path (can be relative to FTP root).
     * @param int    $mode      Directory permissions (default 0775).
     * @return bool  True if the directory exists or was created successfully, false otherwise.
     * @throws ApiException if directory creation fails.
     */
    public static function prepareDir(string $filePath, int $mode = 0775): bool
    {
        // Resolve absolute directory path
        $absDir = self::ftpDir($filePath);

        if (is_dir($absDir)) {
            return true; // already exists
        }

        // Try to create the directory recursively
        if (@mkdir($absDir, $mode, true)) {
            Logger::info("Created directory: $absDir");
            return true;
        }

        // Recheck in case another process created it
        if (is_dir($absDir)) {
            return true;
        }

        $error = error_get_last()['message'] ?? 'unknown error';
        Logger::error("Failed to create directory: $absDir — $error");
        throw new ApiException("Failed to create directory '$absDir': $error");
    }

    /**
     * Prepares the directory for a file path and optionally creates the file if it doesn't exist.
     * @param string $filePath   Relative or absolute file path.
     * @param bool   $createFile If true, will create an empty file if it doesn’t exist.
     * @param int    $dirMode    Directory permissions (default: 0775).
     * @param int    $fileMode   File permissions if creating a new file (default: 0664).
     * @return string Absolute resolved file path.
     * @throws ApiException if directory or file creation fails.
     */
    public static function prepareFile(
        string $filePath,
        bool $createFile = false,
        int $dirMode = 0775,
        int $fileMode = 0664
    ): string {
        // Resolve absolute file path
        $absPath = self::ftpDir($filePath);

        // Ensure directory exists
        self::prepareDir($filePath, $dirMode);

        // Optionally create the file if missing
        if ($createFile && !file_exists($absPath)) {
            if (@touch($absPath)) {
                @chmod($absPath, $fileMode);
                Logger::info("Created file: $absPath");
            } else {
                $error = error_get_last()['message'] ?? 'unknown error';
                Logger::error("Failed to create file '$absPath': $error");
                throw new ApiException("Failed to create file '$absPath': $error");
            }
        }

        return $absPath;
    }

    /**
     * Safely writes contents to a file within the FTP root.
     * Creates directories and optionally the file if needed.
     * @param string $filePath   Relative or absolute path to the file.
     * @param string $contents   Content to write.
     * @param int    $flags      file_put_contents() flags (e.g. FILE_APPEND).
     * @param int    $dirMode    Directory permissions (default: 0775).
     * @param int    $fileMode   File permissions (default: 0664).
     * @return int   Number of bytes written.
     * @throws ApiException if the write fails.
     */
    public static function writeToFile(
        string $filePath,
        string $contents,
        int $flags = 0,
        int $dirMode = 0775,
        int $fileMode = 0664
    ): int {
        // Ensure the directory is ready and resolve absolute path
        $absPath = self::prepareFile($filePath, createFile: true, dirMode: $dirMode, fileMode: $fileMode);

        // Try to write with exclusive lock
        $flags |= LOCK_EX;

        $bytes = @file_put_contents($absPath, $contents, $flags);

        if ($bytes === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            Logger::error("Failed to write to file '$absPath': $error");
            throw new ApiException("Failed to write to file '$absPath': $error");
        }

        Logger::debug("Wrote $bytes bytes to file: $absPath");
        return $bytes;
    }

    /**
     * Reads a file from the FTP root as a string with safety checks and optional size limit.
     * @param int|null $maxBytes Optional maximum bytes to read (null for no limit).
     * @return string File contents.
     * @throws ApiException if file cannot be read.
     */
    public static function readFileAsString(string $filePath, ?int $maxBytes = null): string
    {
        $absPath = self::ftpDir($filePath);

        if (!is_file($absPath)) {
            Logger::error("readFileAsString: File not found '$absPath'");
            throw new ApiException("File not found: $absPath");
        }

        $handle = @fopen($absPath, 'rb');
        if (!$handle) {
            $error = error_get_last()['message'] ?? 'unknown error';
            Logger::error("readFileAsString: Failed to open '$absPath': $error");
            throw new ApiException("Unable to open file '$absPath': $error");
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            Logger::error("readFileAsString: Could not lock file '$absPath' for reading.");
            throw new ApiException("Unable to acquire shared lock on file '$absPath'");
        }

        $contents = $maxBytes !== null
            ? stream_get_contents($handle, $maxBytes + 1)
            : stream_get_contents($handle);

        flock($handle, LOCK_UN);
        fclose($handle);

        if ($contents === false) {
            Logger::error("readFileAsString: Failed to read from '$absPath'");
            throw new ApiException("Error reading from file: $absPath");
        }

        if ($maxBytes !== null && strlen($contents) > $maxBytes) {
            Logger::error("readFileAsString: File '$absPath' exceeds limit of $maxBytes bytes");
            throw new ApiException("File size exceeds limit of $maxBytes bytes: $absPath");
        }

        return $contents;
    }

    /**
     * Deletes a file from the FTP root safely with logging.
     * @param string $filePath   Relative or absolute path within FTP root.
     * @param bool   $mustExist  If true, throw an error if the file is missing. If false, silently skip missing files.
     * @return bool  True if the file was deleted, false if skipped/missing.
     * @throws ApiException if deletion fails when $mustExist is true.
     */
    public static function deleteFile(string $filePath, bool $mustExist = false): bool
    {
        $absPath = self::ftpDir($filePath);

        if (!file_exists($absPath)) {
            if ($mustExist) {
                Logger::error("deleteFile: File not found '$absPath'");
                throw new ApiException("File not found: $absPath");
            }
            Logger::debug("deleteFile: File '$absPath' does not exist, skipping");
            return false;
        }

        // Try to lock the file before deleting
        $fh = @fopen($absPath, 'rb');
        if ($fh) {
            @flock($fh, LOCK_EX); // exclusive lock while deleting
        }

        if (!@unlink($absPath)) {
            if ($fh) {
                @flock($fh, LOCK_UN);
                fclose($fh);
            }
            $error = error_get_last()['message'] ?? 'unknown error';
            Logger::error("deleteFile: Failed to delete '$absPath': $error");
            throw new ApiException("Unable to delete file '$absPath': $error");
        }

        if ($fh) {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }

        Logger::info("Deleted file: $absPath");
        return true;
    }

    /**
     * Deletes a directory and all its contents safely
     * @param string $dirPath    Relative or absolute path within FTP root.
     * @param bool   $mustExist  If true, throw if the directory doesn't exist.
     * @param bool   $dryRun     If true, list files/dirs without deleting.
     * @return bool  True if deleted (or would be deleted in dryRun), false if skipped/missing.
     * @throws ApiException on failure (unless $mustExist=false and dir missing).
     */
    public static function deleteDir(string $dirPath, bool $mustExist = false, bool $dryRun = false): bool
    {
        // Normalise trailing slash removal
        $dirPath = rtrim($dirPath, '/\\');

        $absDir = self::ftpDir($dirPath);

        if (!is_dir($absDir)) {
            if ($mustExist) {
                Logger::error("deleteDir: Directory not found '$absDir'");
                throw new ApiException("Directory not found: $absDir");
            }
            Logger::debug("deleteDir: Directory '$absDir' does not exist, skipping");
            return false;
        }

        self::deleteDirInternal($absDir, $dryRun);
        if (!$dryRun) {
            if (!@rmdir($absDir)) {
                $error = error_get_last()['message'] ?? 'unknown error';
                Logger::error("deleteDir: Failed to remove dir '$absDir': $error");
                throw new ApiException("Failed to remove directory $absDir: $error");
            }
            Logger::info("Deleted directory: $absDir");
        } else {
            Logger::info("[DRY-RUN] Would delete directory: $absDir");
        }

        return true;
    }

    /**
     * Recursively deletes all contents of a directory.
     * @param string $absDir  Absolute path (already resolved).
     * @param bool   $dryRun
     */
    private static function deleteDirInternal(string $absDir, bool $dryRun = false): void
    {
        $entries = array_diff(scandir($absDir), ['.', '..']);

        foreach ($entries as $entry) {
            $path = $absDir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {

                // Recurse into subdirectories
                self::deleteDirInternal($path, $dryRun);
                if (!$dryRun && !@rmdir($path)) {
                    $error = error_get_last()['message'] ?? 'unknown error';
                    Logger::error("deleteDirInternal: Failed to remove dir '$path': $error");
                    throw new ApiException("Failed to remove directory $path: $error");
                }
                if ($dryRun) {
                    Logger::info("[DRY-RUN] Would delete directory: $path");
                } else {
                    Logger::info("Deleted directory: $path");
                }
            } else {
                if ($dryRun) {
                    Logger::info("[DRY-RUN] Would delete file: $path");
                    continue;
                }

                // Lock before delete if possible
                if (is_file($path)) {
                    $fh = @fopen($path, 'rb');
                    if ($fh) {
                        @flock($fh, LOCK_EX);
                    }
                    if (!@unlink($path)) {
                        if ($fh) {
                            @flock($fh, LOCK_UN);
                            fclose($fh);
                        }
                        $error = error_get_last()['message'] ?? 'unknown error';
                        Logger::error("deleteDirInternal: Failed to delete file '$path': $error");
                        throw new ApiException("Failed to delete file $path: $error");
                    }
                    if ($fh) {
                        @flock($fh, LOCK_UN);
                        fclose($fh);
                    }
                    Logger::info("Deleted file: $path");
                } else {
                    // Symbolic link or special file
                    if (!@unlink($path)) {
                        $error = error_get_last()['message'] ?? 'unknown error';
                        Logger::error("deleteDirInternal: Failed to delete link '$path': $error");
                        throw new ApiException("Failed to delete link $path: $error");
                    }
                    Logger::info("Deleted link: $path");
                }
            }
        }
    }
    /**
     * Outputs a file to the browser with correct headers, optional download mode.
     * @param string $filePath   Relative or absolute path within FTP root.
     * @param string $contentType MIME type to send (e.g., "image/png").
     * @param string $fileName   Name for the download or inline display.
     * @param bool   $isDownload If true, forces download ("attachment"); otherwise displays inline.
     * @throws ApiException if file missing or unreadable.
     */
    #[NoReturn]
    public static function outputFile(
        string $filePath,
        string $contentType,
        string $fileName,
        bool $isDownload = false
    ): void {
        $absPath = self::ftpDir($filePath);

        if (!is_file($absPath) || !is_readable($absPath)) {
            Logger::error("outputFile: File not found or unreadable '$absPath'");
            http_response_code(404);
            exit("File not found.");
        }

        // Lock the file before streaming
        $fh = @fopen($absPath, 'rb');
        if (!$fh) {
            Logger::error("outputFile: Failed to open '$absPath' for reading.");
            http_response_code(500);
            exit("Internal Server Error");
        }

        if (!flock($fh, LOCK_SH)) {
            fclose($fh);
            Logger::error("outputFile: Could not acquire shared lock on '$absPath'");
            http_response_code(500);
            exit("Internal Server Error");
        }

        // Clean any previous output buffers
        if (ob_get_level()) {
            ob_end_clean();
        }

        $safeFileName = str_replace(["\r", "\n", '"'], '', $fileName);
        $contentDisposition  = $isDownload ? 'attachment' : 'inline';
        if (!$contentType) {
            $contentType = self::getMimeType($fileName, $absPath);
        }

        header("Content-Type: $contentType");
        header('Content-Length: ' . filesize($absPath));
        header("Content-Disposition: $contentDisposition; filename=\"$safeFileName\"");
        header("Cache-Control: no-cache, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");

        fpassthru($fh);

        flock($fh, LOCK_UN);
        fclose($fh);
        exit;
    }

    /**
     * Checks if a regular file exists within the FTP root and is readable.
     *
     * @param string $filePath Relative or absolute path within FTP root.
     * @param bool   $requireReadable If true, also checks that the file is readable.
     * @return bool True if the file exists (and is readable if $requireReadable is true), false otherwise.
     */
    public static function fileExists(string $filePath, bool $requireReadable = false): bool
    {
        try {
            $absPath = self::ftpDir($filePath);
        } catch (Throwable $e) {
            Logger::error("fileExists: Invalid path '$filePath': " . $e->getMessage());
            return false;
        }

        if (!is_file($absPath)) {
            Logger::debug("fileExists: File not found or not a regular file '$absPath'");
            return false;
        }

        if ($requireReadable && !is_readable($absPath)) {
            Logger::debug("fileExists: File exists but is not readable '$absPath'");
            return false;
        }

        return true;
    }

    /**
     * Handle chunked upload of a file (currently only board import supported).
     * @param array $request Must contain: 'context', 'chunkIndex', 'data'
     * @return array ['size' => int]
     * @throws InvalidArgumentException On bad input.
     * @throws ApiException On permission denied, invalid context, or file error.
     */
    public static function UploadChunk(array $request): array
    {
        // --- Require login ---
        if (!Session::isUserLoggedIn()) {
            throw new ApiException("Must be logged in to upload data", 403);
        }

        // --- Validate basic parameters ---
        foreach (['context', 'chunkIndex', 'data'] as $key) {
            if (!isset($request[$key])) {
                throw new InvalidArgumentException("Missing parameter: $key");
            }
        }

        $context    = (string)$request['context'];
        $chunkIndex = (int)$request['chunkIndex'];
        $dataB64    = (string)$request['data'];

        if ($chunkIndex < 0) {
            throw new InvalidArgumentException("Invalid chunkIndex: must be >= 0");
        }

        // --- Determine destination path based on context ---
        switch ($context) {
            case 'ImportBoard':
                if (empty($_SESSION['is_admin']) && !DB::getInstance()->getDBSetting('board_import_enabled')) {
                    throw new ApiException("Board import is disabled on this server (upload)", 403);
                }
                $destFilePath = Board::TEMP_EXPORT_FILE;
                break;
            default:
                throw new ApiException("Invalid upload context '$context'", 400);
        }

        // --- Decode base64 safely ---
        $chunkContent = base64_decode($dataB64, true);
        if ($chunkContent === false) {
            throw new ApiException("Invalid base64-encoded chunk data", 400);
        }

        // --- Ensure directory exists ---
        File::prepareDir($destFilePath);

        // --- Determine write mode ---
        $writeFlags = ($chunkIndex === 0) ? 0 : FILE_APPEND;

        // --- Write data ---
        if (!File::writeToFile($destFilePath, $chunkContent, $writeFlags)) {
            throw new ApiException("Failed to write chunk to storage", 500);
        }

        // --- Report file size ---
        $fullPath = File::ftpDir($destFilePath);
        if (!is_file($fullPath)) {
            throw new ApiException("Destination file missing after write", 500);
        }
        $size = filesize($fullPath);

        return ['size' => $size];
    }
}