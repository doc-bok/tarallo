/**
 * A class to handle server requests to Tarallo.
 */
class TaralloServer {

    /**
     * Request a JSON response from the server.
     * @param pageUrl The URL of the page.
     * @param postParams The POST parameters.
     * @returns {Promise<{succeeded: boolean, error: string}|{succeeded: boolean, response: any}>}
     */
    static async jsonRequest(pageUrl, postParams) {
        try {
            const response = await fetch(pageUrl, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(postParams)
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(
                    errorText || `Request to ${pageUrl} failed with ${response.status} (${response.statusText})`
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
     * @returns {Promise<{succeeded: boolean, error: string}|{succeeded: boolean, response: *}>}
     */
    static async call(apiName, params = {}) {
        const postParams = {
            OP: apiName,
            ...Object.fromEntries(TaralloUtils.GetQueryStringParams()),
            ...params
        };

        return await TaralloServer.jsonRequest("php/api.php", postParams);
    }

    /**
     * Make an asynchronous call to the Tarallo Server.
     * @param apiName The API to call.
     * @param params The POST parameters.
     * @param onSuccess The callback to use on success.
     * @param onError The callback to use on failure.
     * @returns {Promise<void>}
     */
    static async asyncCall(apiName, params, onSuccess, onError) {
        const result = await TaralloServer.call(apiName, params);
        if (result.succeeded) {
            onSuccess?.(result.response);
        } else {
            onError?.(result.error);
        }
    }
}
