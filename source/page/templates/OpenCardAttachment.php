<?php
?>
<!-- Template for a card attachment -->
<template id="tmpl-opencard-attachment">
    <div class="opencard-attachment" id="attachment-$id">
        <a class="opencard-attachment-link" href="" target="_blank">
            <div class="ext">$extension</div>
            <img src="" />
            <svg class="dim-icon"><use href="#icon-attachment" /></svg>
        </a>
        <div class="loader"></div>
        <div class="opencard-attachment-desc">
            <p id="attachment-$id-title" class="attachment-name" contenteditable="true">$name</p>
            <div class="toolbar">
                <button class="copy-markup-btn dim-btn thin-btn">Copy Markup</button>
            </div>
        </div>
        <div class="opencard-attachment-btns">
            <svg class="opencard-attachment-delete-btn opencard-attachment-btn icon"><use href="#icon-trashbin" /></svg>
        </div>
    </div>
</template>
