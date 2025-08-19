import {showErrorPopup, showInfoPopup} from "../core/popup.js";
import {serverAction} from "../core/server.js";
import {
    AddClassToAll,
    BlurOnEnter, CloseDialog,
    GetContentElement,
    LoadTemplate,
    RemoveClassFromAll,
    SetEventBySelector
} from "../core/utils.js";

/**
 * Class to help with card operations
 */
export class CardUI {

    coverImageObserver = null;
    openCardCache = [];

    constructor() {
        // create an observer to lazy-load card cover images
        this.coverImageObserver = new IntersectionObserver((entries, observer) => this._onImgElemVisible(entries, observer));
    }

    /**
     * Init links to other UI objects
     */
    init({attachmentUI, cardDND, labelUI}) {
        this.attachmentUI = attachmentUI;
        this.cardDND = cardDND;
        this.labelUI = labelUI;
    }

    /**
     * Add a new card
     */
    addNewCard(cardlistID, cardlistNode) {
        // clear editing of other cards in other lists
        for (const cardlist of document.querySelectorAll(".cardlist")) {
            if (cardlist.id !== "add-cardlist-btn") {
                this.cancelNewCard(cardlist);
            }
        }

        // disable cardlist dragging to allow card text selection
        cardlistNode.setAttribute("draggable", "false");

        // enable editing of a new card
        AddClassToAll(cardlistNode, ".addcard-ui", "hidden");
        RemoveClassFromAll(cardlistNode, ".editcard-ui", "hidden");
        cardlistNode.querySelector(".editcard-ui[contentEditable]").focus();
    }

    /**
     * Cancel a new card creation
     */
    cancelNewCard(cardlistNode) {
        const editableCard = cardlistNode.querySelector(".editcard-ui[contentEditable]");
        editableCard.innerHTML = "";

        RemoveClassFromAll(cardlistNode, ".addcard-ui", "hidden");
        AddClassToAll(cardlistNode, ".editcard-ui", "hidden");

        // re-enable cardlist dragging
        cardlistNode.setAttribute("draggable", "true");
    }

    /**
     * Create a new card
     */
    newCard(cardlistID, cardlistNode) {
        // prepare new card call args
        let args = [];
        args["title"] = cardlistNode.querySelector(".editcard-ui[contentEditable]").textContent;
        args["cardlist_id"] = cardlistID;

        // disable card editing
        this.cancelNewCard(cardlistNode);

        // submit new card to the server
        serverAction("AddNewCard", args, (response) => this.onCardAdded(response), "page-error");
    }

    /**
     * Called after a card is added
     */
    onCardAdded(jsonResponseObj) {
        // read the card html node
        const newCardNode = this.loadCard(jsonResponseObj);

        // add it to the cardlist node, after the prev card id
        const cardlistNode = document.getElementById("cardlist-" + jsonResponseObj["cardlist_id"]);
        let prevCardNode = null;
        if (jsonResponseObj["prev_card_id"] === 0) {
            prevCardNode = cardlistNode.querySelector(".cardlist-start");
        } else {
            prevCardNode = cardlistNode.querySelector("#card-" + jsonResponseObj["prev_card_id"]);
        }

        prevCardNode.insertAdjacentElement("afterend", newCardNode);
    }

    loadCard(cardData) {
        const newCardElem = LoadTemplate("tmpl-card", cardData);
        const coverImgElem = newCardElem.querySelector("img");

        // display moved date if available
        if (cardData["last_moved_date"] !== undefined) {
            newCardElem.querySelector(".card-moved-date").classList.remove("hidden");
        }

        if (cardData["cover_img_url"]) {
            // set cover image
            coverImgElem.setAttribute("data-src", cardData["cover_img_url"]);
            // add callback for lazy-loading cover image
            if (this.coverImageObserver) {
                this.coverImageObserver.observe(coverImgElem);
            }
        } else {
            // remove cover image
            newCardElem.removeChild(coverImgElem);
        }

        // load labels
        const labelListElem = newCardElem.querySelector(".card-labellist");
        let labelMask = cardData["label_mask"];
        if (labelMask > 0) {
            labelListElem.classList.remove("hidden");

            for (let i = 0; labelMask > 0; i++, labelMask = labelMask >> 1) {
                if (labelMask & 0x01) {
                    const labelAdditionalParams = { "card-id": cardData["id"], "index": i };
                    const labelElem = this.labelUI.loadLabel("tmpl-card-label", i, labelAdditionalParams);
                    labelListElem.appendChild(labelElem);
                }
            }
        }

        // events
        newCardElem.onclick = () => this._openCard(cardData["id"]);
        newCardElem.ondragstart = (e) => this.cardDND.dragCardStart(e);
        newCardElem.ondragenter = (e) => this.cardDND.dragCardEnter(e);
        newCardElem.ondragover = (e) => e.preventDefault();
        newCardElem.ondragleave = (e) => this.cardDND.dragCardLeave(e);
        newCardElem.ondrop = (e) => this.cardDND.dropCard(e);
        newCardElem.ondragend = (e) => this.cardDND.dragCardEnd(e);

        return newCardElem;
    }

