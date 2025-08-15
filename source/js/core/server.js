import { ShowErrorPopup } from './popup.js';
import {TaralloServer} from '../api.js';

/**
 * Calls the Tarallo API method with standardised error handling
 * @param {string} method - API operation name
 * @param {Object} args - key/value parameters
 * @param {Function} onSuccess - callback for success
 * @param {string} popupId - error popup DOM ID
 */
export function serverAction(method, args, onSuccess, popupId) {
    TaralloServer.asyncCall(method, args, onSuccess, (msg) => {
        ShowErrorPopup(msg, popupId);
    });
}

/**
 * Async/await version for modern code
 */
export async function serverActionAsync(method, args, popupId) {
    const result = await TaralloServer.call(method, args);
    if (!result.succeeded) {
        ShowErrorPopup(result.error, popupId);
        return null;
    }
    return result.response;
}
