import {
    CloseDialog,
    FileToBase64,
    LoadTemplate,
    SelectFileDialog,
    setEventBySelector
} from "../core/utils.js";
import {serverAction} from "../core/server.js";
import {ShareDialog} from "../ui/share-dialog.js";

/**
 * Class to help with board-level operations
 */
export class BoardUI {

    /**
     * Setup dependencies
     */
    init({account, page, pageUI}) {
        this.account = account;
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

        if (boardData["closed"]) {
            // add a tile for a closed board
            const newBoardTileElem = LoadTemplate("tmpl-closed-boardtile", boardData);
            closedListElem.appendChild(newBoardTileElem);
        } else {
            // add tile for a normal board
            const newBoardTileElem = LoadTemplate("tmpl-boardtile", boardData);
            boardListElem.insertBefore(newBoardTileElem, createBoardBtn);
            setEventBySelector(newBoardTileElem, ".delete-board-btn", "onclick", () => this._closeBoard(boardData["id"]));
        }
    }

    /**
     * Close a board
     */
    _closeBoard(boardID) {
        let args = [];
        args["id"] = boardID;
        serverAction("CloseBoard", args, (response) => this._onBoardClosed(response), 'page-error');
    }

    /**
     * Called after a board is closed.
     */
    _onBoardClosed(jsonResponseObj) {
        const boardTileElem = document.getElementById("board-tile-" + jsonResponseObj["id"]);
        boardTileElem.remove();
        this.loadBoardTile(jsonResponseObj);
    }

    /**
     * Create a new board
     */
    createNewBoard() {
        let args = [];
        args["title"] = "My new board";
        serverAction("CreateNewBoard", args, (response) => this.onBoardCreated(response), "page-error");
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
     * @param titleNode
     */
    boardTitleChanged(titleNode) {
        let args = [];
        args["title"] = titleNode.textContent;
        serverAction("UpdateBoardTitle", args, (response) => this._onBoardTitleUpdated(response), "page-error");
    }

    /**
     * Called when a board title is updated
     */
    _onBoardTitleUpdated(jsonResponseObj) {
        const boardTitleElem = document.getElementById("projectbar-left").querySelector("h2");
        boardTitleElem.textContent = jsonResponseObj["title"];
    }

    /**
     * Change the background of a board
     */
    changeBackground(boardID) {
        SelectFileDialog("image/*", false, (file) => this._onBackgroundSelected(file, boardID));
    }

    /**
     * Called when a background is selected
     */
    async _onBackgroundSelected(file, boardID) {
        // upload the new background image to the server
        let args = [];
        args["filename"] = file.name;
        args["background"] = await FileToBase64(file);
        serverAction("UploadBackground", args, (response) => this._onBackgroundChanged(response), "page-error");
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
    shareBoard(boardID) {
        let args = [];
        args["id"] = boardID;
        serverAction("GetBoardPermissions", args, (response) => this._loadShareDialog(response), "page-error");
    }

    /**
     * Open the share dialog
     */
    _loadShareDialog(jsonResponseObj) {
        // initialize the share dialog
        const shareDialogElem = LoadTemplate("tmpl-share-dialog", jsonResponseObj);
        setEventBySelector(shareDialogElem, ".dialog-close-btn", "onclick", () => CloseDialog());
        const permissionListElem = shareDialogElem.querySelector("#share-dialog-list");
        const dialogButtons = permissionListElem.querySelector(".share-dialog-entry");

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
                const permissionElem = new ShareDialog(this.account);                ;
                permissionListElem.insertBefore(permissionElem.show(permissionObj), dialogButtons);
            }

            // add on registration board permissions
            AddSpecialPermission(-1, "On registration", "Permission for this board that is automatically assigned to new users on registration");
        }

        // add all permission rows
        for (const permission of jsonResponseObj["permissions"]) {
            if (permission["user_id"] < 0)
                continue; // skip special
            const permissionElem = new ShareDialog(this.account);
            permissionListElem.insertBefore(permissionElem.show(permission), dialogButtons);
        }

        // add the dialog to the content
        const contentElem = this.page.getContentElem();
        contentElem.appendChild(shareDialogElem);
    }

    /**
     * Reopen a board
     */
    reopenBoard(boardID) {
        let args = [];
        args["id"] = boardID;
        serverAction("ReopenBoard", args, (response) => this.onBoardReopened(response), "page-error");
    }

    /**
     * Called after a board is reopened.
     */
    onBoardReopened(jsonResponseObj) {
        this.pageUI.getCurrentPage();
    }

    /**
     * Show a confirmation before delete
     */
    showBoardDeleteConfirmation(boardID) {
        const msgElem = document.getElementById("closedboard-delete-label");
        const linkElem = document.getElementById("closedboard-delete-link");
        msgElem.classList.remove("hidden");
        linkElem.textContent = "Yes, delete everything!";
        linkElem.onclick = () => this._deleteBoard(boardID);
    }

    /**
     * Delete a board
     */
    _deleteBoard(boardID) {
        let args = [];
        args["id"] = boardID;
        serverAction("DeleteBoard", args, (response) => this._onBoardDeleted(response), "page-error");
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
    requestBoardAccess() {
        serverAction("RequestBoardAccess", [], (response) => this._onBoardAccessUpdated(response), "page-error");
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
