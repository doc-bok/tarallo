<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class Card
{
    private const CARD_FLAGS = [
        'locked'   => 0x001,
        // 'archived' => 0x002,
        // 'urgent'   => 0x004,
    ];

    /**
     * Convert a raw DB card record into an API-friendly array.
     * @param array $cardRecord Raw DB row (must contain at least id, board_id, cardlist_id, title, etc.)
     * @return array            Normalised card data for API output.
     */
    public static function cardRecordToData(array $cardRecord): array
    {
        // Defensive extraction & casting
        $id         = (int)($cardRecord['id'] ?? 0);
        $boardId    = (int)($cardRecord['board_id'] ?? 0);
        $cardlistId = (int)($cardRecord['cardlist_id'] ?? 0);
        $title      = (string)($cardRecord['title'] ?? '');
        $prevCardId = isset($cardRecord['prev_card_id']) ? (int)$cardRecord['prev_card_id'] : 0;
        $nextCardId = isset($cardRecord['next_card_id']) ? (int)$cardRecord['next_card_id'] : 0;
        $coverId    = isset($cardRecord['cover_attachment_id']) ? (int)$cardRecord['cover_attachment_id'] : 0;
        $labelMask  = (int)($cardRecord['label_mask'] ?? 0);
        $flags      = (int)($cardRecord['flags'] ?? 0);
        $lastMoved  = isset($cardRecord['last_moved_time']) ? (int)$cardRecord['last_moved_time'] : 0;

        // Base card data
        $card = [
            'id'             => $id,
            'title'          => $title,
            'cardlist_id'    => $cardlistId,
            'prev_card_id'   => $prevCardId,
            'next_card_id'   => $nextCardId,
            'label_mask'     => $labelMask,
            'cover_img_url'  => '',
        ];

        // Add cover thumbnail URL if we have an attachment
        if ($coverId > 0 && $boardId > 0) {
            $card['cover_img_url'] = Attachment::getThumbnailProxyUrl($boardId, $coverId);
        }

        // Add last moved date if set
        if ($lastMoved > 0) {
            $card['last_moved_date'] = date('l, d M Y', $lastMoved); // could be configurable/localised
        }

        // Merge in the unpacked flags (e.g., locked, archived, etc.)
        return array_merge($card, self::cardFlagMaskToList($flags));
    }

    /**
     * Convert a bitmask into an associative array of boolean flags.
     * @param int $flagMask Bitmask representing card flags.
     * @return array<string,bool> e.g. ['locked' => true, 'archived' => false]
     */
    private static function CardFlagMaskToList(int $flagMask): array
    {
        return array_map(function ($bit) use ($flagMask) {
            return (bool)($flagMask & $bit);
        }, self::CARD_FLAGS);
    }

    /**
     * Open a card.
     * @param array $request The request parameters.
     * @return string[] The card data, if successful.
     */
    public static function openCard(array $request): array
    {
        Session::ensureSession();

        $userId = $_SESSION['user_id'] ?? null;
        $cardId = isset($request['id']) ? (int) $request['id'] : 0;

        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }
        if ($cardId <= 0) {
            http_response_code(400);
            return ['error' => 'Invalid card ID'];
        }

        // Get board_id for this card
        $boardRow = DB::getInstance()->fetchRow(
            "SELECT board_id FROM tarallo_cards WHERE id = :id LIMIT 1",
            ['id' => $cardId]
        );
        if (!$boardRow) {
            http_response_code(404);
            return ['error' => 'Card not found'];
        }

        try {
            $boardData = Board::GetBoardData(
                (int) $boardRow['board_id'],
                UserType::Observer,
                false,  // no lists
                true,   // include cards
                true,   // include card content
                true    // include attachments
            );
        } catch (ApiException) {
            http_response_code(403);
            return ['error' => 'Unable to get card data'];
        }

        // Find the card by ID and return it
        foreach ($boardData['cards'] as $card) {
            if ((int)$card['id'] === $cardId) {
                return $card;
            }
        }

        http_response_code(404);
        return ['error' => 'Card not accessible'];
    }

    /**
     * Add a new card to a list
     * @param array $request The request parameters.
     * @return string[] The card data, if successful.
     */
    public static function addNewCard(array $request): array
    {
        Session::ensureSession();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        $boardId    = isset($request['board_id']) ? (int)$request['board_id'] : 0;
        $cardlistId = isset($request['cardlist_id']) ? (int)$request['cardlist_id'] : 0;
        $title      = trim($request['title'] ?? '');

        if ($boardId <= 0 || $cardlistId <= 0 || $title === '') {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // Check board permission
        try {
            Board::GetBoardData($boardId, UserType::Member);
        } catch (ApiException) {
            Logger::warning("AddNewCard: Board $boardId not accessible to $userId");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Check cardlist belongs to board
        try {
            self::GetCardlistData($boardId, $cardlistId);
        } catch (ApiException) {
            Logger::warning("AddNewCard: Cardlist $cardlistId not found in board $boardId for $userId");
            http_response_code(400);
            return ['error' => 'Invalid cardlist'];
        }

        $content      = "Insert the card description here."; // default
        $coverAttach  = 0;
        $lastMoved    = time();
        $labelMask    = 0;
        $flagMask     = 0;

        try {
            $newCardRecord = self::AddNewCardInternal(
                $boardId,
                $cardlistId,
                0, // prev_card_id means add at top
                $title,
                $content,
                $coverAttach,
                $lastMoved,
                $labelMask,
                $flagMask
            );
        } catch (Throwable $e) {
            Logger::error("AddNewCard: Failed adding card to board $boardId list $cardlistId - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Error adding card'];
        }

        Board::updateBoardModifiedTime($boardId);

        return Card::cardRecordToData($newCardRecord);
    }

    /**
     * Retrieve a cardlist record for a given board, ensuring it belongs to that board.
     * @param int $boardID     The board ID to validate against.
     * @param int $cardlistID  The cardlist ID to fetch.
     * @return array           The cardlist DB row.
     * @throws ApiException If not found or not in specified board.
     */
    public static function GetCardlistData(int $boardID, int $cardlistID): array
    {
        if ($boardID <= 0 || $cardlistID <= 0) {
            throw new ApiException("Invalid board or cardlist ID");
        }

        try {
            $cardlistData = DB::getInstance()->fetchRow(
                "SELECT * FROM tarallo_cardlists WHERE id = :id",
                ['id' => $cardlistID]
            );
        } catch (Throwable $e) {
            Logger::error("GetCardlistData: DB error fetching cardlist $cardlistID - " . $e->getMessage());
            throw new ApiException("Database error while fetching cardlist");
        }

        if (!$cardlistData) {
            throw new ApiException("Cardlist not found", 404);
        }

        if ((int)$cardlistData['board_id'] !== $boardID) {
            throw new ApiException("Cardlist does not belong to board $boardID", 400);
        }

        return $cardlistData;
    }
    /**
     * Internal helper to add a new card.
     * @param int $boardID The ID of the board
     * @param int $cardlistID The ID of the card list
     * @param int $prevCardID The previous card ID
     * @param string $title The title of the card
     * @param string $content The content of the card
     * @param int $coverAttachmentID The ID of the cover attachment
     * @param int $lastMovedTime The last time the card was moved
     * @param int $labelMask The labels that are set
     * @param int $flagMask Any flags that are set
     * @return array The new card data if successful
     * @throws ApiException if the database fails to update.
     */
    public static function AddNewCardInternal(
        int $boardID,
        int $cardlistID,
        int $prevCardID,
        string $title,
        string $content,
        int $coverAttachmentID,
        int $lastMovedTime,
        int $labelMask,
        int $flagMask
    ): array {
        // Count cards in destination list
        $cardCount = (int) DB::getInstance()->fetchOne(
            "SELECT COUNT(*) FROM tarallo_cards WHERE cardlist_id = :cid",
            ['cid' => $cardlistID]
        );

        if ($cardCount === 0 && $prevCardID > 0) {
            throw new ApiException("Previous card ID not in empty list");
        }

        $nextCardID  = 0;

        if ($cardCount > 0) {
            // Find next card
            $nextCardRec = DB::getInstance()->fetchRow(
                "SELECT * FROM tarallo_cards WHERE cardlist_id = :cid AND prev_card_id = :pid",
                ['cid' => $cardlistID, 'pid' => $prevCardID]
            );

            // Validate prev card
            if ($prevCardID > 0) {
                $prevCardRec = DB::getInstance()->fetchRow(
                    "SELECT * FROM tarallo_cards WHERE cardlist_id = :cid AND id = :pid",
                    ['cid' => $cardlistID, 'pid' => $prevCardID]
                );
                if (!$prevCardRec) {
                    throw new ApiException("Previous card ID $prevCardID invalid");
                }
            }

            if ($nextCardRec) {
                $nextCardID = (int) $nextCardRec['id'];
            }
        }

        // Transaction to insert/update links
        DB::getInstance()->beginTransaction();
        try {
            $newCardID = DB::getInstance()->insert(
                "INSERT INTO tarallo_cards 
                (title, content, prev_card_id, next_card_id, cardlist_id, board_id, cover_attachment_id, last_moved_time, label_mask, flags)
             VALUES 
                (:title, :content, :prev_id, :next_id, :cid, :bid, :cover, :last_moved, :label, :flags)",
                [
                    'title'       => $title,
                    'content'     => $content,
                    'prev_id'     => $prevCardID,
                    'next_id'     => $nextCardID,
                    'cid'         => $cardlistID,
                    'bid'         => $boardID,
                    'cover'       => $coverAttachmentID,
                    'last_moved'  => $lastMovedTime,
                    'label'       => $labelMask,
                    'flags'       => $flagMask
                ]
            );

            if ($nextCardID > 0) {
                DB::getInstance()->query(
                    "UPDATE tarallo_cards SET prev_card_id = :new_id WHERE id = :nid",
                    ['new_id' => $newCardID, 'nid' => $nextCardID]
                );
            }
            if ($prevCardID > 0) {
                DB::getInstance()->query(
                    "UPDATE tarallo_cards SET next_card_id = :new_id WHERE id = :pid",
                    ['new_id' => $newCardID, 'pid' => $prevCardID]
                );
            }

            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            throw $e;
        }

        return DB::getInstance()->fetchRow(
            "SELECT * FROM tarallo_cards WHERE id = :id",
            ['id' => $newCardID]
        );
    }

    /**
     * Deletes a card from a list.
     * @param array $request The request parameters.
     * @return array|string[] The result of the operation.
     */
    public static function deleteCard(array $request): array
    {
        Session::ensureSession();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        $boardId = isset($request['board_id']) ? (int)$request['board_id'] : 0;
        $cardId  = isset($request['deleted_card_id']) ? (int)$request['deleted_card_id'] : 0;

        if ($boardId <= 0 || $cardId <= 0) {
            http_response_code(400);
            return ['error' => 'Invalid or missing board_id / deleted_card_id'];
        }

        // Get board data with cards, forcing at least Member role
        try {
            $boardData = Board::GetBoardData($boardId, UserType::Member, false, true);
        } catch (ApiException) {
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Make sure the card is actually in this board
        $cardRecord = null;
        foreach ($boardData['cards'] as $c) {
            if ((int) $c['id'] === $cardId) {
                $cardRecord = $c;
                break;
            }
        }
        if (!$cardRecord) {
            http_response_code(404);
            return ['error' => 'Card not found in the specified board'];
        }

        try {
            $deletedCard = self::deleteCardInternal($cardId);
            Board::updateBoardModifiedTime($boardId);
        } catch (Throwable $e) {
            Logger::error("DeleteCard: Failed to delete card $cardId in board $boardId for user $userId: " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Error deleting card'];
        }

        Logger::info("DeleteCard: User $userId deleted card $cardId from board $boardId");

        return [
            'success' => true,
            'deleted_card' => Card::cardRecordToData($deletedCard)
        ];
    }

    /**
     * Internal helper to delete a card.
     * @param int $cardID The ID of the card.
     * @param bool $deleteAttachments If TRUE will delete attachments as well.
     * @return array The result of the operation.
     * @throws Throwable if database update fails.
     */
    public static function deleteCardInternal(int $cardID, bool $deleteAttachments = true): array
    {
        // Fetch the card record (no extra permission checks, caller already did that)
        $cardRecord = DB::getInstance()->fetchRow(
            "SELECT * FROM tarallo_cards WHERE id = :id",
            ['id' => $cardID]
        );

        if (!$cardRecord) {
            throw new ApiException("Card does not exist");
        }

        DB::getInstance()->beginTransaction();
        try {
            // Relink previous card to skip this one
            if (!empty($cardRecord['prev_card_id'])) {
                DB::getInstance()->query(
                    "UPDATE tarallo_cards
                 SET next_card_id = :next
                 WHERE id = :prev",
                    [
                        'next' => $cardRecord['next_card_id'] ?: 0,
                        'prev' => $cardRecord['prev_card_id']
                    ]
                );
            }

            // Relink next card to skip this one
            if (!empty($cardRecord['next_card_id'])) {
                DB::getInstance()->query(
                    "UPDATE tarallo_cards
                 SET prev_card_id = :prev
                 WHERE id = :next",
                    [
                        'prev' => $cardRecord['prev_card_id'] ?: 0,
                        'next' => $cardRecord['next_card_id']
                    ]
                );
            }

            // Delete attachments if requested
            if ($deleteAttachments) {
                $attachments = DB::getInstance()->fetchTable(
                    "SELECT * FROM tarallo_attachments WHERE card_id = :id",
                    ['id' => $cardID]
                );
                foreach ($attachments as $att) {
                    Attachment::deleteAttachmentFiles($att);
                }
                DB::getInstance()->query(
                    "DELETE FROM tarallo_attachments WHERE card_id = :id",
                    ['id' => $cardID]
                );
            }

            // Finally delete the card itself
            DB::getInstance()->query(
                "DELETE FROM tarallo_cards WHERE id = :id",
                ['id' => $cardID]
            );

            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            throw $e;
        }

        return $cardRecord; // Return original record for logging/response
    }

    /**
     * Move a card from one place to another.
     * @param array $request The request parameters.
     * @return array The result of the operation.
     * @throws Exception If the database fails to update.
     */
    public static function moveCard(array $request): array
    {
        Session::ensureSession();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        $boardId        = isset($request['board_id']) ? (int) $request['board_id'] : 0;
        $movedCardId    = isset($request['moved_card_id']) ? (int) $request['moved_card_id'] : 0;
        $destCardlistId = isset($request['dest_cardlist_id']) ? (int) $request['dest_cardlist_id'] : 0;
        $newPrevCardId  = isset($request['new_prev_card_id']) ? (int) $request['new_prev_card_id'] : 0;

        if ($boardId <= 0 || $movedCardId <= 0 || $destCardlistId <= 0) {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // Get board data with cards to verify membership & card presence
        try {
            $boardData = Board::GetBoardData($boardId, UserType::Member, false, true, true);
        } catch (ApiException) {
            Logger::warning("MoveCard: User $userId permission denied on board $movedCardId");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Find the card being moved
        $cardRecord = null;
        foreach ($boardData['cards'] as $c) {
            if ((int)$c['id'] === $movedCardId) {
                $cardRecord = $c;
                break;
            }
        }
        if (!$cardRecord) {
            http_response_code(404);
            return ['error' => 'Card not found in board'];
        }

        // Validate the destination cardlist
        try {
            self::GetCardlistData($boardId, $destCardlistId);
        } catch (ApiException) {
            http_response_code(400);
            return ['error' => 'Destination cardlist invalid'];
        }

        // Transaction: delete original, insert new at target
        try {
            DB::getInstance()->beginTransaction();

            // Delete original card record (without deleting attachments)
            $deletedCard = self::deleteCardInternal($movedCardId, false);

            // Preserve or update last moved time
            $lastMovedTime = ($deletedCard['cardlist_id'] != $destCardlistId)
                ? time()
                : $deletedCard['last_moved_time'];

            // Add card to new location
            $newCard = Card::AddNewCardInternal(
                $boardId,
                $destCardlistId,
                $newPrevCardId,
                $deletedCard['title'],
                $deletedCard['content'],
                $deletedCard['cover_attachment_id'],
                $lastMovedTime,
                $deletedCard['label_mask'],
                $deletedCard['flags']
            );

            // Move attachments to new card_id
            DB::getInstance()->query(
                "UPDATE tarallo_attachments SET card_id = :new_id WHERE card_id = :old_id",
                ['new_id' => $newCard['id'], 'old_id' => $movedCardId]
            );

            Board::updateBoardModifiedTime($boardId);

            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("MoveCard: Failed to move card $movedCardId in board $movedCardId - {$e->getMessage()}");
            http_response_code(500);
            return ['error' => 'Card move failed'];
        }

        Logger::info("MoveCard: User $userId moved card $movedCardId to list $destCardlistId in board $movedCardId");

        return self::cardRecordToData($newCard);
    }

    /**
     * Update a card's title.
     * @param array $request The request parameters.
     * @return string[] The updated card data.
     */
    public static function updateCardTitle(array $request): array
    {
        Session::ensureSession();

        $userId = $_SESSION['user_id'] ?? null;
        $boardId = isset($request['board_id']) ? (int) $request['board_id'] : 0;
        $cardId  = isset($request['id']) ? (int) $request['id'] : 0;
        $newTitle = trim($request['title'] ?? '');

        // Basic validation
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        if ($boardId <= 0 || $cardId <= 0 || $newTitle === '') {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // Check board membership
        try {
            Board::GetBoardData($boardId, UserType::Member);
        } catch (ApiException) {
            Logger::warning("UpdateCardTitle: User $userId attempted without permission on board $boardId");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Check that the card belongs to this board
        try {
            $cardRecord = self::getCardData($boardId, $cardId);
        } catch (ApiException) {
            http_response_code(404);
            return ['error' => 'Card not found in this board'];
        }

        // Update title
        try {
            DB::getInstance()->query(
                "UPDATE tarallo_cards SET title = :title WHERE id = :id",
                ['title' => $newTitle, 'id' => $cardId]
            );

            Board::updateBoardModifiedTime($boardId);
        } catch (Throwable $e) {
            Logger::error("UpdateCardTitle: DB error updating card $cardId in board $boardId - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Failed to update card title'];
        }

        // Update local record to reflect change
        $cardRecord['title'] = $newTitle;

        Logger::info("UpdateCardTitle: User $userId updated title of card $cardId in board $boardId");

        return Card::cardRecordToData($cardRecord);
    }

    /**
     * Retrieve a card row from the DB, validating it belongs to the given board.
     * @param int $boardID The board ID to validate against.
     * @param int $cardID  The card ID to retrieve.
     * @return array       The card's DB row.
     * @throws ApiException If not found or not part of the board.
     */
    public static function getCardData(int $boardID, int $cardID): array
    {
        if ($boardID <= 0 || $cardID <= 0) {
            throw new ApiException("Invalid board or card ID");
        }

        try {
            $cardData = DB::getInstance()->fetchRow(
                "SELECT * FROM tarallo_cards WHERE id = :card_id",
                ['card_id' => $cardID]
            );
        } catch (Throwable $e) {
            Logger::error("GetCardData: DB error reading card $cardID - " . $e->getMessage());
            throw new ApiException("Database error while fetching card.");
        }

        if (!$cardData) {
            // Card doesn't exist
            throw new ApiException("Card not found", 404);
        }

        if ((int)$cardData['board_id'] !== $boardID) {
            // Card is from another board
            throw new ApiException("Card not part of specified board", 400);
        }

        return $cardData;
    }

    /**
     * Updates a card's content.
     * @param array $request The request parameters.
     * @return array The updated card data.
     */
    public static function UpdateCardContent(array $request): array
    {
        Session::ensureSession();

        $userId    = $_SESSION['user_id'] ?? null;
        $boardId   = isset($request['board_id']) ? (int)$request['board_id'] : 0;
        $cardId    = isset($request['id']) ? (int)$request['id'] : 0;
        $newContent = $request['content'] ?? ''; // allow empty string but still check type

        // === Basic validation ===
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        if ($boardId <= 0 || $cardId <= 0) {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // === Permission check ===
        try {
            Board::GetBoardData($boardId, UserType::Member);
        } catch (ApiException) {
            Logger::warning("UpdateCardContent: User $userId tried to edit card $cardId on board $boardId without permission");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // === Card ownership check ===
        try {
            $cardRecord = self::getCardData($boardId, $cardId);
        } catch (ApiException) {
            http_response_code(404);
            return ['error' => 'Card not found in this board'];
        }

        // === Perform update ===
        try {
            DB::getInstance()->query(
                "UPDATE tarallo_cards SET content = :content WHERE id = :id",
                ['content' => $newContent, 'id' => $cardId]
            );
            Board::updateBoardModifiedTime($boardId);
        } catch (Throwable $e) {
            Logger::error("UpdateCardContent: DB error on card $cardId (board $boardId) - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Failed to update card content'];
        }

        // === Update local record to reflect change ===
        $cardRecord['content'] = $newContent;

        Logger::info("UpdateCardContent: User $userId updated content of card $cardId in board $boardId");

        return self::cardRecordToData($cardRecord);
    }

    /**
     * Updates a card's flags.
     * @param array $request The request parameters.
     * @return array The updated card data.
     */
    public static function updateCardFlags(array $request): array
    {
        Session::ensureSession();

        $userId  = $_SESSION['user_id'] ?? null;
        $boardId = isset($request['board_id']) ? (int)$request['board_id'] : 0;
        $cardId  = isset($request['id']) ? (int)$request['id'] : 0;

        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        if ($boardId <= 0 || $cardId <= 0) {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // Permission check
        try {
            Board::GetBoardData($boardId, UserType::Member);
        } catch (ApiException) {
            Logger::warning("UpdateCardFlags: User $userId tried to update flags on card $cardId in board $boardId without permission");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Card existence/ownership check
        try {
            $cardRecord = Card::getCardData($boardId, $cardId);
        } catch (ApiException) {
            http_response_code(404);
            return ['error' => 'Card not found in this board'];
        }

        // Calculate new flag mask
        $flagList = Card::cardFlagMaskToList($cardRecord['flags']);
        if (array_key_exists('locked', $request)) {
            $flagList['locked'] = (bool)$request['locked'];
        }
        $cardRecord['flags'] = self::CardFlagListToMask($flagList);

        // Update DB
        try {
            DB::getInstance()->query(
                "UPDATE tarallo_cards SET flags = :flags WHERE id = :id",
                ['flags' => $cardRecord['flags'], 'id' => $cardId]
            );
            Board::updateBoardModifiedTime($boardId);
        } catch (Throwable $e) {
            Logger::error("UpdateCardFlags: DB error on card $cardId in board $boardId - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Failed to update card flags'];
        }

        Logger::info("UpdateCardFlags: User $userId updated flags for card $cardId in board $boardId (new flags: {$cardRecord['flags']})");

        return Card::cardRecordToData($cardRecord);
    }

    /**
     * Convert a list of boolean flags into a combined bitmask.
     * @param array<string,bool|int> $flagList e.g. ['locked' => true]
     * @return int Bitmask representing the flags.
     */
    private static function CardFlagListToMask(array $flagList): int
    {
        $mask = 0;

        foreach (self::CARD_FLAGS as $name => $bit) {
            if (!empty($flagList[$name])) {
                $mask |= $bit;
            }
        }

        return $mask;
    }

}