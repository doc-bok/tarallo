import {asyncCall} from "../core/server.js";

/**
 * Class to handle API calls for workspaces.
 */
export class Workspace {

    /**
     * Creates a new board with a default title.
     * @returns {Promise<*>} Updated when operation completes.
     */
    async create() {
        return await asyncCall('CreateNewWorkspace', {name: 'My New Board'});
    }
}
