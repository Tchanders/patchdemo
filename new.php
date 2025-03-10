<?php

use Symfony\Component\Yaml\Yaml;

require_once "includes.php";

include "header.php";

ob_implicit_flush( true );

if ( $useOAuth && !$user ) {
	echo oauth_signin_prompt();
	die();
}

if ( !isset( $_POST['csrf_token'] ) || !check_csrf_token( $_POST['csrf_token'] ) ) {
	die( "Invalid session." );
}

$startTime = time();

$branch = trim( $_POST['branch'] );
$patches = trim( $_POST['patches'] );
$announce = !empty( $_POST['announce'] );
$landingPage = trim( $_POST['landingPage'] ) ? trim( $_POST['landingPage'] ) : null;
$language = trim( $_POST['language'] );

$wiki = substr( md5( $branch . $patches . time() ), 0, 10 );
$server = get_server();
$serverPath = get_server_path();

$branchDesc = preg_replace( '/^origin\//', '', $branch );

$creator = $user ? $user->username : '';
$created = time();

$canAdmin = can_admin();

$branches = get_branches_sorted( 'mediawiki/core' );

/**
 * Check if the user has dropped their connection and delete the wiki if so
 *
 * We could check for dropped connections with register_shutdown_function(), but
 * that could happen in the middle of a shell command. If we tried to delete
 * a wiki while a shell command was running (e.g composer update) we may still
 * be left with stray files (e.g. /vendor)
 *
 * Instead manually check the connection at 'safe' times in between API requests
 * or shell commands.
 */
function check_connection() {
	if ( connection_status() !== CONNECTION_NORMAL ) {
		abandon( 'User disconnected early' );
	}
}

// Don't kill the process automatcally
ignore_user_abort( true );

// Create an entry for the wiki before we have resolved patches.
// Will be updated later.
insert_wiki_data( $wiki, $creator, $created, $branchDesc, $landingPage );

function abandon( string $errHtml ) {
	global $wiki;
	$errJson = json_encode( $errHtml );
	echo <<<EOT
		<script>
			pd.installProgressField.fieldWidget.setDisabled( true );
			pd.installProgressField.fieldWidget.popPending();
			pd.installProgressField.setErrors( [ new OO.ui.HtmlSnippet( $errJson ) ] );
			pd.notify( 'Your PatchDemo wiki failed to build', $errJson );
		</script>
EOT;
	delete_wiki( $wiki );
	die( $errHtml );
}

function set_progress( float $pc, string $label ) {
	echo '<p>' . htmlspecialchars( $label ) . '</p>';
	$labelJson = json_encode( $label );
	echo <<<EOT
		<script>
			pd.installProgressField.fieldWidget.setProgress( $pc );
			pd.installProgressField.setLabel( $labelJson );
		</script>
EOT;
	if ( (int)$pc === 100 ) {
		echo <<<EOT
		<script>
			pd.installProgressField.fieldWidget.popPending();
			pd.openWiki.setDisabled( false );
			pd.notify( 'Your PatchDemo wiki is ready!' );
		</script>
EOT;
	}

	ob_flush();
}

echo new OOUI\FieldsetLayout( [
	'label' => null,
	'classes' => [ 'installForm' ],
	'items' => [
		new OOUI\FieldLayout(
			new OOUI\ProgressBarWidget(),
			[
				'align' => 'top',
				'label' => 'Installing...',
				'classes' => [ 'installProgressField' ],
				'infusable' => true,
			]
		),
		new OOUI\FieldLayout(
			new OOUI\ButtonWidget( [
				'label' => 'Open wiki',
				'flags' => [ 'progressive', 'primary' ],
				'href' => get_wiki_url( $wiki, $landingPage ),
				'disabled' => true,
				'classes' => [ 'openWiki' ],
				'infusable' => true,
			] ),
			[
				'align' => 'inline',
				'classes' => [ 'openWikiField' ],
				'label' => "When complete, use this button to open your wiki ($wiki)",
				'help' => new OOUI\HtmlSnippet( <<<EOT
					You can log in as the following users using the password <code>patchdemo1</code>
					<ul class="userList">
						<li><code>Patch Demo</code> <em>(admin)</em></li>
						<li><code>Alice</code></li>
						<li><code>Bob</code></li>
						<li><code>Mallory</code> <em>(blocked)</em></li>
					</ul>
				EOT ),
				'helpInline' => true,
			]
		),
	]
] );

