import { asyncCall } from '../core/server.js';
import {
    blurOnEnter,
    loadTemplate,
    replaceHtmlTemplateArgs, selectAllInnerText,
    setEventBySelector
} from '../core/utils.js';
import {showErrorPopup, showInfoPopup} from "../ui/popup.js";
import {ProjectBar} from "../ui/project-bar.js";

/**
 * Class to help with page-level operations.
 */
export class PageUi {

    /**
     * Ensure we have access to required fields.
     * @param account The account API
     * @param boardUI The board UI
     * @param cardDnd The card drag-and-drop interface
     * @param cardUI The card UI
     * @param importUI The import UI
     * @param labelUI The label UI
     * @param listUI The list UI
     * @param page The page helpers
     * @param workspaceUI The Workspace UI
     */
    init({account, boardUI, cardDnd, cardUI, importUI, labelUI, listUI, page, workspaceUI}) {
        this._account = account;
        this._boardUI = boardUI;
        this._cardDnd = cardDnd;
        this._cardUI = cardUI;
        this._importUI = importUI;
        this._labelUI = labelUI;
        this._listUI = listUI;
        this._page = page;
        this._projectBar = new ProjectBar();
        this._workspaceUI = workspaceUI;
    }

    /**
     * Request the current page from the server.
     */
    async getCurrentPage() {
        try {
            this._showLoadingSpinner();
            const response = await asyncCall("GetCurrentPage", {}, 'GET');
            this._loadPage(response);
        } catch (e) {
            showErrorPopup("Failed to load page: " + e.message, "page-error");
        } finally {
            this._hideLoadingSpinner();
        }
    }

    /**
     * Load a page from the json response.
     * @param response The JSON response object.
     * @private
     */
    _loadPage(response) {

        const pageContent = response.page_content;
        const pageName = response.page_name;
        switch (pageName) {
            case "FirstStartup":
                this._projectBar.hide();
                this._loadFirstStartupPage(pageContent);
                break;

            case "Login":
                this._projectBar.hide();
                this._loadLoginPage(pageContent);
                break;

            case "Home":
                this._projectBar.showBasicOptions(pageContent);
                this._loadHomePage(pageContent);
                break;

            case "Workspace":
                this._projectBar.showBasicOptions(pageContent);
                this._loadWorkspacePage(pageContent);
                break;

            case "BoardList":
                this._projectBar.showBasicOptions(pageContent);
                this._loadBoardListPage(pageContent);
                break;

            case "Board":
                this._projectBar.showBoardOptions(pageContent);
                this._loadBoardPage(pageContent);
                break;

            case "ClosedBoard":
                this._projectBar.showClosedBoardOptions(pageContent);
                this._loadClosedBoardPage(pageContent);
                break;

            case "UnaccessibleBoard":
                this._projectBar.showBasicOptions(pageContent);
                this._loadUnaccessibleBoardPage(pageContent);
                break;
        }

        // Update the footer.
        const footerElem = this._page.getFooterElem();
        if (footerElem) {
            footerElem.innerHTML = replaceHtmlTemplateArgs(footerElem.innerHTML, pageContent);
        }

        // Hook up logout event.
        this._projectBar.setLogoutEvent(
            async () => {
                try {
                    await this._account.logout();
                    await this.getCurrentPage();
                } catch (e) {
                    showErrorPopup('Logout failed: ' + e.message, 'page-error');
                }
            });
    }

    /**
     * Load a page that display first startup info about the instance.
     * @param admin_user The username of the admin account.
     * @param admin_pass The password of the admin account.
     * @private
     */
    _loadFirstStartupPage({admin_user, admin_pass}) {
        this._loadTemplateWithTitle(
            "tmpl-firststartup",
            {admin_user, admin_pass},
            "Tarallo - First Startup");

        // add events
        this._onClick("continue-btn", () => this.getCurrentPage());
    }

    /**
     * Loads the login page as the page content.
     * @param instance_msg The message to display for the current instance.
     * @param user_name (Optional) The username to fill out the form with.
     * @private
     */
    _loadLoginPage({instance_msg, user_name = ''}) {
        // fill page content with the login form
        this._loadTemplateWithTitle(
            "tmpl-login",
            {user_name},
            "Tarallo - Login");

        // setup login button event
        const formNode = this._page.getLoginFormElem();
        setEventBySelector(
            formNode,
            "#login-btn",
            "onclick",
            () => this._login())

        setEventBySelector(formNode, "#register-page-btn", "onclick", () => this._loadRegisterPage({}));

        // add instance message if any
        if (instance_msg) {
            const instanceMsgElem = loadTemplate("tmpl-instance-msg", {instance_msg})
            this._page.getContentElem().prepend(instanceMsgElem);
        }
    }

