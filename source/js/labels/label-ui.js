import {loadTemplate, setEventBySelector} from "../core/utils.js";
import {Label} from "./label.js";
import {showErrorPopup} from "../ui/popup.js";

export class CardLabelUI {

    /**
     * Construction
     */
    constructor() {
        this._allColorNames = [];
        this.label = new Label();
        this._labelNames = [];
        this._labelColors = [];
    }

    init(cardUI) {
        this.cardUI = cardUI;
    }

    /**
     * Mutators
     */
    setAllColorNames(colorNames) {
        this._allColorNames = colorNames;
    }

    setLabelColors(colorNames) {
        this._labelColors = colorNames;
    }

    setLabelNames(labelNames) {
        this._labelNames = labelNames;
    }

    /**
     * Accessors
     */
    getLabelNames() {
        return this._labelNames;
    }

    /**
     * Load a label
     */
    loadLabel(templateName, labelIndex, additionalParams = {}) {
        const labelData = additionalParams;
        labelData["name"] = this._labelNames[labelIndex];
        labelData["color"] = this._labelColors[labelIndex];
        return loadTemplate(templateName, labelData);
    }

    /**
     * Load the label in an open card
     */
    loadLabelInOpenCard(openCardElem, cardID, labelIndex, active) {
        // retrieve needed elements of the open card
        const labelListElem = openCardElem.querySelector(".opencard-labellist");
        const labelSelectionDiag = openCardElem.querySelector("#opencard-label-select-diag");
        const addLabelBtnElem = openCardElem.querySelector(".opencard-add-label");
        const createLabelBtnElem = openCardElem.querySelector(".opencard-label-create-btn");

        // prepare label info
        const labelAdditionalParams = { "card-id": cardID, "index": labelIndex };
        const labelElemID = "#label-" + cardID + "-" + labelIndex;
        const openLabelElemID = labelElemID + "-open";

        // retrieve elements of the card tile in the board
        const cardElem = document.getElementById("card-" + cardID);
        const cardLabelListElem = cardElem.querySelector(".card-labellist");
        const cardLabelElem = cardElem.querySelector(labelElemID);

        if (active) {
            // remove label from the selectable ones
            const selectableLabelElem = labelSelectionDiag.querySelector(openLabelElemID);
            if (selectableLabelElem) {
                selectableLabelElem.remove();
            }

            // add it to the open card
            const openLabelElem = this.loadLabel("tmpl-opencard-label", labelIndex, labelAdditionalParams);
            labelListElem.insertBefore(openLabelElem, addLabelBtnElem);
            openLabelElem.onclick = () => this._setLabel(cardID, labelIndex, false);

            // add it to the card if missing
            if (!cardLabelElem) {
                const labelElem = this.loadLabel("tmpl-card-label", labelIndex, labelAdditionalParams);
                cardLabelListElem.appendChild(labelElem);
            }
        } else {
            //remove label from the open card
            const openLabelElem = labelListElem.querySelector(openLabelElemID);
            if (openLabelElem) {
                openLabelElem.remove();
            }
            // remove label from the card tile in the board
            if (cardLabelElem) {
                cardLabelElem.remove();
            }

            // add it to the selectable ones
            const labelElem = this.loadLabel("tmpl-selectable-label", labelIndex, labelAdditionalParams);
            labelSelectionDiag.insertBefore(labelElem, createLabelBtnElem);
            setEventBySelector(labelElem, ".selectable-label", "onclick", () => this._setLabel(cardID, labelIndex, true));
            setEventBySelector(labelElem, ".selectable-label-edit-btn", "onclick", () => this._editLabel(labelIndex));
        }
    }

    /**
     * Set a label
     */
    async _setLabel(cardId, labelIndex, active) {
        try {
            const response = await this.label.set(cardId, labelIndex, active);
            this._onOpenCardLabelChanged(response);
        } catch (e) {
            showErrorPopup(`Could set label "${labelIndex}" on card with ID "${cardId}": ${e.message}`, 'page-error');
        }
    }

