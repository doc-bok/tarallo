import {showErrorPopup, showInfoPopup} from "../ui/popup.js";
import {
    blurOnEnter,
    fileToBase64,
    loadTemplate, selectAllInnerText,
    selectFileDialog,
    setEventBySelector,
    setOnClickEventBySelector
} from "../core/utils.js";
import {Attachment} from "./attachment.js";

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
        this.attachment = new Attachment();
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
    loadOpenCardAttachment(response, parentNode) {
        const attachmentElem = loadTemplate("tmpl-opencard-attachment", response);
        const url = response.url;
        const thumbUrl = response.thumbnail;

        const attachmentLinkElem = attachmentElem.querySelector(".opencard-attachment-link");
        if (attachmentLinkElem) {
            if (url !== undefined || thumbUrl !== undefined) {
                // loaded attachment
                attachmentLinkElem.setAttribute("href", url);
                attachmentElem.querySelector(".loader").remove();

                // Events
                setOnClickEventBySelector(
                    attachmentElem,
                    ".opencard-attachment-delete-btn",
                    () => this._deleteAttachment(response.id));

                setEventBySelector(
                    attachmentElem,
                    ".attachment-name",
                    "onblur",
                    (elem) => this._attachmentNameChanged(elem, response.id));

                setEventBySelector(
                    attachmentElem,
                    ".attachment-name",
                    "onkeydown",
                    (elem, event) => blurOnEnter(event));

                setOnClickEventBySelector(
                    attachmentElem,
                    '.attachment-name',
                    () => selectAllInnerText(`attachment-${response.id}-title`)
                )

                if (thumbUrl) {
                    // prepare attachment with a preview
                    attachmentLinkElem.querySelector(".ext").remove();
                    attachmentLinkElem.querySelector("svg").remove();
                    attachmentLinkElem.querySelector("img").setAttribute("src", thumbUrl);

                    // attach event to the copy markup button
                    setEventBySelector(attachmentElem, ".copy-markup-btn", "onclick", async () => {
                        const attachmentName = attachmentElem.querySelector(".attachment-name").textContent;
                        const attachmentMarkup = GetImageMarkup(url, attachmentName, attachmentName);
                        await navigator.clipboard.writeText(attachmentMarkup);
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
        }

        parentNode.appendChild(attachmentElem);
    }

    /**
     * Delete an attachment
     */
    async _deleteAttachment(id) {
        try {
            const response = await this.attachment.delete(id);
            this._onAttachmentDeleted(response);
        } catch (e) {
            showErrorPopup(`Could not delete attachment with ID "${id}: ${e.message}`, 'page-error');
        }
    }

    /**
     * Called when the attachment name is changed
     */
    async _attachmentNameChanged(nameElem, id) {
        try {
            const response = await this.attachment.updateName(id, nameElem.textContent);
            this._onAttachmentUpdated(response);
        } catch (e) {
            showErrorPopup(`Could not update attachment name with ID "${id}": ${e.message}`, 'page-error');
        }
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
    _onAttachmentUpdated(response) {
        // update the attachment name
        const attachmentElem = document.getElementById("attachment-" + response.id);
        if (attachmentElem) {
            attachmentElem.querySelector(".attachment-name").textContent = response.name;
        }
    }

    /**
     * Add an attachment
     * 
     */
    async addAttachment(cardID) {
        await selectFileDialog("image/*", true, (files) => {
            for (const file of files) {
                this._onAttachmentSelected(file, cardID);
            }
        });
    }

    /**
     * Called when an attachment is selected
     */
    async _onAttachmentSelected(file, cardId) {
        const attachlistElem = document.querySelector(".opencard-attachlist");
        if (attachlistElem) {
            await this.loadOpenCardAttachment({"id": 0, "name": "uploading..."}, attachlistElem); // empty loading attachment
        }

        // Upload it to the server.
        try {
            const response = await this.attachment.upload(cardId, file.name,await fileToBase64(file));
            await this._onAttachmentAdded(response);
        } catch (e) {
            this._removeUiAttachmentPlaceholder();
            showErrorPopup(`Could not upload attachment to card with ID "${cardId}": ${e.message}`, 'page-error');
        }


    }

    /**
     * Called after an attachment is added
     */
    async _onAttachmentAdded(response) {
        this._removeUiAttachmentPlaceholder();
        const attachlistElem = document.querySelector(".opencard-attachlist");
        if (attachlistElem) {
            await this.loadOpenCardAttachment(response, attachlistElem);
        }

        this.cardUI.onCardUpdated(response.card);
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
    async dropAttachment(event) {
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
            await this._onAttachmentSelected(file, cardID);
        }

        event.preventDefault();
    }

    /**
     * Get an attachment list from a node
     */
    attachmentListFromNode(attachmentListNode) {
        const attachmentList = [];
        for (const attachmentNode of attachmentListNode.querySelectorAll(".opencard-attachment")) {
            const attachmentName = attachmentNode.querySelector(".attachment-name").textContent;
            const attachmentUrl = attachmentNode.querySelector(".opencard-attachment-link").getAttribute("href");
            if (attachmentName && attachmentUrl) {
                attachmentList.push({'name': attachmentName, 'url': attachmentUrl});
            }
        }
        return attachmentList;
    }

    /**
     * Handle paste attachment
     */
    async UiPaste(pasteEvent) {
        const openCardElem = document.querySelector(".opencard"); // search for a destination card
        if (openCardElem) {
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
                    await this._onAttachmentSelected(clipboardFile, cardID);
                    pasteEvent.preventDefault();
                } else if (item.type === "text/plain" && window.getSelection().rangeCount && tag !== "INPUT") {
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
}
