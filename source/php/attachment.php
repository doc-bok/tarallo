<?php

declare(strict_types=1);

require_once __DIR__ . '/board.php';

class Attachment
{
    /**
     * Build the base proxy URL for a board attachment.
     * @param int $boardID       Board ID
     * @param int $attachmentID  Attachment ID
     * @return string            Fully formed relative URL to the attachment proxy endpoint
     */
    private static function getAttachmentProxyUrl(int $boardID, int $attachmentID): string
    {
        // Validate IDs
        if ($boardID <= 0 || $attachmentID <= 0) {
            throw new InvalidArgumentException("Invalid board or attachment ID");
        }

        // Build query string safely
        $params = [
            'OP'       => 'ProxyAttachment',
            'board_id' => $boardID,
            'id'       => $attachmentID
        ];

        return 'php/api.php?' . http_build_query($params);
    }

    /**
     * Convert an attachment DB record into API-friendly data.
     * @param array $attachmentRecord  DB row containing attachment fields.
     * @return array                   Normalised attachment data.
     */
    public static function attachmentRecordToData(array $attachmentRecord): array
    {
        $id        = isset($attachmentRecord['id']) ? (int)$attachmentRecord['id'] : 0;
        $boardId   = isset($attachmentRecord['board_id']) ? (int)$attachmentRecord['board_id'] : 0;
        $cardId    = isset($attachmentRecord['card_id']) ? (int)$attachmentRecord['card_id'] : 0;
        $name      = (string)($attachmentRecord['name'] ?? '');
        $extension = strtolower((string)($attachmentRecord['extension'] ?? ''));

        // Defensive: if extension is somehow empty, set to 'bin'
        if ($extension === '') {
            $extension = 'bin';
        }

        return [
            'id'        => $id,
            'name'      => $name,
            'extension' => $extension,
            'card_id'   => $cardId,
            'board_id'  => $boardId,
            'url'       => self::getAttachmentProxyUrlFromRecord($attachmentRecord),
            'thumbnail' => self::getThumbnailProxyUrlFromRecord($attachmentRecord)
        ];
    }

    /**
     * Get the public proxy URL for the thumbnail of a board attachment.
     * @param int $boardID       The board's ID.
     * @param int $attachmentID  The attachment's ID.
     * @return string            URL including a ?thumbnail=true query param.
     */
    public static function getThumbnailProxyUrl(int $boardID, int $attachmentID): string
    {
        $baseUrl = Attachment::getAttachmentProxyUrl($boardID, $attachmentID);

        // Determine correct separator
        $separator = (!str_contains($baseUrl, '?')) ? '?' : '&';

        return $baseUrl . $separator . 'thumbnail=true';
    }

    /**
     * Build an attachment proxy URL from a DB record.
     * @param array $record  Must contain 'board_id' and 'id' keys.
     * @return string        Proxy URL for the given attachment.
     * @throws InvalidArgumentException if required keys are missing or invalid.
     */
    private static function getAttachmentProxyUrlFromRecord(array $record): string
    {
        if (empty($record['board_id']) || empty($record['id'])) throw new InvalidArgumentException(
            'Attachment record must contain board_id and id'
        );

        $boardId      = (int) $record['board_id'];
        $attachmentId = (int) $record['id'];

        if ($boardId <= 0 || $attachmentId <= 0) {
            throw new InvalidArgumentException(
                "Invalid board_id ($boardId) or attachment id ($attachmentId)"
            );
        }

        return self::getAttachmentProxyUrl($boardId, $attachmentId);
    }

    /**
     * Get the thumbnail proxy URL from an attachment DB record if the file type supports thumbnails.
     * @param array $record Attachment record array, must include 'extension', 'board_id', 'id' keys.
     * @return string|null  Thumbnail proxy URL string if supported, or null if not supported or invalid data.
     * @throws InvalidArgumentException If required keys are missing or invalid types.
     */
    private static function getThumbnailProxyUrlFromRecord(array $record): ?string
    {
        // Validate presence of required keys
        foreach (['extension', 'board_id', 'id'] as $key) {
            if (!isset($record[$key])) {
                throw new InvalidArgumentException("Attachment record missing required key: $key");
            }
        }

        // Normalize file extension to lowercase for case-insensitive comparison
        $ext = strtolower($record['extension']);

        // Supported image file extensions for thumbnails
        $supportedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($ext, $supportedExtensions, true)) {
            // Unsupported extension: no thumbnail available
            return null;
        }

        // Cast IDs to integers and validate
        $boardId = (int)$record['board_id'];
        $attachmentId = (int)$record['id'];

