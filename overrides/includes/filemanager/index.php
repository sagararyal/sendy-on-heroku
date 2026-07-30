<?php
	// File browser disabled in this build.
	//
	// Sendy 7.1.1 replaced the old TinyFileManager backend (tinyfilemanager.php
	// + sendy-config.php, both deleted upstream) with this single entry point.
	// The rewrite is hardened but still purely local-filesystem: it browses
	// includes/../../uploads on the app container. On this deployment assets
	// live in S3/R2, and the container filesystem is ephemeral, so the browser
	// would only ever show an empty or misleading local directory.
	//
	// Uploads still work via the GrapesJS / CKEditor paths in
	// includes/create/upload.php and land in the configured S3/R2 bucket.
	// To browse stored assets, use the provider dashboard (Cloudflare R2 /
	// AWS S3 console).
	//
	// The matching "Files" button is suppressed in
	// includes/helpers/file-manager-button.php. This file is the second layer:
	// it blocks the direct URL, which the button override alone does not.
	//
	// To re-enable, delete this override and let stock ship — but expect it to
	// list the local uploads dir, not the bucket.
	//
	// [OVERRIDE-STRATEGY: clobber] — returns 410 and exits before any stock
	// code runs, so upstream changes to the rest of this file are moot.
	// Deliberately does not include functions.php or login/auth.php: there is
	// nothing here worth authenticating for.

	http_response_code(410);
	header('Content-Type: text/html; charset=utf-8');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: SAMEORIGIN');
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>File browser disabled</title>
<style>body{font-family:system-ui,sans-serif;padding:2rem;color:#444;line-height:1.5}</style>
</head><body>
<h2>File browser is disabled</h2>
<p>Uploaded files are stored in S3/R2 and can be browsed from your cloud provider's dashboard.</p>
</body></html>
<?php
	exit;
?>
