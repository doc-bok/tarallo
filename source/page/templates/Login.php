<?php
?>
<!-- Template loaded for login page -->
<template id="tmpl-login">
    <div class="centered-container">
        <form id="login-form" class="centered-dialog dialog" onsubmit="return false;">
            <label>
                Username
                <input type="text" id="login-username" placeholder="Username" value="$user_name" />
            </label>
            <label>
                Password
                <input type="password" id="login-password" placeholder="Password" />
            </label>
            <button type="submit" id="login-btn" class="contrast-btn separator full-width">Login</button>
            <a id="register-page-btn" class="inline-link separator" href="#">Register a New Account</a>
            <p id="login-error" class="popup separator"></p>
        </form>
    </div>
</template>
