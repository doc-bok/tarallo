<?php
?>
<!-- The Project Bar -->
<div id="project-bar">

    <div id="project-bar-left" class="projectbar-tile">
        <h2 id="board-title" contenteditable="true" spellcheck="false">$title</h2>
        <h3>
            <a class="inline-link" href="?">Back to Boards</a> |
            <a id="board-change-bg-btn" class="inline-link" href="#">Change Background</a> |
            <a id="board-share-btn" class="inline-link" href="#">Edit Permissions</a> |
            <a id="board-export-btn" class="inline-link" href="php/api.php?OP=ExportBoard&board_id=$id">Export Board</a>
        </h3>
    </div>

    <div id="project-bar-middle" class="projectbar-tile">
        <svg class="bin-icon icon"><use href="#icon-trashbin" /></svg>
    </div>

    <div id="project-bar-closed" class="projectbar-tile">
        <h2 id="board-title-closed">$title</h2>
        <h3>
            <a class="inline-link" href="?">Back to Boards</a>
        </h3>
    </div>

    <div id="project-bar-right" class="projectbar-tile">
        <h3>
            Logged in as $display_name |
            <a id="project-bar-logout-btn" class="inline-link" href="#">Log Out</a>
        </h3>
    </div>
</div>
