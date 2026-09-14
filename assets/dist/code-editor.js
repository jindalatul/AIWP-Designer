/**
 * The AIWP code editor.
 *
 * WordPress's own CodeMirror, with an overlay that colours the template
 * language, and a Check button that runs the real server-side validators before
 * anything is saved. There is no JavaScript mode on purpose.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.aiwpCode === 'undefined' ) {
		return;
	}

	var config = window.aiwpCode;
	var fitTimer = 0;
	var CM = window.wp && window.wp.CodeMirror ? window.wp.CodeMirror : window.CodeMirror;
	var editors = {};
	var dirty = false;

	/* ------------------------------------------------- template language mode */
	if ( CM && ! CM.modes.aiwp ) {
		CM.defineMode( 'aiwp', function ( cmConfig ) {
			var overlay = {
				token: function ( stream ) {
					if ( stream.match( /\{\{#(if|each):[^}]*\}\}/ ) || stream.match( /\{\{\/(if|each)\}\}/ ) ) {
						return 'aiwp-block';
					}

					if ( stream.match( /\{\{[a-z_]+:[^}]*\}\}/ ) ) {
						return 'aiwp-field';
					}

					// Anything that looks like a directive but is not one.
					if ( stream.match( /\{\{[^}]*\}\}/ ) ) {
						return 'aiwp-bad';
					}

					while ( stream.next() != null && ! stream.match( /\{\{/, false ) ) {
						/* advance to the next directive */
					}

					return null;
				}
			};

			return CM.overlayMode( CM.getMode( cmConfig, 'htmlmixed' ), overlay );
		} );
	}

	/* ----------------------------------------------------------------- markup */
	function shell( data ) {
		var tabs = '';
		var panes = '';
		var first = true;

		Object.keys( data.files ).forEach( function ( key ) {
			var file = data.files[ key ];

			tabs += '<button type="button" class="aiwp-code__tab' + ( first ? ' is-active' : '' ) +
				'" data-file="' + key + '">' + file.label +
				'<span class="aiwp-code__badge" data-count="' + key + '"></span></button>';

			panes += '<div class="aiwp-code__pane' + ( first ? ' is-active' : '' ) + '" data-file="' + key + '">' +
				'<textarea id="aiwp-code-' + key + '" data-mode="' + file.mode + '"></textarea></div>';

			first = false;
		} );

		return '<div class="aiwp-code__bar">' +
				'<div class="aiwp-code__tabs">' + tabs + '</div>' +
				'<div class="aiwp-code__actions">' +
					'<span class="aiwp-code__status"></span>' +
					'<button type="button" class="button aiwp-code__expand" title="Full screen (Esc to leave)">' +
						'<span class="dashicons dashicons-editor-expand"></span>' +
						'<span class="aiwp-code__expand-text">Full screen</span>' +
					'</button>' +
					'<button type="button" class="button aiwp-code__check">Check</button>' +
					'<button type="button" class="button button-primary aiwp-code__save">Save</button>' +
				'</div>' +
			'</div>' +
			'<div class="aiwp-code__panes">' + panes + '</div>' +
			'<div class="aiwp-code__report" hidden></div>';
	}

	/* ---------------------------------------------------------------- editors */
	function makeEditor( key, file ) {
		var textarea = document.getElementById( 'aiwp-code-' + key );
		if ( ! textarea ) {
			return;
		}

		textarea.value = file.contents;

		var base = 'css' === file.mode ? config.settings.css : config.settings.aiwp;
		if ( ! base || ! base.codemirror ) {
			// Highlighting is switched off in the user's profile; a plain textarea
			// still works, and the server still validates.
			textarea.classList.add( 'aiwp-code__plain' );
			textarea.addEventListener( 'input', function () {
				markDirty();
			} );
			editors[ key ] = { getValue: function () { return textarea.value; }, plain: true };
			return;
		}

		var settings = $.extend( true, {}, base );
		settings.codemirror = $.extend( {}, settings.codemirror, {
			mode: 'css' === file.mode ? 'text/css' : 'aiwp',
			lineNumbers: true,
			lineWrapping: true,
			indentUnit: 2,
			tabSize: 2,
			styleActiveLine: true,
			gutters: [ 'CodeMirror-linenumbers', 'aiwp-gutter' ]
		} );

		var instance = wp.codeEditor.initialize( textarea, settings );
		instance.codemirror.on( 'change', markDirty );
		editors[ key ] = instance;
	}

	/* ------------------------------------------------------------------ sizing */
	function refreshAll() {
		Object.keys( editors ).forEach( function ( key ) {
			if ( editors[ key ] && ! editors[ key ].plain ) {
				editors[ key ].codemirror.refresh();
			}
		} );
	}

	/**
	 * Give the editor whatever is left of the window.
	 *
	 * Guessing the height of the admin bar, the heading and any notices with a
	 * fixed subtraction is always wrong on somebody's screen, so measure it.
	 */
	function fitHeight() {
		var app = document.getElementById( 'aiwp-code-app' );
		if ( ! app || isFullScreen() ) {
			return;
		}

		var panes = app.querySelector( '.aiwp-code__panes' );
		if ( ! panes ) {
			return;
		}

		var top   = panes.getBoundingClientRect().top;
		var space = Math.max( 420, Math.round( window.innerHeight - top - 28 ) );

		app.style.setProperty( '--aiwp-code-height', space + 'px' );
		refreshAll();
	}

	/* ------------------------------------------------------------- full screen */
	function setFullScreen( on ) {
		var app = document.getElementById( 'aiwp-code-app' );
		if ( ! app ) {
			return;
		}

		document.body.classList.toggle( 'aiwp-code-fullscreen', on );
		app.classList.toggle( 'is-fullscreen', on );

		var button = app.querySelector( '.aiwp-code__expand' );
		if ( button ) {
			button.querySelector( '.aiwp-code__expand-text' ).textContent = on ? 'Exit full screen' : 'Full screen';
			button.querySelector( '.dashicons' ).className = 'dashicons dashicons-editor-' + ( on ? 'contract' : 'expand' );
		}

		// CodeMirror measures itself on creation, so it has to be told.
		refreshAll();

		if ( ! on ) {
			fitHeight();
		}

		try {
			window.localStorage.setItem( 'aiwpCodeFullScreen', on ? '1' : '0' );
		} catch ( e ) {
			/* private browsing; not worth reporting */
		}
	}

	function isFullScreen() {
		return document.body.classList.contains( 'aiwp-code-fullscreen' );
	}

	function value( key ) {
		var editor = editors[ key ];
		if ( ! editor ) {
			return '';
		}
		return editor.plain ? editor.getValue() : editor.codemirror.getValue();
	}

	function markDirty() {
		if ( ! dirty ) {
			dirty = true;
			status( config.i18n.unsaved, 'warn' );
		}
	}

	/* --------------------------------------------------------------- feedback */
	function status( text, kind ) {
		var el = document.querySelector( '.aiwp-code__status' );
		if ( el ) {
			el.textContent = text || '';
			el.className = 'aiwp-code__status' + ( kind ? ' is-' + kind : '' );
		}
	}

	function clearMarks() {
		Object.keys( editors ).forEach( function ( key ) {
			var editor = editors[ key ];
			if ( editor && ! editor.plain ) {
				editor.codemirror.clearGutter( 'aiwp-gutter' );
			}
		} );

		document.querySelectorAll( '.aiwp-code__badge' ).forEach( function ( b ) {
			b.textContent = '';
			b.className = 'aiwp-code__badge';
		} );
	}

	function report( result ) {
		clearMarks();

		var box = document.querySelector( '.aiwp-code__report' );
		var counts = {};
		var html = '';

		function row( item, kind ) {
			var file = item.file || 'template';
			counts[ file ] = ( counts[ file ] || 0 ) + ( 'error' === kind ? 1 : 0 );

			var where = item.line ? config.i18n.line.replace( '%d', item.line ) : '';

			if ( item.line && editors[ file ] && ! editors[ file ].plain ) {
				var marker = document.createElement( 'span' );
				marker.className = 'aiwp-gutter__mark is-' + kind;
				marker.title = item.message;
				marker.textContent = 'error' === kind ? '!' : '?';
				editors[ file ].codemirror.setGutterMarker( item.line - 1, 'aiwp-gutter', marker );
			}

			return '<li class="is-' + kind + '"><button type="button" class="aiwp-code__jump" data-file="' +
				file + '" data-line="' + ( item.line || 0 ) + '">' +
				( editorLabel( file ) ) + ( where ? ' · ' + where : '' ) +
				'</button><span>' + escapeHtml( item.message ) + '</span></li>';
		}

		( result.errors || [] ).forEach( function ( e ) { html += row( e, 'error' ); } );
		( result.warnings || [] ).forEach( function ( w ) { html += row( w, 'warn' ); } );

		Object.keys( counts ).forEach( function ( file ) {
			var badge = document.querySelector( '.aiwp-code__badge[data-count="' + file + '"]' );
			if ( badge && counts[ file ] > 0 ) {
				badge.textContent = counts[ file ];
				badge.className = 'aiwp-code__badge is-error';
			}
		} );

		if ( ! html ) {
			box.hidden = true;
			box.innerHTML = '';
			return;
		}

		box.hidden = false;
		box.innerHTML = '<ul class="aiwp-code__list">' + html + '</ul>';
	}

	function editorLabel( key ) {
		var tab = document.querySelector( '.aiwp-code__tab[data-file="' + key + '"]' );
		return tab ? tab.childNodes[ 0 ].textContent.trim() : key;
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	/* ----------------------------------------------------------------- server */
	function payload( app ) {
		var body = { target: app.dataset.target };

		if ( 'page' === app.dataset.target ) {
			body.page_id = parseInt( app.dataset.page, 10 );
			body.template = value( 'template' );
		} else {
			body.header = value( 'header' );
			body.footer = value( 'footer' );
		}

		body.css = value( 'css' );

		return body;
	}

	function send( path, body ) {
		return fetch( config.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( body )
		} ).then( function ( r ) { return r.json(); } );
	}

	function looksLikePhp( app ) {
		var keys = 'page' === app.dataset.target ? [ 'template' ] : [ 'header', 'footer' ];

		return keys.some( function ( key ) {
			return /<\?php|<\?=/.test( value( key ) );
		} );
	}

	/* ------------------------------------------------------------------- boot */
	function start() {
		var app = document.getElementById( 'aiwp-code-app' );
		if ( ! app ) {
			return;
		}

		var url = config.root + '?target=' + app.dataset.target +
			( 'page' === app.dataset.target ? '&page_id=' + app.dataset.page : '' );

		fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data.files ) {
					app.innerHTML = '<div class="notice notice-error"><p>' +
						escapeHtml( data.error || 'Could not load this.' ) + '</p></div>';
					return;
				}

				app.innerHTML = shell( data );

				Object.keys( data.files ).forEach( function ( key ) {
					makeEditor( key, data.files[ key ] );
				} );

				dirty = false;
				status( '' );

				var remembered = '0';
				try {
					remembered = window.localStorage.getItem( 'aiwpCodeFullScreen' ) || '0';
				} catch ( e ) {
					remembered = '0';
				}

				if ( '1' === remembered ) {
					setFullScreen( true );
				} else {
					fitHeight();
				}
			} );
	}

	/* ---------------------------------------------------------------- events */
	$( document ).on( 'click', '.aiwp-code__tab', function () {
		var file = this.dataset.file;

		document.querySelectorAll( '.aiwp-code__tab' ).forEach( function ( t ) {
			t.classList.toggle( 'is-active', t.dataset.file === file );
		} );
		document.querySelectorAll( '.aiwp-code__pane' ).forEach( function ( p ) {
			p.classList.toggle( 'is-active', p.dataset.file === file );
		} );

		if ( editors[ file ] && ! editors[ file ].plain ) {
			editors[ file ].codemirror.refresh();
			editors[ file ].codemirror.focus();
		}
	} );

	$( document ).on( 'click', '.aiwp-code__jump', function () {
		var file = this.dataset.file;
		var line = parseInt( this.dataset.line, 10 );

		var tab = document.querySelector( '.aiwp-code__tab[data-file="' + file + '"]' );
		if ( tab ) {
			tab.click();
		}

		if ( line > 0 && editors[ file ] && ! editors[ file ].plain ) {
			editors[ file ].codemirror.setCursor( line - 1, 0 );
			editors[ file ].codemirror.focus();
		}
	} );

	$( document ).on( 'click', '.aiwp-code__check', function () {
		var app = document.getElementById( 'aiwp-code-app' );

		if ( looksLikePhp( app ) ) {
			status( config.i18n.phpRefused, 'error' );
			return;
		}

		status( config.i18n.checking );

		send( '/check', payload( app ) ).then( function ( result ) {
			report( result );
			status( result.valid ? config.i18n.clean : '', result.valid ? 'ok' : 'error' );
		} ).catch( function () {
			status( config.i18n.failed, 'error' );
		} );
	} );

	$( document ).on( 'click', '.aiwp-code__save', function () {
		var app = document.getElementById( 'aiwp-code-app' );

		if ( looksLikePhp( app ) ) {
			status( config.i18n.phpRefused, 'error' );
			return;
		}

		status( config.i18n.saving );

		send( '/save', payload( app ) ).then( function ( result ) {
			report( result );

			if ( result.saved && result.unchanged ) {
				// Pressing save on a file you did not edit is not a failure, and
				// it should not walk the version number up either.
				dirty = false;
				status( config.i18n.unchanged, 'ok' );
			} else if ( result.saved ) {
				dirty = false;
				status( config.i18n.saved.replace( '%d', result.version ), 'ok' );
			} else {
				status( config.i18n.notSaved, 'error' );
			}
		} ).catch( function () {
			status( config.i18n.failed, 'error' );
		} );
	} );

	$( document ).on( 'click', '.aiwp-code__expand', function () {
		setFullScreen( ! isFullScreen() );
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && isFullScreen() ) {
			setFullScreen( false );
		}
	} );

	window.addEventListener( 'resize', function () {
		clearTimeout( fitTimer );
		fitTimer = setTimeout( fitHeight, 120 );
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) {
			e.preventDefault();
			e.returnValue = config.i18n.leaveWarn;
			return config.i18n.leaveWarn;
		}
	} );

	$( start );
}( jQuery ) );
