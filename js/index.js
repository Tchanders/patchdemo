/* global OO */

/* eslint-disable no-jquery/no-global-selector */

( function () {
	window.pd = window.pd || {};

	var form = document.getElementById( 'new-form' );
	if ( form ) {
		var submit = OO.ui.infuse( $( '.form-submit' ) );
		var patchesInput = OO.ui.infuse( $( '.form-patches' ) );
		var patchesLayout = OO.ui.infuse( $( '.form-patches-layout' ) );

		form.addEventListener( 'submit', function ( e ) {
			// Blur is not fired on patchesInput, so call manually
			patchesInput.doInputEnter();

			if ( !patchesInput.getValue().length ) {
				OO.ui.confirm(
					'Are you sure you want to create a demo with no patches applied?'
				).then( function ( confirmed ) {
					if ( confirmed ) {
						form.submit();
					}
				} );
				e.preventDefault();
				return;
			}

			submit.setDisabled( true );
			return false;
		} );

		patchesInput.on( 'matchWikis', function ( wikis ) {
			patchesLayout.setWarnings(
				( wikis || [] ).map( function ( wiki ) {
					wiki = wiki.slice( 0, 10 );
					return $( '<span>' ).append(
						document.createTextNode( 'A wiki with these patches already exists: ' ),
						$( '<a>' ).addClass( 'wiki' ).attr( 'href', '#' + wiki ).text( wiki )
					);
				} )
			);
		} );

		var landingPageInput = OO.ui.infuse( $( '.form-landingPage' ) );

		if ( $( '.form-announce' ).length ) {
			var announceLayout = OO.ui.infuse( $( '.form-announce-layout' ) );
			var taskLabel = new OO.ui.LabelWidget( { classes: [ 'form-announce-taskList' ] } );
			announceLayout.$field.append( taskLabel.$element );

			// eslint-disable-next-line es-x/no-block-scoped-functions, no-inner-declarations
			function updateLinkedTasks( linkedTasks ) {
				var $label = $( [] );
				if ( !linkedTasks.length ) {
					$label = $( '<em>' ).text( 'No linked tasks found.' );
				} else {
					linkedTasks.forEach( function ( task ) {
						var id = 'T' + task;
						if ( $label.length ) {
							$label = $label.add( document.createTextNode( ', ' ) );
						}
						$label = $label.add(
							$( '<a>' )
								.attr( {
									href: window.pd.config.phabricatorUrl + '/' + id,
									target: '_blank'
								} )
								.text( id )
						);
					} );
				}
				taskLabel.setLabel( $label );
			}

			patchesInput.on( 'linkedTasks', updateLinkedTasks );
			updateLinkedTasks( [] );
		}

		if ( $( '.closedWikis' ).length ) {
			var $wikisTable = $( '.wikis' );
			var closedWikis = OO.ui.infuse( $( '.closedWikis' ) );

			// eslint-disable-next-line es-x/no-block-scoped-functions, no-inner-declarations
			function updateTableClasses() {
				$wikisTable.toggleClass( 'hideOpen', !!closedWikis.isSelected() );
			}

			closedWikis.on( 'change', updateTableClasses );

			if ( $( '.showClosedButton' ).length ) {
				var showClosedButton = OO.ui.infuse( $( '.showClosedButton' ) );
				showClosedButton.on( 'click', function () {
					closedWikis.setSelected( true );
					updateTableClasses();
				} );
			}
		}

		var presetInput = OO.ui.infuse( $( '.form-preset' ) );
		var reposInput = OO.ui.infuse( $( '.form-repos' ) );
		var reposField = OO.ui.infuse( $( '.form-repos-field' ) );
		var branchSelect = OO.ui.infuse( $( '.form-branch' ) );

		branchSelect.on( 'change', function () {
			var branch = branchSelect.value;
			for ( var repo in window.repoBranches ) {
				var validBranch = window.repoBranches[ repo ].indexOf( branch ) !== -1;
				reposInput.checkboxMultiselectWidget
					.findItemFromData( repo )
					.setDisabled( !validBranch || repo === 'mediawiki/core' );
			}
			reposInput.emit( 'change' );
		} );

		var reposFieldLabel = reposField.getLabel();

		presetInput.on( 'change', OO.ui.debounce( function () {
			var val = presetInput.getValue();
			if ( val === 'custom' ) {
				reposField.$body[ 0 ].open = true;
			}
			if ( val !== 'custom' ) {
				reposInput.setValue( window.presets[ val ] );
			}
		} ) );
		reposInput.on( 'change', OO.ui.debounce( function () {
			var val = reposInput.getValue();
			var matchingPresetName = 'custom';
			for ( var presetName in window.presets ) {
				if ( window.presets[ presetName ].sort().join( '|' ) === val.sort().join( '|' ) ) {
					matchingPresetName = presetName;
					break;
				}
			}
			if ( presetInput.getValue() !== matchingPresetName ) {
				presetInput.setValue( matchingPresetName );
			}

			var selected = 0, enabled = 0;
			reposInput.checkboxMultiselectWidget.items.forEach( function ( option ) {
				if ( !option.isDisabled() ) {
					enabled++;
					if ( option.isSelected() ) {
						selected++;
					}
				}
			} );

			reposField.setLabel( reposFieldLabel + ' (' + selected + '/' + enabled + ')' );
		} ) );

		reposInput.emit( 'change' );

		var instantCommonsCheckbox = OO.ui.infuse( $( '.form-instantCommons' ) );
		var instantCommonsMethodDropdown = OO.ui.infuse( $( '.form-instantCommonsMethod' ) );

		instantCommonsCheckbox.on( 'change', function ( value ) {
			instantCommonsMethodDropdown.setDisabled( !value );
		} );

		var languageInput = OO.ui.infuse( $( '.form-language' ) );
		languageInput.setValidation( /^[a-z-]{2,}$/ );

		$( '.copyWiki' ).on( 'click', function ( e ) {
			var params = new URL( this.href ).searchParams;
			patchesInput.setValue( params.get( 'patches' ) ? params.get( 'patches' ).split( ',' ) : [] );
			branchSelect.setValue( 'origin/' + params.get( 'branch' ) );
			branchSelect.scrollElementIntoView( { padding: { top: $( 'header' ).height() + 10 } } );
			landingPageInput.setValue( params.get( 'landingPage' ) );
			e.preventDefault();
		} );

		if ( 'Notification' in window ) {
			var notifField = OO.ui.infuse( document.getElementsByClassName( 'enableNotifications' )[ 0 ] );
			// Enable placholder widget so field label isn't greyed out
			notifField.fieldWidget.setDisabled( false );
			var notifFieldLabel = notifField.getLabel();

			var notifToggle = new OO.ui.ToggleButtonWidget( {
				icon: 'bellOutline'
			} );

			var onRequestPermission = function ( permission ) {
				notifToggle.setValue( permission === 'granted' );
				if ( permission === 'granted' ) {
					notifField.setLabel( 'You will get a browser notification when your wiki is ready' );
				}
				if ( permission === 'denied' ) {
					notifField.setErrors( [ 'Permission denied' ] );
				}
			};

			var onNotifChange = function ( value ) {
				if ( !value ) {
					localStorage.setItem( 'patchdemo-notifications', '0' );
					notifField.setLabel( notifFieldLabel );
				} else {
					localStorage.setItem( 'patchdemo-notifications', '1' );
					Notification.requestPermission().then( onRequestPermission );
				}
			};

			notifToggle.on( 'change', onNotifChange );
			if ( +localStorage.getItem( 'patchdemo-notifications' ) && Notification.permission ) {
				onRequestPermission( Notification.permission );
			}

			notifField.$field.empty().append( notifToggle.$element );
		}
	}

	var $lastMatch = $( [] );
	$( window ).on( 'hashchange', function () {
		if ( location.hash.match( /^#[0-9a-f]{10}$/ ) ) {
			$lastMatch.removeClass( 'highlight' );
			$lastMatch = $( location.hash ).closest( 'tr' );
			$lastMatch.addClass( 'highlight' );
		}
	} );
	$( window ).trigger( 'hashchange' );

}() );