    _loadHomePage({workspace_list}) {
        this._loadTemplateWithTitle(
            "tmpl-home",
            {},
            "Tarallo - Workspace");

        // Load workspaces
        for (let i = 0; i < workspace_list.length; i++) {
            this._workspaceUI.loadWorkspaceTile(workspace_list[i]);
        }

        // Add events
        this._onClick("create-workspace-button", () => this._workspaceUI._openCreateWorkspaceDialog());
    }

    _loadWorkspacePage({}) {
        this._loadTemplateWithTitle(
            "tmpl-workspace",
            {},
            "Tarallo - Workspace");

        // Load board lists

        // Add events
    }

    /**
     * Load a page with the list of the board tiles for each user.
     * @param display_name The user's display name.
     * @param boards The list of boards.
     * @private
     */
    _loadBoardListPage({display_name, boards}) {
        this._loadTemplateWithTitle(
            "tmpl-boardlist",
            {display_name},
            "Tarallo - Boards");

        for (let i = 0; i < boards.length; i++) {
            this._boardUI.loadBoardTile(boards[i]);
        }

        // add events
        this._onClick("new-board-btn", () => this._boardUI.createNewBoard());
        this._onClick("import-board-btn", () => this._importUI.importBoard());
        this._onClick("trello-import-btn", () => this._importUI.importFromTrello());
    }

    /**
     * Load the content of the current board page.
     * @param title The board's title.
     * @param id The board's ID.
     * @param display_name The user's display name.
     * @param label_names The list of label names.
     * @param label_colors The list of label colors.
     * @param all_color_names All available color names.
     * @param cardlists The card lists.
     * @param cards The cards.
     * @private
     */
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
        this._loadTemplateWithTitle(
            "tmpl-board",
            {title, id, display_name},
            title);

        const boardElem = this._page.getBoardElem();
        const newCardlistBtn = this._page.getAddCardListButtonElem()

        if (label_names) {
            this._labelUI.setLabelNames(label_names.split(","));
            this._labelUI.setLabelColors(label_colors.split(","));
        }

        this._labelUI.setAllColorNames(all_color_names);

        // create card lists
        for (const cardlist of this._dbLinkedListIterator(cardlists, "id", "prev_list_id", "next_list_id")) {
            // create cardlist
            const newCardlistElem = this._listUI.loadCardList(cardlist);

            // create owned cards
            const cardlistID = cardlist["id"];
            for (const cardData of this._dbLinkedListIterator(cards, "id", "prev_card_id", "next_card_id", card => card["cardlist_id"] === cardlistID)) {
                const newCardElem = this._cardUI.loadCard(cardData, newCardlistElem);
                // append the new card to the list
                newCardlistElem.appendChild(newCardElem);
            }

            // add cardlist to the board
            boardElem.insertBefore(newCardlistElem, newCardlistBtn);
        }

        // project bar drag drop events
        const projectBar = this._page.getProjectBarElem();
        projectBar.ondragover = (e) => e.preventDefault();
        projectBar.ondragenter = (e) => this._cardDnd.dragDeleteEnter(e);
        projectBar.ondragleave = (e) => this._cardDnd.dragDeleteLeave(e);
        projectBar.ondrop = (e) => this._cardDnd.dropDelete(e);

