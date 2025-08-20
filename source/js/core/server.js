import {TaralloServer} from '../api.js';

/**
 * Make an asynchronous call to the Tarallo server. This version throws on
 * failure to allow for better error handling.
 * @param apiName The API that wants to be called.
 * @param params The request parameters.
 * @returns {Promise<*>} The promise
 */
export async function asyncCall(apiName, params) {
    const result = await TaralloServer.call(apiName, params);

    if (result.succeeded) {
        return result.response;
    } else {
        // Throw an Error so try/catch can handle it
        throw new Error(result.error);
    }
}
