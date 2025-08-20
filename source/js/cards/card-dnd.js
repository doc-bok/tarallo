/**
 * Handles drag/drop UI operations
 */
export class CardDnd {

    draggedCard = null;

    /**
     * Init UI dependencies.
     * @param cardUI The Card UI.
     * @param page The page API.
     */
    init({cardUI, page}) {
        this.cardUI = cardUI;
        this.page = page;
    }

    /**
     * Start dragging a card.
     * @param event The drag event.
     */
    start(event) {
        this.draggedCard = event.currentTarget;
        const projectBar = this.page.getProjectBarElem();
        projectBar.classList.add("pb-mode-delete");
        projectBar.ondrop = (e) => this.dropDelete(e);
    }

    /**
     * Enter drag card state.
     * @param event The drag event.
     */
    enter(event) {
        if (this.draggedCard === null) {
            return; // not dragging a cardlist
        }

        event.currentTarget.classList.add("drag-target-card");
        event.preventDefault();
    }

    /**
     * Leave the drag card state.
     * @param event The drag event.
     */
    leave(event) {
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
     * Drop a card somewhere.
     * @param event The drag event.
     */
    async dropMove(event) {
        event.currentTarget.classList.remove("drag-target-card");

        // check that a dragged card has been saved
        if (this.draggedCard === null) {
            return;
        }

        // fill call args
        const movedCardId = this.draggedCard.getAttribute("dbid");
        let newPrevCardId = 0;
        if (event.currentTarget.matches(".card")) {
            newPrevCardId = event.currentTarget.getAttribute("dbid");
        }

        const destCardListId= event.currentTarget.closest(".cardlist").getAttribute("dbid");

        // make the call if the card has actually moved
        if (movedCardId !== newPrevCardId) {
            await this.cardUI.moveCard(movedCardId, newPrevCardId, destCardListId);
        } else {
            this.draggedCard = null;
        }
    }

    /**
     * Called when a card is moved.
     */
    onCardMoved() {
        this.draggedCard.remove(); // remove from the old position
        this.draggedCard = null;
    }

    /**
     * Drop on delete.
     * @param event The drag event.
     */
    async dropDelete(event) {
        event.currentTarget.classList.remove("drag-target-bar");

        if (this.draggedCard !== null) { // drag-delete card
            const id = this.draggedCard.getAttribute("dbid");
            await this.cardUI.deleteCard(id);
        }
    }

    /**
     * Called when a card is deleted.
     */
    onCardDeleted() {
        this.draggedCard.remove();
        this.draggedCard = null;
    }

    /**
     * End dragging a card.
     */
    end() {
        this.page.getProjectBarElem().classList.remove("pb-mode-delete");
    }

    /**
     * Drag a file to attach
     * TODO: Move to attachmentDnd?
     */
    dragOverAttachment(event) {
        event.preventDefault();
    }

    /**
     * Drag to delete
     * TODO: Move to delete element handler? Or page-dnd?
     */
    dragDeleteEnter(event) {
        event.currentTarget.classList.add("drag-target-bar");
        event.preventDefault();
    }

    /**
     * End drag to delete
     * TODO: Move to delete element handler? Or page-dnd?
     */
    dragDeleteLeave(event) {
        // discard leave events if we are just leaving a child
        if (event.currentTarget.contains(event.relatedTarget)) {
            return;
        }

        event.currentTarget.classList.remove("drag-target-bar");
        event.preventDefault();
    }
}