/**
 * Class to help with auth level operations
 */

import {ShowInfoPopup} from "../core/popup.js";
import {serverAction} from '../core/server.js';

export class AuthUI {

    /**
     * Initialise dependencies
     */
    init(page) {
        this.page = page;
    }

    /**
     * Logs a user out of the session
     */
    logout(onSuccess, popupId) {
        serverAction('Logout', {}, onSuccess, popupId);
    }

    /**
     * Logs a user into a session
     */
    login(onSuccess) {
        let args = {};
        args['username'] = document.getElementById('login-username').value;
        args['password'] = document.getElementById('login-password').value;
        serverAction('Login', args, onSuccess, 'login-error');
    }

    /**
     * Register a new user
     */
    register() {
        let args = {};
        args["username"] = document.getElementById("login-username").value;
        args["password"] = document.getElementById("login-password").value;
        args["display_name"] = document.getElementById("login-display-name").value;
        serverAction("Register", args, (response) => this._onSuccessfulRegistration(response), "register-error");
    }

    /**
     * Called on successful registration
     */
    _onSuccessfulRegistration(jsonResponseObj) {
        this.page.loadLoginPage(jsonResponseObj);
        document.getElementById("login-username").value = jsonResponseObj["username"];
        ShowInfoPopup("Account successfully created, please login!", "login-error");
    }
}
