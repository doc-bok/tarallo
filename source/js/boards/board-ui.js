import {
    closeDialog,
    fileToBase64,
    loadTemplate,
    selectFileDialog,
    setEventBySelector
} from "../core/utils.js";
import {ShareDialog} from "../ui/share-dialog.js";
import {showErrorPopup} from "../ui/popup.js";
import {Board} from "./board.js";

/**
 * Class to help with board-level operations
 */
export class BoardUI {

    /**
     * Setup dependencies
     */
    init({account, page, pageUI}) {
        this.account = account;
        this.board = new Board();
        this.page = page;
        this.pageUI = pageUI;
    }

    /**
     * Set the background of a board.
     */
    setBackground(backgroundUrl, tiled) {
        document.body.style.backgroundImage = `url("${backgroundUrl}")`;
        if (tiled) {
            document.body.classList.remove('nontiled-bg');
        } else {
            document.body.classList.add('nontiled-bg');
        }
    }

    /**
     * Load a board tile for display
     */
    loadBoardTile(boardData) {
        const boardListElem = document.getElementById("boards");
        const closedListElem = document.getElementById("closed-boards");
        const createBoardBtn = document.getElementById("new-board-btn");

        const newBoardTileElem = boardData.closed
            ? loadTemplate("tmpl-closed-boardtile", boardData)
            : loadTemplate("tmpl-boardtile", boardData);

        if (boardData["closed"]) {
            closedListElem.appendChild(newBoardTileElem);
        } else {
            boardListElem.insertBefore(newBoardTileElem, createBoardBtn);
            setEventBySelector(newBoardTileElem, ".delete-board-btn", "onclick", () => this._closeBoard(boardData.id));
        }
    }

    /**
     * Close a board
     */
    async _closeBoard(id) {
        try {
            const response = await this.board.close(id);
            this._onBoardClosed(response);
        } catch (e) {
            showErrorPopup('Could not close board: ' + e.message, 'page-error');
        }
    }

    /**
     * Called after a board is closed.
     */
    _onBoardClosed(response) {
        const boardTileElem = document.getElementById("board-tile-" + response.id);
        if (boardTileElem) {
            boardTileElem.remove();
        }

        this.loadBoardTile(response);
    }

    /**
     * Create a new board
     */
    async createNewBoard() {
        try {
            const response = await this.board.create();
            this.onBoardCreated(response);
        } catch (e) {
            showErrorPopup('Could not create new board: ' + e.message, 'page-error');
        }
    }

    /**
     * Called after a board is created
     */
    onBoardCreated(jsonResponseObj) {
        if (Number.isInteger(jsonResponseObj["id"])) {
            // redirect to the newly created board
            window.location.href = new URL("?board_id=" + jsonResponseObj["id"], window.location.href).href;
        }
    }

    /**
     * Called when a board title is changed
     * @param titleNode The node that contains the new title.
     */
    async boardTitleChanged(titleNode) {
        try {
            const response = await this.board.updateTitle(titleNode.textContent);
            this._onBoardTitleUpdated(response);
        } catch (e) {
            showErrorPopup('Could not update board: ' + e.message, 'page-error');
        }
    }

    /**
     * Called when a board title is updated
     */
    _onBoardTitleUpdated(jsonResponseObj) {
        const boardTitleElem = document.getElementById("project-bar-left").querySelector("h2");
        boardTitleElem.textContent = jsonResponseObj["title"];
    }

    /**
     * Change the background of a board
     */
    changeBackground() {
        selectFileDialog("image/*", false, (file) => this._onBackgroundSelected(file));
    }

    /**
     * Called when a background is selected
     */
    async _onBackgroundSelected(file) {
        try {
            const response = await this.board.uploadBackground(file.name, await fileToBase64(file));
            this._onBackgroundChanged(response);
        } catch (e) {
            showErrorPopup('Could not upload background: ' + e.message, 'page-error');
        }
    }

    /**
     * Called when the board background changes
     */
    _onBackgroundChanged(jsonResponseObj) {
        this.setBackground(jsonResponseObj["background_url"], jsonResponseObj["background_tiled"]);
    }