    /**
     * Called when the image element is made visible
     */
    _onImgElemVisible(entries, observer) {
        for (const e of entries) {
            if (!e.isIntersecting) {
                continue;
            }

            // trigger img source loading
            const imgElem = e.target;
            imgElem.src = imgElem.dataset.src;
            imgElem.classList.remove("lazy");
            observer.unobserve(imgElem);
        }
    }

    /**
     * Opens a card
     */
    _openCard(cardID) {

        if (!navigator.onLine) {
            // offline, read from cache if available
            if (this.openCardCache[cardID] !== undefined) {
                this.loadOpenCard(this.openCardCache[cardID]);
                showErrorPopup("No connection, card displayed from cache!", "page-error");
            } else {
                showErrorPopup("No connection!", "page-error");
            }
            return;
        }

        serverAction(
            "OpenCard",
            { "id": cardID },
            (response) => this.loadOpenCard(response),
            "page-error");
    }

    /**
     * Load an open card
     */
    loadOpenCard(jsonResponseObj) {
        // save to cache
        this.openCardCache[jsonResponseObj["id"]] = jsonResponseObj;

        // create card element
        const openCardData = Object.assign({}, jsonResponseObj);
        openCardData["content"] = ContentMarkupToHtml(jsonResponseObj["content"], jsonResponseObj["attachmentList"]); // decode content
        const openCardElem = LoadTemplate("tmpl-opencard", openCardData);

        // load labels
        let labelMask = jsonResponseObj["label_mask"];
        for (let i = 0; i < this.labelUI.getLabelNames().length; i++, labelMask = labelMask >> 1) {
            if (this.labelUI.getLabelNames()[i].length === 0) {
                continue; // this label has been deleted
            }
            this.labelUI.loadLabelInOpenCard(openCardElem, jsonResponseObj["id"], i, labelMask & 0x01);
        }

        if (jsonResponseObj["attachmentList"] !== undefined) {
            // create attachments and add them to the card
            const attachList = jsonResponseObj["attachmentList"];
            const attachlistElem = openCardElem.querySelector(".opencard-attachlist");
            for (let i = 0; i < attachList.length; i++) {
                this.attachmentUI.loadOpenCardAttachment(attachList[i], attachlistElem);
            }
        }

        // locked status
        if (jsonResponseObj["locked"]) {
            this._toggleOpenCardLock(openCardElem);
        }

        // events
        SetEventBySelector(openCardElem, ".dialog-close-btn", "onclick", () => CloseDialog);
        SetEventBySelector(openCardElem, "#opencard-title", "onblur", (elem) => this._cardTitleChanged(elem, openCardData["id"]));
        SetEventBySelector(openCardElem, "#opencard-title", "onkeydown", (elem, event) => BlurOnEnter(event));
        SetEventBySelector(openCardElem, ".opencard-add-label", "onclick", () => this.labelUI.openLabelSelectionDialog());
        SetEventBySelector(openCardElem, ".opencard-label-cancel-btn", "onclick", () => this.labelUI.closeLabelSelectionDialog());
        SetEventBySelector(openCardElem, ".opencard-label-create-btn", "onclick", () => this.labelUI.createLabel());
        SetEventBySelector(openCardElem, ".opencard-content", "onfocus", (elem) => this._cardContentEditing(elem));
        SetEventBySelector(openCardElem, ".opencard-content", "onblur", (elem) => this._cardContentChanged(elem, openCardData["id"]));
        SetEventBySelector(openCardElem, ".add-attachment-btn", "onclick", () => this.attachmentUI.addAttachment(openCardData["id"]));
        SetEventBySelector(openCardElem, ".opencard-lock-btn", "onclick", (elem) => this._cardContentLock(elem, openCardElem));
        this._setCardContentEventHandlers(openCardElem.querySelector(".opencard-content"));

        // drag drop files over a card events
        const cardElem = openCardElem.querySelector(".opencard");
        cardElem.ondragover = (e) => this.cardDND.dragOverAttachment(e);
        cardElem.ondragenter = (e) => this.cardDND.dragAttachmentEnter(e);
        cardElem.ondragleave = (e) => this.cardDND.dragAttachmentLeave(e);
        cardElem.ondrop = (e) => this.cardDND.dropAttachment(e);

        // append the open card to the page
        const contentElem = GetContentElement();
        contentElem.appendChild(openCardElem);
    }

    /**
     * Called when a card is updated
     */
    onCardUpdated(jsonResponseObj) {
        const cardTileElement = document.getElementById("card-" + jsonResponseObj["id"]);
        cardTileElement.remove(); // remove old version
        this.onCardAdded(jsonResponseObj); // add back the new version
    }

    /**
     * Toggles the open card lock
     */
    _toggleOpenCardLock(opencardElem) {
        const contentElem = opencardElem.querySelector(".opencard-content");
        const btnElem = opencardElem.querySelector(".opencard-lock-btn");

        if (btnElem.classList.contains("locked")) {
            // unlock the card content
            btnElem.querySelector("use").setAttribute("href", "#icon-unlocked");
            btnElem.classList.remove("locked");
            contentElem.setAttribute("contenteditable", "true");
        } else {
            // lock the card content
            btnElem.querySelector("use").setAttribute("href", "#icon-locked");
            btnElem.classList.add("locked");
            contentElem.setAttribute("contenteditable", "false");
        }
    }

