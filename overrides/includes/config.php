<?php
// Full override of stock Sendy's includes/config.php — not a v7 fork.
// Stock Sendy ships config.php with hardcoded DB / S3 credentials; this
// version reads everything from environment variables so it can run on
// Heroku, Coolify, Docker, etc. without per-deploy file edits.
//
// All defines below are required by Sendy at boot. Do not remove them.
//
// [OVERRIDE-STRATEGY: clobber] — this fully replaces stock config.php, so
// changed stock lines are moot. The one risk the audit watches for: a new
// Sendy release adding a define() that the rest of Sendy now needs at boot,
// which this hardcoded list would be missing.

// [PROXY] Honor reverse proxy forwarded headers (Caddy, Heroku router,
// Cloudflare, etc.) so Sendy emits https:// URLs and license/domain
// checks see the real client-facing host. Only safe when every request
// is guaranteed to come through a trusted reverse proxy — do not enable
// if the PHP server is directly internet-reachable, as a client could
// then spoof these headers.
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
	$_SERVER['HTTPS'] = 'on';
	$_SERVER['SERVER_PORT'] = 443;
	$_SERVER['REQUEST_SCHEME'] = 'https';
}
if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
	$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_X_FORWARDED_HOST'];
	$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_X_FORWARDED_HOST'];
}

// [PROXY] Real client IP. Behind a reverse proxy, REMOTE_ADDR is the proxy's own address for
// every request. Sendy 7.1.7+ rate-limits logins and password resets per IP (login_attempts), so
// all users would share one bucket and a few wrong passwords could lock everyone out. Prefer
// Cloudflare's CF-Connecting-IP, then the first X-Forwarded-For hop. Same trust rule as above:
// only valid while the app is reachable only through the proxy.
$__client_ip = '';
if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
	$__client_ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
	$__client_ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
}
if ($__client_ip !== '' && filter_var($__client_ip, FILTER_VALIDATE_IP)) {
	$_SERVER['REMOTE_ADDR'] = $__client_ip;
	putenv('REMOTE_ADDR=' . $__client_ip);
}
unset($__client_ip);

// [LOGIN] Drop a stale logged_in cookie when a new password login is submitted. Stock
// two-factor.php bounces away whenever a logged_in cookie exists, even an invalid one (revoked,
// expired, or from before 7.1.7). 2FA users then loop /two-factor -> / -> /login and cannot log in
// until they clear cookies. A fresh password login replaces the device token anyway: the OTP step
// (or the non-2FA path, whose Set-Cookie comes later and wins) issues a new one.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
	&& substr($_SERVER['SCRIPT_NAME'] ?? '', -strlen('/includes/login/main.php')) === '/includes/login/main.php'
	&& isset($_COOKIE['logged_in'])) {
	setcookie('logged_in', '', time() - 3600, '/', getenv('COOKIE_DOMAIN') ?: '');
	unset($_COOKIE['logged_in']);
}

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