    /**
     * Edit a label
     */
    _editLabel(labelIndex) {
        // hide the label selection dialog
        const labelSelectDialogElem = document.getElementById("opencard-label-select-diag");
        labelSelectDialogElem.classList.add("hidden");

        // create and display the edit dialog
        const labelEditDialogElem = this._loadEditLabelDialog(labelIndex);
        labelSelectDialogElem.insertAdjacentElement("afterend", labelEditDialogElem);
    }

    /**
     * Called when an open card's label is changed.
     * @param response The JSON response.
     * @private
     */
    _onOpenCardLabelChanged(response) {
        const openCardElem = document.getElementById("opencard-" + response["card_id"]);
        this.loadLabelInOpenCard(openCardElem, response["card_id"], response["index"], response["active"]);

        // Reload the card so the labels update in real time.
        this.cardUI.loadCard(response);
    }

    /**
     * Load an edit label dialog
     */
    _loadEditLabelDialog(labelIndex) {
        // create the label edit dialog for the specific label
        const labelEditArgs = [];
        labelEditArgs["index"] = labelIndex;
        labelEditArgs["name"] = this._labelNames[labelIndex];
        labelEditArgs["color"] = this._labelColors[labelIndex];
        const labelEditDialogElem = loadTemplate("tmpl-opencard-label-edit-diag", labelEditArgs);
        const labelEditColorListElem = labelEditDialogElem.querySelector("#opencard-label-edit-color-list");

        // load all color selection tiles
        const labelPreviewElem = labelEditDialogElem.querySelector(".label");
        for (const color of this._allColorNames) {
            const colorTileElem = loadTemplate("tmpl-opencard-label-edit-color-tile", { "color": color });
            labelEditColorListElem.appendChild(colorTileElem);
            colorTileElem.onclick = () => this._editLabelColor(color, labelPreviewElem);
        }

        // events
        setEventBySelector(labelEditDialogElem, "#opencard-label-edit-cancel-btn", "onclick", () => this._cancelEditLabel(labelEditDialogElem));
        setEventBySelector(labelEditDialogElem, "#opencard-label-edit-name", "oninput", (elem, event) => this._editLabelName(event.target.value, labelPreviewElem));
        setEventBySelector(labelEditDialogElem, "#opencard-label-edit-save-btn", "onclick", () => this._editLabelSave(labelIndex, labelPreviewElem.innerText, labelPreviewElem.getAttribute("color")));
        setEventBySelector(labelEditDialogElem, "#opencard-label-edit-delete-btn", "onclick", (elem) => this._deleteLabel(labelIndex, elem));
        return labelEditDialogElem;
    }

    /**
     * Edit a label's color
     */
    _editLabelColor(color, labelPreviewElem) {
        // remove previous color
        for (const color of this._allColorNames) {
            labelPreviewElem.classList.remove(color);
        }
        // add the new color
        labelPreviewElem.classList.add(color);
        labelPreviewElem.setAttribute("color", color);
    }

    /**
     * Cancel label editing
     */
    _cancelEditLabel(labelEditDialogElem) {
        // remove the label edit dialog and show (go back to) the selection
        const labelSelectDialogElem = document.getElementById("opencard-label-select-diag");
        labelSelectDialogElem.classList.remove("hidden");
        labelEditDialogElem.remove();
    }

    /**
     * Edit a label's name
     */
    _editLabelName(newNameStr, labelPreviewElem) {
        labelPreviewElem.textContent = newNameStr;
    }

    /**
     * Save an edited label
     */
    async _editLabelSave(labelIndex, labelName, labelColor) {
        try {
            const response = await this.label.update(labelIndex, labelName, labelColor);
            this._onLabelUpdated(response);
        } catch (e) {
            showErrorPopup(`Could not update label "${labelIndex}": ${e}`, 'page-error');
        }
    }

