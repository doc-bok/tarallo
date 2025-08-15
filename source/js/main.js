/**
 * The main entry point of the Tarallo app
 */

import {isMobileDevice} from "./core/utils.js";
import {TaralloClient} from "./tarallo-client.js";

if (isMobileDevice()) {
    document.body.classList.add("mobile");
}

// Create a new instance of the app
const app = new TaralloClient();

// Expose for debugging
window.TaralloApp = app;

// Start the app
app.start();