import {ShowErrorPopup, showInfoPopup} from "../core/popup.js";
import {serverAction} from "../core/server.js";
import {BlurOnEnter, FileToBase64, LoadTemplate, SelectFileDialog, SetEventBySelector} from "../core/utils.js";

/**
 * Class to handle attachment operations
 */
export class CardAttachmentUI {

    /**
     * Construction
     */
    constructor() {
        // paste (ctrl+v) event handler
        document.onpaste = (e) => this.UiPaste(e);
    }

    /**
     * Init dependencies
     */
    init({cardUI}) {
        this.cardUI = cardUI;
    }

    /**
     * Load attachments for the open card
     */
    loadOpenCardAttachment(jsonAttachment, parentNode) {
        const attachmentElem = LoadTemplate("tmpl-opencard-attachment", jsonAttachment);
        const url = jsonAttachment["url"];
        const thumbUrl = jsonAttachment["thumbnail"];

        const attachmentLinkElem = attachmentElem.querySelector(".opencard-attachment-link");
        if (url !== undefined || thumbUrl !== undefined) {
            // loaded attachment
            attachmentLinkElem.setAttribute("href", url);
            attachmentElem.querySelector(".loader").remove();
            SetEventBySelector(attachmentElem, ".opencard-attachment-delete-btn", "onclick", () => this._deleteAttachment(jsonAttachment["id"], attachmentElem));
            SetEventBySelector(attachmentElem, ".attachment-name", "onblur", (elem) => this._attachmentNameChanged(elem, jsonAttachment["id"]));
            SetEventBySelector(attachmentElem, ".attachment-name", "onkeydown", (elem, event) => BlurOnEnter(event));

            if (thumbUrl) {
                // prepare attachment with a preview
                attachmentLinkElem.querySelector(".ext").remove();
                attachmentLinkElem.querySelector("svg").remove();
                attachmentLinkElem.querySelector("img").setAttribute("src", thumbUrl);

                // attach event to the copy markup button
                SetEventBySelector(attachmentElem, ".copy-markup-btn", "onclick", () => {
                    const attachmentName = attachmentElem.querySelector(".attachment-name").textContent;
                    const attachmentMarkup = GetImageMarkup(url, attachmentName, attachmentName);
                    navigator.clipboard.writeText(attachmentMarkup);
                    showInfoPopup("Copied!", "page-error");
                });

            } else {
                // prepare attachment with icon and extension
                attachmentLinkElem.querySelector("img").remove();
                // remove copy markup button
                attachmentElem.querySelector(".copy-markup-btn").remove();
            }

        } else {
            // loading or unavailable attachment
            attachmentLinkElem.style.display = "none";
        }

        parentNode.appendChild(attachmentElem);
    }

    /**
     * Delete an attachment
     */
    _deleteAttachment(attachmentID, attachmentNode) {
        let args = [];
        args["id"] = attachmentID;
        serverAction("DeleteAttachment", args, (response) => this._onAttachmentDeleted(response), "page-error");
    }

    /**
     * Called when the attachment name is changed
     */
    _attachmentNameChanged(nameElem, attachmentID) {
        let args = [];
        args["id"] = attachmentID;
        args["name"] = nameElem.textContent;
        serverAction("UpdateAttachmentName", args, (response) => this._onAttachmentUpdated(response), "page-error");
    }

    /**
     * Called when an attachment is deleted
     *
     */
    _onAttachmentDeleted(jsonResponseObj) {
        const attachmentElem = document.getElementById("attachment-" + jsonResponseObj["id"]);
        if (attachmentElem) {
            attachmentElem.remove();
        }
        this.cardUI.onCardUpdated(jsonResponseObj["card"]);
    }

    /**
     * Called when an attachment is updated
     */
    _onAttachmentUpdated(jsonResponseObj) {
        // update the attachment name
        const attachmentElem = document.getElementById("attachment-" + jsonResponseObj["id"]);
        attachmentElem.querySelector(".attachment-name").textContent = jsonResponseObj["name"];
    }

    /**
     * Add an attachment
     * 
     */
    addAttachment(cardID) {
        SelectFileDialog("image/*", true, (files) => {
            for (const file of files) {
                this._onAttachmentSelected(file, cardID);
            }
        });
    }

    /**
     * Called when an attachment is selected
     */
    async _onAttachmentSelected(file, cardID) {
        // upload it to the server
        let args = [];
        args["card_id"] = cardID;
        args["filename"] = file.name;
        args["attachment"] = await FileToBase64(file);
        serverAction("UploadAttachment", args, (response) => this._onAttachmentAdded(response), (msg) => {
            this._removeUiAttachmentPlaceholder();
            ShowErrorPopup(msg, "page-error");
        });
        const attachlistElem = document.querySelector(".opencard-attachlist");
        this.loadOpenCardAttachment({"id":0, "name":"uploading..." } , attachlistElem); // empty loading attachment
    }

