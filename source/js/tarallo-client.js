import {Account} from "./auth/account.js";
import {BoardUI} from './boards/board-ui.js';
import {CardAttachmentUI} from "./attachment/attachment-ui.js";
import {CardDnd} from "./cards/card-dnd.js";
import {CardLabelUI} from "./labels/label-ui.js";
import {CardUI} from "./cards/card-ui.js";
import {ImportExportUi} from "./import-export/import-export-ui.js";
import {ListUI} from "./lists/list-ui.js";
import {PageUi} from './page/page-ui.js';
import {Page} from "./page/page.js";
import {WorkspaceUI} from "./workspace/workspace-ui.js";

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
        this.account = new Account();
        this.attachmentUI = new CardAttachmentUI();
        this.boardUI = new BoardUI();
        this.cardDnd = new CardDnd();
        this.cardUI = new CardUI();
        this.importUI = new ImportExportUi();
        this.labelUI = new CardLabelUI();
        this.listUI = new ListUI();
        this.page = new Page();
        this.pageUI = new PageUi();
        this.workspaceUI = new WorkspaceUI();
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
            account: this.account,
            page: this.page,
            pageUI: this.pageUI
        });

        this.cardUI.init({
            attachmentUI: this.attachmentUI,
            cardDnd: this.cardDnd,
            labelUI: this.labelUI,
            page: this.page,
        });

        this.cardDnd.init({
            cardUI: this.cardUI,
            page: this.page,
        });

        this.importUI.init({
            boardUI: this.boardUI,
            page: this.page,
        });

        this.labelUI.init(this.cardUI);

        this.listUI.init({
            cardDnd: this.cardDnd,
            cardUI: this.cardUI,
            page: this.page
        });

        this.pageUI.init({
            account: this.account,
            boardUI: this.boardUI,
            cardDnd: this.cardDnd,
            cardUI: this.cardUI,
            importUI: this.importUI,
            labelUI: this.labelUI,
            listUI: this.listUI,
            page: this.page,
            workspaceUI: this.workspaceUI
        });
    }

    /**
     * Start the Tarallo client
     */
    start() {
        this.pageUI.getCurrentPage();
    }
}