    /**
     * Delete a label
     */
    async _deleteLabel(labelIndex, buttonElem) {
        const confirmed = buttonElem.getAttribute("confirmed");
        if (confirmed === 0) { // first confirmation
            buttonElem.textContent = "This label will be removed from all cards, are you sure?";
            buttonElem.setAttribute("confirmed", 1);
            return;
        }

        if (confirmed === 1) { // second confirmation
            buttonElem.textContent = "Are you really sure? there is no undo!";
            buttonElem.setAttribute("confirmed", 2);
            return;
        }

        // Ask server to delete the label.
        try {
            const response = await this.label.delete(labelIndex);
            this._onLabelDeleted(response);
        } catch (e) {
            showErrorPopup(`Could not delete label "${labelIndex}": ${e}`, 'page-error');
        }
    }

    /**
     * Called after a label is updated
     */
    _onLabelUpdated(jsonResponseObj) {
        const labelIndex = jsonResponseObj["index"];

        // update local label values
        this._labelNames[labelIndex] = jsonResponseObj["name"];
        this._labelColors[labelIndex] = jsonResponseObj["color"];

        // go back to the label selection
        const labelEditDialogElem = document.getElementById("opencard-label-edit-diag");
        this._cancelEditLabel(labelEditDialogElem);

        // update all occurrences of the label
        const labelElemList = document.querySelectorAll(`.label-${labelIndex}`);
        for (const labelElem of labelElemList) {
            this._editLabelColor(jsonResponseObj["color"], labelElem);
            this._editLabelName(jsonResponseObj["name"], labelElem);
        }
    }

    _onLabelDeleted(jsonResponseObj) {
        const labelIndex = jsonResponseObj["index"];

        // remove local label values
        this._labelNames[labelIndex] = "";
        this._labelColors[labelIndex] = "";

        // go back to the label selection
        const labelEditDialogElem = document.getElementById("opencard-label-edit-diag");
        this._cancelEditLabel(labelEditDialogElem);

        // remove the corresponding selectable label
        const selectableLabelElem = document.querySelector(`.selectable-label.label-${labelIndex}`);
        selectableLabelElem.parentElement.remove();
        // remove all occurrences of the label in cards
        const labelElemList = document.querySelectorAll(`.label-${labelIndex}`);
        for (const labelElem of labelElemList) {
            labelElem.remove();
        }
    }

    /**
     * Open a label selection dialog
     */
    openLabelSelectionDialog() {
        const labelSelectDialog = document.getElementById("opencard-label-select-diag");
        if (labelSelectDialog.classList.contains("hidden")) {
            labelSelectDialog.classList.remove("hidden"); // display the dialog
            const labelEditDialog = document.getElementById("opencard-label-edit-diag");
            if (labelEditDialog) {
                labelEditDialog.remove(); // delete the label edit dialog if open
            }
        } else {
            labelSelectDialog.classList.add("hidden"); // hide the dialog
        }
    }

    /**
     * Close a label selection dialog
     */
    closeLabelSelectionDialog() {
        // hide the dialog
        document.getElementById("opencard-label-select-diag").classList.add("hidden");
    }

    /**
     * Create a label
     */
    async createLabel() {
        try {
            const response = await this.label.create();
            this._onBoardLabelsChanged(response);
        } catch (e) {
            showErrorPopup(`Could not create label: ${e}`, 'page-error');
        }
    }

    /**
     * Called when board labels change
     * @param jsonResponseObj
     * @constructor
     */
    _onBoardLabelsChanged(jsonResponseObj) {
        this._labelNames = jsonResponseObj["label_names"].split(",");
        this._labelColors = jsonResponseObj["label_colors"].split(",");

        // update open card if still open
        const openCardElem = document.querySelector(".opencard");
        if (openCardElem) {
            const cardID = openCardElem.getAttribute("dbid");
            this.loadLabelInOpenCard(openCardElem, cardID, jsonResponseObj["index"], false);
        }
    }
}
