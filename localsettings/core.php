<?php

// Generic verbose error reporting stuff
$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
error_reporting( -1 );
ini_set( 'display_startup_errors', 1 );
ini_set( 'display_errors', $isCli ? 'stderr' : 1 );
$wgShowSQLErrors = true;
$wgDebugDumpSql  = true;
$wgShowDBErrorBacktrace = true;
$wgShowExceptionDetails = true;
$wgDebugComments = true;

// Slice off the '/w' from the end
$basePath = substr( $wgScriptPath, 0, -2 );

// Don't share cookies across wikis (#78)
$wgCookiePath = $basePath;

// Short URLs
$wgArticlePath = $basePath . "/wiki/$1";

// Disable ResourceLoader's localStorage cache as this will
// fill up quickly when domains host multiple wikis (#35)
$wgResourceLoaderStorageEnabled = false;

// Set the name of the project namespace to "Project:", rather than
// "Patch Demo (123456,7):"
$wgMetaNamespace = 'Project';

// Special:Upload (also UploadWizard)
$wgEnableUploads = true;

// Logo
$wgLogos = [
	'svg' => "$wgResourceBasePath/logo.svg",
	'icon' => "$wgResourceBasePath/icon.svg",
	'wordmark' => [
		'src' => "$wgResourceBasePath/wordmark.svg",
		'width' => 138,
		'height' => 18,
	]
];
$wgFavicon = "$wgResourceBasePath/favicon.ico";
// Legacy logo setting for older releases
$wgLogo = "$wgResourceBasePath/logo.svg";

// Email settings
$wgAllowHTMLEmail = true;

// Production uses HTML5 section IDs
$wgFragmentMode = [ 'html5', 'legacy' ];

// Choose a sensible timezone
$wgLocaltimezone = 'UTC';

// Enable watchlist expiry feature
$wgWatchlistExpiry = true;