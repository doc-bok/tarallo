import {showErrorPopup} from "../ui/popup.js";
import {fileToBase64, jsonFileToObj, loadTemplate, selectFileDialog} from "../core/utils.js";
import {ImportExport} from "./import-export.js";

/**
 * Class to handle import/export operations
 */
export class ImportExportUi {

    /**
     * Initialise UI dependencies
     */
    init({boardUI, page}) {
        this.boardUI = boardUI;
        this.importExport = new ImportExport();
        this.page = page;
    }

    /**
     * Imports a board
     */
    importBoard() {
        selectFileDialog("application/zip", false, (boardExportZip) => {
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
        const context = "ImportBoard";

        try {
            for (let i = 0; i < chunkCount && response.succeeded; i++) {

                // encode a file chunk in base64
                const startByte = i * chunkSize;
                let endByte = startByte + chunkSize;
                endByte = endByte > boardExportZip.size ? boardExportZip.size : endByte;
                const data = await fileToBase64(boardExportZip.slice(startByte, endByte));

                // upload to server
                response = await this.importExport.uploadChunk(context, chunkCount, data, i);

                // update progress
                this._setProgressPercent((i + 1) / chunkCount);
            }

            if (response.succeeded) {
                const importResponse = await this.importExport.importBoard();
                this.boardUI.onBoardCreated(importResponse);
            }
        } catch (e) {
            this._hideLoadingDialog();
            this._setProgressPercent(0);
            showErrorPopup(`Failed to import board: ${e.message}`, 'page-error');
        }
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
        selectFileDialog("application/json", false, (jsonFile) => this._onTrelloExportSelected(jsonFile));
    }

    async _onTrelloExportSelected(jsonFile) {
        try {
            const trelloExport = await jsonFileToObj(jsonFile);
            const response = await this.importExport.importFromTrello(trelloExport);
            this.boardUI.onBoardCreated(response);
        } catch (e) {
            showErrorPopup(`Failed to import board from Trello: ${e.message}`, 'page-error');
        }
    }
}
