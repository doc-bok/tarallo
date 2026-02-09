<?php
?>

<!-- Template loaded for first startup -->
<template id="tmpl-firststartup">
    <div class="centered-container">
        <form id="first-startup-diag" class="centered-dialog dialog">
            <h1>Welcome to Tarallo!</h1>
            <p class="dim-text">Your Tarallo instance has just been initialized.</p>
            <p class="separator">A new administrator account has been created:</p>
            <label>
                Username
                <input value="$admin_user" readonly>
            </label>
            <label>
                Password
                <input value="$admin_pass" readonly>
            </label>
            <p class="popup persistent warning text-center">NB: This account credentials won't be shown again so save them!</p>
            <button type="submit" id="continue-btn" class="contrast-btn separator">Continue to Tarallo</button>
        </form>
    </div>
</template>