        if ($boardId <= 0 || $attachmentId <= 0) {
            throw new InvalidArgumentException("Invalid 'board_id' or 'id' in attachment record");
        }

        // Return the safe, validated thumbnail URL
        return self::GetThumbnailProxyUrl($boardId, $attachmentId);
    }

    /**
     * Delete the physical files (main + thumbnail) for an attachment.
     * @param array $attachmentRecord Must contain necessary data for file path resolution.
     * @return void
     */
    public static function deleteAttachmentFiles(array $attachmentRecord): void
    {
        // Defensive resolution of file paths
        try {
            $attachmentPath = self::getAttachmentFilePathFromRecord($attachmentRecord);
        } catch (Throwable $e) {
            Logger::error("DeleteAttachmentFiles: Could not resolve attachment path - " . $e->getMessage());
            return;
        }

        try {
            $thumbnailPath = self::getThumbnailFilePathFromRecord($attachmentRecord);
        } catch (Throwable) {
            $thumbnailPath = null; // No thumbnail is fine (e.g. for non-images)
        }

        // Delete main file
        if ($attachmentPath && File::fileExists($attachmentPath)) {
            try {
                File::deleteFile($attachmentPath);
                Logger::debug("DeleteAttachmentFiles: Deleted file $attachmentPath");
            } catch (Throwable $e) {
                Logger::warning("DeleteAttachmentFiles: Failed to delete file $attachmentPath - " . $e->getMessage());
            }
        } else {
            Logger::warning("DeleteAttachmentFiles: Main file missing for attachment");
        }

        // Delete thumbnail if available
        if ($thumbnailPath && File::fileExists($thumbnailPath)) {
            try {
                File::deleteFile($thumbnailPath);
                Logger::debug("DeleteAttachmentFiles: Deleted thumbnail $thumbnailPath");
            } catch (Throwable $e) {
                Logger::warning("DeleteAttachmentFiles: Failed to delete thumbnail $thumbnailPath - " . $e->getMessage());
            }
        }
    }

    /**
     * Build the filesystem path for an attachment from its DB record.
     *
     * @param array $record Must contain 'board_id', 'guid', and 'extension'.
     * @return string       Filesystem path to the attachment.
     * @throws InvalidArgumentException If required keys are missing or invalid.
     */
    public static function getAttachmentFilePathFromRecord(array $record): string
    {
        foreach (['board_id', 'guid', 'extension'] as $key) {
            if (empty($record[$key])) {
                throw new InvalidArgumentException("Attachment record missing required key: $key");
            }
        }

        $boardId   = (int)$record['board_id'];
        $guid      = (string)$record['guid'];
        $extension = strtolower((string)$record['extension']);

        if ($boardId <= 0) {
            throw new InvalidArgumentException("Invalid board_id: $boardId");
        }

        return self::getAttachmentFilePath($boardId, $guid, $extension);
    }

    /**
     * Build the filesystem path for an attachment file.
     * @param int    $boardID   Board ID (must be > 0)
     * @param string $guid      Attachment GUID (sanitised alphanumeric + separators)
     * @param string $extension File extension (sanitised, lowercase, no leading dot)
     * @return string           Full filesystem path to the attachment.
     * @throws InvalidArgumentException if inputs are invalid.
     */
    public static function getAttachmentFilePath(int $boardID, string $guid, string $extension): string
    {
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Sanitize GUID (allow only safe chars, replace anything else with underscore)
        $guidSanitised = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $guid);
        if ($guidSanitised === '' || $guidSanitised !== $guid) {
            Logger::warning("GetAttachmentFilePath: GUID sanitised from '$guid' to '$guidSanitised'");
        }

        // Sanitise extension: lowercase, strip leading dots and any unsafe chars
        $extSanitised = strtolower(ltrim($extension, '.'));
        $extSanitised = preg_replace('/[^a-z0-9]+/', '', $extSanitised) ?: 'bin';

        // Build and return
        return rtrim(self::getAttachmentDir($boardID), '/\\')
            . DIRECTORY_SEPARATOR
            . $guidSanitised . '.' . $extSanitised;
    }

    /**
     * Get the filesystem directory for storing a board's attachments.
     * @param int $boardID Board ID (must be > 0)
     * @return string      Absolute or relative path, ending with a directory separator
     * @throws InvalidArgumentException if $boardID is invalid
     */
    private static function getAttachmentDir(int $boardID): string
    {
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // TODO: move 'a' to a constant so it can be changed centrally
        $subdir = 'a';

        // Ensure base dir is valid & normalised
        $baseDir = rtrim(Board::getBoardContentDir($boardID), '/\\');

        return $baseDir . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR;
    }

    /**
     * Build the filesystem path for an attachment's thumbnail from its DB record.
     * @param array $record Must contain 'board_id' and 'guid'.
     * @return string       Filesystem path to the thumbnail image.
     * @throws InvalidArgumentException if required data is missing or invalid.
     */
    public static function getThumbnailFilePathFromRecord(array $record): string
    {
        foreach (['board_id', 'guid'] as $key) {
            if (!isset($record[$key]) || $record[$key] === '') {
                throw new InvalidArgumentException(
                    "Attachment record missing required key: $key"
                );
            }
        }

        $boardId = (int) $record['board_id'];
        $guid    = (string) $record['guid'];

        if ($boardId <= 0) {
            throw new InvalidArgumentException("Invalid board_id: $boardId");
        }

        // Sanitise GUID to avoid unsafe path components
        $guidSanitised = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $guid);
        if ($guidSanitised !== $guid) {
            Logger::warning("GetThumbnailFilePathFromRecord: GUID sanitised from '$guid' to '$guidSanitised'");
        }

        return self::getThumbnailFilePath($boardId, $guidSanitised);
    }

    /**
     * Build the filesystem path for a board attachment's thumbnail.
     * @param int    $boardID The board's ID (must be > 0)
     * @param string $guid    Attachment GUID (sanitised alphanumeric/underscores/hyphens)
     * @param string $ext     Thumbnail extension (default 'jpg')
     * @return string         Full filesystem path
     * @throws InvalidArgumentException if inputs are invalid
     */
    public static function getThumbnailFilePath(int $boardID, string $guid, string $ext = 'jpg'): string
    {
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Sanitise GUID
        $guidSanitised = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $guid);
        if ($guidSanitised !== $guid) {
            Logger::warning("GetThumbnailFilePath: GUID sanitised from '$guid' to '$guidSanitised'");
        }

        // Sanitise and normalise extension
        $extSanitised = strtolower(ltrim($ext, '.'));
        $extSanitised = preg_replace('/[^a-z0-9]+/', '', $extSanitised) ?: 'jpg';

        return rtrim(self::getThumbnailDir($boardID), '/\\')
            . DIRECTORY_SEPARATOR
            . $guidSanitised . '.' . $extSanitised;
    }

    /**
     * Get the filesystem path to the thumbnail directory for a given board.
     * @param int $boardID Board ID (must be > 0)
     * @return string      Full path ending with a directory separator
     * @throws InvalidArgumentException If board ID is invalid
     */
    private static function getThumbnailDir(int $boardID): string
    {
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Store the subdirectory name centrally for easy changes later
        $subdir = 't';

        // Normalise base directory from GetAttachmentDir()
        $baseDir = rtrim(self::getAttachmentDir($boardID), '/\\');

        return $baseDir . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR;
    }

    /**
     * Upload an attachment
     * @param array $request The request parameters.
     * @return array|string[]
     */
    public static function uploadAttachment(array $request): array
    {
        Session::ensureSession();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        // Validate required inputs
        $boardId    = isset($request['board_id']) ? (int) $request['board_id'] : 0;
        $cardId     = isset($request['card_id']) ? (int) $request['card_id'] : 0;
        $filename   = trim($request['filename'] ?? '');
        $attachment = $request['attachment'] ?? '';

        if ($boardId <= 0 || $cardId <= 0 || $filename === '' || $attachment === '') {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // Check attachment size limit
        $maxAttachmentSizeKB = (int) DB::getDBSetting('attachment_max_size_kb');

        // Base64 encoding inflates size ~33% (hence * 0.75 to reverse)
        $attachmentSizeKB = (strlen($attachment) * 0.75) / 1024;
        if ($maxAttachmentSizeKB > 0 && $attachmentSizeKB > $maxAttachmentSizeKB) {
            http_response_code(400);
            return ['error' => "Attachment is too big! Max size is $maxAttachmentSizeKB KB"];
        }

        // Permission check: require Member role on board
        try {
            Board::GetBoardData($boardId, Permission::USERTYPE_Member);
        } catch (RuntimeException) {
            Logger::warning("UploadAttachment: User $userId no permission on board $boardId");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Validate card belongs to board
        try {
            Card::getCardData($boardId, $cardId);
        } catch (RuntimeException) {
            http_response_code(404);
            return ['error' => 'Card not found in this board'];
        }

        // Prepare attachment metadata
        $fileInfo = pathinfo($filename);
        $name      = self::cleanAttachmentName($fileInfo['filename'] ?? '');
        $extension = isset($fileInfo['extension']) ? strtolower($fileInfo['extension']) : 'bin';
        $guid      = uniqid('', true);

        // Insert attachment record in DB
        $insertSql = "INSERT INTO tarallo_attachments (name, guid, extension, card_id, board_id)
                  VALUES (:name, :guid, :extension, :card_id, :board_id)";
        try {
            $attachmentID = DB::insert($insertSql, [
                'name'      => $name,
                'guid'      => $guid,
                'extension' => $extension,
                'card_id'   => $cardId,
                'board_id'  => $boardId
            ]);

            if (!$attachmentID) {
                Logger::error("UploadAttachment: Failed to insert attachment record for board $boardId");
                http_response_code(500);
                return ['error' => 'Failed to save new attachment'];
            }
        } catch (Throwable $e) {
            Logger::error("UploadAttachment: Database error - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Database error saving attachment'];
        }

        // Decode base64 content and save file
        $filePath = Attachment::getAttachmentFilePath($boardId, $guid, $extension);
        $fileContent = base64_decode($attachment);

        if ($fileContent === false) {
            http_response_code(400);
            return ['error' => 'Invalid attachment base64 data'];
        }

        if (!File::writeToFile($filePath, $fileContent)) {
            Logger::error("UploadAttachment: Failed to write file to $filePath");
            http_response_code(500);
            return ['error' => 'Failed to save attachment file'];
        }

        // Create thumbnail if possible
        $thumbFilePath = Attachment::getThumbnailFilePath($boardId, $guid);
        self::createImageThumbnail($filePath, $thumbFilePath);
        if (File::fileExists($thumbFilePath)) {
            try {
                DB::query(
                    "UPDATE tarallo_cards SET cover_attachment_id = :attachment_id WHERE id = :card_id",
                    ['attachment_id' => $attachmentID, 'card_id' => $cardId]
                );
            } catch (Throwable $e) {
                Logger::error("UploadAttachment: Failed to set cover attachment - " . $e->getMessage());
                // Not fatal; continue without failing the upload
            }
        }

        DB::UpdateBoardModifiedTime($boardId);

        // Re-fetch attachment record and card data for response
        $attachmentRecord = self::GetAttachmentRecord($boardId, (int)$attachmentID);
        $cardRecord = Card::getCardData($boardId, $cardId);

        $response = Attachment::attachmentRecordToData($attachmentRecord);
        $response['card'] = Card::cardRecordToData($cardRecord);

        Logger::info("UploadAttachment: User $userId uploaded attachment $attachmentID to card $cardId in board $boardId");

        return $response;
    }

    /**
     * Sanitize an attachment name for safe storage.
     *
     * - Removes unsafe characters
     * - Collapses whitespace
     * - Limits length to 100 characters
     * - Preserves extension if possible
     *
     * @param string $name Raw attachment name (user-provided)
     * @return string Safe, truncated name
     */
    public static function cleanAttachmentName(string $name): string
    {
        $name = trim($name);

        // Prevent directory traversal
        $name = basename($name);

        // Replace illegal filesystem characters with underscores
        $name = preg_replace('/[^\p{L}\p{N}\s.\-_()]+/u', '_', $name);

        // Collapse multiple spaces/underscores
        $name = preg_replace('/[ _]+/', '_', $name);

        // Split name & extension
        $ext  = '';
        $base = $name;
        if (str_contains($name, '.')) {
            $parts = explode('.', $name);
            $ext   = array_pop($parts);
            $base  = implode('.', $parts);
        }

        // Enforce length limit (100 chars total)
        $maxBaseLength = $ext ? 100 - (strlen($ext) + 1) : 100;
        $base = substr($base, 0, $maxBaseLength);

        // Recombine
        return $ext ? "$base.$ext" : $base;
    }

    /**
     * Retrieve an attachment record, verifying it belongs to the given board.
     *
     * @param int $boardID      The board ID to check against.
     * @param int $attachmentID The attachment ID to fetch.
     * @return array            Attachment DB row.
     * @throws RuntimeException If not found or board mismatch.
     */
    public static function GetAttachmentRecord(int $boardID, int $attachmentID): array
    {
        if ($boardID <= 0 || $attachmentID <= 0) {
            throw new RuntimeException("Invalid board or attachment ID");
        }

        try {
            $attachmentRecord = DB::fetchRow(
                "SELECT * FROM tarallo_attachments WHERE id = :id",
                ['id' => $attachmentID]
            );
        } catch (Throwable $e) {
            Logger::error("GetAttachmentRecord: DB error fetching $attachmentID - {$e->getMessage()}");
            throw new RuntimeException("Database error while retrieving attachment");
        }

        if (!$attachmentRecord) {
            throw new RuntimeException("Attachment not found", 404);
        }

        if ((int)$attachmentRecord['board_id'] !== $boardID) {
            throw new RuntimeException("Attachment belongs to another board", 403);
        }

        return $attachmentRecord;
    }

    /**
     * Creates a JPEG thumbnail of a source image with a fixed width 256px,
     * maintaining aspect ratio, saving it to the destination path.
     * @param string $srcImgPath  Relative or absolute path within FTP root for source image.
     * @param string $destImgPath Relative or absolute path within FTP root for thumbnail.
     * @param int    $thumbWidth  Width of thumbnail in pixels (default: 256).
     * @param int    $dirMode     Directory permissions (default: 0777).
     * @param int    $jpegQuality JPEG quality 0-100 (default: 85).
     * @throws RuntimeException if any step fails.
     */
    public static function createImageThumbnail(
        string $srcImgPath,
        string $destImgPath,
        int $thumbWidth = 256,
        int $dirMode = 0777,
        int $jpegQuality = 85
    ): void
    {
        $srcAbsPath = File::ftpDir($srcImgPath);

        if (!is_file($srcAbsPath) || !is_readable($srcAbsPath)) {
            Logger::error("createImageThumbnail: Source image not found or unreadable: $srcAbsPath");
            throw new RuntimeException("Source image missing or unreadable: $srcAbsPath");
        }

        $srcInfo = @getimagesize($srcAbsPath);
        if ($srcInfo === false) {
            Logger::error("createImageThumbnail: Failed to get image size for $srcAbsPath");
            throw new RuntimeException("Unable to get image size: $srcAbsPath");
        }

        // Detect image type and create source image resource
        switch ($srcInfo[2]) {
            case IMAGETYPE_GIF:
                $srcImage = @imagecreatefromgif($srcAbsPath);
                break;
            case IMAGETYPE_JPEG:
                $srcImage = @imagecreatefromjpeg($srcAbsPath);
                break;
            case IMAGETYPE_PNG:
                $srcImage = @imagecreatefrompng($srcAbsPath);
                break;
            default:
                Logger::error("createImageThumbnail: Unsupported image type for $srcAbsPath");
                throw new RuntimeException("Unsupported image type for thumbnail: $srcAbsPath");
        }

        if ($srcImage === false) {
            Logger::error("createImageThumbnail: Failed to load image resource from $srcAbsPath");
            throw new RuntimeException("Failed to load image resource: $srcAbsPath");
        }

        // Calculate scaled height keeping aspect ratio
        $srcWidth = $srcInfo[0];
        $srcHeight = $srcInfo[1];
        $thumbHeight = (int)floor($thumbWidth * $srcHeight / $srcWidth);

        $destImage = imagecreatetruecolor($thumbWidth, $thumbHeight);

        // Preserve transparency for PNG and GIF by filling with transparent color
        if (in_array($srcInfo[2], [IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            imagecolortransparent($destImage, imagecolorallocatealpha($destImage, 0, 0, 0, 127));
            imagealphablending($destImage, false);
            imagesavealpha($destImage, true);
        }

        // Resample (resize) the image
        $resampled = imagecopyresampled(
            $destImage, $srcImage,
            0, 0, 0, 0,
            $thumbWidth, $thumbHeight,
            $srcWidth, $srcHeight
        );

        if (!$resampled) {
            imagedestroy($srcImage);
            imagedestroy($destImage);
            Logger::error("createImageThumbnail: Failed to resample image for $srcAbsPath");
            throw new RuntimeException("Failed to resize image: $srcAbsPath");
        }

        // Prepare destination directory
        $destAbsPath = File::ftpDir($destImgPath);
        $destAbsDir = dirname($destAbsPath);
        if (!is_dir($destAbsDir)) {
            if (!mkdir($destAbsDir, $dirMode, true) && !is_dir($destAbsDir)) {
                imagedestroy($srcImage);
                imagedestroy($destImage);
                Logger::error("createImageThumbnail: Failed to create directory $destAbsDir");
                throw new RuntimeException("Failed to create directory: $destAbsDir");
            }
        }

        // Save thumbnail as JPEG with given quality
        $saveSuccess = imagejpeg($destImage, $destAbsPath, $jpegQuality);
        imagedestroy($srcImage);
        imagedestroy($destImage);

        if (!$saveSuccess) {
            Logger::error("createImageThumbnail: Failed to save thumbnail to $destAbsPath");
            throw new RuntimeException("Failed to save thumbnail to: $destAbsPath");
        }

        Logger::info("Thumbnail created: $destAbsPath (width: {$thumbWidth}px, height: {$thumbHeight}px)");
    }
}