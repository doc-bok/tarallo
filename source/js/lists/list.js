import {asyncCallV2} from "../core/server.js";

/**
 * Class to handle API calls for card lists.
 */
export class List {

    /**
     * Add a new card list.
     * @param name The new name.
     * @param prevListId The id of the previous card on the linked list.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async add(name, prevListId) {
        return await asyncCallV2('AddCardList', {name, prev_list_id: prevListId});
    }

    /**
     * Updates the name of a card list.
     * @param id The ID of the card list.
     * @param name The new name.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async updateName(id, name) {
        return await asyncCallV2('UpdateCardListName', {id, name});
    }

    /**
     * Move a card list to a new position.
     * @param movedCardListId The ID of the card list to move.
     * @param newPrevCardListId The new previous ID in the linked list.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async move(movedCardListId, newPrevCardListId) {
        return await asyncCallV2(
            'MoveCardList',
            {
                moved_cardlist_id: movedCardListId,
                new_prev_cardlist_id: newPrevCardListId
            });
    }

    /**
     * Deletes a card list.
     * @param id The ID of the card list.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async delete(id) {
        return await asyncCallV2('DeleteCardList', {id});
    }
}