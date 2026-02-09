<?php
?>
<template id="tmpl-opencard-label-edit-diag">
    <div id="opencard-label-edit-diag" class="label-diag topmost dialog" dbid="$index">
        <button class="opencard-label label-row label $color" color="$color">$name</button>
        <label class="label-row separator">Label name</label>
        <input type="text" id="opencard-label-edit-name" class="label-row" value="$name" />
        <label class="label-row separator">Label color</label>
        <div id="opencard-label-edit-color-list" class="label-row labellist">
        </div>
        <button id="opencard-label-edit-cancel-btn" class="label-row separator dim-btn">Cancel</button>
        <button id="opencard-label-edit-delete-btn" class="label-row dim-btn" confirmed="0">Delete label</button>
        <button id="opencard-label-edit-save-btn" class="label-row contrast-btn">Save label</button>
    </div>
</template>
