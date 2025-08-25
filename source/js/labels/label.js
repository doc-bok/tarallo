import {asyncCall} from "../core/server.js";

/**
 * Handles server operations for labels.
 */
export class Label {

    /**
     * Create a new label.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async create() {
        return await asyncCall("CreateBoardLabel", {})
    }

    /**
     * Update a label.
     * @param index The index of the label.
     * @param name The name of the label.
     * @param color The color of the label.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async update(index, name, color) {
        return await asyncCall('UpdateBoardLabel', {index, name, color}, 'PUT');
    }

    /**
     * Sets a label on a card.
     * @param cardId The ID of the card.
     * @param index The index of the label.
     * @param active Whether the label is active or not.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async set(cardId, index, active) {
        return await asyncCall('SetCardLabel', {card_id: cardId, index, active}, 'PUT');
    }

    /**
     * Deletes a label.
     * @param index The index of the label.
     * @returns {Promise<*>} Updated when the operation completes.
     */
    async delete(index) {
        return await  asyncCall('DeleteBoardLabel', {index}, 'DELETE');
    }
}