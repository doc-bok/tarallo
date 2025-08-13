<?php

declare(strict_types=1);

class Attachment
{
    /**
     * Build the base proxy URL for a board attachment.
     * @param int $boardID       Board ID
     * @param int $attachmentID  Attachment ID
     * @return string            Fully formed relative URL to the attachment proxy endpoint
     */
    public static function getAttachmentProxyUrl(int $boardID, int $attachmentID): string
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

}