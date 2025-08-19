import {BlurOnEnter, LoadTemplate, SetEventBySelector} from "../core/utils.js";
import {serverAction} from "../core/server.js";
import {showErrorPopup} from "../core/popup.js";

export class ListUI {

    /**
     * Ensure we have access to required fields
     */
    init({cardDND, cardUI}) {
        this.cardDND = cardDND;
        this.cardUI = cardUI;
    }

    /**
     * Load a card list
     */
    loadCardList(cardlistData) {
        const cardlistElem = LoadTemplate("tmpl-cardlist", cardlistData);
        // events
        SetEventBySelector(cardlistElem, ".cardlist-title h3", "onfocus", () => this._nameEditStart(cardlistElem));
        const nameChangedHandler = (elem) => this._nameChanged(cardlistData["id"], elem, cardlistElem);
        SetEventBySelector(cardlistElem, ".cardlist-title h3", "onblur", nameChangedHandler);
        SetEventBySelector(cardlistElem, ".cardlist-title h3", "onkeydown", (elem, event) => BlurOnEnter(event));
        SetEventBySelector(cardlistElem, ".addcard-btn", "onclick", () => this.cardUI.addNewCard(cardlistData["id"], cardlistElem));
        SetEventBySelector(cardlistElem, ".editcard-submit-btn", "onclick", () => this.cardUI.newCard(cardlistData["id"], cardlistElem));
        SetEventBySelector(cardlistElem, ".editcard-card", "onkeydown", (elem, keydownEvent) => {
            if (keydownEvent.keyCode === 13) {
                keydownEvent.preventDefault();
                this.cardUI.newCard(cardlistData["id"], cardlistElem)
            }
        });
        SetEventBySelector(cardlistElem, ".editcard-cancel-btn", "onclick", () => this.cardUI.cancelNewCard(cardlistElem));

        // drag and drop events
        const cardlistStartElem = cardlistElem.querySelector(".cardlist-start");
        cardlistStartElem.ondragover = (e) => e.preventDefault();
        cardlistStartElem.ondragenter = (e) => this.cardDND.dragCardEnter(e);
        cardlistStartElem.ondragleave = (e) => this.cardDND.dragCardLeave(e);
        cardlistStartElem.ondrop = (e) => this.cardDND.dropCard(e);

        // events
        cardlistElem.ondragstart = (e) => this.cardDND.dragCardListStart(e);
        cardlistElem.ondragenter = (e) => this.cardDND.dragCardListEnter(e);
        cardlistElem.ondragover = (e) => e.preventDefault();
        cardlistElem.ondragleave = (e) => this.cardDND.dragCardListLeave(e);
        cardlistElem.ondrop = (e) => this.cardDND.dropCardList(e);
        cardlistElem.ondragend = (e) => this.cardDND.dragCardListEnd(e);

        return cardlistElem;
    }

    /**
     * Start editing a list
     */
    _nameEditStart(cardlistElem) {
        // disable add card UI for this cardlist if any
        this.cardUI.cancelNewCard(cardlistElem);

        // disable cardlist dragging to allow title text selection
        cardlistElem.setAttribute("draggable", "false");
    }

    /**
     * Handle name change
     */
    _nameChanged(cardlistID, nameElem, cardlistElem) {
        // re-enable cardlist dragging
        cardlistElem.setAttribute("draggable", "true");

        if (cardlistElem.classList.contains("waiting-deletion")) {
            return; // avoid being triggered while deleting a cardlist (can happen on mobile)
        }

        let args = [];
        args["id"] = cardlistID;
        args["name"] = nameElem.textContent;
        serverAction("UpdateCardListName", args, (response) => this._onCardListUpdated(response), "page-error");
    }

    /**
     * Delete a card list
     */
    deleteCardList(cardlistID, cardlistElem) {
        if (cardlistElem.classList.contains("waiting-deletion")) {
            return; // avoid being triggered again while deleting (can happen on mobile)
        }

        let args = [];
        args["id"] = cardlistID;
        cardlistElem.classList.add("waiting-deletion");
        const onErrorCallback = (msg) => {
            cardlistElem.classList.remove("waiting-deletion");
            showErrorPopup(msg, "page-error");
        };
        serverAction("DeleteCardList", args, (response) => this._onCardListDeleted(response), onErrorCallback);
    }

    /**
     * Called after a card list is moved
     */
    onCardListMoved(jsonResponseObj) {
        if (jsonResponseObj["prev_list_id"]) {
            // remove the cardlist from its current position and re-insert it using the previous one as reference
            this.draggedCardList.remove();
            const prevCardlistNode = document.getElementById("cardlist-" + jsonResponseObj["prev_list_id"]);
            prevCardlistNode.insertAdjacentElement("afterend", this.draggedCardList);
        } else {
            // re-insert as the first list in the board
            const cardlistContainerNode = this.draggedCardList.parentNode;
            this.draggedCardList.remove();
            cardlistContainerNode.prepend(this.draggedCardList);
        }

        this.draggedCardList = null;
    }

    /**
     * Called when a card list is updated
     */
    _onCardListUpdated(jsonResponseObj) {
        const cardListElem = document.getElementById("cardlist-" + jsonResponseObj["id"]);
        cardListElem.querySelector("h3").textContent = jsonResponseObj["name"];
    }

    /**
     * Called when a card list is deleted
     */
    _onCardListDeleted(jsonResponseObj) {
        const cardlistElem = document.getElementById("cardlist-" + jsonResponseObj["id"]);
        if (cardlistElem) {
            cardlistElem.remove();
        }
    }

    /**
     * Add a card list
     */
    addCardList() {
        let args = [];
        args["name"] = "New List";
        args["prev_list_id"] = 0;
        const prevListElem = document.getElementById("add-cardlist-btn").previousElementSibling;
        if (prevListElem !== null) {
            args["prev_list_id"] = prevListElem.getAttribute("dbid");
        }
        serverAction("AddCardList", args, (response) => this._onCardListAdded(response), "page-error");
    }

    /**
     * Called after a card list is added
     */
    _onCardListAdded(jsonResponseObj) {
        // create cardlist
        const boardElem = document.getElementById("board");
        const newCardlistBtn = document.getElementById("add-cardlist-btn");
        const newCardlistElem = this.loadCardList(jsonResponseObj);
        boardElem.insertBefore(newCardlistElem, newCardlistBtn);
        // start name editing automatically
        const listTitleElem = newCardlistElem.querySelector("h3");
        listTitleElem.focus();
        window.getSelection().selectAllChildren(listTitleElem);
    }
}
