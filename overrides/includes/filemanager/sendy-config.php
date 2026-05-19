<?php
	// File browser disabled in this build. Uploads still work via the
	// GrapesJS / CKEditor paths in includes/create/upload.php and land in
	// the configured S3/R2 bucket. To browse stored assets, use the
	// provider dashboard (Cloudflare R2 / AWS S3 console).
	//
	// To re-enable, restore the S3-stream-wrapper version of this file from
	// git history and resolve the directory-listing issues.

	http_response_code(410);
	header('Content-Type: text/html; charset=utf-8');
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
