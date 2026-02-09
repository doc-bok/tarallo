<?php
?>
<!-- Template loaded for the register page -->
<template id="tmpl-register">
    <div class="centered-container">
        <form id="register-form" class="centered-dialog dialog"  onsubmit="return false;">
            <label>
                Username
                <input type="text" id="register-username" placeholder="Username" />
            </label>
            <label>
                Display Name
                <input type="text" id="login-display-name" placeholder="Name Surname" />
            </label>
            <label>
                Password
                <input type="password" id="register-password" placeholder="Password" />
            </label>
            <button type="submit" id="register-btn" class="contrast-btn separator full-width">Register</button>
            <a id="login-page-btn" class="inline-link separator" href="#">Go Back to Login Page</a>
            <p id="register-error" class="popup separator"></p>
        </form>
    </div>
</template>
