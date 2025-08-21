import {asyncCall} from "../core/server.js";

/**
 * Class to handle API calls for import/export.
 */
export class ImportExport {

    /**
     * Upload a chunk.
     * @param context The context in which we are uploading.
     * @param chunkCount The total number of chunks.
     * @param data The data to upload.
     * @param chunkIndex The current chunk's index.
     * @returns {Promise<*>} Updated when operation completed.
     */
    async uploadChunk(context, chunkCount, data, chunkIndex) {
        return await asyncCall('UploadChunk', {context, chunkCount, data, chunkIndex});
    }

    /**
     * Import a board from an uploaded file.
     * @returns {Promise<*>} Updated when operation completed.
     */
    async importBoard() {
        return await asyncCall('ImportBoard', {});
    }

    async importFromTrello(trelloExport) {
        return await asyncCall('ImportFromTrello', {trello_export: trelloExport});
    }
}