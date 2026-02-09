<?php
?>
<!-- Template for Card List page -->
<template id="tmpl-cardlist">
    <div class="cardlist vscrollable" dbid="$id" id="cardlist-$id" draggable="true">
        <div class="cardlist-start">
            <div class="cardlist-title">
                <h3 id="card-list-title-$id" contenteditable="true" spellcheck="false">$name</h3>
            </div>
            <div class="addcard-btn addcard-ui"><p>&#10010 Add a card</p></div>
            <div class="card editcard-card editcard-ui hidden" contenteditable="true" placeholder="Enter a title for this card..."></div>
            <div class="editcard-container editcard-ui hidden">
                <button class="editcard-submit-btn contrast-btn">Add card</button>
                <button class="editcard-cancel-btn close-btn dim-btn"></button>
            </div>
        </div>
    </div>
</template>