    /**
     * Called when a card title is changed
     */
    _cardTitleChanged(titleElement, cardID) {
        const newTitle = titleElement.textContent;

        if (this.openCardCache[cardID] !== undefined) {
            if (this.openCardCache[cardID]["title"] === newTitle) {
                return; // skip server update if the title didn't actually change
            } else {
                this.openCardCache[cardID]["title"] = newTitle; // update cache
            }
        }

        let args = [];
        args["id"] = titleElement.closest(".opencard").getAttribute("dbid");
        args["title"] = titleElement.textContent;
        serverAction("UpdateCardTitle", args, (response) => this.onCardUpdated(response), "page-error");
    }

    /**
     * Start editing card content
     */
    _cardContentEditing(contentElement) {
        // save checkbox values so they can be converted to markup
        this._saveCheckboxValuesToDOM(contentElement);
        // change content to an editable version of the markup language while editing
        contentElement.innerHTML = ContentHtmlToMarkupEditing(contentElement.innerHTML);
        window.getSelection().removeAllRanges();
    }

    /**
     * Called when card content changes
     */
    _cardContentChanged(contentElement, cardID) {
        const cardElement = contentElement.closest(".opencard");

        // re-convert possible html markup generate by content editing (and <br> added for easier markup editing)
        const contentMarkup = ContentHtmlToMarkup(contentElement.innerHTML);

        // update content area html
        const attachmentList = this.attachmentUI.attachmentListFromNode(cardElement.querySelector(".opencard-attachlist"));
        contentElement.innerHTML = ContentMarkupToHtml(contentMarkup, attachmentList);
        this._setCardContentEventHandlers(contentElement);
        window.getSelection().removeAllRanges();

        if (this.openCardCache[cardID] !== undefined) {
            if (this.openCardCache[cardID]["content"] === contentMarkup) {
                return; // skip server update if the content didn't actually change
            } else {
                this.openCardCache[cardID]["content"] = contentMarkup; // update cache
            }
        }

        // post the update to the server
        let args = [];
        args["id"] = cardElement.getAttribute("dbid");
        args["content"] = contentMarkup;
        serverAction("UpdateCardContent", args, (response) => this.onCardUpdated(response), "page-error");
    }

    /**
     * Lock card content
     */
    _cardContentLock(btnElem, opencardElem) {
        this._toggleOpenCardLock(opencardElem);

        // update locked status on the server
        let args = [];
        args["id"] = btnElem.closest(".opencard").getAttribute("dbid");
        args["locked"] = btnElem.classList.contains("locked");
        serverAction("UpdateCardFlags", args, (response) => this.onCardUpdated(response), "page-error");
    }

    /**
     * Set event handlers for card content
     */
    _setCardContentEventHandlers(contentElem) {
        // checkboxes: their state is immediately committed to the server
        for (const checkboxElem of contentElem.querySelectorAll("input[type=checkbox]")) {
            checkboxElem.onchange = () => this._cardCheckboxChanged(checkboxElem, contentElem);
        }

        // copy to clipboard buttons
        for (const copyBtnElem of contentElem.querySelectorAll(".copy-btn")) {
            copyBtnElem.onmousedown = (event) => {
                this._cardContentToClipboard(copyBtnElem.parentElement.querySelector(".monospace"));
                event.preventDefault();
            }
        }
    }

    /**
     * Search the content node for checkboxes, and copy the checked property to the checked attribute,
     * to save their current state into the DOM.
     */
    _saveCheckboxValuesToDOM(contentElem) {
        for (const checkboxElem of contentElem.querySelectorAll("input[type=checkbox]")) {
            if (checkboxElem.checked) {
                checkboxElem.setAttribute("checked", "checked");
            } else {
                checkboxElem.removeAttribute("checked");
            }
        }
    }

    /**
     * Called when a card checkbox changes
     */
    _cardCheckboxChanged(checkboxElem, contentElement) {
        // save checkbox values so they can be converted to markup
        this._saveCheckboxValuesToDOM(contentElement);
        // convert card html content to markup
        const contentMarkup = ContentHtmlToMarkup(contentElement.innerHTML);
        // post the update to the server
        let args = [];
        args["id"] = contentElement.closest(".opencard").getAttribute("dbid");
        args["content"] = contentMarkup;
        serverAction("UpdateCardContent", args, (response) => this.onCardUpdated(response), "page-error");
    }

    /**
     * Copy card content to clipboard
     */
    _cardContentToClipboard(contentElem) {
        // save the content of the specified div to clipboard
        const contentMarkup = ContentHtmlToMarkup(contentElem.innerHTML);
        const textContent = DecodeHTMLEntities(contentMarkup);
        navigator.clipboard.writeText(textContent);
        showInfoPopup("Copied!", "page-error");
    }
}
