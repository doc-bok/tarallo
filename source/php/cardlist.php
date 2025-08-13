<?php

declare(strict_types=1);

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
            Board::GetBoardData($boardId, Permission::USERTYPE_Moderator);
        } catch (RuntimeException) {
            Logger::warning("MoveCardList: User $userId tried to move list $listId in board $boardId without permission");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Source list must exist in this board
        try {
            $cardListData = Card::GetCardlistData($boardId, $listId);
        } catch (RuntimeException) {
            http_response_code(404);
            return ['error' => 'List not found in board'];
        }

        // Determine ID of list that will follow the moved one
        if ($newPrevList > 0) {

            // New prev list must exist in this board
            try {
                $prevListData = Card::GetCardlistData($boardId, $newPrevList);
            } catch (RuntimeException) {
                http_response_code(400);
                return ['error' => 'Invalid new_prev_cardlist_id'];
            }

            $nextCardListID = (int) $prevListData['next_list_id'];
        } else {
            // Find "first" cardlist in board as the next list
            $nextRec = DB::fetchRow(
                "SELECT id FROM tarallo_cardlists WHERE board_id = :bid AND prev_list_id = 0",
                ['bid' => $boardId]
            );

            $nextCardListID = $nextRec ? (int) $nextRec['id'] : 0;
        }

        // Move operation in a transaction
        try {
            DB::beginTransaction();

            self::removeCardListFromLL($cardListData);

            DB::query(
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
            DB::UpdateBoardModifiedTime($boardId);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
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
     * @throws RuntimeException On invalid data or DB error.
     */
    public static function removeCardListFromLL(array $cardListData): void
    {
        foreach (['prev_list_id', 'next_list_id'] as $key) {
            if (!isset($cardListData[$key])) {
                throw new RuntimeException("Missing required key: $key");
            }
        }

        $prevId = (int) $cardListData['prev_list_id'];
        $nextId = (int) $cardListData['next_list_id'];

        try {
            DB::beginTransaction();

            if ($prevId > 0) {
                DB::query(
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
                DB::query(
                    "UPDATE tarallo_cardlists
                 SET prev_list_id = :prev_list_id
                 WHERE id = :next_list_id",
                    [
                        'prev_list_id' => $prevId,
                        'next_list_id' => $nextId
                    ]
                );
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Logger::error("RemoveCardListFromLL: Failed for list (prev=$prevId, next=$nextId) - " . $e->getMessage());
            throw new RuntimeException("Database error while re-linking card lists");
        }
    }

    /**
     * Insert a new card list into the linked-list ordering by updating neighbour pointers.
     * @param int $newListID  The ID of the newly inserted list.
     * @param int $prevListID The ID of the list before the new one (0 if none).
     * @param int $nextListID The ID of the list after the new one (0 if none).
     * @return void
     * @throws RuntimeException On invalid IDs or DB error.
     */
    public static function addCardListToLL(int $newListID, int $prevListID, int $nextListID): void
    {
        // Validate IDs
        if ($newListID <= 0) {
            throw new RuntimeException("Invalid newListID: $newListID");
        }

        try {
            DB::beginTransaction();

            if ($nextListID > 0) {
                DB::query(
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
                DB::query(
                    "UPDATE tarallo_cardlists
                 SET next_list_id = :new_id
                 WHERE id = :prev_list_id",
                    [
                        'new_id'        => $newListID,
                        'prev_list_id'  => $prevListID
                    ]
                );
            }

            DB::commit();

            Logger::debug(
                "AddCardListToLL: Inserted $newListID between prev=$prevListID, next=$nextListID"
            );
        } catch (Throwable $e) {
            DB::rollBack();
            Logger::error(
                "AddCardListToLL: Failed inserting $newListID between prev=$prevListID and next=$nextListID - " .
                $e->getMessage()
            );
            throw new RuntimeException("Failed to update linked list order for new card list");
        }
    }
}