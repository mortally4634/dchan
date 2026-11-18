<?php

// Serve a random banner image from static/banners/. Only real image files are
// considered, so placeholder files like .gitkeep are never served.
$files = @scandir('static/banners/', SCANDIR_SORT_NONE) ?: [];
$files = array_filter($files, function ($f) {
	return preg_match('/\.(png|jpe?g|gif|webp)$/i', $f);
});
$files = array_values($files);

if (!$files) {
	http_response_code(404);
	exit;
}

$name = $files[array_rand($files)];
header("Location: /static/banners/$name", true, 307);
header('Cache-Control: no-cache');
