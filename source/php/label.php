<?php

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
     * @throws RuntimeException On DB error.
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
            throw new RuntimeException("The label configuration cannot be saved.", 400);
        }

        // Update DB with safe parameter binding
        try {
            $rows = DB::query(
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
            throw new RuntimeException("Database error updating board labels.");
        }
    }

    /**
     * Create a new label on a board in the first available slot.
     * @param array $request Must contain 'board_id'
     * @return array Updated label data (names, colours, index)
     * @throws InvalidArgumentException On invalid board ID or exceeding label count.
     * @throws RuntimeException On DB error.
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
        $boardData = Board::GetBoardData($boardID /*, Permission::USERTYPE_Member */);

        // Split label sets safely
        $boardLabelNames  = $boardData['label_names'] !== '' ? explode(',', $boardData['label_names']) : [];
        $boardLabelColors = $boardData['label_colors'] !== '' ? explode(',', $boardData['label_colors']) : [];

        $labelCount = count($boardLabelNames);

        // Find first empty label slot
        $labelIndex = array_search('', $boardLabelNames, true);
        if ($labelIndex === false) {
            if ($labelCount >= self::MAX_LABEL_COUNT) {
                throw new RuntimeException("Cannot create any more labels", 400);
            }
            // Add an empty label slot
            $labelIndex = $labelCount;
            $boardLabelNames[]  = '';
            $boardLabelColors[] = '';
        }

        // Assign default colour; keep name empty
        $newLabelColor = Label::DEFAULT_LABEL_COLORS[$labelIndex % count(Label::DEFAULT_LABEL_COLORS)];
        $boardLabelNames[$labelIndex]  = '';  // Leave name blank until user renames
        $boardLabelColors[$labelIndex] = $newLabelColor;

        // Persist update
        try {
            Label::UpdateBoardLabelsInternal($boardID, $boardLabelNames, $boardLabelColors);
            DB::UpdateBoardModifiedTime($boardID);
        } catch (Throwable $e) {
            Logger::error("createBoardLabel: Failed to add label to board $boardID - " . $e->getMessage());
            throw new RuntimeException("Failed to create board label");
        }

        return [
            'label_names'  => implode(',', $boardLabelNames),
            'label_colors' => implode(',', $boardLabelColors),
            'index'        => $labelIndex
        ];
    }
}