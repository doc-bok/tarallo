import {GetQueryStringParams} from "./core/utils.js";

/**
 * A class to handle server requests to Tarallo.
 */
export class TaralloServer {

    /**
     * Request a JSON response from the server.
     * @param pageUrl The URL of the page.
     * @param params The parameters to use. Will automatically convert to URL
     *               string or POST params, depending on request type.
     * @param method The method to use for the call.
     * @returns {Promise<{succeeded: boolean, error: string}|{succeeded: boolean, response: any}>}
     */
    static async jsonRequest(pageUrl, params, method) {
        try {
            let requestUrl = pageUrl;
            let request = {
                method: method,
                headers: { "Content-Type": "application/json" },
            }

            // Remove PHPStorm-specific params for these requests.
            delete params._ijt;
            delete params._ij_reload;

            if (method === 'GET') {
                requestUrl = requestUrl + '?' + this.encodeQueryData(params);
            } else {
                request.body = JSON.stringify(params)
            }

            const response = await fetch(requestUrl, request);

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(
                    errorText || `Request to ${requestUrl} failed with ${response.status} (${response.statusText})`
                );
            }

            // Will throw if invalid JSON
            return { succeeded: true, response: await response.json() };
        } catch (err) {
            return { succeeded: false, error: err.message || "Network or JSON parsing error." };
        }
    }

    /**
     * Make a call to the Tarallo server.
     * @param apiName The API to call.
     * @param params The POST parameters.
     * @param method The method to use for the call.
     * @returns {Promise<{succeeded: boolean, error: string}|{succeeded: boolean, response: *}>}
     */
    static async call(apiName, params = {}, method) {
        const postParams = {
            OP: apiName,
            ...Object.fromEntries(GetQueryStringParams()),
            ...params
        };

        return await TaralloServer.jsonRequest("php/api.php", postParams, method);
    }

    static encodeQueryData(data) {
        const ret = [];
        for (let d in data)
            ret.push(encodeURIComponent(d) + '=' + encodeURIComponent(data[d]));
        return ret.join('&');
    }
}
