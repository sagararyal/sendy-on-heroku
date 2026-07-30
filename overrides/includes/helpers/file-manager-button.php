<?php
	// "Files" button suppressed — this file intentionally renders nothing.
	//
	// The stock file manager is local-filesystem only and doesn't list objects
	// from S3/R2, so we hide the entry point. Uploads still work via the inline
	// image upload in the campaign editor (GrapesJS / CKEditor) and land in the
	// configured bucket.
	//
	// This hides the button; it does not block the backend URL. That is done
	// separately in includes/filemanager/index.php, which returns 410.
	// (Sendy 7.1.1 deleted the old tinyfilemanager.php + sendy-config.php and
	// moved the entry point to includes/filemanager/index.php.)
	//
	// To re-enable, restore this file from stock and drop the
	// includes/filemanager/index.php override.
	//
	// [OVERRIDE-STRATEGY: clobber] — replaces stock entirely and emits no
	// markup, so upstream changes to the button (URL, asset versioning, CSS)
	// are moot. Nothing to port.
?>
