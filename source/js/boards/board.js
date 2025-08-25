import {asyncCall} from "../core/server.js";

/**
 * Class to handle API calls for boards.
 */
export class Board {

    /**
     * Creates a new board with a default title.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async create() {
        return await asyncCall('CreateNewBoard', {title: 'My New Board'});
    }

    /**
     * Get the permissions of all the board's users.
     * @param id The ID of the board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async getPermissions(id) {
        return await asyncCall('GetBoardPermissions', {id}, 'GET');
    }

    /**
     * Request access to a board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async requestAccess() {
        return await asyncCall('RequestBoardAccess', {});
    }

    /**
     * Updates a board's title.
     * @param title The new title of the board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async updateTitle(title) {
        return await asyncCall('UpdateBoardTitle', {title}, 'PUT');
    }

    /**
     * Uploads a new background for the board.
     * @param filename The filename of the background.
     * @param background The file to upload.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async uploadBackground(filename, background) {
        return await asyncCall('UploadBackground', {filename, background});
    }

    /**
     * Close a board.
     * @param id The ID of the board to close.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async close(id) {
        return await asyncCall('CloseBoard', {id}, 'PUT');
    }

    /**
     * Reopens a board after it has been closed.
     * @param id The ID of the board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async reopen(id) {
        return await asyncCall('ReopenBoard', {id}, 'PUT');
    }

    /**
     * Permanently deletes a closed board.
     * @param id The ID of the board.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async delete(id) {
        return await asyncCall('DeleteBoard', {id}, 'DELETE');
    }
}