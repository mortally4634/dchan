<?php
/*
 *  example.com - Terms of Service (admin-editable via ?/edit-tos).
 */
require_once 'inc/bootstrap.php';
require_once 'inc/mod/auth.php';

imgboard_render_content_page('tos', 'Terms of Service', IMGBOARD_DEFAULT_TOS);
