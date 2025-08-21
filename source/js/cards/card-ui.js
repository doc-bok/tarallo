import {showErrorPopup, showInfoPopup} from "../ui/popup.js";
import {
    blurOnEnter,
    closeDialog,
    loadTemplate, selectAllInnerText,
    setEventBySelector,
    setOnClickEventBySelector,
    setOnEnterEventBySelector
} from "../core/utils.js";
import {Card} from "./card.js";

/**
 * Class to help with card operations
 */
export class CardUI {

    coverImageObserver = null;
    openCardCache = {};

    /**
     * Constructor - Create an observer to lazy-load card cover images.
     */
    constructor() {
        this.coverImageObserver = new IntersectionObserver((entries, observer) => this._onImgElemVisible(entries, observer));
    }

    /**
     * Init links to other UI objects.
     * @param attachmentUI The Attachment UI.
     * @param cardDnd The card drag-n-drop interface.
     * @param labelUI The label UI.
     * @param page The page API.
     */
    init({attachmentUI, cardDnd, labelUI, page}) {
        this.attachmentUI = attachmentUI;
        this.card = new Card();
        this.cardDnd = cardDnd;
        this.labelUI = labelUI;
        this.page = page;
    }

    /**
     * Set up card events for a card list.
     * @param cardListId The ID of the card list.
     * @param cardListElem The card list element to add events to.
     */
    setupEvents(cardListId, cardListElem) {
        setOnClickEventBySelector(
            cardListElem,
            '.addcard-btn',
            () => this._beginAddCard(cardListElem));

        setOnClickEventBySelector(
            cardListElem,
            '.editcard-submit-btn',
            () => this._submitAddCard(cardListId, cardListElem));

        setOnEnterEventBySelector(
            cardListElem,
            '.editcard-card',
            () => this._submitAddCard(cardListId, cardListElem));

        setOnClickEventBySelector(
            cardListElem,
            ".editcard-cancel-btn",
            () => this.endAddCard(cardListElem));
    }

    /**
     * Add a new card.
     * @param cardListElem The card list element.
     * @private
     */
    _beginAddCard(cardListElem) {
        // Clear editing of other cards in other lists.
        for (const cardlist of document.querySelectorAll(".cardlist")) {
            if (cardlist.id !== "add-cardlist-btn") {
                this.endAddCard(cardlist);
            }
        }

        // disable cardlist dragging to allow card text selection
        cardListElem.setAttribute("draggable", "false");

        // enable editing of a new card
        this._addClassToAll(cardListElem, ".addcard-ui", "hidden");
        this._removeClassFromAll(cardListElem, ".editcard-ui", "hidden");

        const editable = cardListElem.querySelector(".editcard-ui[contentEditable]");
        if (editable) {
            // Defer focus to ensure DOM updates
            requestAnimationFrame(() => editable.focus());
        }
    }

    /**
     * Hide the edit card UI.
     * @param cardListElem The card list element.
     */
    endAddCard(cardListElem) {
        const editableCard = cardListElem.querySelector(".editcard-ui[contentEditable]");
        if (editableCard) {
            editableCard.innerHTML = "";
        }

        // Disable card editing interface
        this._removeClassFromAll(cardListElem, ".addcard-ui", "hidden");
        this._addClassToAll(cardListElem, ".editcard-ui", "hidden");

        // re-enable cardlist dragging
        cardListElem.setAttribute("draggable", "true");
    }

    /**
     * Create a new card.
     * @param cardListId The ID of the card list to add the card to.
     * @param cardListElem The card list element.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _submitAddCard(cardListId, cardListElem) {
        const editable = cardListElem.querySelector(".editcard-ui[contentEditable]");
        const title = editable ? editable.textContent.trim() : "";
        if (!title) {
            showErrorPopup(`Card title cannot be empty`, 'page-error');
            return;
        }

        try {
            const response = await this.card.create(cardListId, title);
            this.onCardAdded(response);
        } catch (e) {
            showErrorPopup(`Could not create card "${title}": ${e.message}`, 'page-error');
        } finally {
            this.endAddCard(cardListElem);
        }
    }

    /**
     * Called after a card is added.
     * @param response The JSON response object.
     */
    onCardAdded(response) {
        // Read the card html node.
        const newCardNode = this.loadCard(response);

        // Add it to the cardlist node, after the prev card id.
        const cardlistNode = document.getElementById(`cardlist-${response.cardlist_id}`);
        if (!cardlistNode){
            showErrorPopup(`Couldn't find Card List with ID "${response.cardlist_id}`, 'page-error');
            return;
        }

        const prevCardNode = response.prev_card_id === 0
            ? cardlistNode.querySelector(".cardlist-start")
            : cardlistNode.querySelector("#card-" + response["prev_card_id"]);

        if (!prevCardNode){
            showErrorPopup(`Couldn't find Previous Card in list with ID "${response.prev_card_id}`, 'page-error');
            return;
        }

        prevCardNode.insertAdjacentElement("afterend", newCardNode);
    }

