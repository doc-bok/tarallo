import {asyncCall} from '../core/server.js';
import {ShowErrorPopup} from "../core/popup.js";

/**
 * Class to help with auth level operations
 */
export class Auth {

    /**
     * Registers a new user.
     * @param {Object} options
     * @param {Function} options.username - The username.
     * @param {Function} options.password - The raw password.
     * @param {Function} options.displayName - The display name of the new user.
     * @param {Function} options.onSuccess - Called on successful registration.
     * @param {Function} options.onError - Called on failed registration.
     */
    register({username, password, displayName, onSuccess, onError = (msg) => {ShowErrorPopup(msg, 'register-error');}}) {
        const args = {
            username,
            password,
            display_name: displayName
        };

        asyncCall("Register", args, onSuccess, onError);
    }

    /**
     * Logs a user into a session.
     * @param {Object} options
     * @param {Function} options.username - The username.
     * @param {Function} options.password - The raw password.
     * @param {Function} options.onSuccess - Called on successful login.
     * @param {Function} options.onError - Called on failed login attempt.
     */
    login({username, password, onSuccess, onError = (msg) => {ShowErrorPopup(msg, 'login-error');}}) {
        const args = { username, password };
        asyncCall("Register", args, onSuccess, onError);
    }

    /**
     * Logs a user out of the session.
     * @param {Object} options
     * @param {Function} options.onSuccess - Called on successful logout.
     * @param {Function} options.onError - Called on failed logout.
     */
    logout({onSuccess, onError = (msg) => {ShowErrorPopup(msg, 'page-popup');}}) {
        asyncCall('Logout', {}, onSuccess, onError);
    }
}