    /**
     * Share a board
     */
    async shareBoard(id) {
        try {
            const response = await this.board.getPermissions(id);
            this._loadShareDialog(response);
        } catch (e) {
            showErrorPopup('Could not update board permissions: ' + e.message, 'page-error');
        }
    }

    /**
     * Open the share dialog
     */
    _loadShareDialog(jsonResponseObj) {
        // initialize the share dialog
        const shareDialogElem = loadTemplate("tmpl-share-dialog", jsonResponseObj);
        setEventBySelector(shareDialogElem, ".dialog-close-btn", "onclick", () => closeDialog('share-dialog-container'));
        const permissionListElem = shareDialogElem.querySelector("#share-dialog-list");
        const dialogButtons = permissionListElem.querySelector(".share-dialog-entry");

        const shareDialog = new ShareDialog(this.account);

        // add site admin permissions
        if (jsonResponseObj["is_admin"]) {

            // func to append a special permission element (whether it already exist or not in the DB)
            const AddSpecialPermission = (userId, permissionName, description) => {
                const permission = jsonResponseObj["permissions"].find((p) => p["user_id"] === userId);
                const permissionObj = {
                    "display_name": permissionName,
                    "user_id" : userId,
                    "user_type" : permission ? permission["user_type"] : 10,
                    "class_list": "contrast-text",
                    "hover_text": description
                };

                permissionListElem.insertBefore(shareDialog.show(permissionObj), dialogButtons);
            }

            // add on registration board permissions
            AddSpecialPermission(-1, "On registration", "Permission for this board that is automatically assigned to new users on registration");
        }

        // add all permission rows
        for (const permission of jsonResponseObj["permissions"]) {
            if (permission["user_id"] < 0) {
                continue; // skip special
            }

            permissionListElem.insertBefore(shareDialog.show(permission), dialogButtons);
        }

        // add the dialog to the content
        const contentElem = this.page.getContentElem();
        contentElem.appendChild(shareDialogElem);
    }

    /**
     * Reopen a board
     */
    async reopenBoard(id) {
        try {
            await this.board.reopen(id);
            this._onBoardReopened();
        } catch (e) {
            showErrorPopup('Could not reopen board: ' + e.message, 'page-error');
        }
    }

    /**
     * Called after a board is reopened.
     */
    _onBoardReopened() {
        this.pageUI.getCurrentPage();
    }

    /**
     * Show a confirmation before delete
     */
    showBoardDeleteConfirmation(boardID) {
        const msgElem = document.getElementById("closedboard-delete-label");
        const linkElem = document.getElementById("closedboard-delete-link");
        msgElem.classList.remove("hidden");

        // Remove existing click handlers
        const newLinkElem = linkElem.cloneNode(true);
        linkElem.parentNode.replaceChild(newLinkElem, linkElem);

        newLinkElem.textContent = "Yes, delete everything!";
        newLinkElem.onclick = () => this._deleteBoard(boardID);
    }

    /**
     * Delete a board
     */
    async _deleteBoard(id) {
        try {
            const response = await this.board.delete(id);
            this._onBoardDeleted(response);
        } catch (e) {
            showErrorPopup('Could not delete board: ' + e.message, 'page-error');
        }
    }

    /**
     * Called after a board is deleted
     */
    _onBoardDeleted() {
        // redirect to the home page
        window.location.href = new URL("?", window.location.href).href;
    }

    /**
     * Request access to a board
     */
    async requestBoardAccess() {
        try {
            const response = await this.board.requestAccess();
            this._onBoardAccessUpdated(response);
        } catch (e) {
            showErrorPopup('Could not request access to board: ' + e.message, 'page-error');
        }
    }

    /**
     * Called when board access is updated
     */
    _onBoardAccessUpdated(jsonResponseObj) {
        if (jsonResponseObj["access_requested"]) {
            this.page.getUnaccessibleBoardRequestButtonElem().classList.add("hidden");
            this.page.getUnaccessibleBoardWaitingLabelElem().classList.remove("hidden");
        }
    }
}
