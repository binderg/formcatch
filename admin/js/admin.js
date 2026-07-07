( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initCopyButtons();
		initNotifyToggle();
		initEmailRows();
		initFormTabs();
		initCaptchaSettings();
		initBulkActionFix();
	} );

	/**
	 * "Copy" button next to the endpoint URL.
	 */
	function initCopyButtons() {
		document.querySelectorAll( '.fc-copy-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var targetId = btn.getAttribute( 'data-copy-target' );
				var el = document.getElementById( targetId );
				if ( ! el ) {
					return;
				}
				var text = el.textContent.trim();

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text );
				} else {
					var range = document.createRange();
					range.selectNode( el );
					window.getSelection().removeAllRanges();
					window.getSelection().addRange( range );
					document.execCommand( 'copy' );
					window.getSelection().removeAllRanges();
				}

				var copiedLabel = ( window.fcAdmin && fcAdmin.i18n && fcAdmin.i18n.copied ) ? fcAdmin.i18n.copied : 'Copied!';
				var original = btn.textContent;
				btn.textContent = copiedLabel;
				setTimeout( function () {
					btn.textContent = original;
				}, 1500 );
			} );
		} );
	}

	/**
	 * Show/hide the "send to" / "subject" fields depending on whether
	 * email notifications are enabled for this form.
	 */
	function initNotifyToggle() {
		var toggle = document.getElementById( 'fc-notify-toggle' );
		if ( ! toggle ) {
			return;
		}
		var fields = document.querySelector( '.fc-notify-fields' );

		function sync() {
			fields.style.display = toggle.checked ? '' : 'none';
		}
		toggle.addEventListener( 'change', sync );
		sync();
	}

	/**
	 * Repeatable email rules: "Add email" clones the template block with a
	 * fresh index, and each row's "x" removes itself (never the last one).
	 */
	function initEmailRows() {
		var container = document.getElementById( 'fc-emails' );
		var addBtn = document.querySelector( '.fc-email-add' );
		var template = document.getElementById( 'fc-email-template' );
		if ( ! container || ! addBtn || ! template ) {
			return;
		}

		// Start the running index above any server-rendered rows.
		var nextIndex = container.querySelectorAll( '.fc-email-row' ).length;

		addBtn.addEventListener( 'click', function () {
			var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
			nextIndex++;

			var wrapper = document.createElement( 'div' );
			wrapper.innerHTML = html.trim();
			var row = wrapper.firstChild;
			container.appendChild( row );
			var firstInput = row.querySelector( 'input' );
			if ( firstInput ) {
				firstInput.focus();
			}
		} );

		container.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.fc-email-remove' );
			if ( ! btn ) {
				return;
			}
			var rows = container.querySelectorAll( '.fc-email-row' );
			if ( rows.length <= 1 ) {
				// Keep at least one row; just clear its inputs.
				var only = rows[ 0 ];
				only.querySelectorAll( 'input, textarea' ).forEach( function ( f ) {
					f.value = '';
				} );
				return;
			}
			btn.closest( '.fc-email-row' ).remove();
		} );
	}

	/**
	 * Tabbed form-edit screen (General / Email / Spam / Advanced). All
	 * fields stay in one <form> — this only toggles which panel is
	 * visible, so saving still posts everything regardless of active tab.
	 */
	function initFormTabs() {
		var nav = document.getElementById( 'fc-tabs' );
		if ( ! nav ) {
			return;
		}

		var tabs = Array.prototype.slice.call( nav.querySelectorAll( '.nav-tab' ) );
		var panels = document.querySelectorAll( '.fc-tab-panel' );

		function activate( name ) {
			tabs.forEach( function ( tab ) {
				var isActive = tab.getAttribute( 'data-tab' ) === name;
				tab.classList.toggle( 'nav-tab-active', isActive );
			} );
			panels.forEach( function ( panel ) {
				panel.classList.toggle( 'fc-tab-active', panel.id === 'fc-tab-' + name );
			} );
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var name = tab.getAttribute( 'data-tab' );
				activate( name );
				// history.replaceState (not location.hash) avoids the browser
				// scrolling to the panel div, which shares the hash's id.
				history.replaceState( null, '', '#fc-tab-' + name );
			} );
		} );

		// Deep-link support: reopening #fc-tab-email lands on that tab
		// (e.g. after a save, or a bookmarked link).
		var fromHash = ( window.location.hash || '' ).replace( '#fc-tab-', '' );
		var initial = tabs.some( function ( t ) { return t.getAttribute( 'data-tab' ) === fromHash; } ) ? fromHash : 'general';
		activate( initial );

		// If the browser flags an invalid/required field on submit, jump to
		// the tab containing it first so the user isn't stuck looking at a
		// blank error on a hidden panel.
		var form = document.querySelector( '.fc-form-settings' );
		if ( form ) {
			form.addEventListener( 'invalid', function ( e ) {
				var panel = e.target.closest( '.fc-tab-panel' );
				if ( panel ) {
					var name = panel.id.replace( 'fc-tab-', '' );
					activate( name );
				}
			}, true );
		}
	}

	/**
	 * Settings page: only show the key fields once a CAPTCHA provider is
	 * chosen, and the v3 threshold only for reCAPTCHA v3.
	 */
	function initCaptchaSettings() {
		var select = document.getElementById( 'fc-captcha-provider' );
		if ( ! select ) {
			return;
		}

		var keyRows = document.querySelectorAll( '.fc-captcha-keys' );
		var v3Rows = document.querySelectorAll( '.fc-captcha-v3' );

		function sync() {
			var provider = select.value;
			keyRows.forEach( function ( row ) {
				row.style.display = ( 'none' === provider ) ? 'none' : '';
			} );
			v3Rows.forEach( function ( row ) {
				row.style.display = ( 'recaptcha_v3' === provider ) ? '' : 'none';
			} );
		}

		select.addEventListener( 'change', sync );
		sync();
	}

	/**
	 * WP_List_Table renders its bulk-action dropdowns with
	 * name="action" / name="action2". Our submissions form posts to
	 * admin-post.php, which itself dispatches on $_REQUEST['action'] —
	 * so a native <select name="action"> would silently hijack routing
	 * away from our fc_submission_action handler.
	 *
	 * Fix: rename those selects the moment the page loads, and copy
	 * whichever one the user actually picked into our own hidden
	 * "do" field right before the form submits.
	 */
	function initBulkActionFix() {
		var form = document.getElementById( 'fc-submissions-form' );
		if ( ! form ) {
			return;
		}

		var selects = form.querySelectorAll( 'select[name="action"], select[name="action2"]' );
		selects.forEach( function ( select, i ) {
			select.setAttribute( 'name', 'fc_bulk_action_' + i );
		} );

		form.addEventListener( 'submit', function () {
			var chosen = '';
			selects.forEach( function ( select ) {
				if ( select.value && '-1' !== select.value ) {
					chosen = select.value;
				}
			} );
			var doField = form.querySelector( '.fc-bulk-do-field' );
			if ( doField ) {
				doField.value = chosen;
			}
		} );
	}
} )();
