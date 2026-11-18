<?php
/*
 *  example.com - Privacy Policy (admin-editable via ?/edit-privacy).
 */
require_once 'inc/bootstrap.php';
require_once 'inc/mod/auth.php';

imgboard_render_content_page('privacy', 'Privacy Policy', IMGBOARD_DEFAULT_PRIVACY);
