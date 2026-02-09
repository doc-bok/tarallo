<?php
?>
<template id="tmpl-opencard">
    <div id="card-dialog-container" class="dialog-container">
        <div class="opencard dialog scrollable-dialog vscrollable" id="opencard-$id" dbid="$id">
            <button class="dialog-close-btn close-btn dim-btn"></button>
            <h2 id="opencard-title" contenteditable="true" spellcheck="false">$title</h2>
            <div class="opencard-labellist labellist">
                <button class="opencard-add-label dim-btn">&#10010</button>
            </div>
            <div id="opencard-label-select-diag" class="label-diag dialog topmost hidden">
                <button class="opencard-label-create-btn label-row separator dim-btn">Create new</button>
                <button class="opencard-label-cancel-btn label-row dim-btn">Cancel</button>
            </div>
            <div id="opencard-content-toolbar">
                <h3>Description</h3>
                <svg class="opencard-lock-btn icon"><use href="#icon-unlocked" /></svg>
            </div>
            <div class="opencard-content" contenteditable="true" spellcheck="false">$content</div>
            <h3>
                Attachments
                <button class="add-attachment-btn contrast-btn">Add</button>
            </h3>
            <h3 class="dim-text">Ctrl+V or drop files here</h3>
            <div class="opencard-attachlist"></div>
        </div>
    </div>
</template>
