<?php
?>
<head>
    <meta charset="UTF-8">
    <title>Tarallo</title>
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon.png">
    <link rel="icon" type="image/png" sizes="64x64" href="images/favicon-large.png">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0" />
    <link href="css/tarallo.css" rel="stylesheet" type="text/css" />

    <?php

    // Include templates
    require_once 'templates/FirstStartup.php';
    require_once 'templates/Login.php';
    require_once 'templates/InstanceMessage.php';
    require_once 'templates/Register.php';
    require_once 'templates/Home.php';
    require_once 'templates/Workspace.php';
    require_once 'templates/BoardList.php';
    require_once 'templates/BoardTile.php';
    require_once 'templates/UnaccessibleBoard.php';
    require_once 'templates/CardList.php';
    require_once 'templates/Card.php';
    require_once 'templates/CardLabel.php';
    require_once 'templates/OpenCard.php';
    require_once 'templates/OpenCardLabelEditDialog.php';
    require_once 'templates/OpenCardLabelEditColorTile.php';
    require_once 'templates/OpenCardAttachment.php';
    require_once 'templates/ShareDialog.php';
    require_once 'templates/ShareDialogEntry.php';

    ?>

    <svg style="display:none">
        <?php

        // SVG Icons
        require_once 'svg/Trashbin.php';
        require_once 'svg/Attachment.php';
        require_once 'svg/Locked.php';
        require_once 'svg/Unlocked.php';
        require_once 'svg/Copy.php';

        ?>
    </svg>
</head>
