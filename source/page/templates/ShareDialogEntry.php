<?php
?>
<template id="tmpl-share-dialog-entry">
    <div class="share-dialog-entry">
        <label class="share-dialog-item $class_list:optional">$display_name</label>
        <div class="share-dialog-item">
            <select class="permission" data-user-type="$user_type" data-user-id="$user_id" title="$hover_text:optional">
                <option value="0">Owner</option>
                <option value="2">Moderator</option>
                <option value="6">Member</option>
                <option value="8">Observer</option>
                <option value="9">Guest</option>
                <option value="10">Blocked</option>
            </select>
        </div>
    </div>
</template>
