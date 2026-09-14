/* global jQuery, acf */
( function ( $ ) {
	'use strict';

	if ( typeof acf === 'undefined' ) {
		return;
	}

	var CLONE = 'acfcloneindex';

	function rows( $repeater ) {
		return $repeater.children( '.aiwp-repeater-rows' ).children( '.aiwp-repeater-row' );
	}

	function reindex( $repeater ) {
		var name = $repeater.data( 'name' );
		var max = parseInt( $repeater.data( 'max' ), 10 ) || 0;
		var min = parseInt( $repeater.data( 'min' ), 10 ) || 0;
		var $rows = rows( $repeater );

		$rows.each( function ( index ) {
			var $row = $( this );
			$row.attr( 'data-index', index );
			$row.find( '.aiwp-repeater-order' ).text( index + 1 );

			$row.find( '[name], [id], [for]' ).each( function () {
				var el = this;
				[ 'name', 'id', 'for' ].forEach( function ( attr ) {
					var value = el.getAttribute( attr );
					if ( ! value ) {
						return;
					}
					var updated = value
						.replace( new RegExp( escapeRe( name ) + '\\[[^\\]]*\\]', 'g' ), name + '[' + index + ']' )
						.replace( new RegExp( escapeRe( idify( name ) ) + '-[^-]*-', 'g' ), idify( name ) + '-' + index + '-' );
					if ( updated !== value ) {
						el.setAttribute( attr, updated );
					}
				} );
			} );
		} );

		$repeater.children( '.aiwp-repeater-count' ).val( $rows.length );

		var hint = '';
		if ( max > 0 && $rows.length >= max ) {
			hint = 'Maximum of ' + max + ' rows reached.';
		} else if ( min > 0 && $rows.length < min ) {
			hint = 'At least ' + min + ' row(s) required.';
		}
		$repeater.find( '.aiwp-repeater-hint' ).text( hint );
		$repeater.find( '.aiwp-repeater-add' ).prop( 'disabled', max > 0 && $rows.length >= max );
		$repeater.find( '.aiwp-repeater-remove' ).prop( 'disabled', min > 0 && $rows.length <= min );
	}

	function idify( name ) {
		return name.replace( /\[/g, '-' ).replace( /\]/g, '' );
	}

	function escapeRe( value ) {
		return value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function addRow( $repeater, $after ) {
		var max = parseInt( $repeater.data( 'max' ), 10 ) || 0;
		var $rows = rows( $repeater );
		if ( max > 0 && $rows.length >= max ) {
			return;
		}

		var index = $rows.length;
		var html = $repeater.children( '.aiwp-repeater-template' ).html();
		if ( ! html ) {
			return;
		}

		html = html.split( CLONE ).join( String( index ) );
		var $row = $( html );

		if ( $after && $after.length ) {
			$after.after( $row );
		} else {
			$repeater.children( '.aiwp-repeater-rows' ).append( $row );
		}

		reindex( $repeater );
		acf.doAction( 'append', $row );
	}

	function duplicateRow( $repeater, $row ) {
		var max = parseInt( $repeater.data( 'max' ), 10 ) || 0;
		if ( max > 0 && rows( $repeater ).length >= max ) {
			return;
		}

		// Copy live values across after ACF has initialised the new row.
		var values = [];
		$row.find( 'input, select, textarea' ).each( function () {
			values.push( this.type === 'checkbox' || this.type === 'radio' ? this.checked : this.value );
		} );

		addRow( $repeater, $row );
		var $new = $row.next( '.aiwp-repeater-row' );
		$new.find( 'input, select, textarea' ).each( function ( i ) {
			if ( i >= values.length ) {
				return;
			}
			if ( this.type === 'checkbox' || this.type === 'radio' ) {
				this.checked = values[ i ];
			} else {
				this.value = values[ i ];
			}
			$( this ).trigger( 'change' );
		} );
	}

	function removeRow( $repeater, $row ) {
		var min = parseInt( $repeater.data( 'min' ), 10 ) || 0;
		if ( min > 0 && rows( $repeater ).length <= min ) {
			return;
		}
		acf.doAction( 'remove', $row );
		$row.remove();
		reindex( $repeater );
	}

	function initRepeater( $repeater ) {
		if ( $repeater.data( 'aiwpReady' ) ) {
			return;
		}
		$repeater.data( 'aiwpReady', true );

		$repeater.children( '.aiwp-repeater-rows' ).sortable( {
			handle: '.aiwp-repeater-handle',
			axis: 'y',
			items: '> .aiwp-repeater-row',
			forcePlaceholderSize: true,
			placeholder: 'aiwp-repeater-placeholder',
			start: function ( e, ui ) {
				acf.doAction( 'sortstart', ui.item, ui.placeholder );
			},
			stop: function ( e, ui ) {
				acf.doAction( 'sortstop', ui.item, ui.placeholder );
				reindex( $repeater );
			}
		} );

		reindex( $repeater );
	}

	$( document ).on( 'click', '.aiwp-repeater-add', function ( e ) {
		e.preventDefault();
		addRow( $( this ).closest( '.aiwp-repeater' ) );
	} );

	$( document ).on( 'click', '.aiwp-repeater-remove', function ( e ) {
		e.preventDefault();
		var $row = $( this ).closest( '.aiwp-repeater-row' );
		removeRow( $row.closest( '.aiwp-repeater' ), $row );
	} );

	$( document ).on( 'click', '.aiwp-repeater-duplicate', function ( e ) {
		e.preventDefault();
		var $row = $( this ).closest( '.aiwp-repeater-row' );
		duplicateRow( $row.closest( '.aiwp-repeater' ), $row );
	} );

	acf.addAction( 'ready_field/type=aiwp_repeater', function ( field ) {
		initRepeater( field.$el.find( '.aiwp-repeater' ).first() );
	} );

	acf.addAction( 'append_field/type=aiwp_repeater', function ( field ) {
		initRepeater( field.$el.find( '.aiwp-repeater' ).first() );
	} );

	$( function () {
		$( '.aiwp-repeater' ).each( function () {
			initRepeater( $( this ) );
		} );
	} );
}( jQuery ) );