        // other events
        setEventBySelector(projectBar, '#board-title', 'onclick', () => selectAllInnerText('board-title'));
        setEventBySelector(projectBar, "#board-title", "onblur", (elem) => this._boardUI.boardTitleChanged(elem));
        setEventBySelector(projectBar, "#board-title", "onkeydown", (elem, event) => blurOnEnter(event));
        setEventBySelector(projectBar, "#board-change-bg-btn", "onclick", () => this._boardUI.changeBackground());
        setEventBySelector(projectBar, "#board-share-btn", "onclick", () => this._boardUI.shareBoard(id));
        this._onClick("add-cardlist-btn", () => this._listUI.addCardList());
    }

    /**
     * Load the closed board page.
     * @param display_name The user's display name.
     * @param title The name of the closed board.
     * @param id The ID of the closed board.
     * @private
     */
    _loadClosedBoardPage({display_name, title, id}) {
        this._loadTemplateWithTitle(
            "tmpl-closed-board",
            {display_name},
            "[Closed]" + title);

        this._onClick("closedboard-reopen-btn", () => this._boardUI.reopenBoard(id));
        this._onClick("closedboard-delete-link", () => this._boardUI.showBoardDeleteConfirmation(id));
    }

    /**
     * Load the unaccessible board page.
     * @param display_name The user's display name.
     * @param access_requested Whether or not access has already been requested.
     * @private
     */
    _loadUnaccessibleBoardPage({display_name, access_requested}) {
        this._loadTemplateWithTitle(
            "tmpl-unaccessible-board",
            {display_name},
            "Tarallo");

        if (access_requested) {
            this._page.getUnaccessibleBoardRequestButtonElem().classList.add("hidden");
            this._page.getUnaccessibleBoardWaitingLabelElem().classList.remove("hidden");
        }

        //events
        this._onClick("unaccessibleboard-request-btn", () => this._boardUI.requestBoardAccess());
    }

    /**
     * Show the register page.
     */
    _loadRegisterPage({}) {
        this._loadTemplateWithTitle(
            "tmpl-register",
            {},
            "Tarallo - Register");

        // Setup register button event
        const formNode = document.querySelector("#register-form");
        setEventBySelector(
            formNode,
            "#register-btn",
            "onclick",
            async () => {
                const username = formNode.querySelector("#register-username").value;
                const password = formNode.querySelector("#register-password").value;
                const displayName = formNode.querySelector("#login-display-name").value;

                try {
                    const response = await this._account.register(username, password, displayName);
                    this._loadLoginPage({user_name: response.username});
                    showInfoPopup("Account successfully created, please login!", "login-error");
                } catch (e) {
                    showErrorPopup("Failed to register account: " + e.message, 'register-error');
                }
            });

        setEventBySelector(formNode, "#login-page-btn", "onclick", () => this._loadLoginPage({}));
    }

    /**
     * Helper function to load a template and set the title.
     * @param templateId The ID of the template to load.
     * @param content The content for the template.
     * @param title The title of the page.
     * @private
     */
    _loadTemplateWithTitle(templateId, content, title) {
        const template = document.getElementById(templateId);
        const contentDiv = this._page.getContentElem();
        contentDiv.innerHTML = replaceHtmlTemplateArgs(template.innerHTML, content);
        document.title = title;
    }

    /**
     * Register an onClick event
     * @param id The ID of the page element.
     * @param handler The method to call on click.
     * @private
     */
    _onClick(id, handler) {
        const elem = document.getElementById(id);
        if (elem) {
            elem.addEventListener('click', (event) => handler(elem, event));
        }
    }

    /**
     * Show a loading spinner.
     * @private
     */
    _showLoadingSpinner() {
        const spinner = document.getElementById("loading-spinner");
        if (spinner) {
            spinner.style.display = "flex";
            spinner.setAttribute("aria-busy", "true");
        }
    }

    /**
     * Hide the loading spinner.
     * @private
     */
    _hideLoadingSpinner() {
        const spinner = document.getElementById("loading-spinner");
        if (spinner) {
            spinner.style.display = "none";
            spinner.removeAttribute("aria-busy");
        }
    }

    /**
     * An iterator for linked lists from a database read.
     * @param resultsArray The results array.
     * @param indexFieldName The current index field name.
     * @param prevIndexFieldName The previous index in the linked list.
     * @param nextIndexFieldName The next index in the linked list
     * @param whereCondition The condition for valid members of the linked list.
     * @returns {Generator<any, void, *>} An iterator that can be used to iterate.
     * @private
     */
    * _dbLinkedListIterator(
        resultsArray,
        indexFieldName,
        prevIndexFieldName,
        nextIndexFieldName,
        whereCondition = (result) => true) {

        // indexing of the linked list
        let curID = 0;
        const indexedResults = new Map();
        for (const item of resultsArray) {
            if (!whereCondition(item)) {
                continue;
            }

            if (item[prevIndexFieldName] === 0) {
                curID = item[indexFieldName]; // save first item id in the linked list
            }

            indexedResults.set(item[indexFieldName], item);
        }

        // iterate over the sorted cardlists
        let maxCount = indexedResults.size;
        let curCount = 0;
        while (curID !== 0) {
            if (curCount >= maxCount) {
                console.error("Invalid DB iterator (loop detected at ID = %d).", curID);
                break;
            }

            const curItem = indexedResults.get(curID);

            if (curItem === undefined) {
                console.error("Invalid DB iterator (invalid pointer detected with ID = %d).", curID);
                break;
            }

            yield curItem;

            curID = curItem[nextIndexFieldName];
            curCount++;
        }
    }

    async _login() {
        const formNode = this._page.getLoginFormElem();
        if (formNode) {
            const username = formNode.querySelector('#login-username').value;
            const password = formNode.querySelector('#login-password').value;

            try {
                await this._account.login(username, password);
                await this.getCurrentPage();
            } catch (e) {
                showErrorPopup("Login failed: " + e.message, 'login-error');
            }
        }
    }
}