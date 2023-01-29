<?php

/*
 * ==========================================================
 * POST.PHP
 * ==========================================================
 *
 * Messenger response listener. This file receive the Facebook Messenger messages of the agents forwarded by board.support. This file requires the Messenger App.
 * © 2017-2022 board.support. All rights reserved.
 *
 */

$raw = file_get_contents('php://input');
if ($raw) {
    require('../../include/functions.php');
    sb_messenger_listener(json_decode($raw, true));
}
die();

?>