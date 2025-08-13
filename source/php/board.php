<?php

declare(strict_types=1);

require_once __DIR__ . '/card.php';
require_once __DIR__ . '/label.php';
require_once __DIR__ . '/permission.php';

class Board
{
    const BOARD_CONTENT_BASE = "boards";
    const DEFAULT_BG = "images/tarallo-bg.jpg";
    const DEFAULT_BOARDTILE_BG = "images/boardtile-bg.jpg";

    /**
     * Retrieves board data for the given board ID and user,
     * including the user's permission level for that board.
     * Optionally includes card lists and cards.
     * @param int  $boardId          The ID of the board.
     * @param int  $minRole          Minimum role constant to require.
     * @param bool $includeCardLists Whether to include card lists.
     * @param bool $includeCards     Whether to include cards.
     * @return array The formatted board data.
     * @throws RuntimeException if database retrieval fails.
     */
    public static function getBoardData(
        int $boardId,
        int $minRole = Permission::USERTYPE_None,
        bool $includeCardLists = false,
        bool $includeCards = false,
        bool $includeCardContent = false,
        bool $includeAttachments = false
    ): array {
        Session::ensureSession();

        if (empty($_SESSION['user_id'])) {
            Logger::error("GetBoardData: No user_id in session");
            throw new RuntimeException("Not logged in");
        }

        $userId = (int) $_SESSION['user_id'];

        // Base board data with user permissions
        $sql = "
        SELECT b.*, p.user_type
        FROM tarallo_boards b
        INNER JOIN tarallo_permissions p ON b.id = p.board_id
        WHERE b.id = :board_id AND p.user_id = :user_id
        LIMIT 1
    ";
        $boardRecord = DB::fetchRow($sql, [
            'board_id' => $boardId,
            'user_id'  => $userId
        ]);

        if (!$boardRecord) {
            Logger::warning("GetBoardData: Board $boardId not found or no permissions for user $userId");
            throw new RuntimeException("Board not found or access denied");
        }

        // Check minimum required role
        if (!Permission::CheckPermissions($boardRecord['user_type'], $minRole, false)) {
            Logger::warning("GetBoardData: User $userId has insufficient permissions for board $boardId");
            throw new RuntimeException("Permission denied");
        }

        // Convert DB record to API-friendly structure
        $boardData = self::boardRecordToData($boardRecord);
        $boardData['user_type'] = (int) $boardRecord['user_type'];

        // Optionally pull card lists
        if ($includeCardLists) {
            $listSQL = "
            SELECT id, name, prev_list_id, next_list_id
            FROM tarallo_cardlists
            WHERE board_id = :board_id
            ORDER BY id ASC
        ";
            $boardData['cardlists'] = DB::fetchTable($listSQL, ['board_id' => $boardId]);
        }

        // Optionally pull cards
        if ($includeCards) {
            $sql = "SELECT * FROM tarallo_cards WHERE board_id = :board_id ORDER BY id ASC";

            $cardsRaw = DB::fetchTable($sql, ['board_id' => $boardId]);
            $cards = array_map([Card::class, 'cardRecordToData'], $cardsRaw);

            // If content not included in CardRecordToData, append from raw
            if ($includeCardContent && isset($cardsRaw[0]['content'])) {
                foreach ($cards as $i => &$c) {
                    $c['content'] = $cardsRaw[$i]['content'] ?? '';
                }
            }

            // Optionally add attachments per card
            if ($includeAttachments) {
                foreach ($cards as &$card) {
                    $attachments = DB::fetchTable(
                        "SELECT * FROM tarallo_attachments WHERE card_id = :cid",
                        ['cid' => $card['id']]
                    );
                    $card['attachmentList'] = array_map([Attachment::class, 'attachmentRecordToData'], $attachments);
                }
            }

            $boardData['cards'] = $cards;
        }

        Logger::debug(
            "GetBoardData: User $userId fetched board $boardId" .
            ($includeCardLists ? ' + lists' : '') .
            ($includeCards ? ' + cards' : '') .
            ($includeCardContent ? ' + content' : '') .
            ($includeAttachments ? ' + attachments' : '')
        );

        return $boardData;
    }

