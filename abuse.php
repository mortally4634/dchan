<?php
/*
 *  example.com - Report Abuse (admin-editable via ?/edit-abuse).
 */
require_once 'inc/bootstrap.php';
require_once 'inc/mod/auth.php';

imgboard_render_content_page('abuse', 'Report Abuse', IMGBOARD_DEFAULT_ABUSE);
