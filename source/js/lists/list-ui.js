import {blurOnEnter, loadTemplate, selectAllInnerText, setEventBySelector} from "../core/utils.js";
import {showErrorPopup} from "../ui/popup.js";
import {List} from "./list.js";
import {ListDnd} from "./list-dnd.js";

export class ListUI {

    /**
     * Initialise the Card List UI
     * @param cardDnd The drag-n-drop interface for cards.
     * @param cardUI The card UI.
     * @param page The page API.
     */
    init({cardDnd, cardUI, page}) {
        this.cardDnd = cardDnd;
        this.cardUI = cardUI;
        this.list = new List();
        this.listDnd = new ListDnd(this, page)
        this.page = page;
    }

    /**
     * Load a card list.
     * @param id The ID of the card list.
     * @param name The name of the card list.
     * @returns {*} The card list element.
     */
    loadCardList({id, name}) {
        const cardListElem = loadTemplate("tmpl-cardlist", {id, name});
        if (!cardListElem) {
            throw new Error(`Failed to load card list template with ID "${id}"`);
        }

        // events
        setEventBySelector(
            cardListElem,
            ".cardlist-title h3",
            "onfocus",
            () => this._nameEditStart(cardListElem));

        setEventBySelector(
            cardListElem,
            ".cardlist-title h3",
            "onclick",
            () => selectAllInnerText(`card-list-title-${id}`));

        const nameChangedHandler = (elem) => this._nameChanged(id, elem, cardListElem);
        setEventBySelector(
            cardListElem,
            ".cardlist-title h3",
            "onblur",
            nameChangedHandler);

        setEventBySelector(
            cardListElem,
            ".cardlist-title h3",
            "onkeydown",
            (elem, event) => blurOnEnter(event));

        this.cardUI.setupEvents(id, cardListElem);

        // drag and drop events
        const cardlistStartElem = cardListElem.querySelector(".cardlist-start");
        cardlistStartElem.ondragover = (e) => e.preventDefault();
        cardlistStartElem.ondragenter = (e) => this.cardDnd.enter(e);
        cardlistStartElem.ondragleave = (e) => this.cardDnd.leave(e);
        cardlistStartElem.ondrop = (e) => this.cardDnd.dropMove(e);

        // events
        cardListElem.ondragstart = (e) => this.listDnd.start(e);
        cardListElem.ondragenter = (e) => this.listDnd.enter(e);
        cardListElem.ondragover = (e) => e.preventDefault();
        cardListElem.ondragleave = (e) => this.listDnd.leave(e);
        cardListElem.ondrop = async (e) => await this.listDnd.dropMove(e);

        cardListElem.ondragend = () => this.listDnd.end();

        return cardListElem;
    }

    /**
     * Add a card list.
     * @returns {Promise<void>} Updated when operation completes.
     */
    async addCardList() {
        const prevListId = this.page.getAddCardListButtonElem().previousElementSibling?.getAttribute("dbid") || 0;

        try {
            const response = await this.list.create('New List', prevListId);
            this._onCardListAdded(response);
        } catch (e) {
            showErrorPopup(`Could not add card list: ${e.message}`, 'page-error');
        }
    }

    /**
     * Drop a card list into a new place.
     * @param movedCardListId The ID of the card list to move.
     * @param newPrevCardListId The index of the previous card list on the
     *                          linked list.
     * @returns {Promise<void>} Updated when operation completes.
     */
    async moveCardList(movedCardListId, newPrevCardListId) {
        try {
            const response = await this.list.move(movedCardListId, newPrevCardListId);
            this.listDnd.onCardListMoved(response);
        } catch (e) {
            showErrorPopup(`Could not move card list: ${e.message}`, 'page-error');
        }
    }

    /**
     * Delete a card list.
     * @param id The ID of the card list.
     * @param cardListElem The card list element.
     * @returns {Promise<void>} Updated when operation completes.
     */
    async deleteCardList(id, cardListElem) {
        if (cardListElem.classList.contains("waiting-deletion")) {
            return; // avoid being triggered again while deleting (can happen on mobile)
        }

        cardListElem.classList.add('waiting-deletion');

        try {
            const response = await this.list.delete(id);
            this._onCardListDeleted(response);
        } catch (e) {
            cardListElem.classList.remove('waiting-deletion');
            showErrorPopup(`Could not delete card list: ${e.message}`, 'page-error');
        }
    }

    /**
     * Start editing a list.
     * @param elem The card list element.
     * @private
     */
    _nameEditStart(elem) {
        // Disable add card UI for this cardlist if any
        this.cardUI.endAddCard(elem);

        // disable cardlist dragging to allow title text selection
        elem.setAttribute("draggable", "false");
    }

    /**
     * Called when the name is changed.
     * @param id The ID of the card list.
     * @param nameElem The element containing the new name.
     * @param cardListElem The card list element.
     * @returns {Promise<void>} Updated when operation completes.
     * @private
     */
    async _nameChanged(id, nameElem, cardListElem) {
        // re-enable cardlist dragging
        cardListElem.setAttribute("draggable", "true");

        if (cardListElem.classList.contains("waiting-deletion")) {
            return; // avoid being triggered while deleting a cardlist (can happen on mobile)
        }

        try {
            const response = await this.list.updateName(id, nameElem.textContent);
            this._onCardListUpdated(response);
        } catch (e) {
            showErrorPopup(`Could not update card list name "${nameElem.textContent}": ${e.message}`, 'page-error');
        }
    }

    /**
     * Update a card list's name.
     * @param response The JSON response object.
     * @private
     */
    _onCardListUpdated(response) {
        const cardListElem = document.getElementById("cardlist-" + response.id);
        if (cardListElem) {
            cardListElem.querySelector("h3").textContent = response.name;
        }
    }

    /**
     * Called when a card list is deleted. Removes the element from the DOM.
     * @param response The JSON response object.
     * @private
     */
    _onCardListDeleted(response) {
        const cardlistElem = document.getElementById("cardlist-" + response.id);
        if (cardlistElem) {
            cardlistElem.remove();
        }
    }

    /**
     * Called after a card list is added.
     * @param response The JSON response object.
     * @private
     */
    _onCardListAdded(response) {
        const boardElem = this.page.getBoardElem();
        const newCardlistBtn = this.page.getAddCardListButtonElem();
        const newCardlistElem = this.loadCardList(response);
        boardElem.insertBefore(newCardlistElem, newCardlistBtn);

        // start name editing automatically
        const listTitleElem = newCardlistElem.querySelector("h3");
        listTitleElem.tabIndex = 0;
        listTitleElem.focus();
        window.getSelection().selectAllChildren(listTitleElem);
    }
}
