import {asyncCall} from '../core/server.js';

/**
 * Class to help with auth level operations
 */
export class Account {

    /**
     * Registers a new user.
     * @param username The new username for the account.
     * @param password The password to set for the account.
     * @param displayName The display name to use for the account.
     * @returns {Promise<*>} The promise, updated once the call is complete.
     */
    async register(username, password, displayName) {
        const args = {
            username,
            password,
            display_name: displayName
        };

        return await asyncCall("Register", args);
    }

    /**
     * Logs a user into a session.
     * @param username The username for the account.
     * @param password The password to sign in with.
     * @returns {Promise<*>} The promise, updated once the call is complete.
     */
    async login(username, password) {
        const args = { username, password };
        return await asyncCall("Login", args);
    }

    /**
     * Logs a user out of the session.
     */
    async logout() {
        return await asyncCall('Logout', {});
    }

    /**
     * Sets user permission on the server.
     * @param userId The ID of the user.
     * @param userType The permission level to set for the user.
     * @returns {Promise<*>} The promise, updated once the call is complete.
     */
    async setUserPermission(userId, userType) {
        const args = { user_id: userId, user_type: userType };
        return await asyncCall("SetUserPermission", args, 'PUT');
    }
}
