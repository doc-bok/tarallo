import {replaceHtmlTemplateArgs} from "../core/utils.js";

/**
 * Handles the project bar element at the top of the page.
 */
export class ProjectBar {

    showFull(pageContent) {
        this._show(pageContent);
        this._showElem(this._getProjectBarLeftElem())
        this._showElem(this._getProjectBarMiddleElem())
    }

    showSimple(pageContent) {
        this._show(pageContent);
        this._hideElem(this._getProjectBarLeftElem())
        this._hideElem(this._getProjectBarMiddleElem())
    }

    hide() {
        this._hideElem(this._getProjectBarElem())
    }

    setLogoutEvent(callback) {
        const elem = document.getElementById('project-bar-logout-btn');
        if (elem) {
            elem.onclick = callback
        }
    }

    _show(pageContent) {
        const projectBarElem = this._getProjectBarElem();
        if (projectBarElem) {
            this._showElem(projectBarElem);
            projectBarElem.innerHTML = replaceHtmlTemplateArgs(projectBarElem.innerHTML, pageContent);
        }
    }

    _showElem(elem) {
        if (elem) {
            elem.style.display = "flex";
        }
    }

    _hideElem(elem) {
        if (elem) {
            elem.style.display = "none";
        }
    }

    _getProjectBarElem() {
        return document.getElementById("project-bar");
    }

    _getProjectBarLeftElem() {
        return document.getElementById("project-bar-left");
    }

    _getProjectBarMiddleElem() {
        return document.getElementById("project-bar-middle");
    }
}