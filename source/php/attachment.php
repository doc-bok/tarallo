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
}