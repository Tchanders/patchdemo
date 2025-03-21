<?php

// Parsoid/PHP required configuration
$wgEnableRestAPI = true;
if ( file_exists( 'parsoid/extension.json' ) ) {
	// Ensure we load the right Parsoid (not via Composer)
	AutoLoader::$psr4Namespaces += [
		'Wikimedia\\Parsoid\\' => 'parsoid/src',
	];
	wfLoadExtension( 'Parsoid', 'parsoid/extension.json' );
}
$wgParsoidSettings = [
	'useSelser' => true,
	'linting' => true,
];

// VisualEditor required configuration
$wgVirtualRestConfig['modules']['parsoid'] = [
	'url' => $wgServer . $wgScriptPath . '/rest.php',
];
