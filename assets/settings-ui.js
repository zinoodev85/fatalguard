/**
 * FatalGuard settings screen UI — tab controller + "send test to all channels".
 * Moved out of inline <script> blocks in settings-page.php for wp.org enqueue
 * compliance. Localized strings arrive via wp_localize_script as fatalguardSettingsL10n.
 */
( function () {
	var L10N = window.fatalguardSettingsL10n || {};

	// --- Tab controller: one panel at a time; hide Save on form-less panels;
	//     remember the active tab across saves via the URL hash then sessionStorage.
	( function () {
		var nav = document.querySelector( '.fg-tabs' );
		if ( ! nav ) {
			return;
		}
		var tabs = [].slice.call( nav.querySelectorAll( '.fg-tab' ) );
		var panels = [].slice.call( document.querySelectorAll( '.fg-panel' ) );
		var NOFORM = { ai: 1, upgrade: 1 };
		function show( id ) {
			if ( ! id || ! document.getElementById( 'fg-panel-' + id ) ) {
				id = tabs[ 0 ].dataset.tab;
			}
			tabs.forEach( function ( t ) {
				var active = t.dataset.tab === id;
				t.classList.toggle( 'is-active', active );
				t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				t.tabIndex = active ? 0 : -1;
			} );
			panels.forEach( function ( p ) {
				p.hidden = ( p.dataset.tab !== id );
			} );
			document.body.classList.toggle( 'fg-hide-save', !! NOFORM[ id ] );
			try {
				sessionStorage.setItem( 'fgTab', id );
			} catch ( e ) {}
			if ( history.replaceState ) {
				history.replaceState( null, '', '#' + id );
			}
		}
		tabs.forEach( function ( t ) {
			t.addEventListener( 'click', function () {
				show( t.dataset.tab );
			} );
		} );
		nav.addEventListener( 'keydown', function ( e ) {
			var idx = tabs.indexOf( document.activeElement );
			if ( idx === -1 ) {
				return;
			}
			var next = idx;
			if ( e.key === 'ArrowRight' ) {
				next = ( idx + 1 ) % tabs.length;
			} else if ( e.key === 'ArrowLeft' ) {
				next = ( idx - 1 + tabs.length ) % tabs.length;
			} else if ( e.key === 'Home' ) {
				next = 0;
			} else if ( e.key === 'End' ) {
				next = tabs.length - 1;
			} else {
				return;
			}
			e.preventDefault();
			show( tabs[ next ].dataset.tab );
			tabs[ next ].focus();
		} );
		var initial = ( location.hash || '' ).replace( '#', '' ) || ( function () {
			try {
				return sessionStorage.getItem( 'fgTab' );
			} catch ( e ) {
				return '';
			}
		} )();
		show( initial );
	} )();

	// --- "Send test to all channels" button (Pro Alerts tab). Delegated so it
	//     binds even though the button is printed inside the tab panel.
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '.fg-test-btn' );
		if ( ! b ) {
			return;
		}
		var url = b.getAttribute( 'data-fg-test-url' );
		var nonce = b.getAttribute( 'data-fg-test-nonce' );
		var list = b.parentNode.querySelector( '.fg-test-results' );
		var label = b.textContent;
		b.disabled = true;
		b.textContent = L10N.sending || 'Sending…';
		list.hidden = false;
		list.innerHTML = '';
		fetch( url, { method: 'POST', headers: { 'X-WP-Nonce': nonce } } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( d ) {
				var rows = ( d && d.results ) || [];
				if ( ! rows.length ) {
					var li0 = document.createElement( 'li' );
					li0.textContent = L10N.none || 'No channels configured yet.';
					list.appendChild( li0 );
					return;
				}
				rows.forEach( function ( x ) {
					var li = document.createElement( 'li' );
					var ch = document.createElement( 'span' );
					ch.className = 'fg-test-ch';
					ch.textContent = x.channel;
					var st = document.createElement( 'span' );
					st.className = x.ok ? 'fg-test-ok' : 'fg-test-bad';
					st.textContent = ( x.ok ? '✓ ' : '✗ ' ) + ( x.message || '' );
					li.appendChild( ch );
					li.appendChild( st );
					list.appendChild( li );
				} );
				if ( rows.some( function ( x ) { return x.ok; } ) ) {
					var du = b.getAttribute( 'data-fg-dismiss-url' );
					var dn = b.getAttribute( 'data-fg-dismiss-nonce' );
					if ( du ) {
						var fd = new FormData();
						fd.append( '_ajax_nonce', dn );
						fetch( du, { method: 'POST', body: fd } );
					}
				}
			} )
			.catch( function () {
				var li = document.createElement( 'li' );
				li.className = 'fg-test-bad';
				li.textContent = L10N.failed || 'Request failed.';
				list.innerHTML = '';
				list.appendChild( li );
			} )
			.finally( function () {
				b.disabled = false;
				b.textContent = label;
			} );
	} );
} )();

// AI access (MCP) panel: Copy buttons for the API key and the client snippets.
( function () {
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.matches && e.target.matches( '.fg-mcp-key' ) ) {
			e.target.select(); // click selects the whole key
		}
		var b = e.target.closest( '[data-fg-copy]' );
		if ( ! b ) {
			return;
		}
		var host = b.parentNode;
		var pre = host.querySelector( '.fg-code' );
		var inp = host.querySelector( '.fg-mcp-key' );
		var txt = pre ? pre.innerText : ( inp ? inp.value : '' );
		if ( ! txt ) {
			return;
		}
		var l10n = window.fatalguardSettingsL10n || {};
		var done = function () {
			var o = b.textContent;
			b.textContent = l10n.copied || 'Copied';
			setTimeout( function () { b.textContent = o; }, 1500 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( txt ).then( done, function () {} );
		} else if ( inp ) {
			inp.select();
			try { document.execCommand( 'copy' ); done(); } catch ( err ) {}
		} else {
			var r = document.createRange();
			r.selectNodeContents( pre );
			var s = getSelection();
			s.removeAllRanges();
			s.addRange( r );
			try { document.execCommand( 'copy' ); done(); } catch ( err ) {}
			s.removeAllRanges();
		}
	} );
} )();
