import { showErrorPopup } from '../ui/popup.js';
import {TaralloServer} from '../api.js';

/**
 * Make an asynchronous call to the Tarallo server. This version throws on
 * failure to allow for better error handling.
 * @param apiName The API that wants to be called.
 * @param params The request parameters.
 * @returns {Promise<*>} The promise
 */
export async function asyncCallV2(apiName, params) {
    const result = await TaralloServer.call(apiName, params);

    if (result.succeeded) {
        return result.response;
    } else {
        // Throw an Error so try/catch can handle it
        throw new Error(result.error);
    }
}

// ===== Legacy =====

/**
 * Calls the Tarallo API method with standardised error handling
 * @param {string} method - API operation name
 * @param {Object} args - key/value parameters
 * @param {Function} onSuccess - callback for success
 * @param {string} popupId - error popup DOM ID
 */
export function serverAction(method, args, onSuccess, popupId) {
    TaralloServer.asyncCall(method, args, onSuccess, (msg) => {
        showErrorPopup(msg, popupId);
    });
}

/**
 * Async/await version for modern code
 */
export async function serverActionAsync(method, args, popupId) {
    const result = await TaralloServer.call(method, args);
    if (!result.succeeded) {
        showErrorPopup(result.error, popupId);
        return null;
    }
    return result.response;
}