echo '<script src="' . $basePath . '/js/new.js"></script>';

echo '<div class="consoleLog">';

if ( $patches ) {
	$patches = array_map( 'trim', preg_split( "/\n|\|/", $patches ) );
} else {
	$patches = [];
}

set_progress( 0, 'Checking language code...' );

if ( !preg_match( '/^[a-z-]{2,}$/', $language ) !== false ) {
	$languageHtml = htmlentities( $language );
	abandon( "Invalid language code <em>$languageHtml</em>" );
}

set_progress( 0, 'Querying patch metadata...' );

$patchesApplied = [];
$linkedTasks = [];
$commands = [];
$usedRepos = [];

// Iterate by reference, so that we can modify the $patches array to add new entries
foreach ( $patches as &$patch ) {
	preg_match( '/^(I[0-9a-f]+|(?<r>[0-9]+)(,(?<p>[0-9]+))?)$/', $patch, $matches );
	if ( !$matches ) {
		$patch = htmlentities( $patch );
		abandon( "Invalid patch number <em>$patch</em>" );
	}
	if ( isset( $matches['p'] ) ) {
		$query = $matches['r'];
		$o = 'ALL_REVISIONS';
	} else {
		$query = $patch;
		$o = 'CURRENT_REVISION';
	}
	$data = gerrit_query( "changes/?q=change:$query&o=LABELS&o=$o", true );
	check_connection();

	if ( count( $data ) === 0 ) {
		$patch = htmlentities( $patch );
		abandon( "Could not find patch <em>$patch</em>" );
	}
	if ( count( $data ) !== 1 ) {
		$patch = htmlentities( $patch );
		abandon( "Ambiguous query <em>$patch</em>" );
	}

	// get the info
	$repo = $data[0]['project'];
	$base = 'origin/' . $data[0]['branch'];
	$revision = null;
	if ( isset( $matches['p'] ) ) {
		foreach ( $data[0]['revisions'] as $k => $v ) {
			if ( $v['_number'] === (int)$matches['p'] ) {
				$revision = $k;
				break;
			}
		}
	} else {
		$revision = $data[0]['current_revision'];
	}
	if ( !$revision ) {
		$patch = htmlentities( $patch );
		abandon( "Could not find patch <em>$patch</em>" );
	}
	$ref = $data[0]['revisions'][$revision]['ref'];
	$id = $data[0]['id'];

	$repos = get_repo_data();
	if ( !isset( $repos[ $repo ] ) ) {
		$repo = htmlentities( $repo );
		abandon( "Repository <em>$repo</em> not supported" );
	}
	$path = $repos[ $repo ];
	$usedRepos[] = $repo;

	if (
		$config[ 'requireVerified' ] &&
		( $data[0]['labels']['Verified']['approved']['_account_id'] ?? null ) !== 75 &&
		// Admin override
		!( $canAdmin && isset( $_POST['adminVerified'] ) )
	) {
		// The patch doesn't have V+2, check if the uploader is trusted
		$uploaderId = $data[0]['revisions'][$revision]['uploader']['_account_id'];
		$uploader = gerrit_query( 'accounts/' . $uploaderId, true );
		check_connection();
		if ( !is_trusted_user( $uploader['email'] ) ) {
			if ( $canAdmin ) {
				echo '<form method="POST" action="" id="resubmit-form"><input type="hidden" name="adminVerified" value="1">';
				foreach ( $_POST as $k => $v ) {
					if ( is_array( $v ) ) {
						foreach ( $v as $part ) {
							echo '<input type="hidden" name="' . htmlentities( $k ) . '[]" value="' . htmlentities( $part ) . '">';
						}
					} else {
						echo '<input type="hidden" name="' . htmlentities( $k ) . '" value="' . htmlentities( $v ) . '">';
					}
				}
				echo '</form>';
			}
			abandon(
				"Patch must be approved (Verified+2) by jenkins-bot, or uploaded by a trusted user." .
				( can_admin() ?
					"<p>If you are confident all the patches are safe, as an admin you can bypass these checks:</p>" .
					new OOUI\ButtonWidget( [
						'classes' => [ 'resubmit-button' ],
						'label' => 'Bypass verification',
						'icon' => 'unLock',
						'flags' => [ 'destructive', 'primary' ],
						'infusable' => true,
					] ) .
					"<script>
						$( '.resubmit-button' ).each( function () {
							OO.ui.infuse( this ).on( 'click', function () {
								document.getElementById( 'resubmit-form' ).submit();
							} );
						} );
					</script>"
					 : ""
				)
			);
		}
	}

	$patchesApplied[] = $data[0]['_number'] . ',' . $data[0]['revisions'][$revision]['_number'];
	$commands[] = [
		[
			'REPO' => $path,
			'REF' => $ref,
			'BASE' => $base,
			'HASH' => $revision,
		],
		__DIR__ . '/new/applypatch.sh'
	];

	$relatedChanges = [];
	$relatedChanges[] = [ $data[0]['_number'], $data[0]['revisions'][$revision]['_number'] ];

	// Look at all commits in this patch's tree for cross-repo dependencies to add
	$data = gerrit_query( "changes/$id/revisions/$revision/related", true );
	check_connection();
	// Ancestor commits only, not descendants
	$foundCurr = false;
	foreach ( $data['changes'] as $change ) {
		if ( $foundCurr ) {
			// Querying by change number is allegedly deprecated, but the /related API doesn't return the 'id'
			$relatedChanges[] = [ $change['_change_number'], $change['_revision_number'] ];
		}
		$foundCurr = $foundCurr || $change['commit']['commit'] === $revision;
	}

	foreach ( $relatedChanges as [ $c, $r ] ) {
		$data = gerrit_query( "changes/$c/revisions/$r/commit", true );
		check_connection();

		preg_match_all( '/^Depends-On: (.+)$/m', $data['message'], $m );
		foreach ( $m[1] as $changeid ) {
			if ( !in_array( $changeid, $patches, true ) ) {
				// The entry we add here will be processed by the topmost foreach
				$patches[] = $changeid;
			}
		}
	}
}

