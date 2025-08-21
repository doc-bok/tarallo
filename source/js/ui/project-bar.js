import {replaceHtmlTemplateArgs} from '../core/utils.js';

/**
 * Handles the project bar element at the top of the page.
 */
export class ProjectBar {

    /**
     * Show just the basic options in the project bar.
     * @param pageContent
     */
    showBasicOptions(pageContent) {
        this._show(pageContent);
        this._hideElem(this._getProjectBarClosedElem())
        this._hideElem(this._getProjectBarLeftElem())
        this._hideElem(this._getProjectBarMiddleElem())
    }

    /**
     * Show more options when viewing a board.
     * @param pageContent
     */
    showBoardOptions(pageContent) {
        this._show(pageContent);
        this._hideElem(this._getProjectBarClosedElem())
        this._showElem(this._getProjectBarLeftElem())
        this._showElem(this._getProjectBarMiddleElem())
    }

    /**
     * Show the options for a closed board page.
     * @param pageContent
     */
    showClosedBoardOptions(pageContent) {
        this._show(pageContent);
        this._showElem(this._getProjectBarClosedElem())
        this._hideElem(this._getProjectBarLeftElem())
        this._showElem(this._getProjectBarMiddleElem())
    }

    /**
     * Hide the project bar completely.
     */
    hide() {
        this._hideElem(this._getProjectBarElem())
    }

    /**
     * Set the logout event.
     * @param callback The function to call when the element is clicked.
     */
    setLogoutEvent(callback) {
        const elem = document.getElementById('project-bar-logout-btn');
        if (elem) {
            elem.onclick = callback
        }
    }

    /**
     * Show the project bar.
     * @param pageContent The content with session information.
     * @private
     */
    _show(pageContent) {
        const projectBarElem = this._getProjectBarElem();
        if (projectBarElem) {
            this._showElem(projectBarElem);
            projectBarElem.innerHTML = replaceHtmlTemplateArgs(projectBarElem.innerHTML, pageContent);
        }
    }

    /**
     * Show the specified element.
     * @param elem The element to show.
     * @private
     */
    _showElem(elem) {
        if (elem) {
            elem.style.display = 'flex';
        }
    }

    /**
     * Hide the specified element.
     * @param elem The element to hide.
     * @private
     */
    _hideElem(elem) {
        if (elem) {
            elem.style.display = 'none';
        }
    }

    /**
     * Get the project bar element.
     * @returns {HTMLElement} The requested element.
     * @private
     */
    _getProjectBarElem() {
        return document.getElementById('project-bar');
    }

    /**
     * Get the left project bar element.
     * @returns {HTMLElement} The requested element.
     * @private
     */
    _getProjectBarLeftElem() {
        return document.getElementById('project-bar-left');
    }

    /**
     * Get the closed project bar element.
     * @returns {HTMLElement} The requested element.
     * @private
     */
    _getProjectBarClosedElem() {
        return document.getElementById('project-bar-closed');
    }

    /**
     * Get the middle project bar element.
     * @returns {HTMLElement} The requested element.
     * @private
     */
    _getProjectBarMiddleElem() {
        return document.getElementById('project-bar-middle');
    }
}