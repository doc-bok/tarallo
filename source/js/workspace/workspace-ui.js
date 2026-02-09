import {loadTemplate, setOnClickEventBySelector} from "../core/utils.js";
import {Workspace} from "./workspace.js";

/**
 * Implements the Workspace UI
 */
export class WorkspaceUI {

    constructor() {
        this._workspace = new Workspace();
    }

    /**
     * Load the workspace template
     */
    loadWorkspaceTemplate(workspace_list) {

        // Load each workspace tile.
        for (let i = 0; i < workspace_list.length; i++) {
            this.loadWorkspaceTile(workspace_list[i]);
        }

        // Setup events
        const workspaceContainer = document.getElementById('workspace-container');
        setOnClickEventBySelector(
            workspaceContainer,
            '.create-workspace-button',
            () => this._openCreateWorkspaceDialog()
        );
    }

    /**
     * Load a workspace tile for display
     */
    loadWorkspaceTile(data) {
        const workspaceListElement = document.getElementById("workspace-list");
        const newWorkspaceElement = loadTemplate("tmpl-workspace", data);

        if (workspaceListElement && newWorkspaceElement) {
            workspaceListElement.appendChild(newWorkspaceElement);
        }
    }

    _openCreateWorkspaceDialog() {
        //return await this._workspace.create()
    }
}