<?php

// Define the application path
if (getenv('APP_PATH')) {
	define('APP_PATH', getenv('APP_PATH'));
} else {
	die('Error: APP_PATH environment variable is not set.');
}

/* Domain of cookie (99.99% chance you don't need to edit this at all) */
define('COOKIE_DOMAIN', getenv('COOKIE_DOMAIN') ?: '');

/* 
Change the database character set to something that supports the language you'll
be using. Example, set this to utf16 if you use Chinese or Vietnamese characters
*/

$charset = getenv('CHARSET') ?:'utf8mb4';

// Determine the database connection URL
$dbUrl = getenv('DATABASE_URL') ?: getenv('JAWSDB_URL');

// Check if a valid database URL is available
if ($dbUrl) {
	$dbParts = parse_url($dbUrl);

	$dbHost = $dbParts['host'];
	$dbUser = $dbParts['user'];
	$dbPass = $dbParts['pass'];
	$dbName = ltrim($dbParts['path'], '/');
	$dbPort = isset($dbParts['port']) ? $dbParts['port'] : 3306; // Default MySQL port
} else {
	die('Error: No database configuration found. Please set DATABASE_URL or JAWSDB_URL in your environment.');
}

// Define database constants
define('DB_HOST', $dbHost);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
define('DB_NAME', $dbName);
define('DB_PORT', $dbPort);

// Optional: AWS S3 or Bucketeer configuration
if ((getenv('S3_ACCESS_KEY_ID') ?: getenv('BUCKETEER_AWS_ACCESS_KEY_ID')) && 
	(getenv('S3_SECRET_ACCESS_KEY') ?: getenv('BUCKETEER_AWS_SECRET_ACCESS_KEY')) && 
	(getenv('S3_BUCKET_NAME') ?: getenv('BUCKETEER_BUCKET_NAME'))) {
	
	define('S3_ACCESS_KEY_ID', getenv('S3_ACCESS_KEY_ID') ?: getenv('BUCKETEER_AWS_ACCESS_KEY_ID'));
	define('S3_SECRET_ACCESS_KEY', getenv('S3_SECRET_ACCESS_KEY') ?: getenv('BUCKETEER_AWS_SECRET_ACCESS_KEY'));
	define('S3_BUCKET_NAME', getenv('S3_BUCKET_NAME') ?: getenv('BUCKETEER_BUCKET_NAME'));
	define('S3_REGION', getenv('S3_REGION') ?: (getenv('BUCKETEER_AWS_REGION') ?: 'auto'));
	define('S3_PROVIDER', getenv('S3_PROVIDER') ?: 'aws'); // Currently supported aws and r2
	define('S3_CDN_URL', getenv('S3_CDN_URL') ?: ''); // Currently supported for r2
	define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: 'https://s3.amazonaws.com'); // Default to AWS S3 endpoint if not set
} else {
	// Handle cases where S3 configuration is missing
	die('Error: S3 configuration is incomplete. Please check your S3 or Bucketeer environment variables. and set the following:S3_ACCESS_KEY_ID,S3_SECRET_ACCESS_KEY,S3_BUCKET_NAME,');
}
?>