$wikiName = "Patch demo (" . trim(
	// Add branch name if it's not master, or if there are no patches
	( $branchDesc !== 'master' || !$patchesApplied ? $branchDesc : '' ) . ' ' .
	// Add list of patches
	implode( ' ', $patchesApplied )
) . ")";

// Update DB record with patches applied
wiki_add_patches( $wiki, $patchesApplied );

// Choose repositories to enable
$repos = get_repo_data();

if ( $_POST['preset'] === 'custom' ) {
	$allowedRepos = $_POST['repos'];
} else {
	$allowedRepos = get_repo_presets()[ $_POST['preset'] ];
}

// Always include repos we are trying to patch (#401)
$allowedRepos = array_merge( $allowedRepos, $usedRepos );

$useProxy = !empty( $_POST['proxy'] );
$useInstantCommons = !empty( $_POST['instantCommons' ] );
// When proxying, always enable MobileFrontend and its content provider
if ( $useProxy ) {
	// Doesn't matter if this appears twice
	$allowedRepos[] = 'mediawiki/extensions/MobileFrontend';
	$allowedRepos[] = 'mediawiki/extensions/MobileFrontendContentProvider';
}
if ( $useInstantCommons ) {
	if ( $_POST['instantCommonsMethod'] === 'quick' ) {
		$allowedRepos[] = 'mediawiki/extensions/QuickInstantCommons';
		$useInstantCommons = false;
	}
}

$defaultSkin = '';
if ( in_array( 'mediawiki/skins/Vector', $allowedRepos, true ) ) {
	$defaultSkin = 'vector-2022';
	foreach ( $branches as $b ) {
		if ( $b === $branch ) {
			break;
		}
		// Vector 2022 was deployed to en.wiki on 18th Jan 2023, around the time
		// of this branch. At this point it became the most widely used skin
		// in WMF environmnets.
		// Branches older than this can use the normal default skin (vector)
		if ( $b === 'origin/wmf/1.40.0-wmf.19' ) {
			$defaultSkin = '';
			break;
		}
	}
}

$repoSpecificBranches = [];
foreach ( array_keys( $repos ) as $repo ) {
	// Unchecked the checkbox
	if ( $repo !== 'mediawiki/core' && !in_array( $repo, $allowedRepos, true ) ) {
		unset( $repos[$repo] );
	}

	$repoBranches = get_branches( $repo );
	// This branch doesn't exist for this repo
	if ( !in_array( $branch, $repoBranches, true ) ) {
		if ( $branch === 'origin/master' && in_array( 'origin/main', $repoBranches, true ) ) {
			// master doesn't exist but main does; use main
			$repoSpecificBranches[$repo] = 'origin/main';
		} else {
			unset( $repos[$repo] );
		}
	}
}

