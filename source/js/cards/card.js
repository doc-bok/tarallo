import {asyncCall} from "../core/server.js";

/**
 * Class to handle server operations for cards.
 */
export class Card {

    /**
     * Create a new card.
     * @param cardListId The ID of the card list to add the card to.
     * @param title The title of the card.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async create(cardListId, title) {
        return await asyncCall('AddNewCard', {cardlist_id: cardListId, title})
    }

    /**
     * Open a card to view.
     * @param id The ID of the card.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async open(id) {
        return await asyncCall('OpenCard', {id}, 'GET');
    }

    /**
     * Move a card.
     * @param movedCardId The ID of the card to move.
     * @param newPrevCardId The ID of the new previous card on the linked list.
     * @param destinationCardListId The ID of the destination card list.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async move(movedCardId, newPrevCardId, destinationCardListId) {
        return await asyncCall(
            'MoveCard',
            {
                moved_card_id: movedCardId,
                new_prev_card_id: newPrevCardId,
                dest_cardlist_id: destinationCardListId
            },
            'PUT')
    }

    /**
     * Update a card's title.
     * @param id The ID of the card.
     * @param title The new title of the card.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async updateTitle(id, title) {
        return await asyncCall('UpdateCardTitle', {id, title}, 'PUT');
    }

    /**
     * Update a cards flags. Presently the only flag is locked/unlocked.
     * @param id The ID of the card.
     * @param locked TRUE if the card is locked.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async updateFlags(id, locked) {
        return await asyncCall('UpdateCardFlags', {id, locked}, 'PUT');
    }

    /**
     * Update the card's content.
     * @param id The ID of the card.
     * @param content The content in the card.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async updateContent(id, content) {
        return await asyncCall('UpdateCardContent', {id, content}, 'PUT');
    }

    /**
     * Deletes a card.
     * @param id The ID of the card.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async delete(id) {
        return await asyncCall('DeleteCard', {deleted_card_id: id}, 'DELETE');
    }
}