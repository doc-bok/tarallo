import {AuthUI} from "./auth/auth-ui.js";
import {Permission} from "./auth/permission.js";
import {BoardUI} from './boards/board-ui.js';
import {CardAttachmentUI} from "./cards/attachment-ui.js";
import {CardDnd} from "./cards/card-dnd.js";
import {CardLabelUI} from "./cards/label-ui.js";
import {CardUI} from "./cards/card-ui.js";
import {IsMobileDevice} from "./core/utils.js";
import {ImportUI} from "./import-export/import-ui.js";
import {ListUI} from "./lists/list-ui.js";
import {Page} from './page/page.js';

class TaralloClient {

    constructor() {
        this.attachmentUI = new CardAttachmentUI();
        this.authUI = new AuthUI();
        this.boardUI = new BoardUI();
        this.cardDND = new CardDnd();
        this.cardUI = new CardUI();
        this.importUI = new ImportUI();
        this.labelUI = new CardLabelUI();
        this.listUI = new ListUI();
        this.page = new Page();
        this.permission = new Permission();

        // Initialise dependencies
        this.attachmentUI.init(this.cardUI);
        this.authUI.init(this.page);
        this.boardUI.init(this.page, this.permission)
        this.cardUI.init(this.attachmentUI, this.cardDND, this.labelUI);
        this.cardDND.init(this.cardUI, this.listUI);
        this.importUI.init(this.boardUI);
        this.listUI.init(this.cardDND, this.cardUI);
        this.page.init(this.authUI, this.boardUI, this.cardDND, this.cardUI, this.importUI, this.labelUI, this.listUI);

        // set mobile class
        if (IsMobileDevice()) {
            document.body.classList.add("mobile");
        }

        // check with the server what should be displayed
        this.page.getCurrentPage();
    }
}

window.TaralloApp = new TaralloClient();