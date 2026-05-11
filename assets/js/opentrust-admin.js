/**
 * Ettic admin design system — JS template.
 *
 * BEFORE USING: replace `opentrust` everywhere with your plugin's slug.
 * Vanilla JS, no jQuery. Provides: media pickers, color sync, dirty
 * tracking, char counters, hex validation, form submission, notices,
 * toasts, confirm modals, loading state. Public surfaces (`showToast`,
 * `showConfirm`, `setLoading`) are local to this IIFE — expose them on
 * a namespace if you need cross-script access.
 */

(function () {
	'use strict';

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	function init() {
		initMediaPickers();
		initColorPickers();
		initRowDirtyMarks();
		initDirtyTracking();
		initCharCounters();
		initHexValidation();
		initFormSubmission();
		initNoticeDismissal();
		initWpNoticeScoop();
		initToastFromUrl();
		initDiscardFlash();
	}

	// Media pickers (logo + agency favicon, via wp.media)
	function initMediaPickers() {
		var pickers = document.querySelectorAll( '[data-opentrust-media-picker]' );
		Array.prototype.forEach.call( pickers, bindMediaPicker );
	}

	function bindMediaPicker( root ) {
		var idInput  = root.querySelector( '[data-opentrust-media-id]' );
		var preview  = root.querySelector( '[data-opentrust-media-preview]' );
		var pickBtn  = root.querySelector( '[data-opentrust-media-pick]' );
		var clearBtn = root.querySelector( '[data-opentrust-media-clear]' );
		if ( ! idInput || ! pickBtn ) {
			return;
		}

		var frame;
		pickBtn.addEventListener( 'click', function ( ev ) {
			ev.preventDefault();
			if ( ! frame ) {
				frame = wp.media( {
					title: pickBtn.textContent || 'Choose image',
					button: { text: 'Use this image' },
					library: { type: [ 'image/png', 'image/jpeg', 'image/webp', 'image/svg+xml' ] },
					multiple: false
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					idInput.value = attachment.id;
					if ( preview ) {
						preview.innerHTML = '';
						var img = document.createElement( 'img' );
						img.src = attachment.url;
						img.alt = '';
						preview.appendChild( img );
						preview.classList.add( 'opentrust-media__preview--filled' );
					}
					if ( clearBtn ) {
						clearBtn.style.display = '';
					}
					idInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );
			}
			frame.open();
		} );

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				idInput.value = '0';
				if ( preview ) {
					preview.innerHTML = '';
					preview.classList.remove( 'opentrust-media__preview--filled' );
				}
				clearBtn.style.display = 'none';
				idInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		}
	}

	// Color picker — native swatch synced with hex text input
	function initColorPickers() {
		document.querySelectorAll( '.opentrust-admin .opentrust-color' ).forEach( function ( color ) {
			var swatch = color.querySelector( 'input[type="color"]' );
			var text   = color.querySelector( 'input[type="text"]' );
			if ( ! swatch || ! text ) {
				return;
			}

			swatch.addEventListener( 'input', function () {
				text.value = swatch.value.toUpperCase();
				text.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );

			text.addEventListener( 'input', function () {
				var v = text.value.trim();
				if ( /^#?[0-9a-fA-F]{6}$/.test( v ) ) {
					swatch.value = v.charAt( 0 ) === '#' ? v : '#' + v;
				}
			} );
		} );
	}

	// Per-row dirty mark — auto-injected, opacity toggled via .is-dirty
	function initRowDirtyMarks() {
		document.querySelectorAll( '.opentrust-admin .opentrust-row' ).forEach( function ( row ) {
			var control = row.querySelector( '.opentrust-row__control' );
			if ( ! control ) {
				return;
			}
			var mark = document.createElement( 'span' );
			mark.className = 'opentrust-row__dirty-mark';
			mark.setAttribute( 'aria-hidden', 'true' );
			control.appendChild( mark );
		} );
	}

	// Dirty tracking — per-field signature compared against page-load snapshot.
	// A "field" = inputs sharing a `name` (text, radio group, checkbox group, hidden+checkbox toggle).
	var dirtySet, initialSig;
	var dirtyEl, numEl, labelEl, saveBtn, discardBtn, form;
	var IGNORE_NAMES = { option_page: 1, action: 1, _wpnonce: 1, _wp_http_referer: 1, submit: 1 };

	function initDirtyTracking() {
		dirtyEl    = document.querySelector( '[data-dirty]' );
		numEl      = document.querySelector( '[data-dirty-num]' );
		labelEl    = document.querySelector( '[data-dirty-label]' );
		saveBtn    = document.querySelector( '[data-save]' );
		discardBtn = document.querySelector( '[data-discard]' );
		// Prefer the form the topbar Save is wired to (HTML5 `form="..."` attr,
		// which points at the active tab's Settings API form on multi-form
		// screens like AI Chat). Fall back to the first form inside the wrap.
		var saveTargetId = saveBtn ? saveBtn.getAttribute( 'form' ) : null;
		form = saveTargetId ? document.getElementById( saveTargetId ) : null;
		if ( ! form ) {
			form = document.querySelector( '.opentrust-admin form' );
		}
		if ( ! dirtyEl || ! saveBtn || ! form ) {
			return;
		}

		dirtySet   = {};
		initialSig = {};
		captureInitialState();
		renderDirty();

		form.addEventListener( 'input', onInteract );
		form.addEventListener( 'change', onInteract );
	}

	function isTrackable( name ) {
		return !! name && ! IGNORE_NAMES[ name ];
	}

	function cssEscape( s ) {
		if ( window.CSS && CSS.escape ) {
			return CSS.escape( s );
		}
		return String( s ).replace( /(["\\])/g, '\\$1' );
	}

	function fieldSignature( name ) {
		var els = form.querySelectorAll( '[name="' + cssEscape( name ) + '"]' );
		if ( ! els.length ) {
			return '';
		}
		var hasCheckable = false;
		for ( var i = 0; i < els.length; i++ ) {
			if ( els[ i ].type === 'checkbox' || els[ i ].type === 'radio' ) {
				hasCheckable = true;
				break;
			}
		}
		if ( hasCheckable ) {
			var vals = [];
			for ( var j = 0; j < els.length; j++ ) {
				var el = els[ j ];
				if ( ( el.type === 'checkbox' || el.type === 'radio' ) && el.checked ) {
					vals.push( el.value );
				}
			}
			vals.sort();
			return vals.join( '|' );
		}
		// Last wins, matching PHP $_POST behavior for duplicate names.
		return els[ els.length - 1 ].value;
	}

	function captureInitialState() {
		var seen = {};
		var els  = form.querySelectorAll( '[name]' );
		for ( var i = 0; i < els.length; i++ ) {
			var name = els[ i ].name;
			if ( seen[ name ] || ! isTrackable( name ) ) {
				continue;
			}
			seen[ name ]       = 1;
			initialSig[ name ] = fieldSignature( name );
		}
	}

	function updateFieldDirty( name ) {
		if ( ! isTrackable( name ) || ! ( name in initialSig ) ) {
			return;
		}
		var current = fieldSignature( name );
		if ( current === initialSig[ name ] ) {
			delete dirtySet[ name ];
		} else {
			dirtySet[ name ] = 1;
		}
		var el = form.querySelector( '[name="' + cssEscape( name ) + '"]' );
		if ( el ) {
			var row = el.closest( '.opentrust-row' );
			if ( row ) {
				row.classList.toggle( 'is-dirty', !! dirtySet[ name ] );
			}
		}
		renderDirty();
	}

	function onInteract( ev ) {
		var t = ev.target;
		if ( ! t.matches || ! t.matches( 'input, select, textarea' ) ) {
			return;
		}
		if ( ! t.name ) {
			return;
		}
		updateFieldDirty( t.name );
	}

	function dirtyCount() {
		return Object.keys( dirtySet ).length;
	}

	function renderDirty() {
		if ( ! dirtyEl ) {
			return;
		}
		var count = dirtyCount();
		if ( count === 0 ) {
			dirtyEl.classList.add( 'is-clean' );
			if ( labelEl ) {
				labelEl.textContent = '';
			}
			if ( saveBtn ) {
				saveBtn.setAttribute( 'disabled', '' );
			}
			if ( discardBtn ) {
				discardBtn.setAttribute( 'disabled', '' );
			}
		} else {
			dirtyEl.classList.remove( 'is-clean' );
			if ( numEl ) {
				numEl.textContent = count;
			}
			if ( labelEl ) {
				labelEl.textContent = count === 1 ? ' unsaved change' : ' unsaved changes';
			}
			if ( saveBtn ) {
				saveBtn.removeAttribute( 'disabled' );
			}
			if ( discardBtn ) {
				discardBtn.removeAttribute( 'disabled' );
			}
		}
	}

	// Char counters under capped text inputs
	function initCharCounters() {
		document.querySelectorAll( '[data-counter]' ).forEach( function ( el ) {
			var max = parseInt( el.getAttribute( 'maxlength' ), 10 );
			if ( ! max ) {
				return;
			}
			var counter = document.createElement( 'div' );
			counter.className = 'opentrust-field-counter';
			el.parentNode.appendChild( counter );

			function update() {
				var len = el.value.length;
				counter.textContent = len + ' / ' + max;
				counter.classList.toggle( 'opentrust-field-counter--warn', len >= max - 5 && len < max );
				counter.classList.toggle( 'opentrust-field-counter--error', len >= max );
			}

			el.addEventListener( 'input', update );
			update();
		} );
	}

	// Live hex validation
	function initHexValidation() {
		document.querySelectorAll( '[data-validate-hex]' ).forEach( function ( el ) {
			var colorEl = el.closest( '.opentrust-color' );
			if ( ! colorEl ) {
				return;
			}
			var msgEl = null;

			function validate() {
				var v = el.value.trim();
				var valid = /^#?[0-9a-fA-F]{6}$/.test( v );
				if ( v && ! valid ) {
					colorEl.classList.add( 'is-invalid' );
					el.classList.add( 'opentrust-input--invalid' );
					if ( ! msgEl ) {
						msgEl = document.createElement( 'div' );
						msgEl.className = 'opentrust-field-msg opentrust-field-msg--error';
						msgEl.innerHTML = '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="5"/><line x1="6" y1="3.5" x2="6" y2="6.5"/><circle cx="6" cy="8.5" r="0.6" fill="currentColor"/></svg><span>Use a 6-character hex like <code>#0F5CFA</code></span>';
						colorEl.parentNode.appendChild( msgEl );
					}
				} else {
					colorEl.classList.remove( 'is-invalid' );
					el.classList.remove( 'opentrust-input--invalid' );
					if ( msgEl ) {
						msgEl.remove();
						msgEl = null;
					}
				}
			}

			el.addEventListener( 'input', validate );
		} );
	}

	// Module-scoped so initTabSwitchGuard and the beforeunload listener can
	// both consult / flip it. Flipped to true when the user is intentionally
	// leaving the page (Save click, Discard click, confirmed tab switch).
	var navConsented = false;

	// Form submission — Save = native submit, Discard = reload with sessionStorage flag
	function initFormSubmission() {
		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function () {
			navConsented = true;
			setLoading( saveBtn, true, 'Saving…' );
			setLoading( discardBtn, true, 'Saving…' );
		} );

		if ( discardBtn ) {
			discardBtn.addEventListener( 'click', function ( e ) {
				if ( discardBtn.hasAttribute( 'disabled' ) ) {
					return;
				}
				e.preventDefault();
				navConsented = true;
				try {
					sessionStorage.setItem( 'opentrust_discarded', '1' );
				} catch ( err ) { /* private mode / quota */ }
				window.location.reload();
			} );
		}

		// Browser-native catch-all: hard nav, window close, back-button. The
		// custom tabbar guard below intercepts in-app tab clicks so the user
		// gets a typed modal instead of the generic browser confirm.
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( navConsented || dirtyCount() === 0 ) {
				return;
			}
			// Modern browsers ignore the returnValue text — they show their own
			// generic "Leave site?" prompt — but assigning it is the contract.
			e.preventDefault();
			e.returnValue = '';
			return '';
		} );

		initTabSwitchGuard();
	}

	// Intercept tabbar clicks when the current tab has unsaved changes. Each
	// tab is a separate page load, so switching always drops in-flight edits —
	// the modal makes that explicit and offers a clean off-ramp.
	function initTabSwitchGuard() {
		var tabs = document.querySelectorAll( '.opentrust-admin .opentrust-tabbar__tab' );
		if ( ! tabs.length ) {
			return;
		}
		Array.prototype.forEach.call( tabs, function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				if ( dirtyCount() === 0 || tab.classList.contains( 'is-active' ) ) {
					return;
				}
				var target = tab.getAttribute( 'href' );
				if ( ! target ) {
					return;
				}
				e.preventDefault();
				var n = dirtyCount();
				var i18n = ( window.OpenTrustAdmin && window.OpenTrustAdmin.i18n ) || {};
				var manyText = ( i18n.tabSwitchManyUnsaved || 'You have %d unsaved changes on this tab. Switching tabs will discard them.' ).replace( '%d', String( n ) );
				showConfirm( {
					title:       i18n.tabSwitchTitle || 'Discard unsaved changes?',
					lede:        n === 1
						? ( i18n.tabSwitchOneUnsaved || 'You have 1 unsaved change on this tab. Switching tabs will discard it.' )
						: manyText,
					body:        '<p>' + ( i18n.tabSwitchBody || 'Each tab saves independently. Save first to keep your changes, or discard them and switch.' ) + '</p>',
					confirmText: i18n.tabSwitchConfirm || 'Discard and switch',
					cancelText:  i18n.tabSwitchCancel || 'Stay on this tab',
					danger:      true,
					onConfirm:   function ( done ) {
						navConsented = true;
						try {
							sessionStorage.setItem( 'opentrust_discarded', '1' );
						} catch ( err ) { /* private mode / quota */ }
						window.location.href = target;
						done();
					}
				} );
			} );
		} );
	}

	// Notice dismissal
	function initNoticeDismissal() {
		document.querySelectorAll( '.opentrust-admin .opentrust-notice__close' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var notice = btn.closest( '.opentrust-notice' );
				if ( ! notice ) {
					return;
				}
				notice.style.transition = 'opacity 160ms ease, transform 160ms ease';
				notice.style.opacity = '0';
				notice.style.transform = 'translateY(-4px)';
				setTimeout( function () { notice.remove(); }, 180 );
			} );
		} );
	}

	// Toast system — floating stack on body
	var toastStack;

	function getToastStack() {
		if ( ! toastStack ) {
			toastStack = document.querySelector( '.opentrust-toast-stack' );
			if ( ! toastStack ) {
				toastStack = document.createElement( 'div' );
				toastStack.className = 'opentrust-toast-stack';
				toastStack.setAttribute( 'aria-live', 'polite' );
				document.body.appendChild( toastStack );
			}
		}
		return toastStack;
	}

	function toastIcon( type ) {
		if ( type === 'success' ) {
			return '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3,6.5 5,8.5 9,4"/></svg>';
		}
		if ( type === 'error' ) {
			return '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="3" x2="9" y2="9"/><line x1="9" y1="3" x2="3" y2="9"/></svg>';
		}
		return '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="4.5"/><line x1="6" y1="5.5" x2="6" y2="8.5"/><circle cx="6" cy="3.6" r="0.4" fill="currentColor"/></svg>';
	}

	function showToast( opts ) {
		var type     = opts.type || 'success';
		var message  = opts.message || '';
		var duration = opts.duration || 3500;
		var stack    = getToastStack();

		var toast = document.createElement( 'div' );
		toast.className = 'opentrust-toast opentrust-toast--' + type;
		toast.innerHTML =
			'<span class="opentrust-toast__icon">' + toastIcon( type ) + '</span>' +
			'<span class="opentrust-toast__msg"></span>' +
			'<button class="opentrust-toast__close" aria-label="Dismiss" type="button">&times;</button>';
		toast.querySelector( '.opentrust-toast__msg' ).textContent = message;
		stack.appendChild( toast );

		var timer;
		var dismiss = function () {
			clearTimeout( timer );
			if ( toast.classList.contains( 'is-leaving' ) ) {
				return;
			}
			toast.classList.add( 'is-leaving' );
			setTimeout( function () { toast.remove(); }, 200 );
		};
		toast.querySelector( '.opentrust-toast__close' ).addEventListener( 'click', dismiss );
		timer = setTimeout( dismiss, duration );
	}

	function initToastFromUrl() {
		var params  = new URLSearchParams( window.location.search );
		var changed = false;

		if ( params.get( 'settings-updated' ) === 'true' ) {
			showToast( { type: 'success', message: 'Settings saved.', duration: 7000 } );
			params.delete( 'settings-updated' );
			changed = true;
		}

		// Extend here: add `params.get( 'yourflag' )` branches that map admin
		// redirects (e.g. ?yourflag=sent after a wp_safe_redirect from a form
		// handler) to toasts, then `params.delete()` to keep the URL clean.

		if ( changed ) {
			var newSearch = params.toString();
			var newUrl = window.location.pathname + ( newSearch ? '?' + newSearch : '' );
			window.history.replaceState( {}, '', newUrl );
		}
	}

	// Discard flash — sessionStorage flag set pre-reload, consumed here.
	function initDiscardFlash() {
		var flag;
		try {
			flag = sessionStorage.getItem( 'opentrust_discarded' );
			if ( flag ) {
				sessionStorage.removeItem( 'opentrust_discarded' );
			}
		} catch ( err ) { return; }
		if ( flag === '1' ) {
			showToast( { type: 'info', message: 'Changes discarded.', duration: 6000 } );
		}
	}

	// WP notice scooper — options-head.php auto-calls settings_errors(), rendering a duplicate .notice
	// above our wrap. Reroute into toasts. Tight scope (only id^="setting-error-") so unrelated admin
	// notices stay where users expect them.
	function initWpNoticeScoop() {
		var wrap = document.querySelector( '.wrap.opentrust-admin' );
		if ( ! wrap || ! wrap.parentNode ) {
			return;
		}
		var notices = wrap.parentNode.querySelectorAll( 'div[id^="setting-error-"]' );
		Array.prototype.forEach.call( notices, function ( notice ) {
			var msgEl = notice.querySelector( 'p' ) || notice;
			var msg   = ( msgEl.textContent || '' ).trim();
			if ( ! msg ) {
				notice.remove();
				return;
			}
			var type = 'info';
			if ( notice.classList.contains( 'notice-success' ) || notice.classList.contains( 'updated' ) ) {
				type = 'success';
			} else if ( notice.classList.contains( 'notice-error' ) || notice.classList.contains( 'error' ) ) {
				type = 'error';
			} else if ( notice.classList.contains( 'notice-warning' ) ) {
				type = 'info';
			}
			showToast( { type: type, message: msg, duration: type === 'error' ? 12000 : 8000 } );
			notice.remove();
		} );
	}

	// Confirm modal
	function showConfirm( opts ) {
		var title       = opts.title;
		var lede        = opts.lede || '';
		var body        = opts.body || '';
		var confirmText = opts.confirmText || 'Confirm';
		var cancelText  = opts.cancelText || 'Cancel';
		var danger      = ! ! opts.danger;
		var onConfirm   = opts.onConfirm;

		var backdrop = document.createElement( 'div' );
		backdrop.className = 'opentrust-modal-backdrop';
		backdrop.innerHTML =
			'<div class="opentrust-modal" role="dialog" aria-modal="true">' +
				'<div class="opentrust-modal__head">' +
					'<span class="opentrust-modal__icon ' + ( danger ? 'opentrust-modal__icon--danger' : 'opentrust-modal__icon--warn' ) + '">' +
						'<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
							'<path d="M9 2.2L17 16H1L9 2.2Z"/><line x1="9" y1="7.5" x2="9" y2="11"/><circle cx="9" cy="13" r="0.7" fill="currentColor"/>' +
						'</svg>' +
					'</span>' +
					'<div class="opentrust-modal__head-text">' +
						'<h3 data-modal-title></h3>' +
						( lede ? '<p class="opentrust-modal__lede" data-modal-lede></p>' : '' ) +
					'</div>' +
				'</div>' +
				'<div class="opentrust-modal__body" data-modal-body></div>' +
				'<div class="opentrust-modal__foot">' +
					'<button class="opentrust-btn opentrust-btn--ghost" data-cancel type="button"></button>' +
					'<button class="opentrust-btn ' + ( danger ? 'opentrust-btn--danger' : 'opentrust-btn--primary' ) + '" data-confirm type="button"></button>' +
				'</div>' +
			'</div>';
		backdrop.querySelector( '[data-modal-title]' ).textContent = title;
		if ( lede ) {
			backdrop.querySelector( '[data-modal-lede]' ).textContent = lede;
		}
		backdrop.querySelector( '[data-modal-body]' ).innerHTML = body;
		backdrop.querySelector( '[data-cancel]' ).textContent  = cancelText;
		backdrop.querySelector( '[data-confirm]' ).textContent = confirmText;

		document.body.appendChild( backdrop );

		var confirmBtn = backdrop.querySelector( '[data-confirm]' );
		var cancelBtn  = backdrop.querySelector( '[data-cancel]' );

		function close() {
			if ( backdrop.classList.contains( 'is-leaving' ) ) {
				return;
			}
			backdrop.classList.add( 'is-leaving' );
			setTimeout( function () { backdrop.remove(); }, 140 );
		}

		cancelBtn.addEventListener( 'click', function () {
			if ( confirmBtn.classList.contains( 'opentrust-btn--loading' ) ) {
				return;
			}
			close();
		} );

		confirmBtn.addEventListener( 'click', function () {
			if ( confirmBtn.classList.contains( 'opentrust-btn--loading' ) ) {
				return;
			}
			setLoading( confirmBtn, true, 'Working…' );
			cancelBtn.setAttribute( 'disabled', '' );
			Promise.resolve( onConfirm && onConfirm() ).then( function () {
				setLoading( confirmBtn, false );
				cancelBtn.removeAttribute( 'disabled' );
				close();
			} ).catch( function () {
				setLoading( confirmBtn, false );
				cancelBtn.removeAttribute( 'disabled' );
				close();
			} );
		} );

		backdrop.addEventListener( 'click', function ( e ) {
			if ( e.target === backdrop && ! confirmBtn.classList.contains( 'opentrust-btn--loading' ) ) {
				close();
			}
		} );

		function escHandler( e ) {
			if ( e.key === 'Escape' && ! confirmBtn.classList.contains( 'opentrust-btn--loading' ) ) {
				close();
				document.removeEventListener( 'keydown', escHandler );
			}
		}
		document.addEventListener( 'keydown', escHandler );

		setTimeout( function () { confirmBtn.focus(); }, 50 );
	}

	// Loading state helper
	function setLoading( btn, on, label ) {
		if ( ! btn ) {
			return;
		}
		if ( on ) {
			if ( ! btn.dataset.origHtml ) {
				btn.dataset.origHtml = btn.innerHTML;
			}
			btn.classList.add( 'opentrust-btn--loading' );
			btn.setAttribute( 'disabled', '' );
			var txt = label || btn.dataset.origHtml;
			btn.innerHTML = '<span class="opentrust-btn__spinner"></span><span class="opentrust-btn__label">' + txt + '</span>';
		} else {
			btn.classList.remove( 'opentrust-btn--loading' );
			btn.removeAttribute( 'disabled' );
			if ( btn.dataset.origHtml ) {
				btn.innerHTML = btn.dataset.origHtml;
				delete btn.dataset.origHtml;
			}
		}
	}

	// ---------------------------------------------------------------
	// Pattern: AJAX action with confirm + toast feedback.
	// Copy and adapt this when wiring an action-row button to a wp_ajax
	// endpoint. The confirm modal is optional — omit it for non-destructive
	// actions. Activate by calling it from init() once you've added markup
	// like: <button data-opentrust-action="my_action">…</button>
	//
	// function initMyAction() {
	//   var root = document.querySelector( '[data-opentrust-action-root]' );
	//   if ( ! root ) return;
	//   var ajaxurl = root.dataset.ajaxurl || window.ajaxurl;
	//   var nonce   = root.dataset.nonce;
	//   root.addEventListener( 'click', function ( ev ) {
	//     var btn = ev.target.closest( '[data-opentrust-action]' );
	//     if ( ! btn ) return;
	//     ev.preventDefault();
	//     showConfirm( {
	//       title: 'Are you sure?',
	//       confirmText: 'Yes, do it',
	//       danger: true,
	//       onConfirm: function () {
	//         return fetch( ajaxurl, {
	//           method: 'POST', credentials: 'same-origin',
	//           headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
	//           body: new URLSearchParams( {
	//             action: 'opentrust_' + btn.dataset.opentrustAction,
	//             _ajax_nonce: nonce
	//           } )
	//         } )
	//           .then( function ( r ) { return r.json(); } )
	//           .then( function ( p ) {
	//             if ( ! p || ! p.success ) {
	//               showToast( { type: 'error', message: ( p && p.data && p.data.message ) || 'Request failed.' } );
	//               return;
	//             }
	//             showToast( { type: 'success', message: p.data.message || 'Done.' } );
	//           } )
	//           .catch( function () {
	//             showToast( { type: 'error', message: 'Network error.' } );
	//           } );
	//       }
	//     } );
	//   } );
	// }
	// ---------------------------------------------------------------
})();
