import {asyncCall, asyncCallV2} from '../core/server.js';
import {ShowErrorPopup} from "../core/popup.js";

/**
 * Class to help with auth level operations
 */
export class Account {

    /**
     * Registers a new user.
     * @param {Object} options
     * @param {Function} options.username - The username.
     * @param {Function} options.password - The raw password.
     * @param {Function} options.displayName - The display name of the new user.
     * @param {Function} options.onSuccess - Called on successful registration.
     * @param {Function} options.onError - Called on failed registration.
     */
    async register({username, password, displayName, onSuccess, onError = (msg) => {ShowErrorPopup(msg, 'register-error');}}) {
        const args = {
            username,
            password,
            display_name: displayName
        };

        return await asyncCall("Register", args, onSuccess, onError);
    }

    /**
     * Logs a user into a session.
     * @param {Object} options
     * @param {Function} options.username - The username.
     * @param {Function} options.password - The raw password.
     * @param {Function} options.onSuccess - Called on successful login.
     * @param {Function} options.onError - Called on failed login attempt.
     */
    async login({username, password, onSuccess, onError = (msg) => {ShowErrorPopup(msg, 'login-error');}}) {
        const args = { username, password };
        return await asyncCall("Login", args, onSuccess, onError);
    }

    /**
     * Logs a user out of the session.
     * @param {Object} options
     * @param {Function} options.onSuccess - Called on successful logout.
     * @param {Function} options.onError - Called on failed logout.
     */
    async logout({onSuccess, onError = (msg) => {ShowErrorPopup(msg, 'page-popup');}}) {
        return await asyncCall('Logout', {}, onSuccess, onError);
    }

    /**
     * Sets user permission on the server.
     * @param {Object} options
     * @param {number} userId - The ID of the user
     * @param {string} userType - The type of permission to set
     * @param {Function} options.onSuccess - Called on successful logout.
     * @param {Function} options.onError - Called on failed logout.
     * @returns {Promise<Object>} Resolves with server response
     */
    async setUserPermission(userId, userType) {
        const args = { user_id: userId, user_type: userType };
        return await asyncCallV2("SetUserPermission", args);
    }
}
