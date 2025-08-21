/**
 * Drag and Drop support for card lists.
 */
export class ListDnd {

    /**
     * Create a new drag-n-drop interface.
     * @param listUI The List UI.
     * @param page The Page API.
     */
    constructor(listUI, page) {
        this.draggedCardList = null;
        this.listUI = listUI;
        this.page = page;
    }

    /**
     * Start dragging a card list.
     * @param event The drag event.
     */
    start(event) {
        if (!event.originalTarget.classList.contains("cardlist")) {
            return; // not dragging a cardlist
        }

        this.draggedCardList = event.currentTarget;
        const projectBar = this.page.getProjectBarElem();
        projectBar.classList.add("pb-mode-delete");
        projectBar.ondrop = (e) => this.dropDelete(e);
    }

    /**
     * Enter the drag state.
     * @param event The drag event.
     */
    enter(event) {
        if (this.draggedCardList === null) {
            return; // not dragging a cardlist
        }

        event.currentTarget.classList.add("drag-target-cardlist");
        event.preventDefault();
    }

    /**
     * Drop a card list into a new place.
     * @param event The drag event.
     */
    async dropMove(event) {
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

        const movedCardListId = this.draggedCardList.getAttribute("dbid");
        const newPrevCardListId = event.currentTarget.matches(".cardlist") ? prevListElem.getAttribute("dbid") : 0;

        await this.listUI.moveCardList(movedCardListId, newPrevCardListId);
    }

    /**
     * Drop on delete
     * @param event The drag event.
     */
    async dropDelete(event) {
        event.currentTarget.classList.remove("drag-target-bar");

        if (this.draggedCardList !== null) {
            // trigger cardlist deletion
            const cardlistID = this.draggedCardList.getAttribute("dbid");
            await this.listUI.deleteCardList(cardlistID, this.draggedCardList);
            this.draggedCardList = null;
        }
    }

    /**
     * Called after a card list is moved
     * @param response The JSON response.
     */
    onCardListMoved(response) {
        if (response.prev_list_id) {
            // remove the cardlist from its current position and re-insert it using the previous one as reference
            this.draggedCardList.remove();
            const prevCardlistNode = document.getElementById("cardlist-" + response.prev_list_id);
            if (prevCardlistNode) {
                prevCardlistNode.insertAdjacentElement("afterend", this.draggedCardList);
            }

        } else {
            // re-insert as the first list in the board
            const cardlistContainerNode = this.draggedCardList.parentNode;
            this.draggedCardList.remove();
            cardlistContainerNode.prepend(this.draggedCardList);
        }

        this.draggedCardList = null;
    }

    /**
     * Leave the drag state.
     * @param event The drag event.
     */
    leave(event) {
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
     * End the drag state.
     */
    end() {
        this.page.getProjectBarElem().classList.remove("pb-mode-delete");
    }
}