$reposString = implode( "\n", array_map( static function ( $k, $v ) {
	return "$k $v";
}, array_keys( $repos ), array_values( $repos ) ) );

// Build Main_Page
$mainPage = "This wiki was generated on [$server$serverPath '''Patch demo'''] at ~~~~~.";

if ( $landingPage ) {
	// $landingPage could have a query string so build an external link.
	$mainPage .= "\n\nThe designated landing page for this wiki is [{{SERVER}}{{ARTICLEPATH}}/../$landingPage $landingPage].";
}

$hasOOUI = in_array( 'oojs/ui', $allowedRepos, true );
if ( $hasOOUI ) {
	$mainPage .= "\n\nThis wiki was built with OOUI patches so you can also view the [{{SERVER}}{{SCRIPTPATH}}/build/ooui/demos patched '''OOUI Demos'''].";
}

$hasCodex = in_array( 'design/codex', $allowedRepos, true );
if ( $hasCodex ) {
	$mainPage .= "\n\nThis wiki was built with Codex patches, so you can also view the [{{SERVER}}{{SCRIPTPATH}}/build/codex/docs patched '''Codex demos and documentation'''].";
}

$mainPage .= "\n\n;Branch: $branchDesc";
$mainPage .= "\n;Applied patches:";

if ( !$patchesApplied ) {
	$mainPage .= " (none)";
}
foreach ( $patchesApplied as $patch ) {
	preg_match( '`([0-9]+),([0-9]+)`', $patch, $matches );
	list( $t, $r, $p ) = $matches;

	$data = gerrit_query( "changes/$r/revisions/$p/commit", true );
	check_connection();
	if ( $data ) {
		$t = $t . ': ' . $data[ 'subject' ];
		get_linked_tasks( $data['message'], $linkedTasks );
	}

	$t = htmlentities( $t );

	$mainPage .= "\n:* [{$config['gerritUrl']}/r/c/$r/$p <nowiki>$t</nowiki>]";
}

$mainPage .= "\n;Linked tasks:";
if ( !$linkedTasks ) {
	$mainPage .= " (none)";
}
foreach ( $linkedTasks as $task ) {
	$mainPage .= "\n:* [{$config['phabricatorUrl']}/T$task T$task]";
}

$baseEnv = [
	'PATCHDEMO' => __DIR__,
	'NAME' => $wiki,
];

$start = 5;
$end = 40;
$n = 1;
$repoProgress = $start;
$repoCount = count( $repos );

foreach ( $repos as $source => $target ) {
	set_progress( $repoProgress, "Updating repositories ($n/$repoCount)..." );

	check_connection();
	$error = shell_echo( __DIR__ . '/new/updaterepos.sh',
		$baseEnv + [
			'REPO_SOURCE' => $source,
		]
	);

	if ( $error ) {
		abandon( "Could not update repository <em>$source</em>" );
	}

	$repoProgress += ( $end - $start ) / $repoCount;
	$n++;
}

// Just creates empty folders so no need for progress update
check_connection();
$error = shell_echo( __DIR__ . '/new/precheckout.sh', $baseEnv );
if ( $error ) {
	abandon( "Could not create directories for wiki" );
}

$start = 40;
$end = 60;
$n = 1;
$repoProgress = $start;
$repoCount = count( $repos );
foreach ( $repos as $source => $target ) {
	set_progress( $repoProgress, "Checking out repositories ($n/$repoCount)..." );

	check_connection();
	$error = shell_echo( __DIR__ . '/new/checkout.sh',
		$baseEnv + [
		'BRANCH' => $repoSpecificBranches[$source] ?? $branch,
		'REPO_SOURCE' => $source,
		'REPO_TARGET' => $target,
		]
	);
	if ( $error ) {
		abandon( "Could not check out repository <em>$source</em>" );
	}

	$repoProgress += ( $end - $start ) / $repoCount;
	$n++;
}

