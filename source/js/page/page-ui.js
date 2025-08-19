import { asyncCallV2 } from '../core/server.js';
import {
    BlurOnEnter,
    DBLinkedListIterator,
    GetContentElement,
    LoadTemplate,
    replaceContentWithTemplate,
    setEventById,
    SetEventBySelector,
    TrySetEventById
} from '../core/utils.js';
import {showErrorPopup, showInfoPopup} from "../core/popup.js";

/**
 * Class to help with page-level operations.
 */
export class PageUi {

    /**
     * Ensure we have access to required fields
     */
    init({account: account, boardUI, cardDND, cardUI, importUI, labelUI, listUI, page}) {
        this.account = account;
        this.boardUI = boardUI;
        this.cardDND = cardDND;
        this.cardUI = cardUI;
        this.importUI = importUI;
        this.labelUI = labelUI;
        this.listUI = listUI;
        this.page = page;
    }

    /**
     * Request the current page from the server
     */
    async getCurrentPage() {
        try {
            const response = await asyncCallV2("GetCurrentPage", {});
            this._loadPage(response);
        } catch (e) {
            showErrorPopup("Failed to load page: " + e.message, "page-error");
        }
    }

    /**
     * Load a page from the json response
     * @param jsonResponseObj
     * @private
     */
    _loadPage(jsonResponseObj) {
        const pageContent = jsonResponseObj["page_content"];
        const pageName = jsonResponseObj["page_name"];
        switch (pageName) {
            case "FirstStartup":
                this._loadFirstStartupPage(pageContent);
                break;
            case "Login":
                this._loadLoginPage(pageContent);
                break;
            case "BoardList":
                this._loadBoardListPage(pageContent);
                break;
            case "Board":
                this._loadBoardPage(pageContent);
                break;
            case "ClosedBoard":
                this._loadClosedBoardPage(pageContent);
                break;
            case "UnaccessibleBoard":
                this._loadUnaccessibleBoardPage(pageContent);
                break;
        }

        // update background if required
        if (pageContent["background_url"] !== undefined) {
            this.boardUI.setBackground(pageContent["background_url"], pageContent["background_tiled"]);
        }

        // add needed events
        TrySetEventById(
            "projectbar-logout-btn",
            "onclick",
            async () => {
                try {
                    await this.account.logout();
                    await this.getCurrentPage();
                } catch (e) {
                    showErrorPopup('Logout failed: ' + e.message, 'page-error');
                }
            });
    }

    /**
     * Load a page that display first startup info about the instance
     */
    _loadFirstStartupPage({admin_user, admin_pass}) {
        replaceContentWithTemplate("tmpl-firststartup", {admin_user, admin_pass});
        document.title = "Tarallo - First Startup";

        // add events
        setEventById("continue-btn", "onclick", () => this.getCurrentPage());
    }

    /**
     * Loads the login page as the page content
     */
    _loadLoginPage({instance_msg}) {
        // fill page content with the login form
        replaceContentWithTemplate("tmpl-login", {});
        document.title = "Tarallo - Login";

        // setup login button event
        const formNode = document.querySelector("#login-form");
        SetEventBySelector(
            formNode,
            "#login-btn",
            "onclick",
            async () => {
                const username = formNode.querySelector('#login-username').value;
                const password = formNode.querySelector('#login-password').value;

                try {
                    await this.account.login(username, password);
                    await this.getCurrentPage();
                } catch (e) {
                    showErrorPopup("Login failed: " + e.message, 'login-error');
                }
            });

        SetEventBySelector(formNode, "#register-page-btn", "onclick", () => this._loadRegisterPage());

        // add instance message if any
        if (instance_msg) {
            const instanceMsgElem = LoadTemplate("tmpl-instance-msg", contentObj)
            this.page.getContentElem().prepend(instanceMsgElem);
        }
    }

    /**
     * Load a page with the list of the board tiles for each user
     */
    _loadBoardListPage({display_name, boards}) {
        replaceContentWithTemplate("tmpl-boardlist", {display_name});
        document.title = "Tarallo - Boards";
        for (let i = 0; i < boards.length; i++) {
            this.boardUI.loadBoardTile(boards[i]);
        }
        // add events
        setEventById("new-board-btn", "onclick", () => this.boardUI.createNewBoard());
        setEventById("import-board-btn", "onclick", () => this.importUI.importBoard());
        setEventById("trello-import-btn", "onclick", () => this.importUI.importFromTrello());
    }