    /**
     * Convert a raw board DB record into an API-friendly data array.
     * @param array $boardRecord  DB row with required board fields.
     * @return array              Normalised board data ready for API output.
     */
    private static function boardRecordToData(array $boardRecord): array
    {
        // Defensive defaults
        $id              = (int)($boardRecord['id'] ?? 0);
        $userType        = (int)($boardRecord['user_type'] ?? Permission::USERTYPE_None);
        $title           = (string)($boardRecord['title'] ?? '');
        $closed          = !empty($boardRecord['closed']);
        $backgroundGuid  = $boardRecord['background_guid'] ?? null;
        $labelNames      = $boardRecord['label_names'] ?? [];
        $labelColors     = $boardRecord['label_colors'] ?? [];
        $lastModified    = isset($boardRecord['last_modified_time'])
            ? (int)$boardRecord['last_modified_time']
            : time();

        // Build output
        return [
            'id'                   => $id,
            'user_type'            => $userType,
            'title'                => $title,
            'closed'               => $closed,
            'background_url'       => self::getBackgroundUrl($id, $backgroundGuid),
            'background_thumb_url' => self::getBackgroundUrl($id, $backgroundGuid, true),
            'background_tiled'     => !$backgroundGuid,
            'label_names'          => is_array($labelNames) ? $labelNames : json_decode($labelNames, true),
            'label_colors'         => is_array($labelColors) ? $labelColors : json_decode($labelColors, true),
            'all_color_names'      => Label::DEFAULT_LABEL_COLORS,
            'last_modified_date'   => date('d M Y', $lastModified), // could delegate formatting to caller
        ];
    }

    /**
     * Build the URL for a board background or its thumbnail.
     * @param int         $boardID   The board ID.
     * @param string|null $guid      Background GUID in the format "{guid}#{ext}" or null.
     * @param bool        $thumbnail Whether to return thumbnail variant.
     * @return string                URL to the background image.
     */
    public static function getBackgroundUrl(int $boardID, ?string $guid, bool $thumbnail = false): string
    {
        // No custom background set → return default
        if (empty($guid)) {
            return $thumbnail ? self::DEFAULT_BOARDTILE_BG : self::DEFAULT_BG;
        }

        // Expect {guid}#{ext}
        $guidElems = explode('#', $guid, 2);
        if (count($guidElems) !== 2 || $guidElems[0] === '' || $guidElems[1] === '') {
            Logger::warning("GetBackgroundUrl: Malformed background GUID '$guid' for board $boardID");
            return $thumbnail ? self::DEFAULT_BOARDTILE_BG : self::DEFAULT_BG;
        }

        // Sanitise extension
        $safeExt = preg_replace('/[^a-z0-9]+/i', '', strtolower($guidElems[1])) ?: 'bin';
        $fileName = $guidElems[0] . ($thumbnail ? '-thumb.' : '.') . $safeExt;

        // Build path
        return rtrim(self::getBoardContentDir($boardID), '/\\') . '/' . $fileName;
    }

    /**
     * Get the filesystem directory path for a board's content.
     * @param int|string $boardID  The board's numeric ID.
     * @return string              Absolute or relative path to the board's content dir, ending with a slash.
     */
    public static function GetBoardContentDir(int|string $boardID): string
    {
        // Ensure integer and safe basename
        $id = (int) $boardID;
        if ($id <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Define a base directory constant somewhere in config if possible
        $baseDir = rtrim(self::BOARD_CONTENT_BASE ?? 'boards', '/\\');

        // Build and return normalised path with one trailing slash
        return "$baseDir/$id/";
    }
}