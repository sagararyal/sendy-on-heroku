<?php
	// Override of v7 includes/create/upload.php that writes uploads to
	// S3 / Cloudflare R2 instead of the local filesystem.
	//
	// To keep this file diffable against future Sendy v7.x stock releases,
	// we preserve the original structure and only:
	//   - comment out lines replaced by S3 logic (prefix `// [S3] stock:`)
	//   - mark our additions with `// [S3]`

	include('../functions.php');
	include('../login/auth.php');
	require_once __DIR__.'/../../../vendor/autoload.php'; // [S3]
	use Aws\S3\S3Client;                                  // [S3]

	//Init
	$app = isset($_GET['app']) && is_numeric($_GET['app']) ? mysqli_real_escape_string($mysqli, (int)$_GET['app']) : exit;
	$is_grapesjs_upload = isset($_FILES['files']) || (isset($_GET['response']) && $_GET['response']=='json');
	$files_key = $is_grapesjs_upload ? 'files' : 'upload';
	if(!isset($_FILES[$files_key])) exit;

	$time = time();
	// [S3] stock: local-FS path setup not needed when uploading to bucket.
	// $uploads_path = sendy_uploads_path($app, '', __DIR__.'/../../uploads', true);
	// if(!is_writable($uploads_path))
	//     @chmod($uploads_path, 0777);
	// if(!is_writable($uploads_path))
	//     sendy_upload_json_error(_('Unable to upload image. Please ensure the /uploads/ folder permission is set to 777.'));
	$uploads_prefix = 'uploads/'.$app.'/'; // [S3] per-app key prefix in bucket

	//get smtp settings
	$app_path = APP_PATH;
	$q = 'SELECT custom_domain, custom_domain_protocol, custom_domain_enabled FROM apps WHERE id = '.$app.' AND userID = '.get_app_info('main_userID').' LIMIT 1';
	$r = mysqli_query($mysqli, $q);
	if ($r && mysqli_num_rows($r) > 0)
	{
		while($row = mysqli_fetch_array($r))
		{
			$custom_domain = $row['custom_domain'];
			$custom_domain_protocol = $row['custom_domain_protocol'];
			$custom_domain_enabled = $row['custom_domain_enabled'];
			if($custom_domain!='' && $custom_domain_enabled)
			{
				$parse = parse_url(APP_PATH);
				$domain = $parse['host'];
				$protocol = $parse['scheme'];
				$app_path = str_replace($domain, $custom_domain, APP_PATH);
				$app_path = str_replace($protocol, $custom_domain_protocol, $app_path);
			}
			else $app_path = APP_PATH;
		}
	}
	else sendy_upload_json_error(_('Unable to upload image. Brand not found.'));

	// [S3] Initialize S3 / R2 client and resolve public CDN base URL.
	$is_r2 = defined('S3_PROVIDER') && S3_PROVIDER === 'r2';
	$s3 = new S3Client([
		'version'                 => 'latest',
		'region'                  => S3_REGION,
		'credentials'             => ['key' => S3_ACCESS_KEY_ID, 'secret' => S3_SECRET_ACCESS_KEY],
		'endpoint'                => S3_ENDPOINT,
		'use_path_style_endpoint' => $is_r2,
	]);
	$cdn_base = defined('S3_CDN_URL') && S3_CDN_URL !== ''
		? rtrim(S3_CDN_URL, '/')
		: rtrim(S3_ENDPOINT, '/').'/'.S3_BUCKET_NAME;

	if($is_grapesjs_upload)
	{
		$file_names = is_array($_FILES[$files_key]['name']) ? $_FILES[$files_key]['name'] : array($_FILES[$files_key]['name']);
		$file_tmps = is_array($_FILES[$files_key]['tmp_name']) ? $_FILES[$files_key]['tmp_name'] : array($_FILES[$files_key]['tmp_name']);
		$file_errors = is_array($_FILES[$files_key]['error']) ? $_FILES[$files_key]['error'] : array($_FILES[$files_key]['error']);
		$uploaded_assets = array();

		for($i=0;$i<count($file_names);$i++)
		{
			$file_name = $file_names[$i];
			$file = $file_tmps[$i];
			$file_error = isset($file_errors[$i]) ? (int)$file_errors[$i] : UPLOAD_ERR_OK;
			if($file_name=='' || $file=='') continue;
			if($file_error!==UPLOAD_ERR_OK) continue;

			$extension_explode = explode('.', $file_name);
			$extension = strtolower($extension_explode[count($extension_explode)-1]);
			$extension2 = count($extension_explode) > 1 ? strtolower($extension_explode[count($extension_explode)-2]) : '';
			if($extension2=='php' || $file_name=='.htaccess') continue;

			$allowed = array("jpeg", "jpg", "gif", "png", "webp");
			if(in_array($extension, $allowed) && sendy_upload_is_valid_image($file, $extension))
			{
				$upload_name = $time.'-'.ran_string(6, 6, false, false, true).'.'.$extension;
				// [S3] stock: wrote to local FS, returned local URL.
				// if(move_uploaded_file($file, $uploads_path.'/'.$upload_name))
				//     $uploaded_assets[] = array('src' => sendy_uploads_url($app_path, $app, $upload_name));
				$key = $uploads_prefix.$upload_name;                                   // [S3]
				if(sendy_s3_put($s3, $file, $key))                                     // [S3]
					$uploaded_assets[] = array('src' => $cdn_base.'/'.$key);           // [S3]
			}
		}

		if(!count($uploaded_assets))
			sendy_upload_json_error(_('Please upload only these file formats: jpeg, jpg, gif, png or webp.'));

		header('Content-Type: application/json');
		echo json_encode(array('data' => $uploaded_assets));
	}
	else
	{
		$file = $_FILES[$files_key]['tmp_name'];
		$file_name = $_FILES[$files_key]['name'];
		$extension_explode = explode('.', $file_name);
		$extension = $extension_explode[count($extension_explode)-1];
		$extension2 = count($extension_explode) > 1 ? $extension_explode[count($extension_explode)-2] : '';
		if($extension2=='php' || $file_name=='.htaccess') exit;

		//Check filetype
		$allowed = array("jpeg", "jpg", "gif", "png");
		if(in_array(strtolower($extension), $allowed) && sendy_upload_is_valid_image($file, strtolower($extension))) //if file is an image, allow upload
		{
			// [S3] stock: wrote to local FS.
			// move_uploaded_file($file, $uploads_path.'/'.$time.'.'.$extension);
			$key = $uploads_prefix.$time.'.'.$extension;                              // [S3]
			if(!sendy_s3_put($s3, $file, $key)) exit;                                 // [S3]

			// Required: anonymous function reference number as explained above.
			$funcNum = (int)$_GET['CKEditorFuncNum'] ;

			// Check the $_FILES array and save the file. Assign the correct path to a variable ($url).
			// [S3] stock: $url = sendy_uploads_url($app_path, $app, $time.'.'.$extension);
			$url = $cdn_base.'/'.$key;                                                // [S3]
			// Usually you will only assign something here if the file could not be uploaded.
			$message = '';

			echo "<script type='text/javascript'>window.parent.CKEDITOR.tools.callFunction($funcNum, '$url', '$message');</script>";
		}
		else exit;
	}

	function sendy_upload_is_valid_image($file, $extension)
	{
		if(!is_uploaded_file($file))
			return false;

		$image_info = @getimagesize($file);
		if($image_info===false || !isset($image_info['mime']))
			return false;

		$allowed_mimes = array(
			'jpeg' => array('image/jpeg'),
			'jpg' => array('image/jpeg'),
			'gif' => array('image/gif'),
			'png' => array('image/png'),
			'webp' => array('image/webp')
		);

		return isset($allowed_mimes[$extension]) && in_array($image_info['mime'], $allowed_mimes[$extension]);
	}

	function sendy_upload_json_error($message)
	{
		if(isset($_GET['response']) && $_GET['response']=='json')
		{
			header('Content-Type: application/json');
			http_response_code(400);
			echo json_encode(array('error' => $message));
			exit;
		}

		exit;
	}

	// [S3] helper: upload a local tmp file to the configured bucket.
	function sendy_s3_put($s3, $tmp_path, $key)
	{
		$params = [
			'Bucket'       => S3_BUCKET_NAME,
			'Key'          => $key,
			'SourceFile'   => $tmp_path,
			'ContentType'  => mime_content_type($tmp_path) ?: 'application/octet-stream',
			'CacheControl' => 'public, max-age=3600',
		];
		// R2 rejects ACL headers (InvalidArgument). On R2, public access is
		// configured at bucket / custom-domain level instead of per-object.
		if(!(defined('S3_PROVIDER') && S3_PROVIDER === 'r2')) {
			$params['ACL'] = 'public-read';
		}
		try {
			$s3->putObject($params);
			return true;
		} catch (Aws\Exception\AwsException $e) {
			error_log('S3 putObject failed: '.$e->getAwsErrorCode().' '.$e->getMessage());
			return false;
		}
	}
?>