    // load the content of the current board page
    _loadBoardPage({
                       title,
                       id,
                       display_name,
                       label_names,
                       label_colors,
                       all_color_names,
                       cardlists,
                       cards
    }) {
        replaceContentWithTemplate("tmpl-board", {title, id, display_name});
        document.title = title;
        const boardElem = this.page.getBoardElem();
        const newCardlistBtn = this.page.getAddCardListButtonElem()

        if (label_names) {
            this.labelUI.setLabelNames(label_names.split(","));
            this.labelUI.setLabelColors(label_colors.split(","));
        }

        this.labelUI.setAllColorNames(all_color_names);

        // create card lists
        for (const cardlist of DBLinkedListIterator(cardlists, "id", "prev_list_id", "next_list_id")) {
            // create cardlist
            const newCardlistElem = this.listUI.loadCardList(cardlist);

            // create owned cards
            const cardlistID = cardlist["id"];
            for (const cardData of DBLinkedListIterator(cards, "id", "prev_card_id", "next_card_id", card => card["cardlist_id"] === cardlistID)) {
                const newCardElem = this.cardUI.loadCard(cardData, newCardlistElem);
                // append the new card to the list
                newCardlistElem.appendChild(newCardElem);
            }

            // add cardlist to the board
            boardElem.insertBefore(newCardlistElem, newCardlistBtn);
        }

        // project bar drag drop events
        const projectBar = this.page.getProjectBarElem();
        projectBar.ondragover = (e) => e.preventDefault();
        projectBar.ondragenter = (e) => this.cardDND.dragDeleteEnter(e);
        projectBar.ondragleave = (e) => this.cardDND.dragDeleteLeave(e);
        projectBar.ondrop = (e) => this.cardDND.dropDelete(e);

        // other events
        SetEventBySelector(projectBar, "#board-title", "onblur", (elem) => this.boardUI.boardTitleChanged(elem));
        SetEventBySelector(projectBar, "#board-title", "onkeydown", (elem, event) => BlurOnEnter(event));
        SetEventBySelector(projectBar, "#board-change-bg-btn", "onclick", () => this.boardUI.changeBackground(id));
        SetEventBySelector(projectBar, "#board-share-btn", "onclick", () => this.boardUI.shareBoard(id));
        setEventById("add-cardlist-btn", "onclick", () => this.listUI.addCardList());
    }

    /**
     * Load the closed board page
     */
    _loadClosedBoardPage({display_name, title, id}) {
        replaceContentWithTemplate("tmpl-closed-board", {display_name});
        document.title = "[Closed]" + title;

        setEventById("closedboard-reopen-btn", "onclick", () => this.boardUI.reopenBoard(id));
        setEventById("closedboard-delete-link", "onclick", () => this.boardUI.showBoardDeleteConfirmation(id));
    }

    /**
     * Load the unaccessible board page
     */
    _loadUnaccessibleBoardPage({display_name, access_requested}) {
        replaceContentWithTemplate("tmpl-unaccessible-board", {display_name});
        document.title = "Tarallo"
        if (access_requested) {
            this.page.getUnaccessibleBoardRequestButtonElem().classList.add("hidden");
            this.page.getUnaccessibleBoardWaitingLabelElem().classList.remove("hidden");
        }

        //events
        setEventById("unaccessibleboard-request-btn", "onclick", () => this.boardUI.requestBoardAccess());
    }

    /**
     * Show the register page
     */
    _loadRegisterPage() {
        // fill page content with the registration form
        replaceContentWithTemplate("tmpl-register", {});
        document.title = "Tarallo - Register";

        // Setup register button event
        const formNode = document.querySelector("#login-form");
        SetEventBySelector(
            formNode,
            "#register-btn",
            "onclick",
            async () => {
                const username = formNode.querySelector("#login-username").value;
                const password = formNode.querySelector("#login-password").value;
                const displayName = formNode.querySelector("#login-display-name").value;

                try {
                    const response = await this.account.register(username, password, displayName);
                    this._loadLoginPage(response);
                    this.page.getLoginUsernameElem().value = response.username;
                    showInfoPopup("Account successfully created, please login!", "login-error");
                } catch (e) {
                    showErrorPopup("Failed to register account: " + e.message, 'register-error');
                }
            });

        SetEventBySelector(formNode, "#login-page-btn", "onclick", () => this._loadLoginPage({}));
    }
}