<?php
?>
<!-- Template for a board tile -->
<template id="tmpl-boardtile">
    <div class="boardtile" style="background-image: url('$background_thumb_url')" id="board-tile-$id">
        <div class="backdrop">
            <svg class="delete-board-btn contrast-icon"><use href="#icon-trashbin" /></svg>
            <a href="?board_id=$id">
                <h4>$title</h4>
                <p class="last-edit-date">Last edit: $last_modified_date</p>
            </a>
        </div>
    </div>
</template>
<template id="tmpl-closed-boardtile">
    <div class="boardtile closed" id="board-tile-$id">
        <a href="?board_id=$id">
            <h4>$title</h4>
        </a>
    </div>
</template>
<template id="tmpl-board">
    <div id="board">
        <div id="add-cardlist-btn" class="cardlist">
            <h3>&#10010 Add another list</h3>
        </div>
    </div>
</template>
<template id="tmpl-closed-board">
    <div class="centered-container">
        <div id="closedboard" class="centered-dialog dialog">
            <h1>This board is closed</h1>
            <button id="closedboard-reopen-btn" class="contrast-btn">Reopen board</button>
            <p id="closedboard-delete-label" class="hidden">All the board cards and attachments will be deleted, and you won't be able to acces them again, are you sure?</p>
            <a id="closedboard-delete-link" class="inline-link" href="#">Permanently delete board</a>
        </div>
    </div>
</template>
<template id="tmpl-loading-dialog">
    <div class="centered-container">
        <div id="loading-dialog" class="centered-dialog dialog">
            <h1>$title</h1>
            <p class="separator text-center">$msg</p>
            <div class="loader separator"></div>
        </div>
    </div>
</template>