// TODO: Make this a loop
set_progress( 60, 'Fetching submodules...' );
check_connection();
$error = shell_echo( __DIR__ . '/new/submodules.sh', $baseEnv );
if ( $error ) {
	abandon( "Could not fetch submodules" );
}

$composerInstallRepos = Yaml::parse( file_get_contents( __DIR__ . '/repository-lists/composerinstall.yaml' ) );
// Filter down to repos which are being installed
$composerInstallRepos = array_values( array_filter(
	$composerInstallRepos,
	static function ( string $repo ) use ( $repos ): bool {
		return isset( $repos[$repo] );
	}
) );
$start = 60;
$end = 65;
$repoProgress = $start;
$repoCount = count( $composerInstallRepos );
foreach ( $composerInstallRepos as $i => $repo ) {
	$n = $i + 1;
	set_progress( $repoProgress, "Fetching dependencies ($n/$repoCount)..." );
	check_connection();
	$error = shell_echo( __DIR__ . '/new/composerinstall.sh',
		$baseEnv + [
			// Variable used by composer itself, not our script
			'COMPOSER_HOME' => __DIR__ . '/composer',
			'REPO_TARGET' => $repos[$repo],
		]
	);
	if ( $error ) {
		abandon( "Could not fetch dependencies for <em>$repo</em>" );
	}

	$repoProgress += ( $end - $start ) / $repoCount;
}

$start = 65;
$end = 75;
$progress = $start;
$count = count( $commands );
foreach ( $commands as $i => $command ) {
	$n = $i + 1;
	set_progress( $progress, "Fetching and applying patches ($n/$count)..." );
	check_connection();
	$error = shell_echo( $command[1], $baseEnv + $command[0] );
	if ( $error ) {
		abandon( "Could not apply patch {$patchesApplied[$i]}" );
	}
	$progress += ( $end - $start ) / $count;
}

set_progress( 75, 'Installing your wiki...' );

check_connection();
$error = shell_echo( __DIR__ . '/new/install.sh',
	$baseEnv + [
		'WIKINAME' => $wikiName,
		'SERVER' => $server,
		'SERVERPATH' => $serverPath,
		'LANGUAGE' => $language,
		'REPOSITORIES' => $reposString,
		'DEFAULT_SKIN' => $defaultSkin,
	]
);
if ( $error ) {
	abandon( "Could not install wiki" );
}

set_progress( 90, 'Setting up wiki content...' );

check_connection();
$error = shell_echo( __DIR__ . '/new/postinstall.sh',
	$baseEnv + [
		'MAINPAGE' => $mainPage,
		'USE_PROXY' => $useProxy,
		'USE_TEMPUSER' => !empty( $_POST['tempuser'] ),
		'USE_INSTANT_COMMONS' => $useInstantCommons,
		'REPOSITORIES' => $reposString,
		// May be required for npm (e.g. if using nvm)
		'EXTRA_PATH' => implode( ':', $config['extraPaths'] ),
		// Variable used by composer itself, not our script
		'COMPOSER_HOME' => __DIR__ . '/composer',
		'SERVERPATH' => $serverPath,
	]
);
if ( $error ) {
	abandon( "Could not setup wiki content" );
}

if ( $announce && count( $linkedTasks ) ) {
	set_progress( 95, 'Posting to Phabricator...' );

	foreach ( $linkedTasks as $task ) {
		post_phab_comment(
			'T' . $task,
			"Test wiki **created** on [[ $server$serverPath | Patch demo ]]" . ( $creator ? ' by ' . $creator : '' ) . " using patch(es) linked to this task:" .
			"\n" .
			"$server$serverPath/" . get_wiki_url( $wiki, $landingPage ) .
			( $hasOOUI ?
				"\n\n" .
				"Also created an **OOUI Demos** page:" .
				"\n" .
				"$server$serverPath/wikis/$wiki/w/build/ooui/demos"
				: ""
			) .
			( $hasCodex ?
				"\n\n" .
				"Also created a **Codex documentation** site:" .
				"\n" .
				"$server$serverPath/wikis/$wiki/w/build/codex/docs"
				: ""
			)
		);
	}
	wiki_add_announced_tasks( $wiki, $linkedTasks );
}

$timeToCreate = time() - $startTime;
wiki_set_time_to_create( $wiki, $timeToCreate );

set_progress( 100, 'All done! Wiki created in ' . format_duration( $timeToCreate ) . '.' );

echo '</div>';
