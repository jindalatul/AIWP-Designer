/**
 * AIWP Designer trusted behavior library.
 *
 * AI never writes JavaScript. It declares data-aiwp-behavior="name" and this
 * file supplies the implementation. Everything here is progressive enhancement:
 * with JS off the markup still reads correctly.
 */
( function () {
	'use strict';

	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function each( root, selector, fn ) {
		Array.prototype.forEach.call( root.querySelectorAll( selector ), fn );
	}

	var behaviors = {};

	/* ------------------------------------------------------------ accordion */
	behaviors.accordion = function ( el ) {
		var items = el.querySelectorAll( '[data-aiwp-accordion-item]' );
		if ( ! items.length ) {
			return;
		}
		var single = el.getAttribute( 'data-aiwp-accordion-single' ) === 'true';

		Array.prototype.forEach.call( items, function ( item, index ) {
			var trigger = item.querySelector( '[data-aiwp-accordion-trigger]' );
			var panel = item.querySelector( '[data-aiwp-accordion-panel]' );
			if ( ! trigger || ! panel ) {
				return;
			}

			var panelId = panel.id || 'aiwp-panel-' + Math.random().toString( 36 ).slice( 2, 8 ) + '-' + index;
			panel.id = panelId;

			var button = trigger;
			if ( trigger.tagName !== 'BUTTON' ) {
				button = document.createElement( 'button' );
				button.type = 'button';
				button.className = trigger.className;
				button.innerHTML = trigger.innerHTML;
				button.setAttribute( 'data-aiwp-accordion-trigger', '' );
				trigger.parentNode.replaceChild( button, trigger );
			}

			var open = item.hasAttribute( 'data-aiwp-open' );
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			button.setAttribute( 'aria-controls', panelId );
			panel.hidden = ! open;

			button.addEventListener( 'click', function () {
				var expanded = button.getAttribute( 'aria-expanded' ) === 'true';

				if ( single && ! expanded ) {
					each( el, '[data-aiwp-accordion-trigger]', function ( other ) {
						if ( other === button ) {
							return;
						}
						other.setAttribute( 'aria-expanded', 'false' );
						var otherPanel = document.getElementById( other.getAttribute( 'aria-controls' ) );
						if ( otherPanel ) {
							otherPanel.hidden = true;
						}
					} );
				}

				button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
				panel.hidden = expanded;
			} );
		} );
	};

	/* ----------------------------------------------------------------- tabs */
	behaviors.tabs = function ( el ) {
		var list = el.querySelector( '[data-aiwp-tablist]' );
		var tabs = el.querySelectorAll( '[data-aiwp-tab]' );
		var panels = el.querySelectorAll( '[data-aiwp-tabpanel]' );
		if ( ! list || ! tabs.length || tabs.length !== panels.length ) {
			return;
		}

		list.setAttribute( 'role', 'tablist' );

		function select( index ) {
			Array.prototype.forEach.call( tabs, function ( tab, i ) {
				var active = i === index;
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', active ? '0' : '-1' );
				tab.classList.toggle( 'is-active', active );
				panels[ i ].hidden = ! active;
				panels[ i ].classList.toggle( 'is-active', active );
			} );
		}

		Array.prototype.forEach.call( tabs, function ( tab, i ) {
			var id = tab.id || 'aiwp-tab-' + Math.random().toString( 36 ).slice( 2, 8 ) + '-' + i;
			tab.id = id;
			tab.setAttribute( 'role', 'tab' );
			panels[ i ].setAttribute( 'role', 'tabpanel' );
			panels[ i ].setAttribute( 'aria-labelledby', id );
			if ( ! panels[ i ].hasAttribute( 'tabindex' ) ) {
				panels[ i ].setAttribute( 'tabindex', '0' );
			}
			tab.setAttribute( 'aria-controls', panels[ i ].id || ( panels[ i ].id = id + '-panel' ) );

			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				select( i );
			} );

			tab.addEventListener( 'keydown', function ( e ) {
				var next = null;
				if ( e.key === 'ArrowRight' ) {
					next = ( i + 1 ) % tabs.length;
				} else if ( e.key === 'ArrowLeft' ) {
					next = ( i - 1 + tabs.length ) % tabs.length;
				} else if ( e.key === 'Home' ) {
					next = 0;
				} else if ( e.key === 'End' ) {
					next = tabs.length - 1;
				}
				if ( next !== null ) {
					e.preventDefault();
					select( next );
					tabs[ next ].focus();
				}
			} );
		} );

		select( 0 );
	};

	/* ---------------------------------------------------------------- modal */
	behaviors.modal = function ( el ) {
		var dialog = el.querySelector( '[data-aiwp-modal-dialog]' );
		var openers = el.querySelectorAll( '[data-aiwp-modal-open]' );
		if ( ! dialog || ! openers.length ) {
			return;
		}

		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.hidden = true;
		// Tells the stylesheet the behavior is live; until now the dialog was
		// hidden by CSS alone.
		dialog.setAttribute( 'data-aiwp-ready', '' );

		var lastFocus = null;

		function open() {
			lastFocus = document.activeElement;
			dialog.hidden = false;
			el.classList.add( 'is-open' );
			var focusable = dialog.querySelector( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
			( focusable || dialog ).focus();
			document.addEventListener( 'keydown', onKey );
		}

		function close() {
			dialog.hidden = true;
			el.classList.remove( 'is-open' );
			document.removeEventListener( 'keydown', onKey );
			if ( lastFocus ) {
				lastFocus.focus();
			}
		}

		var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), '
			+ 'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				close();
				return;
			}

			if ( e.key !== 'Tab' ) {
				return;
			}

			// aria-modal="true" tells a screen reader nothing outside the
			// dialog exists. Without this, Tab walked straight out of it into
			// the page behind, which is still there and still clickable — the
			// promise was made in the markup and broken by the keyboard.
			var items = dialog.querySelectorAll( FOCUSABLE );
			if ( ! items.length ) {
				e.preventDefault();
				dialog.focus();
				return;
			}

			var first = items[ 0 ];
			var last  = items[ items.length - 1 ];

			if ( e.shiftKey && ( document.activeElement === first || document.activeElement === dialog ) ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}

		Array.prototype.forEach.call( openers, function ( opener ) {
			opener.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				open();
			} );
		} );

		each( el, '[data-aiwp-modal-close]', function ( closer ) {
			closer.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				close();
			} );
		} );
	};

	/* -------------------------------------------------------------- counter */
	behaviors.counter = function ( el ) {
		var target = parseFloat( el.getAttribute( 'data-aiwp-counter-to' ) || el.textContent.replace( /[^0-9.\-]/g, '' ) );
		if ( isNaN( target ) ) {
			return;
		}
		var prefix = el.getAttribute( 'data-aiwp-counter-prefix' ) || '';
		var suffix = el.getAttribute( 'data-aiwp-counter-suffix' ) || '';
		var decimals = ( String( target ).split( '.' )[ 1 ] || '' ).length;

		function format( value ) {
			return prefix + value.toFixed( decimals ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' ) + suffix;
		}

		if ( reduceMotion || ! window.IntersectionObserver ) {
			el.textContent = format( target );
			return;
		}

		el.textContent = format( 0 );

		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}
				observer.unobserve( el );
				var start = null;
				var duration = 1200;
				function step( now ) {
					if ( start === null ) {
						start = now;
					}
					var progress = Math.min( 1, ( now - start ) / duration );
					var eased = 1 - Math.pow( 1 - progress, 3 );
					el.textContent = format( target * eased );
					if ( progress < 1 ) {
						window.requestAnimationFrame( step );
					}
				}
				window.requestAnimationFrame( step );
			} );
		}, { threshold: 0.4 } );

		observer.observe( el );
	};

	/* --------------------------------------------------------------- reveal */
	behaviors.reveal = function ( el ) {
		if ( reduceMotion || ! window.IntersectionObserver ) {
			el.classList.add( 'is-revealed' );
			return;
		}

		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					entry.target.classList.add( 'is-revealed' );
					observer.unobserve( entry.target );
				}
			} );
		}, { threshold: 0.15 } );

		observer.observe( el );
	};

	/* --------------------------------------------------------------- sticky */
	behaviors.sticky = function ( el ) {
		var offset = parseInt( el.getAttribute( 'data-aiwp-sticky-offset' ) || '0', 10 );
		el.style.position = 'sticky';
		el.style.top = offset + 'px';

		if ( ! window.IntersectionObserver ) {
			return;
		}

		var sentinel = document.createElement( 'div' );
		sentinel.setAttribute( 'aria-hidden', 'true' );
		sentinel.style.height = '1px';
		el.parentNode.insertBefore( sentinel, el );

		new IntersectionObserver( function ( entries ) {
			el.classList.toggle( 'is-stuck', ! entries[ 0 ].isIntersecting );
		}, { threshold: 0 } ).observe( sentinel );
	};

	/* ------------------------------------------------------------- carousel */
	behaviors.carousel = function ( el ) {
		var track = el.querySelector( '[data-aiwp-carousel-track]' );
		if ( ! track ) {
			return;
		}
		var slides = track.children;
		if ( slides.length < 2 ) {
			return;
		}

		track.setAttribute( 'role', 'group' );
		track.setAttribute( 'aria-roledescription', 'carousel' );
		track.style.overflowX = 'auto';
		track.style.scrollSnapType = 'x mandatory';
		track.style.scrollBehavior = reduceMotion ? 'auto' : 'smooth';

		Array.prototype.forEach.call( slides, function ( slide ) {
			slide.style.scrollSnapAlign = 'start';
			slide.style.flex = slide.style.flex || '0 0 auto';
		} );

		function scrollBySlide( direction ) {
			track.scrollBy( { left: direction * slides[ 0 ].getBoundingClientRect().width, behavior: track.style.scrollBehavior } );
		}

		each( el, '[data-aiwp-carousel-prev]', function ( btn ) {
			btn.addEventListener( 'click', function () {
				scrollBySlide( -1 );
			} );
		} );
		each( el, '[data-aiwp-carousel-next]', function ( btn ) {
			btn.addEventListener( 'click', function () {
				scrollBySlide( 1 );
			} );
		} );

		track.setAttribute( 'tabindex', '0' );
		track.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowRight' ) {
				e.preventDefault();
				scrollBySlide( 1 );
			} else if ( e.key === 'ArrowLeft' ) {
				e.preventDefault();
				scrollBySlide( -1 );
			}
		} );
	};

	function init( root ) {
		each( root || document, '[data-aiwp-behavior]', function ( el ) {
			if ( el.getAttribute( 'data-aiwp-initialised' ) === 'true' ) {
				return;
			}
			el.setAttribute( 'data-aiwp-initialised', 'true' );

			( el.getAttribute( 'data-aiwp-behavior' ) || '' ).split( /\s+/ ).forEach( function ( name ) {
				if ( behaviors[ name ] ) {
					try {
						behaviors[ name ]( el );
					} catch ( err ) {
						if ( window.console ) {
							window.console.warn( 'AIWP behavior "' + name + '" failed:', err );
						}
					}
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init( document );
		} );
	} else {
		init( document );
	}

	window.AIWPBehaviors = { init: init, registry: behaviors };
}() );
