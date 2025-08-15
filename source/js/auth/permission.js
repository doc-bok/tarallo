/**
 * Class to handle permission operations
 */
import {serverAction} from "../core/server.js";
import {ShowInfoPopup} from "../core/popup.js";

export class Permission {

    /**
     * Load a user's permission entry
     */
    loadUserPermissionEntry(permissionObj) {
        const permissionElem = TaralloUtils.LoadTemplate("tmpl-share-dialog-entry", permissionObj);

        const permissionSelectElem = permissionElem.querySelector(".permission");
        permissionSelectElem.onchange = () => this._userPermissionChanged(permissionSelectElem, permissionObj["user_id"]);
        const selectedOptionElem = permissionSelectElem.querySelector(`option[value='${permissionObj["user_type"]}']`);
        selectedOptionElem.setAttribute("selected", "true");

        return permissionElem;
    }

    /**
     * Called when a user permission changes
     */
    _userPermissionChanged(selectElem, userID) {
        const curUserType = selectElem.getAttribute("dbvalue");
        const requestedUserType = selectElem.value;

        // revert the change (wait confirmation from the server)
        this._setUiPermission(selectElem, curUserType);

        // request permission change to the server
        let args = [];
        args["user_id"] = userID;
        args["user_type"] = requestedUserType;
        serverAction("SetUserPermission", args, (response) => this._onUserPermissionUpdated(response), "share-dialog-popup");
    }

    /**
     * Set a UI permission
     */
    _setUiPermission(selectElem, userType) {
        for (let i = 0; i < selectElem.options.length; i++) {
            if (selectElem.options[i].value === userType) {
                selectElem.selectedIndex = i;
                break;
            }
        }
    }

    /**
     * Called after a user permission is updated
     */
    _onUserPermissionUpdated(jsonResponseObj) {
        // check if the share dialog is still open
        const shareDialog = document.getElementById("share-dialog");
        if (!shareDialog) {
            return;
        }

        // search for the permission selection box and update it
        const selectElem = shareDialog.querySelector(`select[dbuser="${jsonResponseObj["user_id"]}"]`);
        this._setUiPermission(selectElem, jsonResponseObj["user_type"]);
        selectElem.setAttribute("dbvalue", jsonResponseObj["user_type"]); // update cached db value
        ShowInfoPopup("Permissions updated.", "share-dialog-popup");
    }
}