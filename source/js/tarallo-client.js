import {Auth} from "./auth/auth.js";
import {Permission} from "./auth/permission.js";
import {BoardUI} from './boards/board-ui.js';
import {CardAttachmentUI} from "./cards/attachment-ui.js";
import {CardDnd} from "./cards/card-dnd.js";
import {CardLabelUI} from "./cards/label-ui.js";
import {CardUI} from "./cards/card-ui.js";
import {ImportUI} from "./import-export/import-ui.js";
import {ListUI} from "./lists/list-ui.js";
import {Page} from './page/page.js';

/**
 * The definition of the Tarallo Client
 */
export class TaralloClient {

    /**
     * Create a new instance of the tarallo client
     */
    constructor() {
        this.setupInstances();
        this.initDependencies();
    }

    /**
     * Setup instances of each library
     */
    setupInstances() {
        this.attachmentUI = new CardAttachmentUI();
        this.authUI = new Auth();
        this.boardUI = new BoardUI();
        this.cardDND = new CardDnd();
        this.cardUI = new CardUI();
        this.importUI = new ImportUI();
        this.labelUI = new CardLabelUI();
        this.listUI = new ListUI();
        this.page = new Page();
        this.permission = new Permission();
    }

    /**
     * Initialise each library with their dependencies
     * TODO: Replace with dependency injection
     */
    initDependencies() {
        this.attachmentUI.init({
            cardUI: this.cardUI
        });

        this.boardUI.init({
            page: this.page,
            permission: this.permission
        });

        this.cardUI.init({
            attachmentUI: this.attachmentUI,
            cardDND: this.cardDND,
            labelUI: this.labelUI}
        );

        this.cardDND.init({
            cardUI: this.cardUI,
            listUI: this.listUI
        });

        this.importUI.init({
            boardUI: this.boardUI
        });

        this.listUI.init({
            cardDND: this.cardDND,
            cardUI: this.cardUI
        });

        this.page.init({
            authUI: this.authUI,
            boardUI: this.boardUI,
            cardDND: this.cardDND,
            cardUI: this.cardUI,
            importUI: this.importUI,
            labelUI: this.labelUI,
            listUI: this.listUI
        });
    }

    /**
     * Start the Tarallo client
     */
    start() {
        this.page.getCurrentPage();
    }
}