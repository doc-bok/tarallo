<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class Board
{
    const BOARD_CONTENT_BASE = "boards";
    const DEFAULT_BG = "images/tarallo-bg.jpg";
    const DEFAULT_BOARDTILE_BG = "images/boardtile-bg.jpg";
    const TEMP_EXPORT_PATH = "temp";
    const TEMP_EXPORT_FILE = "temp/export.zip";

    /**
     * Retrieves board data for the given board ID and user,
     * including the user's permission level for that board.
     * Optionally includes card lists and cards.
     * @param int  $boardId          The ID of the board.
     * @param int  $minRole          Minimum role constant to require.
     * @param bool $includeCardLists Whether to include card lists.
     * @param bool $includeCards     Whether to include cards.
     * @return array The formatted board data.
     * @throws ApiException if database retrieval fails.
     */
    public static function getBoardData(
        int $boardId,
        UserType $minRole = UserType::None,
        bool $includeCardLists = false,
        bool $includeCards = false,
        bool $includeCardContent = false,
        bool $includeAttachments = false
    ): array {
        Session::ensureSession();

        if (empty($_SESSION['user_id'])) {
            Logger::error("GetBoardData: No user_id in session");
            throw new ApiException("Not logged in");
        }

        $userId = (int) $_SESSION['user_id'];

        // Base board data with user permissions
        $sql = "
            SELECT b.*, p.user_type
            FROM tarallo_boards b
            INNER JOIN tarallo_permissions p ON b.id = p.board_id
            WHERE b.id = :board_id AND p.user_id = :user_id
            LIMIT 1";

        $boardRecord = DB::getInstance()->fetchRow($sql, [
            'board_id' => $boardId,
            'user_id'  => $userId
        ]);

        if (!$boardRecord) {
            Logger::warning("GetBoardData: Board $boardId not found or no permissions for user $userId");
            throw new ApiException("Board not found or access denied");
        }

        // Check minimum required role
        if (!Permission::CheckPermissions(UserType::from($boardRecord['user_type']), $minRole, false)) {
            Logger::warning("GetBoardData: User $userId has insufficient permissions for board $boardId");
            throw new ApiException("Permission denied");
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
            ORDER BY id
        ";
            $boardData['cardlists'] = DB::getInstance()->fetchTable($listSQL, ['board_id' => $boardId]);
        }

        // Optionally pull cards
        if ($includeCards) {
            $sql = "SELECT * FROM tarallo_cards WHERE board_id = :board_id ORDER BY id ";

            $cardsRaw = DB::getInstance()->fetchTable($sql, ['board_id' => $boardId]);
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
                    $attachments = DB::getInstance()->fetchTable(
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
        $userType        = (int)($boardRecord['user_type'] ?? UserType::None);
        $title           = (string)($boardRecord['title'] ?? '');
        $closed          = !empty($boardRecord['closed']);
        $backgroundGuid  = $boardRecord['background_guid'] ?? null;
        $labelNames      = $boardRecord['label_names'] ?? '';
        $labelColors     = $boardRecord['label_colors'] ?? '';
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
            'label_names'          => $labelNames,
            'label_colors'         => $labelColors,
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

    /**
     * Handle uploading a new background image for a board.
     * @param array $request Must include 'board_id', 'filename', and 'background' (base64).
     * @return array Updated board data with new background paths.
     * @throws InvalidArgumentException On invalid inputs.
     * @throws ApiException On file or database errors.
     */
    public static function UploadBackground(array $request): array
    {
        // Validate required keys
        foreach (['board_id', 'filename', 'background'] as $key) {
            if (empty($request[$key]) || !is_string($request[$key])) {
                throw new InvalidArgumentException("Missing or invalid parameter: $key");
            }
        }

        $boardID = (int) $request['board_id'];

        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID.");
        }

        // Get board data (throws if not found)
        $boardData = Board::GetBoardData($boardID);

        // Validate and sanitize file extension
        $fileInfo = pathinfo($request['filename']);
        if (empty($fileInfo['extension'])) {
            throw new InvalidArgumentException("Filename has no extension.");
        }

        $extension = strtolower(preg_replace('/[^a-z0-9]+/', '', $fileInfo['extension']));
        if (!$extension) {
            throw new InvalidArgumentException("Invalid file extension.");
        }

        // Create sanitized unique GUID for image file
        $guid = uniqid('', true) . '.' . $extension;

        // Decode base64 background image data safely
        $fileContent = base64_decode($request['background'], true);
        if ($fileContent === false) {
            throw new ApiException("Failed to decode background image data.");
        }

        // Paths for new background and thumbnail
        $newBackgroundPath = Board::getBackgroundUrl($boardID, $guid);
        $newBackgroundThumbPath = Board::getBackgroundUrl($boardID, $guid, true);

        // Write background file, throw if fails
        if (!File::writeToFile($newBackgroundPath, $fileContent)) {
            throw new ApiException("Failed to save background image file.");
        }

        // Generate thumbnail, throw if fails
        Attachment::createImageThumbnail($newBackgroundPath, $newBackgroundThumbPath);

        // Delete old background files if not default
        if (stripos($boardData['background_url'], Board::DEFAULT_BG) === false) {
            File::deleteFile($boardData['background_url']);
            File::deleteFile($boardData['background_thumb_url']);
        }

        // Update DB inside try-catch to handle DB errors
        try {
            // Use parameter array instead of global DB::getInstance()->setParam()
            DB::getInstance()->query(
                "UPDATE tarallo_boards SET background_guid = :background_guid WHERE id = :board_id",
                [
                    'background_guid' => $guid,
                    'board_id'        => $boardID
                ]
            );

            Board::updateBoardModifiedTime($boardID);
        } catch (Throwable $e) {
            // On DB failure, clean up newly created files
            File::deleteFile($newBackgroundPath);
            File::deleteFile($newBackgroundThumbPath);
            Logger::error("UploadBackground: DB update failed for board $boardID - " . $e->getMessage());
            throw new ApiException("Failed to update board background.");
        }

        // Update $boardData locally with new paths and return
        $boardData['background_url'] = $newBackgroundPath;
        $boardData['background_tiled'] = false;
        $boardData['background_thumb_url'] = $newBackgroundThumbPath;

        return $boardData;
    }

    /**
     * Update the title of a board.
     * @param array $request Must contain 'board_id' and 'title'.
     * @return array Updated board data.
     * @throws InvalidArgumentException On invalid input.
     * @throws ApiException On DB error.
     */
    public static function updateBoardTitle(array $request): array
    {
        foreach (['board_id', 'title'] as $key) {
            if (!isset($request[$key]) || $request[$key] === '') {
                throw new InvalidArgumentException("Missing or invalid parameter: $key");
            }
        }

        $boardID = (int) $request['board_id'];
        $rawTitle = (string) $request['title'];

        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Ensure the board exists / user has the right to edit
        Board::GetBoardData($boardID);

        // Sanitise and validate title
        $cleanTitle = Utils::sanitizeString($rawTitle, 64);
        if ($cleanTitle === '') {
            throw new InvalidArgumentException("Board title cannot be empty after cleaning.");
        }

        // Update in DB
        try {
            $rows = DB::getInstance()->query(
                "UPDATE tarallo_boards SET title = :title WHERE id = :id",
                [
                    'title' => $cleanTitle,
                    'id'    => $boardID
                ]
            );

            if ($rows < 1) {
                Logger::warning("updateBoardTitle: No board updated for ID $boardID");
            }
        } catch (Throwable $e) {
            Logger::error("updateBoardTitle: DB error updating board $boardID - " . $e->getMessage());
            throw new ApiException("Database error updating board title.");
        }

        // Mark as modified
        Board::updateBoardModifiedTime($boardID);

        // Return updated board data
        return Board::GetBoardData($boardID);
    }

    /**
     * Public API entry point for creating a new board.
     * @param array $request Must contain 'title', optional 'label_names', 'label_colors', 'background_guid'
     * @return array Newly created board data
     * @throws ApiException On user not logged in or DB error
     */
    public static function createNewBoard(array $request): array
    {
        if (!Session::isUserLoggedIn()) {
            throw new ApiException(
                "Cannot create a new board without being logged in.",
                403
            );
        }

        if (!isset($request['title']) || trim($request['title']) === '') {
            throw new InvalidArgumentException("Board title is required.");
        }

        $labelNames   = $request['label_names']  ?? '';
        $labelColors  = $request['label_colors'] ?? '';
        $background   = $request['background_guid'] ?? null;

        $newBoardID = self::createNewBoardInternal(
            $request['title'],
            $labelNames,
            $labelColors,
            $background
        );

        return self::GetBoardData($newBoardID);
    }

    /**
     * Internal helper to insert a new board and assign the current user as owner.
     * @param string      $title
     * @param string      $labelNames
     * @param string      $labelColors
     * @param string|null $backgroundGUID
     * @return int Board ID
     * @throws ApiException On DB errors
     */
    public static function createNewBoardInternal(
        string $title,
        string $labelNames = '',
        string $labelColors = '',
        ?string $backgroundGUID = null
    ): int {
        $cleanTitle = Utils::sanitizeString($title, 64);
        if ($cleanTitle === '') {
            throw new InvalidArgumentException("Board title cannot be empty after cleaning.");
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId || !is_numeric($userId)) {
            throw new ApiException("Invalid or missing user session.");
        }

        try {
            DB::getInstance()->beginTransaction();

            // Insert board record
            $newBoardID = DB::getInstance()->insert(
                "INSERT INTO tarallo_boards 
                (title, label_names, label_colors, last_modified_time, background_guid)
             VALUES
                (:title, :label_names, :label_colors, :last_modified_time, :background_guid)",
                [
                    'title'              => $cleanTitle,
                    'label_names'        => $labelNames,
                    'label_colors'       => $labelColors,
                    'last_modified_time' => time(),
                    'background_guid'    => $backgroundGUID
                ]
            );

            if (!$newBoardID) {
                throw new ApiException("Failed to insert new board.");
            }

            // Assign owner permission to the creator
            DB::getInstance()->query(
                "INSERT INTO tarallo_permissions (user_id, board_id, user_type)
             VALUES (:user_id, :board_id, :user_type)",
                [
                    'user_id'  => (int)$userId,
                    'board_id' => (int)$newBoardID,
                    'user_type'=> UserType::Owner
                ]
            );

            DB::getInstance()->commit();

            Logger::info("Board '$cleanTitle' [ID $newBoardID] created by user $userId");

        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("createNewBoardInternal: Failed to create board '$title' - " . $e->getMessage());
            throw new ApiException("Failed to create board.");
        }

        return (int)$newBoardID;
    }

    /**
     * Mark a board as closed.
     * @param array $request Must contain 'id' (board ID).
     * @return array Updated board data.
     * @throws InvalidArgumentException On missing/invalid parameters.
     * @throws ApiException On DB error.
     */
    public static function closeBoard(array $request): array
    {
        // Validate input
        if (!isset($request['id']) || !is_numeric($request['id'])) {
            throw new InvalidArgumentException("Missing or invalid board ID.");
        }

        $boardID = (int) $request['id'];
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Ensure board exists and enforce permissions (adjust required user type as needed)
        $boardData = self::GetBoardData($boardID /*, UserTypes::USERTYPE_Owner */);

        // Attempt to mark as closed
        try {
            $rows = DB::getInstance()->query(
                "UPDATE tarallo_boards SET closed = 1 WHERE id = :id",
                ['id' => $boardID]
            );

            if ($rows < 1) {
                Logger::warning("closeBoard: No board updated for ID $boardID (already closed?)");
            }
        } catch (Throwable $e) {
            Logger::error("closeBoard: Failed to close board $boardID - " . $e->getMessage());
            throw new ApiException("Database error while closing board.");
        }

        // Update last-modified timestamp
        Board::updateBoardModifiedTime($boardID);

        // Reflect the change in the returned board data
        $boardData['closed'] = 1;

        return $boardData;
    }

    /**
     * Reopen a board (set closed = 0).
     * @param array $request Must contain 'id' (board ID).
     * @return array Updated board data.
     * @throws InvalidArgumentException On invalid input.
     * @throws ApiException On DB error.
     */
    public static function reopenBoard(array $request): array
    {
        if (!isset($request['id']) || !is_numeric($request['id'])) {
            throw new InvalidArgumentException("Missing or invalid board ID.");
        }

        $boardID = (int) $request['id'];
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Load board and optionally check permissions
        $boardData = self::GetBoardData($boardID /*, UserTypes::USERTYPE_Owner */);

        try {
            $rows = DB::getInstance()->query(
                "UPDATE tarallo_boards SET closed = 0 WHERE id = :id",
                ['id' => $boardID]
            );

            if ($rows < 1) {
                Logger::warning("ReopenBoard: No board updated for ID $boardID (possibly already open)");
            }
        } catch (Throwable $e) {
            Logger::error("ReopenBoard: Failed to reopen board $boardID - " . $e->getMessage());
            throw new ApiException("Database error while reopening board.");
        }

        Board::updateBoardModifiedTime($boardID);

        $boardData['closed'] = 0;

        return $boardData;
    }

    /**
     * Permanently delete a closed board and all related data/files.
     * @param array $request Must contain 'id' (int board ID)
     * @return array Deleted board data
     * @throws InvalidArgumentException On invalid params
     * @throws ApiException On permission issues or DB/file errors
     */
    public static function deleteBoard(array $request): array
    {
        // Validate input
        if (!isset($request['id']) || !is_numeric($request['id'])) {
            throw new InvalidArgumentException("Missing or invalid board ID.");
        }
        $boardID = (int)$request['id'];
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Permission: only owner/admin can delete boards
        $boardData = Board::GetBoardData($boardID /* , UserTypes::USERTYPE_Owner */);

        // Must be closed before deletion
        if (empty($boardData['closed'])) {
            throw new ApiException("Cannot delete an open board.", 400);
        }

        // Get attachments before DB deletion so we can delete files
        $attachments = DB::getInstance()->fetchTable(
            "SELECT * FROM tarallo_attachments WHERE board_id = :board_id",
            ['board_id' => $boardID]
        );

        try {
            DB::getInstance()->beginTransaction();

            // Delete dependent records first (avoids orphan FKs)
            DB::getInstance()->query("DELETE FROM tarallo_cards WHERE board_id = :board_id", ['board_id' => $boardID]);
            DB::getInstance()->query("DELETE FROM tarallo_cardlists WHERE board_id = :board_id", ['board_id' => $boardID]);
            DB::getInstance()->query("DELETE FROM tarallo_attachments WHERE board_id = :board_id", ['board_id' => $boardID]);
            DB::getInstance()->query("DELETE FROM tarallo_permissions WHERE board_id = :board_id", ['board_id' => $boardID]);

            // Finally delete the board record
            DB::getInstance()->query("DELETE FROM tarallo_boards WHERE id = :board_id", ['board_id' => $boardID]);

            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("deleteBoard: Failed to delete board $boardID - " . $e->getMessage());
            throw new ApiException("Failed to delete board data.");
        }

        // Delete attachment files now that rows are gone
        foreach ($attachments as $att) {
            try {
                Attachment::deleteAttachmentFiles($att);
            } catch (Throwable $t) {
                Logger::warning("deleteBoard: Failed to delete attachment file for ID {$att['id']} - " . $t->getMessage());
            }
        }

        // Remove board directory
        try {
            File::deleteDir(Board::getBoardContentDir($boardID));
        } catch (Throwable $t) {
            Logger::warning("deleteBoard: Failed to delete board directory for board $boardID - " . $t->getMessage());
        }

        Logger::info("Board $boardID deleted.");

        return $boardData;
    }

    /**
     * Import a board previously exported
     * @return array The newly created board
     */
    public static function importBoard(): array
    {
        self::assertImportAllowed();

        if (!Session::isUserLoggedIn()) {
            throw new ApiException("Must be logged in to import a board", 403);
        }

        $zip = self::openExportZip();

        $boardExportData = self::loadExportMetadata($zip);
        self::validateExportSchema($boardExportData);

        // Build ID mapping for new DB inserts
        $cardlistIndex = self::buildNewIds('tarallo_cardlists', $boardExportData['cardlists']);
        $cardIndex     = self::buildNewIds('tarallo_cards', $boardExportData['cards']);
        $attachIndex   = self::buildNewIds('tarallo_attachments', $boardExportData['attachments']);

        try {
            DB::getInstance()->beginTransaction();

            // Create new board record
            $newBoardID = Board::createNewBoardInternal(
                $boardExportData['title'],
                $boardExportData['label_names'],
                $boardExportData['label_colors'],
                $boardExportData['background_guid']
            );

            self::insertCardlists($boardExportData['cardlists'], $cardlistIndex, $newBoardID);
            self::insertCards($boardExportData['cards'], $cardIndex, $cardlistIndex, $attachIndex, $newBoardID);
            self::insertAttachments($boardExportData['attachments'], $attachIndex, $cardIndex, $newBoardID);

            // Extract files now
            self::extractBoardFiles($zip, $newBoardID);

            DB::getInstance()->commit();

        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("Board import failed: " . $e->getMessage());
            throw new ApiException("Board import failed: {$e->getMessage()}", 500);
        }

        return Board::GetBoardData($newBoardID);
    }

    /**
     * Check the user is allowed to import a board
     */
    private static function assertImportAllowed(): void
    {
        if (!($_SESSION['is_admin'] ?? false)
            && !DB::getInstance()->getDBSetting('board_import_enabled')) {
            throw new ApiException("Board import is disabled on this server", 403);
        }
    }

    /**
     * Open the zip fil from a previous export
     * @return ZipArchive The opened zip archive
     */
    private static function openExportZip(): ZipArchive
    {
        $zip = new ZipArchive();
        $path = File::ftpDir(self::TEMP_EXPORT_FILE);

        if ($zip->open($path) !== true) {
            throw new ApiException("Export zip not found", 500);
        }

        // ZIP Slip prevention
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                $zip->close();
                throw new ApiException("Unsafe file path in archive", 400);
            }
        }
        return $zip;
    }

    /**
     * Load the metadata from the export file
     * @param ZipArchive $zip The opened zip archive
     * @return array The metadata
     */
    private static function loadExportMetadata(ZipArchive $zip): array
    {
        $dbExportJson = $zip->getFromName('db.json');
        if ($dbExportJson === false) {
            throw new ApiException("Invalid export file: missing db.json", 400);
        }

        $data = json_decode($dbExportJson, true);
        if (!is_array($data)) {
            throw new ApiException("Invalid db.json content", 400);
        }
        return $data;
    }

    /**
     * Validate the export uses the correct schema
     * @param array $data The export data
     */
    private static function validateExportSchema(array $data): void
    {
        foreach (['title', 'label_names', 'label_colors', 'background_guid', 'cardlists', 'cards', 'attachments'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ApiException("Export data missing: $key", 400);
            }
        }
    }

    /**
     * Set up new IDs for the import
     * @param string $table The table to import to
     * @param array $entities The entities to write
     * @return array The entities mapped to their new IDs
     */
    private static function buildNewIds(string $table, array $entities): array
    {
        $maxId = (int) DB::getInstance()->fetchOne("SELECT MAX(id) FROM $table") + 1;
        $map = DB::getInstance()->rebuildDBIndex($entities, 'id', $maxId);
        $map[0] = 0; // for unlinked placeholders
        return $map;
    }

    /**
     * Insert the card lists
     * @param array $lists The cardlists to insert
     * @param array $idMap The map of IDs
     * @param int $boardID The board's ID
     */
    private static function insertCardlists(array $lists, array $idMap, int $boardID): void
    {
        if (!$lists) return;
        $placeholders = [];
        $params = [];
        foreach ($lists as $list) {
            $placeholders[] = '(?, ?, ?, ?, ?)';
            $params[] = $idMap[$list['id']];
            $params[] = $boardID;
            $params[] = $list['name'];
            $params[] = $idMap[$list['prev_list_id']];
            $params[] = $idMap[$list['next_list_id']];
        }
        DB::getInstance()->query(
            "INSERT INTO tarallo_cardlists (id, board_id, name, prev_list_id, next_list_id) VALUES " . implode(',', $placeholders),
            $params
        );
    }

    /**
     * Insert the cards
     * @param array $cards The cards to insert
     * @param array $cardMap The map to card IDs
     * @param array $listMap The map to list IDs
     * @param array $attachMap The map to attachment IDs
     * @param int $boardID The board ID
     */
    private static function insertCards(array $cards, array $cardMap, array $listMap, array $attachMap, int $boardID): void
    {
        if (!$cards) return;
        $placeholders = [];
        $params = [];
        foreach ($cards as $card) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $params[] = $cardMap[$card['id']];
            $params[] = $card['title'];
            $params[] = $card['content'];
            $params[] = $cardMap[$card['prev_card_id']];
            $params[] = $cardMap[$card['next_card_id']];
            $params[] = $listMap[$card['cardlist_id']];
            $params[] = $boardID;
            $params[] = $attachMap[$card['cover_attachment_id']];
            $params[] = $card['last_moved_time'];
            $params[] = $card['label_mask'];
            $params[] = $card['flags'];
        }
        DB::getInstance()->query(
            "INSERT INTO tarallo_cards (id, title, content, prev_card_id, next_card_id, cardlist_id, board_id, cover_attachment_id, last_moved_time, label_mask, flags) VALUES " .
            implode(',', $placeholders),
            $params
        );
    }

    /**
     * Insert the attachments
     * @param array $attachments The attachments to insert
     * @param array $attachMap The map to attachment IDs
     * @param array $cardMap The map to card IDs
     * @param int $boardID The board ID
     */
    private static function insertAttachments(array $attachments, array $attachMap, array $cardMap, int $boardID): void
    {
        if (!$attachments) return;
        $placeholders = [];
        $params = [];
        foreach ($attachments as $att) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?)';
            $params[] = $attachMap[$att['id']];
            $params[] = $att['name'];
            $params[] = $att['guid'];
            $params[] = $att['extension'];
            $params[] = $cardMap[$att['card_id']];
            $params[] = $boardID;
        }
        DB::getInstance()->query(
            "INSERT INTO tarallo_attachments (id, name, guid, extension, card_id, board_id) VALUES " .
            implode(',', $placeholders),
            $params
        );
    }

    /**
     * Read the files for the specified board from the zip archive
     * @param ZipArchive $zip The opened zip archive
     * @param int $boardID The board ID
     */
    private static function extractBoardFiles(ZipArchive $zip, int $boardID): void
    {
        $boardFolder = Board::getBoardContentDir($boardID);
        if (!$zip->extractTo(File::ftpDir($boardFolder))) {
            throw new ApiException("Extraction failed", 500);
        }
        $zip->close();
        File::deleteFile($boardFolder . "db.json");
        File::deleteFile(self::TEMP_EXPORT_FILE);
    }

    /**
     * Import a board from Trello
     * @param array $request The request parameters
     * @return array The newly created board
     */
    public static function importFromTrello(array $request): array
    {
        // Feature flag & permission check
        if (!($_SESSION['is_admin'] ?? false) && !DB::getInstance()->getDBSetting('trello_import_enabled')) {
            throw new ApiException("Trello import disabled", 403);
        }
        if (!Session::isUserLoggedIn()) {
            throw new ApiException("Must be logged in to import board", 403);
        }

        if (empty($request['trello_export']) || !is_array($request['trello_export'])) {
            throw new InvalidArgumentException("Missing or invalid Trello export data");
        }
        $trello = $request['trello_export'];

        // Validate required top-level keys
        foreach (['name', 'labelNames', 'lists', 'cards', 'checklists'] as $key) {
            if (!isset($trello[$key])) {
                throw new InvalidArgumentException("Trello export missing key: $key");
            }
        }

        // Prepare DB
        $nextCardID = (int) DB::getInstance()->fetchOne("SELECT MAX(id) FROM tarallo_cards") + 1;

        DB::getInstance()->beginTransaction();
        try {
            // 1. Create board
            $newBoardID = Board::createNewBoardInternal((string) $trello['name']);

            // 2. Prepare & add labels
            $labelNames = [];
            $labelColors = [];
            foreach ($trello['labelNames'] as $val) {
                $val = trim((string)$val);
                if ($val !== '') {
                    $labelNames[] = Label::CleanLabelName($val);
                    $labelColors[] = Label::DEFAULT_LABEL_COLORS[count($labelColors) % count(Label::DEFAULT_LABEL_COLORS)];
                }
            }
            if ($labelNames) {
                Label::UpdateBoardLabelsInternal($newBoardID, $labelNames, $labelColors);
            }

            // 3. Iterate lists
            $prevCardlistID = 0;
            foreach ($trello['lists'] as $trelloList) {
                if (!empty($trelloList['closed'])) {
                    continue; // skip archived
                }

                $newListData = CardList::addNewCardListInternal(
                    $newBoardID,
                    $prevCardlistID,
                    trim((string)$trelloList['name'])
                );
                $newCardlistID = $newListData['id'];

                // Filter cards for this list & skip archived
                $cardsForList = array_filter($trello['cards'], function ($c) use ($trelloList) {
                    return !$c['closed'] && $c['idList'] === $trelloList['id'];
                });

                if ($cardsForList) {
                    usort($cardsForList, [self::class, 'compareTrelloSortedItems']);
                    self::bulkInsertCardsFromTrello($cardsForList, $newCardlistID, $newBoardID, $labelNames, $nextCardID, $trello['checklists']);
                }

                $prevCardlistID = $newCardlistID;
            }

            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("Trello import failed: " . $e->getMessage());
            throw new ApiException("Board import from Trello failed");
        }

        return Board::GetBoardData($newBoardID);
    }

    /**
     * Insert cards from Trello in bulk
     * @param array $cards The cards to add
     * @param int $listID The card list ID
     * @param int $boardID The board ID
     * @param array $labelNames The label names
     * @param int $nextCardID The next card ID
     * @param array $allChecklists The checklists
     */
    private static function bulkInsertCardsFromTrello(array $cards, int $listID, int $boardID, array $labelNames, int &$nextCardID, array $allChecklists): void
    {
        $placeholders = [];
        $params = [];

        $lastIndex = count($cards) - 1;
        foreach (array_values($cards) as $i => $card) {
            // Last moved time from due date
            $lastMovedTime = 0;
            if (!empty($card['due'])) {
                $due = DateTime::createFromFormat("Y-m-d*H:i:s.v+", $card['due']);
                if ($due) $lastMovedTime = $due->getTimestamp();
            }

            // Label mask
            $labelMask = 0;
            foreach ($card['labels'] ?? [] as $label) {
                $idx = array_search($label['name'], $labelNames, true);
                if ($idx !== false) {
                    $labelMask |= (1 << $idx);
                }
            }

            // Checklist content markdown
            $clistContent = '';
            foreach ($card['idChecklists'] ?? [] as $chkId) {
                $chkData = array_values(array_filter($allChecklists, fn($c) => $c['id'] === $chkId))[0] ?? null;
                if (!$chkData) continue;

                usort($chkData['checkItems'], [self::class, 'compareTrelloSortedItems']);
                $clistContent .= "\n## " . $chkData['name'];
                foreach ($chkData['checkItems'] as $item) {
                    $clistContent .= "\n- [" . ($item['state'] === 'complete' ? 'x' : ' ') . "] " . $item['name'];
                }
                $clistContent .= "\n";
            }

            // Params for one row
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $params[] = $nextCardID;                                  // id
            $params[] = trim((string)$card['name']);                  // title
            $params[] = $card['desc'] . $clistContent;        // content
            $params[] = $i === 0 ? 0 : ($nextCardID - 1);              // prev
            $params[] = $i === $lastIndex ? 0 : ($nextCardID + 1);     // next
            $params[] = $listID;                                      // cardlist_id
            $params[] = $boardID;                                     // board_id
            $params[] = 0;                                            // cover_attachment_id
            $params[] = $lastMovedTime;
            $params[] = $labelMask;

            $nextCardID++;
        }

        DB::getInstance()->query(
            "INSERT INTO tarallo_cards (id, title, content, prev_card_id, next_card_id, cardlist_id, board_id, cover_attachment_id, last_moved_time, label_mask) VALUES " .
            implode(',', $placeholders),
            $params
        );
    }

    /**
     * Comparator for Trello items based on their "pos" field.
     * @param array $a Item with a 'pos' key (numeric).
     * @param array $b Item with a 'pos' key (numeric).
     * @return int -1 if $a < $b, 1 if $a > $b, 0 if equal.
     */
    private static function compareTrelloSortedItems(array $a, array $b): int
    {
        $posA = isset($a['pos']) ? (float)$a['pos'] : 0.0;
        $posB = isset($b['pos']) ? (float)$b['pos'] : 0.0;

        if ($posA === $posB) {
            return 0;
        }

        return ($posA < $posB) ? -1 : 1;
    }

    /**
     * Export a board's DB data and files as a downloadable ZIP.
     *
     * @param array $request Must contain 'board_id' (int)
     * @throws InvalidArgumentException if the arguments aren't valid
     * @throws ApiException if there is an error reading from the database
     * @throws Throwable for any other errors
     */
    public static function ExportBoard(array $request): void
    {
        // --- Feature gating ---
        if (!($_SESSION['is_admin'] ?? false) && !DB::getInstance()->getDBSetting('board_export_enabled')) {
            throw new ApiException("Board export is disabled on this server", 403);
        }

        // --- Input validation ---
        if (!isset($request['board_id']) || !is_numeric($request['board_id'])) {
            throw new InvalidArgumentException("Missing or invalid board_id");
        }
        $boardID = (int)$request['board_id'];
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID");
        }

        // --- Access control ---
        $boardData = Board::GetBoardData($boardID, UserType::Moderator);

        // --- Prepare export ZIP ---
        $exportPath = Board::TEMP_EXPORT_PATH . '/export_' . uniqid('', true) . '.zip';
        File::prepareDir(dirname($exportPath));

        $zip = new ZipArchive();
        if ($zip->open(File::ftpDir($exportPath), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ApiException("Export failed: zip creation error with path $exportPath", 500);
        }

        try {
            // --- Create board data array ---
            $boardExportData = DB::getInstance()->fetchRow(
                "SELECT * FROM tarallo_boards WHERE id = :id",
                ['id' => $boardID]
            );
            $boardExportData['cardlists'] = DB::getInstance()->fetchTable(
                "SELECT * FROM tarallo_cardlists WHERE board_id = :board_id",
                ['board_id' => $boardID]
            );
            $boardExportData['cards'] = DB::getInstance()->fetchTable(
                "SELECT * FROM tarallo_cards WHERE board_id = :board_id",
                ['board_id' => $boardID]
            );
            $boardExportData['attachments'] = DB::getInstance()->fetchTable(
                "SELECT * FROM tarallo_attachments WHERE board_id = :board_id",
                ['board_id' => $boardID]
            );
            $boardExportData['db_version'] = DB::getInstance()->getDBSetting("db_version");

            // --- Add db.json to ZIP ---
            $jsonData = json_encode($boardExportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonData === false) {
                throw new ApiException("Failed to encode board export JSON");
            }
            if (!$zip->addFromString("db.json", $jsonData)) {
                throw new ApiException("Export failed: could not add db.json to zip");
            }

            // --- Add board files to ZIP ---
            $boardBaseDir = File::ftpDir("boards/$boardID/");
            if (is_dir($boardBaseDir)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($boardBaseDir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $filePath => $fileInfo) {
                    if ($fileInfo->isFile()) {
                        // Security: ensure path is inside base dir
                        if (!str_starts_with(realpath($filePath), realpath($boardBaseDir))) {
                            Logger::warning("ExportBoard: Skipping file outside board dir: $filePath");
                            continue;
                        }
                        $zipPath = ltrim(str_replace($boardBaseDir, '', $filePath), '/\\');
                        if (!$zip->addFile($filePath, $zipPath)) {
                            throw new ApiException("Failed to add file: $zipPath");
                        }
                    }
                }
            }

            // --- Finish ZIP ---
            $zip->close();
        } catch (Throwable $e) {
            $zip->close();
            File::deleteFile($exportPath);
            Logger::error("ExportBoard: Failed for board $boardID - " . $e->getMessage());
            throw $e;
        }

        // --- Send file as download ---
        $downloadName = "export - " . preg_replace('/[^\w\- ]+/u', '', strtolower($boardData['title'])) .
            " " . date("Y-m-d H-i-s") . ".zip";
        File::outputFile($exportPath, File::getMimeType('zip'), $downloadName, true);

        // Optionally clean up immediately after download if outputFile streams
        //File::deleteFile($exportPath);
    }

    /**
     * Update the last_modified_time for a given board.
     * @param int $boardID The board's ID.
     * @return bool        True if a row was updated, false if not (board not found).
     * @throws ApiException On invalid ID or DB error.
     */
    public static function updateBoardModifiedTime(int $boardID): bool
    {
        if ($boardID <= 0) {
            throw new ApiException("Invalid board ID: $boardID");
        }

        try {
            $rows = DB::getInstance()->getInstance()->query(
                "UPDATE tarallo_boards 
             SET last_modified_time = :last_modified_time 
             WHERE id = :board_id",
                [
                    'last_modified_time' => time(),
                    'board_id'           => $boardID
                ]
            );
        } catch (Throwable $e) {
            Logger::error(
                "UpdateBoardModifiedTime: DB error updating board $boardID - " . $e->getMessage()
            );
            throw new ApiException("Failed to update board modified time");
        }

        if ($rows > 0) {
            Logger::debug("UpdateBoardModifiedTime: Board $boardID modified time updated.");
            return true;
        } else {
            Logger::warning("UpdateBoardModifiedTime: Board $boardID not found or not updated.");
            return false;
        }
    }
}