    /**
     * Load a card from the card data.
     * @param cardData The Card data.
     * @returns {*} The new card element.
     */
    loadCard(cardData) {
        const newCardElem = loadTemplate("tmpl-card", cardData);
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
        newCardElem.ondragstart = (e) => this.cardDnd.start(e);
        newCardElem.ondragenter = (e) => this.cardDnd.enter(e);
        newCardElem.ondragover = (e) => e.preventDefault();
        newCardElem.ondragleave = (e) => this.cardDnd.leave(e);
        newCardElem.ondrop = (e) => this.cardDnd.dropMove(e);
        newCardElem.ondragend = () => this.cardDnd.end();

        return newCardElem;
    }

    /**
     * Called when the image element is made visible.
     * @param entries The list of entries.
     * @param observer The observers.
     * @private
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
     * @param id The ID of the card.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _openCard(id) {

        if (!navigator.onLine) {
            // offline, read from cache if available
            if (this.openCardCache[id] !== undefined) {
                await this._loadOpenCard(this.openCardCache[id]);
                showErrorPopup("No connection, card displayed from cache!", "page-error");
            } else {
                showErrorPopup("No connection!", "page-error");
            }
            return;
        }

        try {
            const response = await this.card.open(id);
            await this._loadOpenCard(response);
        } catch (e) {
            showErrorPopup(`Could not open card with id ${id}: ${e.message}`, 'page-error');
        }
    }

    /**
     * Load an open card.
     * @param response The JSON response data.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _loadOpenCard(response) {
        // save to cache
        this.openCardCache[response["id"]] = response;

        // create card element
        const openCardData = Object.assign({}, response);
        openCardData["content"] = ContentMarkupToHtml(response["content"], response["attachmentList"]); // decode content
        const openCardElem = loadTemplate("tmpl-opencard", openCardData);

        // load labels
        let labelMask = response["label_mask"];
        for (let i = 0; i < this.labelUI.getLabelNames().length; i++, labelMask = labelMask >> 1) {
            if (this.labelUI.getLabelNames()[i].length === 0) {
                continue; // this label has been deleted
            }
            this.labelUI.loadLabelInOpenCard(openCardElem, response["id"], i, labelMask & 0x01);
        }

        if (response["attachmentList"] !== undefined) {
            // create attachments and add them to the card
            const attachList = response["attachmentList"];
            const attachlistElem = openCardElem.querySelector(".opencard-attachlist");
            for (let i = 0; i < attachList.length; i++) {
                this.attachmentUI.loadOpenCardAttachment(attachList[i], attachlistElem);
            }
        }

        // locked status
        if (response["locked"]) {
            this._toggleOpenCardLock(openCardElem);
        }

        // events
        setOnClickEventBySelector(
            openCardElem,
            ".dialog-close-btn",
            () => closeDialog('card-dialog-container'));

        setOnClickEventBySelector(
            openCardElem,
            '#opencard-title',
            () => selectAllInnerText('opencard-title')
        )

        setEventBySelector(
            openCardElem,
            "#opencard-title",
            "onblur",
            (elem) => this._cardTitleChanged(elem, openCardData["id"]));

        setEventBySelector(
            openCardElem,
            "#opencard-title",
            "onkeydown",
            (elem, event) => blurOnEnter(event));

        setOnClickEventBySelector(
            openCardElem,
            ".opencard-add-label",
            () => this.labelUI.openLabelSelectionDialog());

        setOnClickEventBySelector(
            openCardElem,
            ".opencard-label-cancel-btn",
            () => this.labelUI.closeLabelSelectionDialog());

        setOnClickEventBySelector(
            openCardElem,
            ".opencard-label-create-btn",
            () => this.labelUI.createLabel());

        setEventBySelector(
            openCardElem,
            ".opencard-content",
            "onfocus",
            (elem) => this._cardContentEditing(elem));

        setEventBySelector(
            openCardElem,
            ".opencard-content",
            "onblur",
            (elem) => this._cardContentChanged(elem, openCardData["id"]));

        setOnClickEventBySelector(
            openCardElem,
            ".add-attachment-btn",
            () => this.attachmentUI.addAttachment(openCardData["id"]));

        setOnClickEventBySelector(
            openCardElem,
            ".opencard-lock-btn",
            (elem) => this._cardContentLock(elem, openCardElem));

        await this._setCardContentEventHandlers(openCardElem.querySelector(".opencard-content"));

        // drag drop files over a card events
        const cardElem = openCardElem.querySelector(".opencard");
        cardElem.ondragover = (e) => this.cardDnd.dragOverAttachment(e);
        cardElem.ondragenter = (e) => this.cardDnd.dragAttachmentEnter(e);
        cardElem.ondragleave = (e) => this.cardDnd.dragAttachmentLeave(e);
        cardElem.ondrop = (e) => this.cardDnd.dropAttachment(e);

        // append the open card to the page
        const contentElem = this.page.getContentElem();
        contentElem.appendChild(openCardElem);
    }

    /**
     * Called when a card is updated.
     * @param response The JSON response data.
     */
    onCardUpdated(response) {
        const cardTileElement = document.getElementById("card-" + response.id);
        if (cardTileElement) {
            cardTileElement.remove(); // remove old version
        }

        this.onCardAdded(response); // add back the new version
    }

