import {showErrorPopup} from "../ui/popup.js";
import {serverAction, serverActionAsync} from "../core/server.js";
import {fileToBase64, JsonFileToObj, loadTemplate, SelectFileDialog} from "../core/utils.js";

/**
 * Class to handle import/export operations
 */
export class ImportUI {

    /**
     * Initialise UI dependencies
     */
    init({boardUI, page}) {
        this.boardUI = boardUI;
        this.page = page;
    }

    /**
     * Imports a board
     */
    importBoard() {
        SelectFileDialog("application/zip", false, (boardExportZip) => {
            this._onBoardExportSelected(boardExportZip);
        });
    }

    /**
     * Called when a board export is selected
     */
    async _onBoardExportSelected(boardExportZip) {
        let response = { succeeded: true };

        // show loading dialog
        this._showLoadingDialog("Importing board...", "Upload in progress, do not refresh the board!");

        // upload exported zip to the server in chunks
        const chunkSize = 2000000;
        const chunkCount = Math.ceil(boardExportZip.size / chunkSize);
        let args = [];
        args["context"] = "ImportBoard";
        args["chunkCount"] = chunkCount;
        for (let i = 0; i < chunkCount && response.succeeded; i++) {
            // encode a file chunk in base64
            const startByte = i * chunkSize;
            let endByte = startByte + chunkSize;
            endByte = endByte > boardExportZip.size ? boardExportZip.size : endByte;
            args["data"] = await fileToBase64(boardExportZip.slice(startByte, endByte));
            // upload to server
            args["chunkIndex"] = i;
            response = await serverActionAsync("UploadChunk", args);
            // update progress
            this._setProgressPercent((i + 1) / chunkCount);
        }

        // check that all the blocks have been uploaded
        if (!response.succeeded) {
            this._hideLoadingDialog();
            this._setProgressPercent(0);
            showErrorPopup(response.error, "page-error");
            return;
        }

        serverAction("ImportBoard", [], (response) => this.boardUI.onBoardCreated(response), "page-error");
    }

    /**
     * Show the loading dialog
     */
    _showLoadingDialog(title, msg) {
        const args = [];
        args["title"] = title;
        args["msg"] = msg;
        const dialogElem = loadTemplate("tmpl-loading-dialog", args);
        this.page.getContentElem().append(dialogElem);
    }

    /**
     * Set the current progress
     */
    _setProgressPercent(percent) {
        const progressBarElem = document.getElementById("progress-bar");
        progressBarElem.style.width = (100 * percent) + "%"
    }

    /**
     * Hide the loading dialog
     */
    _hideLoadingDialog() {
        const dialogElem = document.getElementById("loading-dialog");
        if (dialogElem) {
            dialogElem.parentElement.remove();
        }
    }

    importFromTrello() {
        SelectFileDialog("application/json", false, (jsonFile) => this._onTrelloExportSelected(jsonFile));
    }

    async _onTrelloExportSelected(jsonFile) {
        // upload the new trello export to the server
        let args = [];
        args["trello_export"] = await JsonFileToObj(jsonFile);
        serverAction("ImportFromTrello", args, (response) => this.boardUI.onBoardCreated(response), "page-error");
    }
}
