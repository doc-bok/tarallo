import { serverAction } from '../core/server.js';
import {
    BlurOnEnter,
    DBLinkedListIterator,
    GetContentElement,
    LoadTemplate,
    ReplaceContentWithTemplate,
    SetEventById,
    SetEventBySelector,
    TrySetEventById
} from '../core/utils.js';
import {ShowInfoPopup} from "../core/popup";

/**
 * Class to help with page-level operations.
 */
export class Page {

    /**
     * Ensure we have access to required fields
     */
    init({authUI, boardUI, cardDND, cardUI, importUI, labelUI, listUI}) {
        this.authUI = authUI;
        this.boardUI = boardUI;
        this.cardDND = cardDND;
        this.cardUI = cardUI;
        this.importUI = importUI;
        this.labelUI = labelUI;
        this.listUI = listUI;
    }

    /**
     * Request the current page from the server
     */
    getCurrentPage() {
        serverAction(
            "GetCurrentPage",
            {},
            (response) => this._loadPage(response),
            'page-error')
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
                this.loadLoginPage(pageContent);
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
            () => this.authUI.logout({
                onSuccess: () => this.getCurrentPage()
            }));
    }

    /**
     * Load a page that display first startup info about the instance
     */
    _loadFirstStartupPage(contentObj) {
        ReplaceContentWithTemplate("tmpl-firststartup", contentObj);
        document.title = "Tarallo - First Startup";

        // add events
        SetEventById("continue-btn", "onclick", () => this.getCurrentPage());
    }

    /**
     * Loads the login page as the page content
     */
    loadLoginPage(contentObj) {
        // fill page content with the login form
        ReplaceContentWithTemplate("tmpl-login", contentObj);
        document.title = "Tarallo - Login";

        // setup login button event
        const formNode = document.querySelector("#login-form");
        SetEventBySelector(
            formNode,
            "#login-btn",
            "onclick",
            () => {
                const username = formNode.querySelector('#login-username').value;
                const password = formNode.querySelector('#login-password').value;

                this.authUI.login({
                    username: username,
                    password: password,
                    onSuccess: () => this.getCurrentPage(),
                });
            });

        SetEventBySelector(formNode, "#register-page-btn", "onclick", () => this._loadRegisterPage(contentObj));

        // add instance message if any
        if (contentObj["instance_msg"]) {
            const instanceMsgElem = LoadTemplate("tmpl-instance-msg", contentObj)
            GetContentElement().prepend(instanceMsgElem);
        }
    }

    /**
     * Load a page with the list of the board tiles for each user
     */
    _loadBoardListPage(contentObj) {
        ReplaceContentWithTemplate("tmpl-boardlist", contentObj);
        document.title = "Tarallo - Boards";
        const boards = contentObj["boards"];
        for (let i = 0; i < boards.length; i++) {
            this.boardUI.loadBoardTile(boards[i]);
        }
        // add events
        SetEventById("new-board-btn", "onclick", () => this.boardUI.createNewBoard());
        SetEventById("import-board-btn", "onclick", () => this.importUI.importBoard());
        SetEventById("trello-import-btn", "onclick", () => this.importUI.importFromTrello());
    }

    // load the content of the current board page
    _loadBoardPage(contentObj) {
        ReplaceContentWithTemplate("tmpl-board", contentObj);
        document.title = contentObj["title"];
        const boardElem = document.getElementById("board");
        const newCardlistBtn = document.getElementById("add-cardlist-btn");

        if (contentObj["label_names"]) {
            this.labelUI.setLabelNames(contentObj["label_names"].split(","));
            this.labelUI.setLabelColors(contentObj["label_colors"].split(","));
        }
        this.labelUI.setAllColorNames(contentObj["all_color_names"]);

        // create card lists
        for (const cardlist of DBLinkedListIterator(contentObj["cardlists"], "id", "prev_list_id", "next_list_id")) {
            // create cardlist
            const newCardlistElem = this.listUI.loadCardList(cardlist);

            // create owned cards
            const cardlistID = cardlist["id"];
            for (const cardData of DBLinkedListIterator(contentObj["cards"], "id", "prev_card_id", "next_card_id", card => card["cardlist_id"] === cardlistID)) {
                const newCardElem = this.cardUI.loadCard(cardData, newCardlistElem);
                // append the new card to the list
                newCardlistElem.appendChild(newCardElem);
            }

            // add cardlist to the board
            boardElem.insertBefore(newCardlistElem, newCardlistBtn);
        }

        // project bar drag drop events
        const projectBar = document.getElementById("projectbar");
        projectBar.ondragover = (e) => e.preventDefault();
        projectBar.ondragenter = (e) => this.cardDND.dragDeleteEnter(e);
        projectBar.ondragleave = (e) => this.cardDND.dragDeleteLeave(e);
        projectBar.ondrop = (e) => this.cardDND.dropDelete(e);
        // other events
        SetEventBySelector(projectBar, "#board-title", "onblur", (elem) => this.boardUI.boardTitleChanged(elem));
        SetEventBySelector(projectBar, "#board-title", "onkeydown", (elem, event) => BlurOnEnter(event));
        SetEventBySelector(projectBar, "#board-change-bg-btn", "onclick", () => this.boardUI.changeBackground(contentObj["id"]));
        SetEventBySelector(projectBar, "#board-share-btn", "onclick", () => this.boardUI.shareBoard(contentObj["id"]));
        SetEventById("add-cardlist-btn", "onclick", () => this.listUI.addCardList());
    }

    /**
     * Load the closed board page
     */
    _loadClosedBoardPage(contentObj) {
        ReplaceContentWithTemplate("tmpl-closed-board", contentObj);
        document.title = "[Closed]" + contentObj["title"];

        SetEventById("closedboard-reopen-btn", "onclick", () => this.boardUI.reopenBoard(contentObj["id"]));
        SetEventById("closedboard-delete-link", "onclick", () => this.boardUI.showBoardDeleteConfirmation(contentObj["id"]));
    }

    /**
     * Load the unaccessible board page
     */
    _loadUnaccessibleBoardPage(contentObj) {
        ReplaceContentWithTemplate("tmpl-unaccessible-board", contentObj);
        document.title = "Tarallo"
        if (contentObj["access_requested"]) {
            document.getElementById("unaccessibleboard-request-btn").classList.add("hidden");
            document.getElementById("unaccessibleboard-waiting-label").classList.remove("hidden");
        }

        //events
        SetEventById("unaccessibleboard-request-btn", "onclick", () => this.boardUI.requestBoardAccess());
    }

    /**
     * Show the register page
     */
    _loadRegisterPage(contentObj) {
        // fill page content with the registration form
        ReplaceContentWithTemplate("tmpl-register", contentObj);
        document.title = "Tarallo - Register";

        // Setup register button event
        const formNode = document.querySelector("#login-form");
        SetEventBySelector(
            formNode,
            "#register-btn",
            "onclick",
            () => {
                const username = formNode.querySelector("#login-username").value;
                const password = formNode.querySelector("#login-password").value;
                const displayName = formNode.querySelector("#login-display-name").value;

                this.authUI.register({
                    username,
                    password,
                    displayName,
                })
            },
            (jsonResponseObj) => {
                this.loadLoginPage(jsonResponseObj);
                document.getElementById("login-username").value = jsonResponseObj["username"];
                ShowInfoPopup("Account successfully created, please login!", "login-error");
            },
            );

        SetEventBySelector(formNode, "#login-page-btn", "onclick", () => this.loadLoginPage(contentObj));
    }
}