    /**
     * Toggles the open card lock.
     * @param openCardElem The open card element.
     * @private
     */
    _toggleOpenCardLock(openCardElem) {
        const contentElem = openCardElem.querySelector(".opencard-content");
        const btnElem = openCardElem.querySelector(".opencard-lock-btn");

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
     * Called when a card title is changed.
     * @param titleElement The card's title element.
     * @param id The ID of the card.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _cardTitleChanged(titleElement, id) {
        const newTitle = titleElement.textContent;

        if (this.openCardCache[id] !== undefined) {
            if (this.openCardCache[id]["title"] === newTitle) {
                return; // skip server update if the title didn't actually change
            } else {
                this.openCardCache[id]["title"] = newTitle; // update cache
            }
        }

        try {
            const response = await this.card.updateTitle(id, titleElement.textContent);
            this.onCardUpdated(response);
        } catch (e) {
            showErrorPopup(`Could not update card title "${titleElement.textContent}" with ID "${id}: ${e.message}`, 'page-error')
        }
    }

    /**
     * Start editing card content.
     * @param contentElem The card's content element.
     * @private
     */
    _cardContentEditing(contentElem) {
        // save checkbox values so they can be converted to markup
        this._saveCheckboxValuesToDOM(contentElem);
        // change content to an editable version of the markup language while editing
        contentElem.innerHTML = ContentHtmlToMarkupEditing(contentElem.innerHTML);
        window.getSelection().removeAllRanges();
    }

    /**
     * Called when card content changes.
     * @param contentElem The card's content element.
     * @param id The ID of the card.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _cardContentChanged(contentElem, id) {
        const cardElement = contentElem.closest(".opencard");

        // re-convert possible html markup generate by content editing (and <br> added for easier markup editing)
        const content = contentHtmlToMarkup(contentElem.innerHTML);

        // update content area html
        const attachmentList = this.attachmentUI.attachmentListFromNode(cardElement.querySelector(".opencard-attachlist"));
        contentElem.innerHTML = ContentMarkupToHtml(content, attachmentList);
        await this._setCardContentEventHandlers(contentElem);
        window.getSelection().removeAllRanges();

        if (this.openCardCache[id] !== undefined) {
            if (this.openCardCache[id]["content"] === content) {
                return; // skip server update if the content didn't actually change
            } else {
                this.openCardCache[id]["content"] = content; // update cache
            }
        }

        // Post the update to the server
        await this._updateCardContent(cardElement.getAttribute("dbid"), content);
    }

    /**
     * Lock card content.
     * @param buttonElem The lock button element.
     * @param openCardElem The open card element.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _cardContentLock(buttonElem, openCardElem) {
        this._toggleOpenCardLock(openCardElem);

        // Update locked status on the server.
        const id = buttonElem.closest(".opencard").getAttribute("dbid");
        const locked = buttonElem.classList.contains("locked");
        try {
            const response = await this.card.updateFlags(id, locked);
            this.onCardUpdated(response);
        } catch (e) {
            showErrorPopup(`Could not update card locked with ID "${id}": ${e.message}`, 'page-error');
        }
    }

    /**
     * Set event handlers for card content.
     * @param contentElem The card's content element.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _setCardContentEventHandlers(contentElem) {
        // checkboxes: their state is immediately committed to the server
        for (const checkboxElem of contentElem.querySelectorAll("input[type=checkbox]")) {
            checkboxElem.onchange = () => this._cardCheckboxChanged(checkboxElem, contentElem);
        }

        // copy to clipboard buttons
        for (const copyBtnElem of contentElem.querySelectorAll(".copy-btn")) {
            copyBtnElem.onmousedown = async (event) => {
                await this._cardContentToClipboard(copyBtnElem.parentElement.querySelector(".monospace"));
                event.preventDefault();
            }
        }
    }

    /**
     * Search the content node for checkboxes, and copy the checked property to the checked attribute,
     * to save their current state into the DOM.
     * @param contentElem The card's content element.
     * @private
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
     * @param checkboxElem The card's checkbox element.
     * @param contentElem The card's content element.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _cardCheckboxChanged(checkboxElem, contentElem) {

        // Save checkbox values so they can be converted to markup
        this._saveCheckboxValuesToDOM(contentElem);

        // Convert card html content to markup
        const id = contentElem.closest(".opencard").getAttribute("dbid");
        const content = contentHtmlToMarkup(contentElem.innerHTML);
        await this._updateCardContent(id, content);
    }

    /**
     * Copy card content to clipboard.
     * @param contentElem The card's content element.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _cardContentToClipboard(contentElem) {
        // save the content of the specified div to clipboard
        const contentMarkup = contentHtmlToMarkup(contentElem.innerHTML);
        const textContent = DecodeHTMLEntities(contentMarkup);
        await navigator.clipboard.writeText(textContent);
        showInfoPopup("Copied!", "page-error");
    }

    /**
     * Update a card's content.
     * @param id The ID of the card.
     * @param content The card's new content.
     * @returns {Promise<void>} Updated when the operation completes.
     * @private
     */
    async _updateCardContent(id, content) {
        try {
            const response = await this.card.updateContent(id, content);
            this.onCardUpdated(response);
        } catch (e) {
            showErrorPopup(`Could not update card content with ID "${id}": ${e.message}`, 'page-error')
        }
    }

    /**
     * Delete a card.
     * @param id The ID of the card.
     * @returns {Promise<void>} Updated when the operation completes.
     */
    async deleteCard(id) {
        try {
            await this.card.delete(id);
            this.cardDnd.onCardDeleted();
        } catch (e) {
            showErrorPopup(`Could not delete card with ID "${id}": ${e.message}`, 'page-error')
        }
    }

    /**
     * Move a card.
     * @param movedCardId The ID of the card to move.
     * @param newPrevCardId The ID of the new previous card in the linked list.
     * @param destinationCardListId The ID of the destination card list.
     * @returns {Promise<void>} Updated when the operation completes.
     */
    async moveCard(movedCardId, newPrevCardId, destinationCardListId) {
        try {
            const response = await this.card.move(movedCardId, newPrevCardId, destinationCardListId);
            this.cardDnd.onCardMoved();
            this.onCardAdded(response); // add back in the new position
        } catch (e) {
            showErrorPopup(`Could not move card with ID "${movedCardId}": ${e.message}`, 'page-error')
        }
    }

    /**
     * Add a class to all nodes.
     * @param parentNode The parent node.
     * @param cssSelector The selector used to find the node.
     * @param className The class name to add.
     * @private
     */
    _addClassToAll(parentNode, cssSelector, className) {
        const nodes = parentNode.querySelectorAll(cssSelector);
        for (let i = 0; i < nodes.length; i++) {
            nodes[i].classList.add(className);
        }
    }

    /**
     * Remove a class from all nodes.
     * @param parentNode The parent node.
     * @param cssSelector The selector used to find the node.
     * @param className The class name to remove.
     * @private
     */
    _removeClassFromAll(parentNode, cssSelector, className) {
        const nodes = parentNode.querySelectorAll(cssSelector);
        for (let i = 0; i < nodes.length; i++) {
            nodes[i].classList.remove(className);
        }
    }
}
