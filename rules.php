<?php
/*
 *  example.com - Rules page (admin-editable via ?/edit-rules).
 */
require_once 'inc/bootstrap.php';
require_once 'inc/mod/auth.php';

imgboard_render_content_page('rules', 'Rules', IMGBOARD_DEFAULT_RULES);
