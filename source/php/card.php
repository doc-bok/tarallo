<?php

declare(strict_types=1);

require_once __DIR__ . '/attachment.php';

class Card
{
    private const CARD_FLAG_LOCKED = 0x001;

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
     * Convert a card's flag bitmask into an associative list of booleans.
     * @param int $flagMask Bitmask representing card flags.
     * @return array<string,bool>  Array keyed by flag name, each true/false.
     */
    public static function cardFlagMaskToList(int $flagMask): array
    {
        return [
            'locked' => (bool) ($flagMask & self::CARD_FLAG_LOCKED),
            // Add future flags here:
            // 'archived' => (bool) ($flagMask & self::CARD_FLAG_ARCHIVED),
        ];
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
        $boardRow = DB::fetchRow(
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
                Permission::USERTYPE_Observer,
                false,  // no lists
                true,   // include cards
                true,   // include card content
                true    // include attachments
            );
        } catch (RuntimeException) {
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
            Board::GetBoardData($boardId, Permission::USERTYPE_Member);
        } catch (RuntimeException) {
            Logger::warning("AddNewCard: Board $boardId not accessible to $userId");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Check cardlist belongs to board
        try {
            self::GetCardlistData($boardId, $cardlistId);
        } catch (RuntimeException) {
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

        DB::UpdateBoardModifiedTime($boardId);

        return Card::cardRecordToData($newCardRecord);
    }

    /**
     * Retrieve a cardlist record for a given board, ensuring it belongs to that board.
     * @param int $boardID     The board ID to validate against.
     * @param int $cardlistID  The cardlist ID to fetch.
     * @return array           The cardlist DB row.
     * @throws RuntimeException If not found or not in specified board.
     */
    public static function GetCardlistData(int $boardID, int $cardlistID): array
    {
        if ($boardID <= 0 || $cardlistID <= 0) {
            throw new RuntimeException("Invalid board or cardlist ID");
        }

        try {
            $cardlistData = DB::fetchRow(
                "SELECT * FROM tarallo_cardlists WHERE id = :id",
                ['id' => $cardlistID]
            );
        } catch (Throwable $e) {
            Logger::error("GetCardlistData: DB error fetching cardlist $cardlistID - " . $e->getMessage());
            throw new RuntimeException("Database error while fetching cardlist");
        }

        if (!$cardlistData) {
            throw new RuntimeException("Cardlist not found", 404);
        }

        if ((int)$cardlistData['board_id'] !== $boardID) {
            throw new RuntimeException("Cardlist does not belong to board $boardID", 400);
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
     * @throws RuntimeException if the database fails to update.
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
        $cardCount = (int) DB::fetchOne(
            "SELECT COUNT(*) FROM tarallo_cards WHERE cardlist_id = :cid",
            ['cid' => $cardlistID]
        );

        if ($cardCount === 0 && $prevCardID > 0) {
            throw new RuntimeException("Previous card ID not in empty list");
        }

        $nextCardID  = 0;

        if ($cardCount > 0) {
            // Find next card
            $nextCardRec = DB::fetchRow(
                "SELECT * FROM tarallo_cards WHERE cardlist_id = :cid AND prev_card_id = :pid",
                ['cid' => $cardlistID, 'pid' => $prevCardID]
            );

            // Validate prev card
            if ($prevCardID > 0) {
                $prevCardRec = DB::fetchRow(
                    "SELECT * FROM tarallo_cards WHERE cardlist_id = :cid AND id = :pid",
                    ['cid' => $cardlistID, 'pid' => $prevCardID]
                );
                if (!$prevCardRec) {
                    throw new RuntimeException("Previous card ID $prevCardID invalid");
                }
            }

            if ($nextCardRec) {
                $nextCardID = (int) $nextCardRec['id'];
            }
        }

        // Transaction to insert/update links
        DB::beginTransaction();
        try {
            $newCardID = DB::insert(
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
                DB::query(
                    "UPDATE tarallo_cards SET prev_card_id = :new_id WHERE id = :nid",
                    ['new_id' => $newCardID, 'nid' => $nextCardID]
                );
            }
            if ($prevCardID > 0) {
                DB::query(
                    "UPDATE tarallo_cards SET next_card_id = :new_id WHERE id = :pid",
                    ['new_id' => $newCardID, 'pid' => $prevCardID]
                );
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return DB::fetchRow(
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
            $boardData = Board::GetBoardData($boardId, Permission::USERTYPE_Member, false, true);
        } catch (RuntimeException) {
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
            DB::UpdateBoardModifiedTime($boardId);
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
        $cardRecord = DB::fetchRow(
            "SELECT * FROM tarallo_cards WHERE id = :id",
            ['id' => $cardID]
        );

        if (!$cardRecord) {
            throw new RuntimeException("Card does not exist");
        }

        DB::beginTransaction();
        try {
            // Relink previous card to skip this one
            if (!empty($cardRecord['prev_card_id'])) {
                DB::query(
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
                DB::query(
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
                $attachments = DB::fetchTable(
                    "SELECT * FROM tarallo_attachments WHERE card_id = :id",
                    ['id' => $cardID]
                );
                foreach ($attachments as $att) {
                    Attachment::deleteAttachmentFiles($att);
                }
                DB::query(
                    "DELETE FROM tarallo_attachments WHERE card_id = :id",
                    ['id' => $cardID]
                );
            }

            // Finally delete the card itself
            DB::query(
                "DELETE FROM tarallo_cards WHERE id = :id",
                ['id' => $cardID]
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
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
            $boardData = Board::GetBoardData($boardId, Permission::USERTYPE_Member, false, true, true);
        } catch (RuntimeException) {
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
        } catch (RuntimeException) {
            http_response_code(400);
            return ['error' => 'Destination cardlist invalid'];
        }

        // Transaction: delete original, insert new at target
        try {
            DB::beginTransaction();

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
            DB::query(
                "UPDATE tarallo_attachments SET card_id = :new_id WHERE card_id = :old_id",
                ['new_id' => $newCard['id'], 'old_id' => $movedCardId]
            );

            DB::UpdateBoardModifiedTime($boardId);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Logger::error("MoveCard: Failed to move card $movedCardId in board $movedCardId - {$e->getMessage()}");
            http_response_code(500);
            return ['error' => 'Card move failed'];
        }

        Logger::info("MoveCard: User $userId moved card $movedCardId to list $destCardlistId in board $movedCardId");

        return self::cardRecordToData($newCard);
    }
}