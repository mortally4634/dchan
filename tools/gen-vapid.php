<?php
/*
 * Generate a VAPID key pair for offline Web Push staff notifications.
 * Run once from the project root (inside the php container):
 *   composer require minishlink/web-push:^7.0
 *   php tools/gen-vapid.php
 * Put the printed lines in inc/secrets.php (or set the VICHAN_VAPID_* env vars), then enable
 * "Online + offline" in the staff notifications bell. Keep the private key secret.
 */
require __DIR__ . '/../vendor/autoload.php';

if (!class_exists('Minishlink\\WebPush\\VAPID')) {
	fwrite(STDERR, "minishlink/web-push is not installed. Run: composer require minishlink/web-push:^7.0\n");
	exit(1);
}

$keys = Minishlink\WebPush\VAPID::createVapidKeys();
echo "// Web Push (VAPID) keys - generated " . gmdate('Y-m-d H:i') . " UTC. Keep the private key secret.\n";
echo "\$config['imgboard_vapid']['public']  = '" . $keys['publicKey'] . "';\n";
echo "\$config['imgboard_vapid']['private'] = '" . $keys['privateKey'] . "';\n";
