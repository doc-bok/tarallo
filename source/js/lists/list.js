import {asyncCall} from "../core/server.js";

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
    async create(name, prevListId) {
        return await asyncCall('AddCardList', {name, prev_list_id: prevListId});
    }

    /**
     * Updates the name of a card list.
     * @param id The ID of the card list.
     * @param name The new name.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async updateName(id, name) {
        return await asyncCall('UpdateCardListName', {id, name}, 'PUT');
    }

    /**
     * Move a card list to a new position.
     * @param movedCardListId The ID of the card list to move.
     * @param newPrevCardListId The new previous ID in the linked list.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async move(movedCardListId, newPrevCardListId) {
        return await asyncCall(
            'MoveCardList',
            {
                moved_cardlist_id: movedCardListId,
                new_prev_cardlist_id: newPrevCardListId
            },
            'PUT');
    }

    /**
     * Deletes a card list.
     * @param id The ID of the card list.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async delete(id) {
        return await asyncCall('DeleteCardList', {id}, 'DELETE');
    }
}