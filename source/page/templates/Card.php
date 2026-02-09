<?php
?>
<template id="tmpl-card">
    <div class="card" dbid="$id" id="card-$id" draggable="true">
        <img class="lazy" src="" data-src="" />
        <div class="card-labellist labellist hidden"></div>
        <h4>$title</h4>
        <div class="card-moved-date hidden">&#10149 $last_moved_date</div>
    </div>
</template>
