/**
 * Fields on the page editor: add one where you are looking, remove one by
 * typing rather than by ticking.
 */
( function () {
	'use strict';

	var config = window.aiwpSections || null;
	if ( ! config ) {
		return;
	}

	var root = document.getElementById( 'aiwp-sections' );
	if ( ! root ) {
		return;
	}

	function post( path, body ) {
		return fetch( config.root + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( Object.assign( { page_id: config.pageId }, body ) )
		} ).then( function ( r ) { return r.json(); } );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) { node.className = className; }
		if ( undefined !== text && null !== text ) { node.textContent = text; }
		return node;
	}

	function typeSelect() {
		var select = el( 'select', 'aiwp-sec__type' );
		Object.keys( config.types ).forEach( function ( value ) {
			var option = el( 'option', null, config.types[ value ] );
			option.value = value;
			select.appendChild( option );
		} );
		return select;
	}

	function say( message, bad ) {
		var note = root.querySelector( '.aiwp-sec__say' );
		note.textContent = message || '';
		note.className = 'aiwp-sec__say' + ( bad ? ' is-bad' : ( message ? ' is-ok' : '' ) );
	}

	/**
	 * Removing asks for a word, typed. The word is the section's own name when
	 * the design uses it, because that deletion leaves holes in a live page.
	 */
	function confirmRemove( opts ) {
		var back = el( 'div', 'aiwp-sec__veil' );
		var box = el( 'div', 'aiwp-sec__dialog' );

		box.appendChild( el( 'h2', null, opts.title ) );
		box.appendChild( el( 'p', 'aiwp-sec__warn', opts.body ) );

		if ( opts.used && opts.used.length ) {
			var used = el( 'p', 'aiwp-sec__used' );
			used.textContent = config.i18n.usedByDesign.replace( '%s', opts.used.join( ', ' ) );
			box.appendChild( used );
		}

		var label = el( 'label', 'aiwp-sec__ask' );
		label.textContent = config.i18n.typeToConfirm.replace( '%s', opts.phrase );
		var input = el( 'input', 'aiwp-sec__input' );
		input.type = 'text';
		input.autocomplete = 'off';
		label.appendChild( input );
		box.appendChild( label );

		var row = el( 'p', 'aiwp-sec__buttons' );
		var cancel = el( 'button', 'button', config.i18n.cancel );
		cancel.type = 'button';
		var go = el( 'button', 'button button-link-delete', opts.action );
		go.type = 'button';
		go.disabled = true;
		row.appendChild( cancel );
		row.appendChild( go );
		box.appendChild( row );

		back.appendChild( box );
		document.body.appendChild( back );
		input.focus();

		function close() {
			document.body.removeChild( back );
			document.removeEventListener( 'keydown', onKey );
		}

		function onKey( e ) {
			if ( 'Escape' === e.key ) { close(); }
		}

		input.addEventListener( 'input', function () {
			go.disabled = input.value.trim().toLowerCase() !== opts.phrase.toLowerCase();
		} );

		cancel.addEventListener( 'click', close );
		document.addEventListener( 'keydown', onKey );

		go.addEventListener( 'click', function () {
			go.disabled = true;
			opts.confirm( input.value ).then( close );
		} );
	}

	function addFieldRow( section ) {
		var form = el( 'div', 'aiwp-sec__add' );
		var name = el( 'input', 'regular-text' );
		name.type = 'text';
		name.placeholder = config.i18n.fieldName;

		var type = typeSelect();
		var button = el( 'button', 'button button-secondary', config.i18n.addField );
		button.type = 'button';

		function submit() {
			if ( ! name.value.trim() ) {
				say( config.i18n.needName, true );
				name.focus();
				return;
			}
			button.disabled = true;
			post( '/fields', { section_id: section.id, label: name.value, type: type.value } )
				.then( function ( r ) {
					button.disabled = false;
					say( r.message, ! r.ok );
					if ( r.ok ) { draw( r.sections, r.focus ); }
				} );
		}

		name.addEventListener( 'keydown', function ( e ) {
			// Enter adds the field. It must not submit the whole page.
			if ( 'Enter' === e.key ) { e.preventDefault(); submit(); }
		} );
		button.addEventListener( 'click', submit );

		form.appendChild( name );
		form.appendChild( type );
		form.appendChild( button );
		return form;
	}

	/**
	 * The template line for a field, next to the field. Clicking copies it,
	 * because retyping {{image_url:hero.photo}} by hand is how typos get made.
	 */
	function copyable( reference, full ) {
		var code = el( 'button', 'aiwp-sec__ref' );
		code.type = 'button';
		code.textContent = reference;
		code.title = config.i18n.copyRef;
		code.setAttribute( 'aria-label', config.i18n.copyRef );

		code.addEventListener( 'click', function () {
			var flash = function ( word, bad ) {
				var was = code.textContent;
				code.textContent = word;
				code.classList.toggle( 'is-copied', ! bad );
				setTimeout( function () {
					code.textContent = was;
					code.classList.remove( 'is-copied' );
				}, 1200 );
			};

			// Older browsers, and any page the clipboard refuses us on —
			// which includes a window that does not have focus.
			var byHand = function () {
				var box = document.createElement( 'textarea' );
				box.value = full;
				box.setAttribute( 'readonly', 'readonly' );
				box.style.position = 'fixed';
				box.style.opacity = '0';
				document.body.appendChild( box );
				box.select();

				var won = false;
				try { won = document.execCommand( 'copy' ); } catch ( e ) { won = false; }
				document.body.removeChild( box );

				if ( won ) {
					say( '' );
					flash( config.i18n.copied );
					return;
				}

				// Never leave the click looking like nothing happened.
				flash( config.i18n.copyByHand, true );
				say( config.i18n.copyByHandLong, true );
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( full ).then(
					function () { say( '' ); flash( config.i18n.copied ); },
					byHand
				);
				return;
			}

			byHand();
		} );

		return code;
	}

	function drawSection( section ) {
		var card = el( 'div', 'aiwp-sec' + ( section.owner ? ' is-mine' : '' ) );

		var head = el( 'div', 'aiwp-sec__head' );
		var title = el( 'div', 'aiwp-sec__title' );
		title.appendChild( el( 'strong', null, section.label ) );
		title.appendChild( el( 'code', null, section.id ) );
		if ( ! section.owner ) {
			title.appendChild( el( 'span', 'aiwp-sec__tag', config.i18n.fromDesign ) );
		}
		head.appendChild( title );

		var remove = el( 'button', 'aiwp-sec__icon aiwp-sec__icon--section' );
		remove.type = 'button';
		remove.title = config.i18n.removeSection;
		remove.setAttribute( 'aria-label', config.i18n.removeSectionTitle.replace( '%s', section.label ) );
		remove.appendChild( el( 'span', 'dashicons dashicons-trash' ) );
		remove.addEventListener( 'click', function () {
			confirmRemove( {
				title: config.i18n.removeSectionTitle.replace( '%s', section.label ),
				body: section.owner ? config.i18n.removeOwnBody : config.i18n.removeDesignBody,
				used: section.used_by_design,
				phrase: section.confirm,
				action: config.i18n.removeSection,
				confirm: function ( typed ) {
					return post( '/sections/remove', { section_id: section.id, confirm: typed } )
						.then( function ( r ) {
							say( r.message, ! r.ok );
							if ( r.ok ) { draw( r.sections, r.focus ); }
						} );
				}
			} );
		} );
		head.appendChild( remove );
		card.appendChild( head );

		var list = el( 'ul', 'aiwp-sec__fields' );
		section.fields.forEach( function ( field ) {
			var item = el( 'li' );
			item.appendChild( el( 'span', 'aiwp-sec__fname', field.label ) );
			item.appendChild( el( 'span', 'aiwp-sec__ftype', field.type_label ) );
			item.appendChild( copyable( field.reference, field.snippet || field.reference ) );

			var drop = el( 'button', 'aiwp-sec__icon' );
			drop.type = 'button';
			drop.title = config.i18n.removeField;
			drop.setAttribute( 'aria-label', config.i18n.removeFieldTitle.replace( '%s', field.label ) );
			drop.appendChild( el( 'span', 'dashicons dashicons-no-alt' ) );
			drop.addEventListener( 'click', function () {
				confirmRemove( {
					title: config.i18n.removeFieldTitle.replace( '%s', field.label ),
					body: config.i18n.removeFieldBody,
					phrase: 'remove',
					action: config.i18n.removeField,
					confirm: function ( typed ) {
						return post( '/fields/remove', { section_id: section.id, name: field.name, confirm: typed } )
							.then( function ( r ) {
								say( r.message, ! r.ok );
								if ( r.ok ) { draw( r.sections, r.focus ); }
							} );
					}
				} );
			} );

			item.appendChild( drop );
			list.appendChild( item );
		} );
		card.appendChild( list );
		card.appendChild( addFieldRow( section ) );

		return card;
	}

	function drawNewSection() {
		var card = el( 'div', 'aiwp-sec aiwp-sec--new' );
		card.appendChild( el( 'div', 'aiwp-sec__head' ) ).appendChild( el( 'strong', null, config.i18n.newSection ) );

		var form = el( 'div', 'aiwp-sec__add' );
		var name = el( 'input', 'regular-text' );
		name.type = 'text';
		name.placeholder = config.i18n.sectionName;

		var first = el( 'input', 'regular-text' );
		first.type = 'text';
		first.placeholder = config.i18n.firstField;

		var type = typeSelect();
		var button = el( 'button', 'button button-primary', config.i18n.addSection );
		button.type = 'button';

		button.addEventListener( 'click', function () {
			if ( ! name.value.trim() || ! first.value.trim() ) {
				say( config.i18n.needBoth, true );
				return;
			}
			button.disabled = true;
			post( '/sections', { label: name.value, field_label: first.value, field_type: type.value } )
				.then( function ( r ) {
					button.disabled = false;
					say( r.message, ! r.ok );
					if ( r.ok ) { name.value = ''; first.value = ''; draw( r.sections, r.focus ); }
				} );
		} );

		form.appendChild( name );
		form.appendChild( first );
		form.appendChild( type );
		form.appendChild( button );
		card.appendChild( form );

		return card;
	}

	/**
	 * Whatever was just touched goes to the top, then the person's own
	 * sections, then the design's. A section added at the bottom of a growing
	 * list is a section somebody has to go looking for.
	 */
	function order( sections, focus ) {
		var weight = function ( section ) {
			if ( focus && section.id === focus ) { return 0; }
			return section.owner ? 1 : 2;
		};

		return sections.slice().sort( function ( a, b ) { return weight( a ) - weight( b ); } );
	}

	function draw( sections, focus ) {
		var list = root.querySelector( '.aiwp-sec__list' );
		list.innerHTML = '';

		// Adding comes first. It is the reason most people open this screen.
		list.appendChild( drawNewSection() );

		order( sections, focus ).forEach( function ( section ) {
			var card = drawSection( section );
			if ( focus && section.id === focus ) {
				card.classList.add( 'is-fresh' );
			}
			list.appendChild( card );
		} );
	}

	fetch( config.root + '/sections/list?page_id=' + config.pageId, { headers: { 'X-WP-Nonce': config.nonce } } )
		.then( function ( r ) { return r.json(); } )
		.then( function ( r ) { draw( r.sections || [] ); } );
}() );
