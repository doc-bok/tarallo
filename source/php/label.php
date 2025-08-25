<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class Label
{
    const DEFAULT_LABEL_COLORS = array("red", "orange", "yellow", "green", "cyan", "azure", "blue", "purple", "pink", "grey");
    const MAX_LABEL_FIELD_LEN = 400;
    const MAX_LABEL_COUNT = 24;

    /**
     * Sanitise and limit label name length.
     *
     * - Replace commas with spaces
     * - Trim whitespace and collapse multiple spaces
     * - Strip control characters
     * - Limit to 32 visible characters (UTF‑8 safe)
     *
     * @param string $name Raw label name
     * @return string Clean label name
     */
    public static function cleanLabelName(string $name): string
    {
        // Replace commas with space
        $name = str_replace(',', ' ', $name);

        // Strip control characters while keeping normal punctuation/letters/numbers
        $name = preg_replace('/[^\P{C}]+/u', '', $name);

        // Collapse multiple spaces and trim
        $name = preg_replace('/\s+/u', ' ', trim($name));

        // UTF‑8 safe truncation to 32 chars
        return mb_substr($name, 0, 32, 'UTF-8');
    }

    /**
     * Update the label names and colours for a board.
     * @param int   $boardID     The board ID.
     * @param array $labelNames  Array of label names (strings).
     * @param array $labelColors Array of label colours (strings in hex or predefined format).
     * @return void
     * @throws InvalidArgumentException On invalid input.
     * @throws ApiException On DB error.
     */
    public static function updateBoardLabelsInternal(int $boardID, array $labelNames, array $labelColors): void
    {
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Clean and validate label names
        $cleanNames = array_map([self::class, 'CleanLabelName'], $labelNames);

        // Implode to storage strings
        $labelsString      = implode(',', $cleanNames);
        $labelColorsString = implode(',', $labelColors);

        // Length check (multibyte safe)
        if (mb_strlen($labelsString, 'UTF-8') >= self::MAX_LABEL_FIELD_LEN
            || mb_strlen($labelColorsString, 'UTF-8') >= self::MAX_LABEL_FIELD_LEN) {
            throw new ApiException("The label configuration cannot be saved.", 400);
        }

        // Update DB with safe parameter binding
        try {
            $rows = DB::getInstance()->query(
                "UPDATE tarallo_boards 
             SET label_names = :label_names, label_colors = :label_colors 
             WHERE id = :board_id",
                [
                    'label_names'  => $labelsString,
                    'label_colors' => $labelColorsString,
                    'board_id'     => $boardID
                ]
            );

            if ($rows < 1) {
                Logger::warning("UpdateBoardLabelsInternal: No rows updated for board $boardID");
            }
        } catch (Throwable $e) {
            Logger::error("UpdateBoardLabelsInternal: DB error for board $boardID - " . $e->getMessage());
            throw new ApiException("Database error updating board labels.");
        }
    }

    /**
     * Create a new label on a board in the first available slot.
     * @param array $request Must contain 'board_id'
     * @return array Updated label data (names, colours, index)
     * @throws InvalidArgumentException On invalid board ID or exceeding label count.
     * @throws ApiException On DB error.
     */
    public static function createBoardLabel(array $request): array
    {
        if (!isset($request['board_id']) || !is_numeric($request['board_id'])) {
            throw new InvalidArgumentException("Missing or invalid board ID.");
        }
        $boardID = (int)$request['board_id'];
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Ensure user has rights to modify this board
        $boardData = Board::GetBoardData($boardID /*, UserTypes::USERTYPE_Member */);

        // Split label sets safely
        $boardLabelNames  = $boardData['label_names'] !== null ? explode(',', $boardData['label_names']) : [];
        $boardLabelColors = $boardData['label_colors'] !== null ? explode(',', $boardData['label_colors']) : [];

        $labelCount = count($boardLabelNames);

        // Find first empty label slot
        $labelIndex = array_search('', $boardLabelNames, true);
        if ($labelIndex === false) {
            if ($labelCount >= self::MAX_LABEL_COUNT) {
                throw new ApiException("Cannot create any more labels", 400);
            }
            // Add an empty label slot
            $labelIndex = $labelCount;
            $boardLabelNames[]  = '';
            $boardLabelColors[] = '';
        }

        // Assign default colour; keep name empty
        $newLabelColor = Label::DEFAULT_LABEL_COLORS[$labelIndex % count(Label::DEFAULT_LABEL_COLORS)];
        $boardLabelNames[$labelIndex]  = $newLabelColor;  // Default to color name
        $boardLabelColors[$labelIndex] = $newLabelColor;

        // Persist update
        try {
            Label::UpdateBoardLabelsInternal($boardID, $boardLabelNames, $boardLabelColors);
            Board::updateBoardModifiedTime($boardID);
        } catch (Throwable $e) {
            Logger::error("createBoardLabel: Failed to add label to board $boardID - " . $e->getMessage());
            throw new ApiException("Failed to create board label");
        }

        return [
            'label_names'  => implode(',', $boardLabelNames),
            'label_colors' => implode(',', $boardLabelColors),
            'index'        => $labelIndex
        ];
    }

    /**
     * Update a specific label on a board.
     * @param array $request Must contain 'board_id', 'index', 'name', 'color'.
     * @return array Updated label info: ['index', 'name', 'color']
     * @throws InvalidArgumentException On invalid parameters.
     * @throws ApiException On DB error.
     */
    public static function updateBoardLabel(array $request): array
    {
        // Validate required parameters
        foreach (['board_id', 'index', 'name', 'color'] as $key) {
            if (!isset($request[$key])) {
                throw new InvalidArgumentException("Missing parameter: $key");
            }
        }

        $boardID   = (int)$request['board_id'];
        $labelIndex = (int)$request['index'];
        $labelName = (string)$request['name'];
        $labelColor = (string)$request['color'];

        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Get board and check access; optionally enforce edit permission
        $boardData = Board::GetBoardData($boardID /*, UserTypes::USERTYPE_Member */);

        // Split safely
        $boardLabelNames  = $boardData['label_names'] !== null ? explode(',', $boardData['label_names']) : [];
        $boardLabelColors = $boardData['label_colors'] !== null ? explode(',', $boardData['label_colors']) : [];

        $labelCount = count($boardLabelNames);
        if ($labelIndex < 0 || $labelIndex >= $labelCount) {
            throw new InvalidArgumentException("Label index $labelIndex out of range (0-$labelCount).");
        }

        // Clean name
        $boardLabelNames[$labelIndex] = Label::CleanLabelName($labelName);
        $boardLabelColors[$labelIndex] = strtolower($labelColor);

        try {
            Label::UpdateBoardLabelsInternal($boardID, $boardLabelNames, $boardLabelColors);
            Board::updateBoardModifiedTime($boardID);
        } catch (Throwable $e) {
            Logger::error("updateBoardLabel: Failed for board $boardID, label $labelIndex - " . $e->getMessage());
            throw new ApiException("Failed to update board label");
        }

        return [
            'index' => $labelIndex,
            'name'  => $boardLabelNames[$labelIndex],
            'color' => $boardLabelColors[$labelIndex]
        ];
    }

    /**
     * Delete a label from a board and remove it from all card label masks.
     * @param array $request Must contain 'board_id' and 'index'.
     * @return array ['index' => int]
     */
    public static function DeleteBoardLabel(array $request): array
    {
        if (!isset($request['board_id'], $request['index'])) {
            throw new InvalidArgumentException("Missing board_id or index");
        }

        $boardID = (int)$request['board_id'];
        $labelIndex = (int)$request['index'];

        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        $boardData = Board::GetBoardData($boardID /*, UserTypes::USERTYPE_Member */);

        $names  = $boardData['label_names'] !== '' ? explode(',', $boardData['label_names']) : [];
        $colors = $boardData['label_colors'] !== '' ? explode(',', $boardData['label_colors']) : [];

        $labelCount = count($names);
        if ($labelIndex < 0 || $labelIndex >= $labelCount) {
            throw new InvalidArgumentException("Label index $labelIndex out of range.");
        }

        // Remove label name/color
        $names[$labelIndex]  = '';
        $colors[$labelIndex] = '';

        // Trim trailing empty slots
        while ($labelCount > 0 && $names[$labelCount - 1] === '') {
            array_pop($names);
            array_pop($colors);
            $labelCount--;
        }

        try {
            DB::getInstance()->beginTransaction();

            Label::UpdateBoardLabelsInternal($boardID, $names, $colors);

            // Clear the label bit from all cards
            $maskToRemove = ~(1 << $labelIndex);
            DB::getInstance()->query(
                "UPDATE tarallo_cards SET label_mask = label_mask & :maskToRemove WHERE board_id = :board_id",
                [
                    'maskToRemove' => $maskToRemove,
                    'board_id'     => $boardID
                ]
            );

            Board::updateBoardModifiedTime($boardID);
            DB::getInstance()->commit();
        } catch (Throwable $e) {
            DB::getInstance()->rollBack();
            Logger::error("DeleteBoardLabel: Failed for board $boardID - " . $e->getMessage());
            throw new ApiException("Failed to delete board label");
        }

        return ['index' => $labelIndex];
    }

    /**
     * Toggle a label on a specific card.
     * @param array $request Must include 'board_id', 'card_id', 'index', 'active'.
     * @return array Updated label info for the card.
     */
    public static function SetCardLabel(array $request): array
    {
        foreach (['board_id','card_id','index','active'] as $key) {
            if (!isset($request[$key])) {
                throw new InvalidArgumentException("Missing parameter: $key");
            }
        }

        $boardID    = (int)$request['board_id'];
        $cardID     = (int)$request['card_id'];
        $labelIndex = (int)$request['index'];
        $labelActive = (bool)$request['active'];

        if ($boardID <= 0 || $cardID <= 0) {
            throw new InvalidArgumentException("Invalid board or card ID");
        }

        $boardData = Board::GetBoardData($boardID, UserType::Member);

        $names  = $boardData['label_names'] !== null ? explode(',', $boardData['label_names']) : [];
        $colors = $boardData['label_colors'] !== null ? explode(',', $boardData['label_colors']) : [];

        if ($labelIndex < 0 || $labelIndex >= count($names)) {
            throw new InvalidArgumentException("Label index $labelIndex out of range.");
        }

        $cardData = Card::GetCardData($boardID, $cardID);

        $maskBit = (1 << $labelIndex);
        if ($labelActive) {
            $newMask = $cardData['label_mask'] | $maskBit;
        } else {
            $newMask = $cardData['label_mask'] & ~$maskBit;
        }

        try {
            DB::getInstance()->query(
                "UPDATE tarallo_cards SET label_mask = :mask WHERE id = :card_id",
                [
                    'mask'    => $newMask,
                    'card_id' => $cardData['id']
                ]
            );
            Board::updateBoardModifiedTime($boardID);
        } catch (Throwable $e) {
            Logger::error("SetCardLabel: Failed for card $cardID on board $boardID - " . $e->getMessage());
            throw new ApiException("Failed to update card label");
        }

        return [
            'card_id' => $cardData['id'],
            'index'   => $labelIndex,
            'name'    => $names[$labelIndex],
            'color'   => $colors[$labelIndex],
            'active'  => $labelActive
        ];
    }

}