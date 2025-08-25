import {asyncCall} from "../core/server.js";

/**
 * Class to handle Attachment API calls.
 */
export class Attachment {

    /**
     * Upload an attachment.
     * @param cardId The ID of the card to attach to.
     * @param filename The name of the file.
     * @param attachment The attachment data.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async upload(cardId, filename, attachment) {
        return await asyncCall('UploadAttachment', {card_id: cardId, filename, attachment});
    }

    /**
     * Update an attachment's name.
     * @param id The ID of the attachment.
     * @param name The new name for the attachment.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async updateName(id, name) {
        return await asyncCall('UpdateAttachmentName', {id, name}, 'PUT');
    }

    /**
     * Delete an attachment.
     * @param id The ID of the attachment to delete.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async delete(id) {
        return await asyncCall('DeleteAttachment', {id}, 'DELETE');
    }
}