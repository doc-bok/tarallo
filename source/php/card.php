<?php

declare(strict_types=1);

require_once __DIR__ . '/attachment.php';

class Card
{
    private const CARD_FLAG_LOCKED = 0x001;

    /**
     * Convert a raw DB card record into an API-friendly array.
     *
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
}