import {serverAction} from "../core/server.js";

/**
 * Handles drag/drop UI operations
 */
export class CardDnd {

    draggedCard = null;
    draggedCardList = null;

    /**
     * Init UI dependencies
     */
    init({cardUI, listUI, page}) {
        this.cardUI = cardUI;
        this.listUI = listUI;
        this.page = page;
    }

    /**
     * Start dragging a card
     */
    dragCardStart(event) {
        this.draggedCard = event.currentTarget;
        this.page.getProjectBarElem().classList.add("pb-mode-delete");
    }

    /**
     * Enter drag card state
     */
    dragCardEnter(event) {
        if (this.draggedCard === null) {
            return; // not dragging a cardlist
        }

        event.currentTarget.classList.add("drag-target-card");
        event.preventDefault();
    }

    /**
     * Leave the drag card state
     */
    dragCardLeave(event) {
        // discard leave events if we are just leaving a child
        if (event.currentTarget.contains(event.relatedTarget)) {
            return;
        }

        if (this.draggedCard === null) {
            return; // not dragging a cardlist
        }

        event.currentTarget.classList.remove("drag-target-card");
        event.preventDefault();
    }

    /**
     * Drop a card somewhere
     */
    dropCard(event) {
        event.currentTarget.classList.remove("drag-target-card");

        // check that a dragged card has been saved
        if (this.draggedCard === null) {
            return;
        }

        // fill call args
        let args = [];
        args["moved_card_id"] = this.draggedCard.getAttribute("dbid");
        if (event.currentTarget.matches(".card")) {
            args["new_prev_card_id"] = event.currentTarget.getAttribute("dbid");
        } else {
            args["new_prev_card_id"] = 0;
        }
        args["dest_cardlist_id"] = event.currentTarget.closest(".cardlist").getAttribute("dbid");

        // make the call if the card has actually moved
        if (args["moved_card_id"] !== args["new_prev_card_id"]) {
            serverAction("MoveCard", args, (response) => this._onCardMoved(response), "page-error");
        } else {
            this.draggedCard = null;
        }
    }

    /**
     * End dragging a card
     */
    dragCardEnd(event) {
        this.page.getProjectBarElem().classList.remove("pb-mode-delete");
    }

    /**
     * Called when a card is moved
     */
    _onCardMoved(jsonResponseObj) {
        this.draggedCard.remove(); // remove from the old position
        this.cardUI.onCardAdded(jsonResponseObj); // add back in the new position
        this.draggedCard = null;
    }

    /**
     * Drag a file to attach
     */
    dragOverAttachment(event) {
        event.preventDefault();
    }

    /**
     * Drag to delete
     */
    dragDeleteEnter(event) {
        event.currentTarget.classList.add("drag-target-bar");
        event.preventDefault();
    }

    /**
     * End drag to delete
     */
    dragDeleteLeave(event) {
        // discard leave events if we are just leaving a child
        if (event.currentTarget.contains(event.relatedTarget)) {
            return;
        }

        event.currentTarget.classList.remove("drag-target-bar");
        event.preventDefault();
    }

    /**
     * Drop on delete
     */
    dropDelete(event) {
        event.currentTarget.classList.remove("drag-target-bar");

        if (this.draggedCard !== null) { // drag-delete card
            // fill call args
            let args = [];
            args["deleted_card_id"] = this.draggedCard.getAttribute("dbid");

            // make the call if the card has actually moved
            serverAction("DeleteCard", args, (response) => this._onCardDeleted(response), "page-error");
        } else if (this.draggedCardList !== null) {
            // trigger cardlist deletion
            const cardlistID = this.draggedCardList.getAttribute("dbid");
            this.listUI.deleteCardList(cardlistID, this.draggedCardList);
            this.draggedCardList = null;
        }
    }

    /**
     * Called when a card is deleted
     */
    _onCardDeleted(jsonResponseObj) {
        this.draggedCard.remove();
        this.draggedCard = null;
    }

    /**
     * Start dragging a card list
     */
    dragCardListStart(event) {
        if (!event.originalTarget.classList.contains("cardlist")) {
            return; // not dragging a cardlist
        }
        this.draggedCardList = event.currentTarget;
        this.page.getProjectBarElem().classList.add("pb-mode-delete");
    }

    /**
     * Enter drag card list state
     */
    dragCardListEnter(event) {
        if (this.draggedCardList === null) {
            return; // not dragging a cardlist
        }

        event.currentTarget.classList.add("drag-target-cardlist");
        event.preventDefault();
    }

    /**
     * Stop dragging a card list
     */
    dragCardListLeave(event) {
        // discard leave events if we are just leaving a child
        if (event.currentTarget.contains(event.relatedTarget)) {
            return;
        }

        if (this.draggedCardList === null) {
            return; // not dragging a cardlist
        }

        event.currentTarget.classList.remove("drag-target-cardlist");
        event.preventDefault();
    }

    /**
     * Drop a card list
     */
    dropCardList(event) {
        event.currentTarget.classList.remove("drag-target-cardlist");

        // check that a dragged cardlist has been saved
        if (this.draggedCardList === null) {
            return;
        }

        // validate movement
        const prevListElem = event.currentTarget;
        if (prevListElem === this.draggedCardList.previousSibling || prevListElem === this.draggedCardList) {
            // move to the same position, skip
            this.draggedCardList = null;
            return;
        }

        // fill args and update the server
        let args = [];
        args["moved_cardlist_id"] = this.draggedCardList.getAttribute("dbid");
        if (event.currentTarget.matches(".cardlist")) {
            args["new_prev_cardlist_id"] = prevListElem.getAttribute("dbid");
        } else {
            args["new_prev_cardlist_id"] = 0;
        }

        serverAction("MoveCardList", args, (response) => this.listUI.onCardListMoved(response), "page-error");
    }

    /**
     * End card list drag
     */
    dragCardListEnd(event) {
        this.page.getProjectBarElem().classList.remove("pb-mode-delete");
    }
}