<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class CardList
{
    /**
     * Move a card list
     * @param array $request The request parameters.
     * @return string[] The result of the operation.
     */
    public static function moveCardList(array $request): array
    {
        Session::ensureSession();

        $userId       = $_SESSION['user_id'] ?? null;
        $boardId      = isset($request['board_id']) ? (int) $request['board_id'] : 0;
        $listId       = isset($request['moved_cardlist_id']) ? (int) $request['moved_cardlist_id'] : 0;
        $newPrevList  = isset($request['new_prev_cardlist_id']) ? (int) $request['new_prev_cardlist_id'] : 0;

        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        if ($boardId <= 0 || $listId <= 0) {
            http_response_code(400);
            return ['error' => 'Invalid or missing board_id / moved_cardlist_id'];
        }

        // Permission + board existence check
        try {
            Board::GetBoardData($boardId, UserType::Moderator);
        } catch (ApiException) {
            Logger::warning("MoveCardList: User $userId tried to move list $listId in board $boardId without permission");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Source list must exist in this board
        try {
            $cardListData = Card::GetCardlistData($boardId, $listId);
        } catch (ApiException) {
            http_response_code(404);
            return ['error' => 'List not found in board'];
        }

        // Determine ID of list that will follow the moved one
        if ($newPrevList > 0) {

            // New prev list must exist in this board
            try {
                $prevListData = Card::GetCardlistData($boardId, $newPrevList);
            } catch (ApiException) {
                http_response_code(400);
                return ['error' => 'Invalid new_prev_cardlist_id'];
            }

            $nextCardListID = (int) $prevListData['next_list_id'];
        } else {
            // Find "first" cardlist in board as the next list
            $nextRec = DB::getInstance()->fetchRow(
                "SELECT id FROM tarallo_cardlists WHERE board_id = :bid AND prev_list_id = 0",
                ['bid' => $boardId]
            );

            $nextCardListID = $nextRec ? (int) $nextRec['id'] : 0;
        }

        // Move operation in a transaction
        try {
            DB::getInstance()->beginTransaction();

            self::removeCardListFromLL($cardListData);

            DB::getInstance()->query(
                "UPDATE tarallo_cardlists
             SET prev_list_id = :prev, next_list_id = :next
             WHERE id = :id",
                [
                    'prev' => $newPrevList,
                    'next' => $nextCardListID,
                    'id'   => $listId
                ]
            );

            self::addCardListToLL($listId, $newPrevList, $nextCardListID);
            Board::updateBoardModifiedTime($boardId);

            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("MoveCardList: Failed moving list $listId in board $boardId - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Move failed'];
        }

        Logger::info("MoveCardList: User $userId moved list $listId in board $boardId");

        // Return fresh list data after move
        return Card::GetCardlistData($boardId, $listId);
    }

    /**
     * Remove a card list node from the linked-list ordering by re-linking its neighbours.
     * @param array $cardListData Must contain 'prev_list_id' and 'next_list_id' keys.
     * @return void
     * @throws ApiException On invalid data or DB error.
     */
    public static function removeCardListFromLL(array $cardListData): void
    {
        foreach (['prev_list_id', 'next_list_id'] as $key) {
            if (!isset($cardListData[$key])) {
                throw new ApiException("Missing required key: $key");
            }
        }

        $prevId = (int) $cardListData['prev_list_id'];
        $nextId = (int) $cardListData['next_list_id'];

        try {
            DB::getInstance()->beginTransaction();

            if ($prevId > 0) {
                DB::getInstance()->query(
                    "UPDATE tarallo_cardlists
                 SET next_list_id = :next_list_id
                 WHERE id = :prev_list_id",
                    [
                        'next_list_id' => $nextId,
                        'prev_list_id' => $prevId
                    ]
                );
            }

            if ($nextId > 0) {
                DB::getInstance()->query(
                    "UPDATE tarallo_cardlists
                 SET prev_list_id = :prev_list_id
                 WHERE id = :next_list_id",
                    [
                        'prev_list_id' => $prevId,
                        'next_list_id' => $nextId
                    ]
                );
            }

            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("RemoveCardListFromLL: Failed for list (prev=$prevId, next=$nextId) - " . $e->getMessage());
            throw new ApiException("Database error while re-linking card lists");
        }
    }

    /**
     * Insert a new card list into the linked-list ordering by updating neighbour pointers.
     * @param int $newListID  The ID of the newly inserted list.
     * @param int $prevListID The ID of the list before the new one (0 if none).
     * @param int $nextListID The ID of the list after the new one (0 if none).
     * @return void
     * @throws ApiException On invalid IDs or DB error.
     */
    public static function addCardListToLL(int $newListID, int $prevListID, int $nextListID): void
    {
        // Validate IDs
        if ($newListID <= 0) {
            throw new ApiException("Invalid newListID: $newListID");
        }

        try {
            DB::getInstance()->beginTransaction();

            if ($nextListID > 0) {
                DB::getInstance()->query(
                    "UPDATE tarallo_cardlists
                 SET prev_list_id = :new_id
                 WHERE id = :next_list_id",
                    [
                        'new_id'        => $newListID,
                        'next_list_id'  => $nextListID
                    ]
                );
            }

            if ($prevListID > 0) {
                DB::getInstance()->query(
                    "UPDATE tarallo_cardlists
                 SET next_list_id = :new_id
                 WHERE id = :prev_list_id",
                    [
                        'new_id'        => $newListID,
                        'prev_list_id'  => $prevListID
                    ]
                );
            }

            DB::getInstance()->commit();

            Logger::debug(
                "AddCardListToLL: Inserted $newListID between prev=$prevListID, next=$nextListID"
            );
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error(
                "AddCardListToLL: Failed inserting $newListID between prev=$prevListID and next=$nextListID - " .
                $e->getMessage()
            );
            throw new ApiException("Failed to update linked list order for new card list");
        }
    }

    /**
     * Update the name of a card list on a specific board.
     * @param array $request Must contain 'board_id', 'id', and 'name'.
     * @return array Updated card list data.
     * @throws InvalidArgumentException On invalid/missing input.
     * @throws ApiException On DB error.
     */
    public static function updateCardListName(array $request): array
    {
        // Validate request parameters
        foreach (['board_id', 'id', 'name'] as $key) {
            if (!isset($request[$key]) || $request[$key] === '') {
                throw new InvalidArgumentException("Missing or invalid parameter: $key");
            }
        }

        $boardID   = (int) $request['board_id'];
        $cardlistID = (int) $request['id'];
        $newName   = trim((string) $request['name']);

        if ($boardID <= 0 || $cardlistID <= 0) {
            throw new InvalidArgumentException("Invalid board or card list ID.");
        }
        if ($newName === '') {
            throw new InvalidArgumentException("Card list name cannot be empty.");
        }

        // Check board exists and user has rights
        Board::GetBoardData($boardID);

        // Check card list exists and belongs to the board
        $cardlistData = Card::GetCardlistData($boardID, $cardlistID);

        // Run update with safe parameter binding
        try {
            $rows = DB::getInstance()->query(
                "UPDATE tarallo_cardlists SET name = :name WHERE id = :id",
                [
                    'name' => $newName,
                    'id'   => $cardlistID
                ]
            );

            if ($rows < 1) {
                Logger::warning("updateCardListName: No rows updated for card list $cardlistID on board $boardID");
            }
        } catch (Throwable $e) {
            Logger::error("updateCardListName: DB error updating card list $cardlistID - " . $e->getMessage());
            throw new ApiException("Database error updating card list name.");
        }

        // Mark board as modified
        Board::updateBoardModifiedTime($boardID);

        // Return updated record
        $cardlistData['name'] = $newName;
        return $cardlistData;
    }

    /**
     * Public entry point to add a new card list to a board.
     * @param array $request Must contain 'board_id', 'prev_list_id', 'name'.
     * @return array Newly inserted card list record.
     * @throws InvalidArgumentException On invalid input.
     * @throws ApiException On DB or linked-list errors.
     */
    public static function addCardList(array $request): array
    {
        foreach (['board_id', 'name', 'prev_list_id'] as $key) {
            if (!array_key_exists($key, $request)) {
                throw new InvalidArgumentException("Missing parameter: $key");
            }
        }

        $boardID     = (int) $request['board_id'];
        $prevListID  = (int) $request['prev_list_id'];
        $name        = trim((string) $request['name']);

        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID");
        }
        if ($name === '') {
            throw new InvalidArgumentException("Card list name cannot be empty");
        }

        // Permission & board check
        Board::GetBoardData($boardID);

        // Delegate to internal safe method
        $newCardListData = self::addNewCardListInternal($boardID, $prevListID, $name);

        Board::updateBoardModifiedTime($boardID);

        return $newCardListData;
    }

    /**
     * Internal logic for adding a new list to the DB and maintaining LL links.
     * @param int    $boardID    The board to add the list to.
     * @param int    $prevListID The previous list's ID (0 if adding at start).
     * @param string $name       The list name.
     * @return array Newly created card list record.
     * @throws ApiException On failed DB insert or linked-list update.
     */
    public static function addNewCardListInternal(int $boardID, int $prevListID, string $name): array
    {
        try {
            // Count how many lists exist in this board
            $cardListCount = DB::getInstance()->fetchOne(
                "SELECT COUNT(*) FROM tarallo_cardlists WHERE board_id = :board_id",
                ['board_id' => $boardID]
            );
        } catch (Throwable $e) {
            Logger::error("addNewCardListInternal: Failed counting lists for board $boardID - " . $e->getMessage());
            throw new ApiException("Database error checking existing lists");
        }

        // If board is empty but prevListID given, it's invalid
        if ($cardListCount == 0 && $prevListID > 0) {
            throw new ApiException("The specified previous card list is not in the destination board", 400);
        }

        $nextListID = 0;

        if ($cardListCount > 0) {
            // Find the "next" after the intended prev
            $nextCardListRecord = DB::getInstance()->fetchRow(
                "SELECT id FROM tarallo_cardlists WHERE board_id = :board_id AND prev_list_id = :prev_list_id",
                [
                    'board_id'    => $boardID,
                    'prev_list_id'=> $prevListID
                ]
            );

            if ($prevListID > 0) {
                $prevCardListRecord = DB::getInstance()->fetchRow(
                    "SELECT id FROM tarallo_cardlists WHERE board_id = :board_id AND id = :prev_list_id",
                    [
                        'board_id'    => $boardID,
                        'prev_list_id'=> $prevListID
                    ]
                );
                if (!$prevCardListRecord) {
                    throw new ApiException("Invalid previous card list ID", 400);
                }
            }

            if ($nextCardListRecord) {
                $nextListID = (int) $nextCardListRecord['id'];
            }
        }

        try {
            DB::getInstance()->beginTransaction();

            // Insert new list
            $newListID = DB::getInstance()->insert(
                "INSERT INTO tarallo_cardlists (board_id, name, prev_list_id, next_list_id)
             VALUES (:board_id, :name, :prev_list_id, :next_list_id)",
                [
                    'board_id'     => $boardID,
                    'name'         => $name,
                    'prev_list_id' => $prevListID,
                    'next_list_id' => $nextListID
                ]
            );

            if (!$newListID) {
                throw new ApiException("Failed to insert new card list");
            }

            CardList::addCardListToLL((int)$newListID, $prevListID, $nextListID);

            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("addNewCardListInternal: Insert/LL update failed for board $boardID - " . $e->getMessage());
            throw new ApiException("Failed to add card list");
        }

        return Card::GetCardlistData($boardID, (int)$newListID);
    }

    /**
     * Delete a card list from a board, only if it is empty.
     * @param array $request Must contain 'board_id' and 'id'.
     * @return array The deleted list's data.
     * @throws InvalidArgumentException On invalid/missing parameters.
     * @throws ApiException On deletion failure or if list is not empty.
     */
    public static function deleteCardList(array $request): array
    {
        // Validate request parameters
        foreach (['board_id', 'id'] as $key) {
            if (!isset($request[$key]) || !is_numeric($request[$key])) {
                throw new InvalidArgumentException("Missing or invalid parameter: $key");
            }
        }

        $boardID = (int) $request['board_id'];
        $listID  = (int) $request['id'];

        if ($boardID <= 0 || $listID <= 0) {
            throw new InvalidArgumentException("Invalid board or list ID");
        }

        // Check board access
        Board::GetBoardData($boardID);

        // Get list and confirm it belongs to board
        $cardListData = Card::GetCardlistData($boardID, $listID);

        // Ensure list is empty
        try {
            $cardCount = DB::getInstance()->fetchOne(
                "SELECT COUNT(*) FROM tarallo_cards WHERE cardlist_id = :id",
                ['id' => $listID]
            );
        } catch (Throwable $e) {
            Logger::error("deleteCardList: Failed to count cards for list $listID - " . $e->getMessage());
            throw new ApiException("Database error checking list contents");
        }

        if ($cardCount > 0) {
            throw new ApiException(
                "List $listID still contains $cardCount cards and cannot be deleted",
                400
            );
        }

        // Execute deletion
        self::deleteCardListInternal($cardListData);

        // Update board modified time
        Board::updateBoardModifiedTime($boardID);

        return $cardListData;
    }

    /**
     * Internal helper to remove a card list and update linked-list pointers.
     * @param array $cardListData Must contain 'id', 'prev_list_id', 'next_list_id'.
     * @return void Deleted list's data.
     * @throws ApiException On DB/linked list errors.
     */
    private static function deleteCardListInternal(array $cardListData): void
    {
        try {
            DB::getInstance()->beginTransaction();

            CardList::removeCardListFromLL($cardListData);

            DB::getInstance()->query(
                "DELETE FROM tarallo_cardlists WHERE id = :id",
                ['id' => (int) $cardListData['id']]
            );

            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("deleteCardListInternal: Failed to delete list {$cardListData['id']} - " . $e->getMessage());
            throw new ApiException("Failed to delete card list");
        }
    }
}