    /**
     * Called after an attachment is added
     */
    _onAttachmentAdded(jsonResponseObj) {
        this._removeUiAttachmentPlaceholder();
        const attachlistElem = document.querySelector(".opencard-attachlist");
        this.loadOpenCardAttachment(jsonResponseObj, attachlistElem);
        this.cardUI.onCardUpdated(jsonResponseObj["card"]);
    }

    /**
     * Removes the UI placeholder
     */
    _removeUiAttachmentPlaceholder() {
        const attachlistElem = document.querySelector(".opencard-attachlist");
        if (attachlistElem) {
            attachlistElem.querySelector(".loader").parentElement.remove();
        }
    }

    /**
     * Drag an attachment
     */
    dragAttachmentEnter(event) {
        event.currentTarget.classList.add("drag-target-attachment");
        event.preventDefault();
    }

    /**
     * Stop dragging an attachment
     */
    dragAttachmentLeave(event) {
        // discard leave events if we are just leaving a child
        if (event.currentTarget.contains(event.relatedTarget)) {
            return;
        }

        event.currentTarget.classList.remove("drag-target-attachment");
        event.preventDefault();
    }

    /**
     * Drop an attachment
     */
    dropAttachment(event) {
        event.currentTarget.classList.remove("drag-target-attachment");
        const cardID = event.currentTarget.getAttribute('dbid');

        if (!event.dataTransfer.items) {
            return; // nothing dropped?
        }

        // iterate and upload dropped files
        for (const item of event.dataTransfer.items) {
            // if dropped items aren't files, reject them
            if (item.kind !== "file") {
                continue;
            }
            // upload the file as attachment
            const file = item.getAsFile();
            this._onAttachmentSelected(file, cardID);
        }

        event.preventDefault();
    }

    /**
     * Get an attachment list from a node
     */
    attachmentListFromNode(attachmentListNode) {
        let attachmentList = [];
        for (const attachmentNode of attachmentListNode.querySelectorAll(".opencard-attachment")) {
            const attachmentName = attachmentNode.querySelector(".attachment-name").textContent;
            const attachmentUrl = attachmentNode.querySelector(".opencard-attachment-link").getAttribute("href");
            attachmentList.push({ 'name': attachmentName, 'url': attachmentUrl });
        }
        return attachmentList;
    }

    /**
     * Handle paste attachment
     */
    UiPaste(pasteEvent) {
        const openCardElem = document.querySelector(".opencard"); // search for a destination card
        const tag = pasteEvent.target.nodeName;
        const clipboardItems = (pasteEvent.clipboardData || pasteEvent.originalEvent.clipboardData).items; // retrieve clipboard items

        if (clipboardItems.length < 1) {
            return; // nothing to be pasted
        }

        // for each item in the clipboard
        for (let i = 0; i < clipboardItems.length; i++) {
            const item = clipboardItems[i];

            if (item.kind === 'file' && openCardElem) {
                // pasting a file into an open card: upload as attachment
                const cardID = openCardElem.getAttribute("dbid");
                const clipboardFile = item.getAsFile();
                this._onAttachmentSelected(clipboardFile, cardID);
                pasteEvent.preventDefault();
            } else if (item.type === "text/plain" && window.getSelection().rangeCount && tag !== "INPUT" ) {
                // pasting text into an editable field: insert as text
                item.getAsString((pastedText) => {
                    const selection = window.getSelection();
                    if (selection.rangeCount) {
                        // delete selected
                        selection.deleteFromDocument();
                        const curSelection = selection.getRangeAt(0);

                        // retrieve the html element enclosing this selection
                        let destElem = selection.anchorNode;
                        while (!(destElem instanceof HTMLElement))
                            destElem = destElem.parentElement;

                        // check where the text is pasted
                        if (destElem.closest(".opencard-content")) {
                            // pasting as card content, divide in lines
                            const pastedLines = pastedText.split("\n");
                            for (let li = 0; li < pastedLines.length; li++) {
                                curSelection.insertNode(document.createTextNode(pastedLines[li]));
                                if (li > 0) {
                                    curSelection.insertNode(document.createElement("BR"));
                                }
                                curSelection.setStart(curSelection.endContainer, curSelection.endOffset);
                            }
                        } else {
                            // pasting as simple text otherwise
                            curSelection.insertNode(document.createTextNode(pastedText));
                        }

                        curSelection.setStart(curSelection.endContainer, curSelection.endOffset);
                    }
                });
                pasteEvent.preventDefault();
            }

        }
    }
}
