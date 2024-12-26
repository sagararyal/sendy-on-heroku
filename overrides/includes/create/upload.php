<?php
// Adapted from: https://gist.github.com/fowkswe/3db02f1d355cab1dc300?permalink_comment_id=5106806#gistcomment-5106806

error_reporting(E_ALL);
ini_set('display_errors', 1);

include('../functions.php');
include('../login/auth.php');
require '../../../vendor/autoload.php'; // Ensure AWS SDK is included via Composer
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Init
$app = isset($_GET['app']) && is_numeric($_GET['app']) ? mysqli_real_escape_string($mysqli, (int)$_GET['app']) : exit;
$file = $_FILES['upload']['tmp_name'];
$file_name = basename($_FILES['upload']['name']); // Prevent path traversal
$extension_explode = explode('.', $file_name);
$extension = strtolower(end($extension_explode));
$extension2 = strtolower($extension_explode[count($extension_explode) - 2]);

if ($extension2 === 'php' || $file_name === '.htaccess') {
	exit; // Prevent dangerous file uploads
}

if (!file_exists($file) || !is_readable($file)) {
	echo json_encode([
		'status' => 'error',
		'message' => 'File is missing or not readable.',
	]);
	exit;
}

$time = time();

// Check filetype
$allowed = ['jpeg', 'jpg', 'gif', 'png', 'pdf'];
if (in_array($extension, $allowed)) {
	// Get AWS/S3 credentials from config.php
	$awsAccessKey = S3_ACCESS_KEY_ID; // Defined in config.php
	$awsSecretKey = S3_SECRET_ACCESS_KEY; // Defined in config.php
	$bucketName = S3_BUCKET_NAME; // Defined in config.php
	$endpoint = S3_ENDPOINT; // Defined in config.php
	$region = S3_REGION; // Defined in config.php

	// Log the values of the variables before initializing the S3 client
	// error_log("Initializing S3 Client with the following parameters:");
	// error_log("AWS Access Key: " . $awsAccessKey);
	// error_log("AWS Secret Key: [HIDDEN]");
	// error_log("Bucket Name: " . $bucketName);
	// error_log("Endpoint: " . $endpoint);
	// error_log("Region: " . $region);

	// Initialize S3 client
	$s3 = new S3Client([
		'version' => 'latest',
		'region' => $region,
		'credentials' => [
			'key' => $awsAccessKey,
			'secret' => $awsSecretKey,
		],
		'endpoint' => $endpoint, // Generic S3 Endpoint
		//'debug' => true, // Enable SDK debug logs
	]);

	// Generate a unique filename for the S3 bucket
	$s3Filename = $time . '_' . $file_name;
	$uploadsPath = 'uploads/';

	try {
		// Upload file to S3
		$result = $s3->putObject([
			'Bucket' => $bucketName,
			'Key' => $uploadsPath . $s3Filename,
			'SourceFile' => $file,
			'ACL' => 'public-read',
			'ContentType' => mime_content_type($file),
			'Metadata' => [
				'Cache-Control' => 'max-age=3600', // Cache files for 1 hour
			],
		]);

		// Construct the file URL
		$fileUrl = $result['ObjectURL']; // Default URL from S3
		
		// Check if CDN_URL exists and overrides url with CDN URL
		// CDN URL needs to persist.  If you change CDN URL for a prod app, please find and replace the old CDN URL from database.
		// For R2, use custom domain of your public bucket: https://developers.cloudflare.com/r2/buckets/public-buckets/
		
		if (S3_PROVIDER === 'r2' && !empty(S3_CDN_URL)) {
			// Parse the file URL to swap the hostname
			$parsedUrl = parse_url($fileUrl);
			$cdnUrl = rtrim(S3_CDN_URL, '/'); // Ensure CDN_URL does not end with a slash
			$fileUrl = $cdnUrl . $parsedUrl['path'];
		}
		
		error_log("File uploaded successfully: " . $fileUrl);
		
		
		// Get the CKEditor function number and other parameters
		$funcNum = (int)$_GET['CKEditorFuncNum'];
		// $CKEditor = $_GET['CKEditor'];
		// $langCode = $_GET['langCode'];

// 		// Get custom domain and app settings from database
// 		$q = 'SELECT custom_domain, custom_domain_protocol, custom_domain_enabled FROM apps WHERE id = ' . $app;
// 		$r = mysqli_query($mysqli, $q);
// 		if (!$r) {
// 			error_log("Database query failed: " . mysqli_error($mysqli));
// 			echo json_encode([
// 				'status' => 'error',
// 				'message' => 'Failed to retrieve app settings from the database.',
// 			]);
// 			exit;
// 		}
// 
// 		if (mysqli_num_rows($r) > 0) {
// 			while ($row = mysqli_fetch_array($r)) {
// 				$custom_domain = $row['custom_domain'];
// 				$custom_domain_protocol = $row['custom_domain_protocol'];
// 				$custom_domain_enabled = $row['custom_domain_enabled'];
// 				if ($custom_domain != '' && $custom_domain_enabled) {
// 					$parse = parse_url(APP_PATH);
// 					$domain = $parse['host'];
// 					$protocol = $parse['scheme'];
// 					$app_path = str_replace($domain, $custom_domain, APP_PATH);
// 					$app_path = str_replace($protocol, $custom_domain_protocol, $app_path);
// 				} else {
// 					$app_path = APP_PATH;
// 				}
// 			}
// 		}

		// Return file URL to CKEditor
		echo "<script type='text/javascript'>window.parent.CKEDITOR.tools.callFunction($funcNum, '" . htmlspecialchars($fileUrl, ENT_QUOTES) . "', '');</script>";

	} catch (AwsException $e) {
		// Handle errors during upload
		error_log("AWS Exception: " . $e->getMessage());
		error_log("Request ID: " . $e->getAwsRequestId());
		error_log("Error Type: " . $e->getAwsErrorType());
		error_log("Error Code: " . $e->getAwsErrorCode());
		echo json_encode([
			'status' => 'error',
			'message' => 'Failed to upload file.',
		]);
		exit;
	}

	// Clean up the temporary file
	if (file_exists($file)) {
		unlink($file);
	}
} else {
	// Invalid file type
	echo json_encode([
		'status' => 'error',
		'message' => 'Invalid file type. Allowed types: ' . implode(', ', $allowed),
	]);
	exit;
}
?>