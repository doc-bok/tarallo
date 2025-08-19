import {loadTemplate} from "../core/utils.js";
import {showInfoPopup} from "./popup.js";

/**
 * Class that displays a dialog for sharing boards
 */
export class ShareDialog {

    /**
     * Construct an instance of the share dialog.
     * @param account The account API.
     */
    constructor(account) {
        this.account = account;
    }

    /**
     * Show the share dialog.
     * @param permissionObj The permission object as a response from the server.
     * @returns {Element|HTMLCollection} The DOM object representing the share dialog.
     */
    show(permissionObj) {
        const permissionElem = this._createPermissionEntry(permissionObj);
        this._attachHandlers(permissionObj, permissionElem);
        return permissionElem;
    }

    /**
     * Create a DOM node.
     * @param permissionObj The permission object as a response from the server.
     * @returns {Element|HTMLCollection} The DOM object representing the share dialog.
     * @private
     */
    _createPermissionEntry(permissionObj) {
        const permissionElem = loadTemplate("tmpl-share-dialog-entry", permissionObj);
        const permissionSelectElem = permissionElem.querySelector(".permission");

        // Set the selected position
        if (permissionSelectElem.querySelector(`option[value="${permissionObj.user_type}"]`)) {
            permissionSelectElem.value = permissionObj.user_type;
        }

        return permissionElem;
    }

    /**
     * Attach the handlers.
     * @param permissionObj The permission object as a response from the server.
     * @param permissionElem The DOM node to wire in the events.
     * @private
     */
    _attachHandlers(permissionObj, permissionElem) {
        const selectElem = permissionElem.querySelector(".permission");
        selectElem.onchange = () => this._userPermissionChanged(selectElem, permissionObj.user_id);
    }

    /**
     * Called when a user permission changes in the UI.
     * @param selectElem The selection element.
     * @param userId The User ID of the user that changed.
     * @returns {Promise<void>} A promise updated when the call completes.
     * @private
     */
    async _userPermissionChanged(selectElem, userId) {
        const userType = selectElem.value;
        selectElem.disabled = true;

        try {
            const response = await this.account.setUserPermission(userId, userType);
            this._onUserPermissionUpdated(response);
        } catch (e) {
            showInfoPopup("Failed to update permission: " + e.message, "share-dialog-popup")
            selectElem.value = selectElem.dataset.userType;
        } finally {
            selectElem.disabled = false;
        }
    }

    /**
     * Called after a user permission is updated
     * @param response The response from the server.
     * @private
     */
    _onUserPermissionUpdated(response) {
        // check if the share dialog is still open
        const shareDialog = document.getElementById("share-dialog");
        if (!shareDialog) {
            return;
        }

        // search for the permission selection box and update it

        const selectElem = shareDialog.querySelector(`select[data-user-id="${response.user_id}"]`);
        if (selectElem) {
            selectElem.value = response.user_type;
            selectElem.dataset.userType = response.user_type;
        }

        showInfoPopup("Permissions updated.", "share-dialog-popup");
    }
}