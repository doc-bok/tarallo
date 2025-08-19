import {asyncCallV2} from "../core/server.js";

/**
 * Class to handle API calls for boards.
 */
export class Board {

    /**
     * Creates a new board with a default title.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async create() {
        return await asyncCallV2('CreateNewBoard', {title: 'My New Board'});
    }

    /**
     * Get the permissions of all the board's users.
     * @param id The ID of the board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async getPermissions(id) {
        return await asyncCallV2('GetBoardPermissions', {id});
    }

    /**
     * Request access to a board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async requestAccess() {
        return await asyncCallV2('RequestBoardAccess', {});
    }

    /**
     * Updates a board's title.
     * @param title The new title of the board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async updateTitle(title) {
        return await asyncCallV2('UpdateBoardTitle', {title});
    }

    /**
     * Uploads a new background for the board.
     * @param filename The filename of the background.
     * @param background The file to upload.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async uploadBackground(filename, background) {
        return await asyncCallV2('UploadBackground', {filename, background});
    }

    /**
     * Close a board.
     * @param id The ID of the board to close.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async close(id) {
        return await asyncCallV2('CloseBoard', {id});
    }

    /**
     * Reopens a board after it has been closed.
     * @param id The ID of the board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async reopen(id) {
        return await asyncCallV2('ReopenBoard', {id});
    }

    /**
     * Permanently deletes a closed board.
     * @param id The ID of the board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async delete(id) {
        return await asyncCallV2('DeleteBoard', {id});
    }
}