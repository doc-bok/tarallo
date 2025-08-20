/**
 * A class to handle server operations related to the page.
 */
export class Page {

    /**
     * Get the add card list button.
     * @returns {HTMLElement} The HTML element.
     */
    getAddCardListButtonElem() {
        return document.getElementById("add-cardlist-btn");
    }

    /**
     * Get the board element from the DOM.
     * @returns {HTMLElement} The board element.
     */
    getBoardElem() {
        return document.getElementById("board");
    }

    /**
     * Get the content element from the DOM.
     * @returns {HTMLElement} The board element.
     */
    getContentElem() {
        return document.getElementById("content");
    }

    /**
     * Get the footer element from the DOM.
     * @returns {HTMLElement} The footer element.
     */
    getFooterElem() {
        return document.getElementById("footer");
    }

    /**
     * Get the login form.
     * @returns {HTMLElement} The login form element.
     */
    getLoginFormElem() {
        return document.getElementById("login-form");
    }

    /**
     * Get the project bar button.
     * @returns {HTMLElement} The HTML element.
     */
    getProjectBarElem() {
        return document.getElementById("project-bar");
    }

    /**
     * Get the unaccessible board request button.
     * @returns {HTMLElement} The HTML element.
     */
    getUnaccessibleBoardRequestButtonElem() {
        return document.getElementById("unaccessibleboard-request-btn");
    }

    /**
     Get the unaccessible board waiting label.
     * @returns {HTMLElement} The HTML element.
     */
    getUnaccessibleBoardWaitingLabelElem() {
        return document.getElementById("unaccessibleboard-waiting-label");
    }
}