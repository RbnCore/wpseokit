/* WPSeoKit admin screens. Plain JS, no dependencies. Loaded on the plugin page and the post editor only.
 * Data comes from window.wpseokitUi (restUrl, nonce, i18n, tone). Lists use the plugin REST routes (namespace wpseokit/v1). */
( function () {
	'use strict';

	var T = window.wpseokitUi || {};
	var I = T.i18n || {};
	var doc = document;

	function $( sel, root ) { return ( root || doc ).querySelector( sel ); }
	function $$( sel, root ) { return Array.prototype.slice.call( ( root || doc ).querySelectorAll( sel ) ); }
	function esc( s ) { return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } ); }
	function icon( n ) { return '<svg class="rbn-ico" aria-hidden="true"><use href="#i-' + n + '"/></svg>'; }
	function fmt( s, n ) { return String( s ).replace( '%d', n ); }
	function on( root, type, sel, fn ) {
		root.addEventListener( type, function ( e ) {
			var t = e.target.closest ? e.target.closest( sel ) : null;
			if ( t && root.contains( t ) ) { fn( e, t ); }
		} );
	}
	function note( type, html, role ) {
		var ic = { ok: 'check', warn: 'alert', err: 'x', info: 'info' }[ type ] || 'info';
		return '<div class="rbn-note rbn-note--' + type + '"' + ( role ? ' role="' + role + '"' : '' ) + '>' + icon( ic ) + '<div><p>' + html + '</p></div></div>';
	}

	/* ---------- REST ---------- */

	function request( method, path, body, isForm ) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': T.nonce, Accept: 'application/json' } };
		if ( body !== undefined && body !== null ) {
			if ( isForm ) { opts.body = body; } else { opts.headers[ 'Content-Type' ] = 'application/json'; opts.body = JSON.stringify( body ); }
		}
		return fetch( T.restUrl + path.replace( /^\//, '' ), opts ).then( function ( res ) {
			return res.text().then( function ( txt ) {
				var data = null;
				try { data = txt ? JSON.parse( txt ) : null; } catch ( e ) { data = txt; }
				return { ok: res.ok, status: res.status, data: data, total: parseInt( res.headers.get( 'X-WP-Total' ) || '-1', 10 ), pages: parseInt( res.headers.get( 'X-WP-TotalPages' ) || '1', 10 ), text: txt };
			} );
		} );
	}

	function errMsg( r ) {
		return ( r && r.data && r.data.message ) ? r.data.message : ( I.saveFailed || 'Request failed.' );
	}

	function say( type, html ) {
		var region = $( '[data-result]' );
		if ( ! region ) { return; }
		region.innerHTML = note( type, html, type === 'err' ? 'alert' : 'status' );
	}

	/* ---------- Shared behaviour ---------- */

	function initShell() {
		var wrap = $( '.wpseokit-wrap' );
		if ( wrap ) { wrap.classList.add( 'rbn-js' ); }
		if ( wrap && T.tone === 'koyu' ) { wrap.setAttribute( 'data-tone', 'koyu' ); }
	}

	/* ---------- Tabs (WAI-ARIA): one tablist per tool, the address keeps ?tab= ---------- */

	function selectTab( list, id, focus ) {
		var tabs = $$( '[role="tab"]', list ), found = false;
		tabs.forEach( function ( t ) {
			var sel = t.getAttribute( 'data-tab' ) === id;
			found = found || sel;
			t.setAttribute( 'aria-selected', sel ? 'true' : 'false' );
			t.tabIndex = sel ? 0 : -1;
			var p = doc.getElementById( t.getAttribute( 'aria-controls' ) );
			if ( p ) { p.hidden = ! sel; }
			if ( sel && focus ) { t.focus(); }
		} );
		if ( found && window.history && history.replaceState ) {
			var u = new URL( window.location.href );
			u.searchParams.set( 'tab', id );
			history.replaceState( null, '', u.toString() );
		}
		return found;
	}

	function initTabs( root ) {
		$$( '[data-tabs]', root ).forEach( function ( list ) {
			list.addEventListener( 'click', function ( e ) {
				var t = e.target.closest( '[role="tab"]' );
				if ( t ) { selectTab( list, t.getAttribute( 'data-tab' ), false ); }
			} );
			list.addEventListener( 'keydown', function ( e ) {
				var tabs = $$( '[role="tab"]', list ), i = tabs.indexOf( doc.activeElement ), n = -1;
				if ( i < 0 ) { return; }
				if ( e.key === 'ArrowRight' ) { n = ( i + 1 ) % tabs.length; } else if ( e.key === 'ArrowLeft' ) { n = ( i - 1 + tabs.length ) % tabs.length; } else if ( e.key === 'Home' ) { n = 0; } else if ( e.key === 'End' ) { n = tabs.length - 1; }
				if ( n < 0 ) { return; }
				e.preventDefault();
				selectTab( list, tabs[ n ].getAttribute( 'data-tab' ), true );
			} );
		} );
	}

	/** Opens a tab of the tool on this page, e.g. goTab( 'dry' ). */
	function goTab( id ) { var list = $( '[data-tabs]' ); return list ? selectTab( list, id, false ) : false; }

	/* ---------- Tools menu ---------- */

	function initMenu() {
		$$( '[data-menu]' ).forEach( function ( btn ) {
			var list = doc.getElementById( btn.getAttribute( 'aria-controls' ) );
			if ( ! list ) { return; }
			function set( open ) { btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' ); list.hidden = ! open; }
			btn.addEventListener( 'click', function () { var open = btn.getAttribute( 'aria-expanded' ) !== 'true'; set( open ); if ( open ) { var a = $( 'a', list ); if ( a ) { a.focus(); } } } );
			doc.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' && btn.getAttribute( 'aria-expanded' ) === 'true' ) { set( false ); btn.focus(); } } );
			doc.addEventListener( 'click', function ( e ) { if ( ! btn.parentNode.contains( e.target ) ) { set( false ); } } );
			list.addEventListener( 'focusout', function ( e ) { if ( e.relatedTarget && ! btn.parentNode.contains( e.relatedTarget ) ) { set( false ); } } );
		} );
	}

	function resolveVars( tpl, ctx ) {
		return String( tpl || '' ).replace( /%([a-z_]+)%/g, function ( all, k ) {
			var v = ctx[ k ];
			return v === undefined ? '' : v;
		} ).replace( /\s+/g, ' ' ).trim();
	}

	function initVars( root ) {
		on( root, 'click', '.rbn-tok[data-insert]', function ( e, btn ) {
			var det = btn.closest( '[data-vars-for]' );
			var target = det && doc.getElementById( det.getAttribute( 'data-vars-for' ) );
			if ( ! target ) { return; }
			var tok = btn.getAttribute( 'data-insert' ), s = target.selectionStart, en = target.selectionEnd;
			if ( typeof s !== 'number' ) { s = en = target.value.length; }
			target.value = target.value.slice( 0, s ) + tok + target.value.slice( en );
			target.focus();
			target.selectionStart = target.selectionEnd = s + tok.length;
			target.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		// Live preview line under each template field.
		var sample = { title: I.previewTitle || 'Sample title', sitename: T.siteName || '', sitedesc: '', sep: '-', page: '', excerpt: '', category: '', primary_category: '', date: '', name: '', term_title: I.previewTitle || 'Sample', searchphrase: '…' };
		function refresh( el ) {
			var out = $( '[data-preview-for="' + el.id + '"]' );
			if ( ! out ) { return; }
			var sepEl = $( 'input[name="templates.sep"]:checked' );
			sample.sep = sepEl ? sepEl.value : '-';
			out.innerHTML = '<b>' + esc( resolveVars( el.value, sample ) ) + '</b>';
		}
		$$( '[data-var-target]', root ).forEach( function ( el ) { if ( $( '[data-preview-for="' + el.id + '"]' ) ) { refresh( el ); } } );
		root.addEventListener( 'input', function ( e ) { if ( e.target.matches && e.target.matches( '[data-var-target]' ) ) { refresh( e.target ); } } );
		root.addEventListener( 'change', function ( e ) { if ( e.target.name === 'templates.sep' ) { $$( '[data-var-target]', root ).forEach( refresh ); } } );
	}

	function openDialog( name, opener ) {
		var d = $( 'dialog[data-dlg="' + name + '"]' );
		if ( ! d ) { return null; }
		d._opener = opener || doc.activeElement;
		if ( ! d.open ) { d.showModal(); }
		if ( ! d._bound ) {
			d._bound = true;
			d.addEventListener( 'close', function () { if ( d._opener && d._opener.focus ) { d._opener.focus(); } } );
			d.addEventListener( 'click', function ( e ) { if ( e.target === d ) { d.close(); } } );
		}
		return d;
	}

	function initDialogs( root ) {
		on( root, 'click', '[data-dlg-close]', function ( e, b ) { var d = b.closest( 'dialog' ); if ( d ) { d.close(); } } );
	}

	function initCopy( root ) {
		on( root, 'click', '[data-copy]', function ( e, b ) {
			var input = doc.getElementById( b.getAttribute( 'data-copy' ) );
			if ( ! input ) { return; }
			input.select();
			var done = function () { say( 'ok', esc( I.copied ) ); };
			if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( input.value ).then( done ); } else { try { doc.execCommand( 'copy' ); done(); } catch ( x ) { /* selection stays so the user can copy by hand */ } }
		} );
	}

	function initMedia( root, onChange ) {
		on( root, 'click', '[data-media-pick]', function ( e, btn ) {
			if ( ! window.wp || ! wp.media ) { return; }
			var box = btn.closest( '[data-media]' );
			var frame = wp.media( { title: I.chooseImage, multiple: false, library: { type: 'image' } } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				setMedia( box, a.id, a.url );
				if ( onChange ) { onChange(); }
			} );
			frame.open();
		} );
		on( root, 'click', '[data-media-clear]', function ( e, btn ) {
			setMedia( btn.closest( '[data-media]' ), 0, '' );
			if ( onChange ) { onChange(); }
		} );
	}

	function setMedia( box, id, url ) {
		var img = $( '.wps-media__img', box ), idIn = $( '[data-media-id]', box ), urlIn = $( '[data-media-url]', box );
		if ( idIn ) { idIn.value = id || 0; }
		if ( urlIn ) { urlIn.value = url || ''; urlIn.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }
		img.classList.toggle( 'wps-media__img--filled', !! url );
		img.innerHTML = url ? '<img src="' + esc( url ) + '" alt="">' : icon( 'image' );
		var pick = $( '[data-media-pick]', box ), clr = $( '[data-media-clear]', box );
		if ( clr ) { clr.hidden = ! url; }
		if ( pick && ! box.hasAttribute( 'data-keep-label' ) ) { pick.lastChild.nodeValue = url ? I.replaceImage : I.chooseImage; }
	}

	/* ---------- Settings forms: collect, dirty bar, save through /settings ---------- */

	function setPath( obj, path, value ) {
		var keys = path.split( '.' ), cur = obj;
		keys.slice( 0, -1 ).forEach( function ( k ) { cur[ k ] = cur[ k ] && typeof cur[ k ] === 'object' ? cur[ k ] : {}; cur = cur[ k ]; } );
		cur[ keys[ keys.length - 1 ] ] = value;
	}

	function collect( form ) {
		var out = {}, multi = {};
		$$( '[name]', form ).forEach( function ( el ) {
			var name = el.name;
			if ( el.hasAttribute( 'data-multi' ) ) {
				multi[ name ] = multi[ name ] || [];
				var inv = el.hasAttribute( 'data-invert' );
				if ( el.checked !== inv ) { multi[ name ].push( el.value ); }
				return;
			}
			if ( el.type === 'radio' ) { if ( el.checked ) { setPath( out, name, el.value ); } return; }
			if ( el.type === 'checkbox' ) { setPath( out, name, el.checked ); return; }
			var type = el.getAttribute( 'data-type' ), v = el.value;
			if ( type === 'int' ) { v = parseInt( v, 10 ); v = isNaN( v ) ? 0 : v; } else if ( type === 'list' ) { v = v.split( /[\r\n,]+/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean ); } else { v = v.trim(); }
			setPath( out, name, v );
		} );
		Object.keys( multi ).forEach( function ( k ) { setPath( out, k, multi[ k ] ); } );
		return out;
	}

	function snapshot( form ) { return JSON.stringify( collect( form ) ); }

	function initSettings( form ) {
		var bar = $( '[data-bar]', form ), saveBtn = $( '[data-save]', form ), base = snapshot( form );
		function sync() { bar.classList.toggle( 'is-dirty', snapshot( form ) !== base ); }
		form.addEventListener( 'input', sync );
		form.addEventListener( 'change', sync );
		initMedia( form, sync );
		on( form, 'click', '[data-discard]', function () {
			form.reset();
			$$( '[data-media]', form ).forEach( function ( box ) { var u = $( '[data-media-url]', box ); setMedia( box, $( '[data-media-id]', box ) ? $( '[data-media-id]', box ).value : 0, u ? u.value : '' ); } );
			sync();
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! bar.classList.contains( 'is-dirty' ) ) { return; }
			var body = collect( form );
			saveBtn.disabled = true;
			form.setAttribute( 'aria-busy', 'true' );
			say( 'info', esc( I.saving ) );
			api( 'POST', 'settings', body ).then( function ( r ) {
				saveBtn.disabled = false;
				form.removeAttribute( 'aria-busy' );
				if ( r.ok ) {
					$$( 'input, textarea, select', form ).forEach( function ( el ) {
						if ( el.type === 'checkbox' || el.type === 'radio' ) { el.defaultChecked = el.checked; } else if ( el.tagName !== 'SELECT' ) { el.defaultValue = el.value; }
					} );
					base = snapshot( form );
					sync();
					say( 'ok', '<b>' + esc( I.saved ) + '</b>' );
				} else {
					say( 'err', '<b>' + esc( I.saveFailed ) + '</b> ' + esc( errMsg( r ) ) );
				}
			} ).catch( function () {
				saveBtn.disabled = false;
				form.removeAttribute( 'aria-busy' );
				say( 'err', '<b>' + esc( I.saveFailed ) + '</b>' );
			} );
		} );
	}

	/* ---------- Dashboard ---------- */

	function dashboard( screen ) {
		function setStat( k, v, sub ) {
			var n = $( '[data-stat="' + k + '"]' ), s = $( '[data-stat-sub="' + k + '"]' );
			if ( n && v !== null ) { n.textContent = v; }
			if ( s && sub ) { s.textContent = sub; }
		}
		function line( id, text ) { var el = $( '[data-card-line="' + id + '"]' ); if ( el ) { el.textContent = text; } }
		function badge( id, kind, ico, text ) { var el = $( '[data-card-badge="' + id + '"]' ); if ( el ) { el.innerHTML = '<span class="rbn-badge rbn-badge--' + kind + '">' + icon( ico ) + esc( text ) + '</span>'; } }
		function loadSitemap() {
			api( 'GET', 'sitemap/status' ).then( function ( r ) {
				if ( r.ok && r.data ) {
					var total = ( r.data.items || [] ).filter( function ( i ) { return i.name !== 'sitemap_index.xml'; } ).reduce( function ( a, i ) { return a + ( +i.count || 0 ); }, 0 );
					setStat( 'sitemap', r.data.enabled ? String( total ) : '0' );
				}
			} );
		}
		loadSitemap();
		api( 'GET', 'redirects?per_page=1&is_active=1' ).then( function ( r ) {
			if ( ! r.ok ) { line( 'redirects', I.loadFailed ); return; }
			var n = r.total >= 0 ? r.total : ( r.data || [] ).length;
			setStat( 'redirects', String( n ) );
			line( 'redirects', fmt( I.cardRedirects || '%d active redirects', n ) );
		} );
		api( 'GET', '404-logs?per_page=5&orderby=hit_count&order=desc' ).then( function ( r ) {
			var tb = $( '[data-activity] tbody' );
			if ( ! r.ok ) { line( 'log404', I.loadFailed ); if ( tb ) { tb.innerHTML = '<tr><td colspan="3">' + esc( I.loadFailed ) + '</td></tr>'; } return; }
			var n = r.total >= 0 ? r.total : ( r.data || [] ).length;
			setStat( 'log', String( n ) );
			line( 'log404', fmt( I.cardLog || '%d errors logged', n ) );
			if ( n ) { badge( 'log404', 'warn', 'alert', I.needsLook || 'Needs a look' ); } else { badge( 'log404', 'ok', 'check', I.clean || 'Clean' ); }
			if ( tb ) {
				var url = T.pageUrl + ( T.pageUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'tool=log404';
				tb.innerHTML = ( r.data || [] ).length ? r.data.map( function ( l ) {
					return '<tr><td data-l="' + esc( I.event || 'Event' ) + '"><code>' + esc( l.url_path ) + '</code></td><td data-l="' + esc( I.hits || 'Hits' ) + '" class="rbn-num">' + esc( l.hit_count ) + '</td><td data-l="' + esc( I.action || 'Action' ) + '" class="rbn-acts"><a class="rbn-btn rbn-btn--sm" href="' + esc( url ) + '">' + esc( I.fix || 'Fix' ) + '</a></td></tr>';
				} ).join( '' ) : '<tr><td colspan="3">' + esc( I.no404 ) + '</td></tr>';
			}
		} );
		api( 'GET', 'import/state' ).then( function ( r ) {
			var b = $( '[data-import-state]' );
			if ( b && r.ok && r.data && r.data.state && r.data.state !== 'none' ) {
				b.innerHTML = '<span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + esc( r.data.state === 'applied' ? ( I.importDone || 'Imported' ) : r.data.state ) + '</span>';
			}
		} );
		on( screen, 'click', '[data-sitemap-flush]', function ( e, b ) {
			b.disabled = true;
			say( 'info', esc( I.rebuilding ) );
			api( 'POST', 'sitemap/flush' ).then( function ( r ) {
				b.disabled = false;
				if ( r.ok ) { say( 'ok', '<b>' + esc( I.rebuilt ) + '</b>' ); loadSitemap(); } else { say( 'err', esc( errMsg( r ) ) ); }
			} );
		} );
		on( screen, 'click', '[data-llms-on]', function ( e, b ) {
			b.disabled = true;
			api( 'POST', 'settings', { llms: { enabled: true } } ).then( function ( r ) {
				if ( r.ok ) { window.location.reload(); return; }
				b.disabled = false;
				say( 'err', '<b>' + esc( I.saveFailed ) + '</b> ' + esc( errMsg( r ) ) );
			} );
		} );
	}

	/* ---------- Schema preview: reads the JSON-LD the chosen page prints ---------- */

	function schema( root ) {
		var sel = $( '[data-schema-url]', root ), out = $( '[data-schema-out]', root ), copy = $( '[data-schema-copy]', root );
		if ( ! sel || ! out ) { return; }
		function load() {
			out.textContent = I.loading;
			fetch( sel.value, { credentials: 'same-origin' } ).then( function ( res ) { return res.text(); } ).then( function ( html ) {
				var d = new DOMParser().parseFromString( html, 'text/html' ), blocks = [];
				$$( 'script[type="application/ld+json"]', d ).forEach( function ( s ) {
					try { blocks.push( JSON.stringify( JSON.parse( s.textContent ), null, 2 ) ); } catch ( x ) { blocks.push( s.textContent.trim() ); }
				} );
				out.textContent = blocks.length ? blocks.join( '\n\n' ) : ( I.noSchema || 'This page prints no schema.' );
			} ).catch( function () { out.textContent = I.loadFailed; } );
		}
		sel.addEventListener( 'change', load );
		if ( copy ) {
			copy.addEventListener( 'click', function () {
				var done = function () { say( 'ok', esc( I.copied ) ); };
				if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( out.textContent ).then( done ); }
			} );
		}
		// Load when the tab is first opened, so a page that is not looked at costs no request.
		var tab = $( '#tab-preview' ), loaded = false;
		function first() { if ( ! loaded ) { loaded = true; load(); } }
		if ( tab ) { tab.addEventListener( 'click', first ); tab.addEventListener( 'focus', first ); if ( tab.getAttribute( 'aria-selected' ) === 'true' ) { first(); } } else { first(); }
	}

	/* ---------- Feed ---------- */

	function feed( root ) {
		var chk = $( '[data-feed-check]', root ), sample = $( '[data-feed-sample]', root );
		api( 'GET', 'feed/status' ).then( function ( r ) {
			if ( ! chk ) { return; }
			if ( ! r.ok || ! r.data ) { chk.textContent = I.loadFailed; return; }
			chk.textContent = r.data.enabled ? ( I.feedOn || 'The feed is on and answers at this address.' ) : ( I.feedOff || 'The feed is off.' );
		} );
		api( 'GET', 'feed/preview' ).then( function ( r ) {
			if ( ! sample ) { return; }
			sample.textContent = r.ok && r.data && r.data.sample_item_xml ? r.data.sample_item_xml : ( r.status === 404 ? ( I.noPosts || 'There is no published post to show.' ) : I.loadFailed );
		} );
	}

	/* ---------- llms.txt preview: built from the form, saved or not ---------- */

	function llms( root ) {
		var out = $( '[data-llms-out]', root ), form = $( '[data-settings-form]', root );
		if ( ! out ) { return; }
		function load() {
			out.textContent = I.loading;
			api( 'POST', 'llms/preview', { llms: form ? ( collect( form ).llms || {} ) : {} } ).then( function ( r ) {
				out.textContent = r.ok && r.data ? r.data.content : ( errMsg( r ) || I.loadFailed );
			} );
		}
		on( root, 'click', '[data-llms-refresh]', load );
		on( root, 'click', '[data-llms-copy]', function () { if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( out.textContent ).then( function () { say( 'ok', esc( I.copied ) ); } ); } } );
		var tab = $( '#tab-preview' ), loaded = false;
		function first() { if ( ! loaded ) { loaded = true; load(); } }
		if ( tab ) { tab.addEventListener( 'click', first ); if ( tab.getAttribute( 'aria-selected' ) === 'true' ) { first(); } } else { first(); }
	}

	/* ---------- Sitemap ---------- */

	function sitemap( root ) {
		function load() {
			api( 'GET', 'sitemap/status' ).then( function ( r ) {
				var tb = $( '[data-sitemap-files] tbody', root );
				if ( ! r.ok || ! r.data ) { tb.innerHTML = '<tr><td colspan="4">' + esc( I.loadFailed ) + '</td></tr>'; return; }
				var items = r.data.items || [];
				tb.innerHTML = items.length ? items.map( function ( i ) {
					return '<tr><th scope="row" data-l="File"><code>' + esc( i.name ) + '</code></th><td data-l="URLs" class="rbn-num">' + esc( i.count ) + '</td><td data-l="Last modified">' + esc( i.lastmod ? new Date( i.lastmod ).toLocaleString() : '–' ) + '</td><td data-l="Status"><span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + esc( 'OK' ) + '</span></td></tr>';
				} ).join( '' ) : '<tr><td colspan="4">' + esc( I.noMatch ) + '</td></tr>';
			} );
		}
		load();
		on( root, 'click', '[data-sitemap-flush]', function ( e, b ) {
			b.disabled = true;
			say( 'info', esc( I.rebuilding ) );
			api( 'POST', 'sitemap/flush' ).then( function ( r ) {
				b.disabled = false;
				if ( r.ok ) { say( 'ok', '<b>' + esc( I.rebuilt ) + '</b>' ); load(); } else { say( 'err', esc( errMsg( r ) ) ); }
			} );
		} );
	}

	/* ---------- Lists: shared pager and selection ---------- */

	function pagerHtml( pg, pages, total, shown ) {
		var h = '<p>' + esc( shown + ' / ' + total ) + '</p><ul>';
		h += pg > 1 ? '<li><a href="#" data-page="' + ( pg - 1 ) + '">' + esc( '‹' ) + '</a></li>' : '<li><span class="rbn-pg" aria-disabled="true">' + esc( '‹' ) + '</span></li>';
		for ( var i = 1; i <= pages && i <= 7; i++ ) { h += '<li><a href="#" data-page="' + i + '"' + ( i === pg ? ' aria-current="page"' : '' ) + '>' + i + '</a></li>'; }
		h += pg < pages ? '<li><a href="#" data-page="' + ( pg + 1 ) + '">' + esc( '›' ) + '</a></li>' : '<li><span class="rbn-pg" aria-disabled="true">' + esc( '›' ) + '</span></li>';
		return h + '</ul>';
	}

	function selection( root, table, bulk ) {
		function ids() { return $$( 'tbody input[data-row]:checked', table ).map( function ( c ) { return +c.value; } ); }
		function sync() {
			var n = ids().length;
			bulk.hidden = n === 0;
			$( '[data-count]', bulk ).textContent = n;
			var all = $( '[data-select-all]', table ), rows = $$( 'tbody input[data-row]', table );
			all.checked = rows.length > 0 && n === rows.length;
		}
		on( table, 'change', 'input[data-row]', sync );
		on( table, 'change', '[data-select-all]', function ( e, a ) { $$( 'tbody input[data-row]', table ).forEach( function ( c ) { c.checked = a.checked; } ); sync(); } );
		return { ids: ids, sync: sync };
	}

	function skeletonRows( cols ) { return '<tr><td colspan="' + cols + '"><span class="wps-spin" aria-hidden="true"></span> ' + esc( I.loading ) + '</td></tr>'; }

	function stateBox( root, html ) { var s = $( '[data-list-state]', root ); if ( s ) { s.innerHTML = html; } }

	/* ---------- Redirects ---------- */

	function dateText( s ) { if ( ! s ) { return I.never; } var d = new Date( s ); return isNaN( d ) ? s : ( d.toDateString() === new Date().toDateString() ? I.today : d.toLocaleDateString() ); }

	function redirects( root ) {
		var table = $( '[data-redirects]', root ), tbody = $( 'tbody', table ), bulk = $( '[data-bulk]', root ), pager = $( '[data-pager]', root ), filter = $( '[data-redirect-filter]', root );
		var st = { page: 1, search: '', status_code: '', is_active: '' }, rows = {}, sel = selection( root, table, bulk );

		function load() {
			var q = 'redirects?per_page=10&page=' + st.page;
			[ 'search', 'status_code', 'is_active' ].forEach( function ( k ) { if ( st[ k ] !== '' ) { q += '&' + k + '=' + encodeURIComponent( st[ k ] ); } } );
			tbody.innerHTML = skeletonRows( 8 );
			table.setAttribute( 'aria-busy', 'true' );
			stateBox( root, '' );
			api( 'GET', q ).then( function ( r ) {
				table.removeAttribute( 'aria-busy' );
				if ( ! r.ok ) {
					tbody.innerHTML = '';
					stateBox( root, '<div class="rbn-card__b">' + note( 'err', '<b>' + esc( I.loadFailed ) + '</b> <button type="button" class="rbn-btn rbn-btn--sm" data-retry>' + esc( I.retry ) + '</button>', 'alert' ) + '</div>' );
					return;
				}
				var list = r.data || [];
				rows = {};
				list.forEach( function ( x ) { rows[ x.id ] = x; } );
				tbody.innerHTML = list.map( row ).join( '' );
				if ( ! list.length ) { stateBox( root, '<div class="rbn-card__b"><div class="rbn-empty"><span class="rbn-empty__i">' + icon( 'arrows' ) + '</span><p>' + esc( st.search || st.status_code || st.is_active !== '' ? I.noMatch : I.noRedirects ) + '</p></div></div>' ); }
				var total = r.total >= 0 ? r.total : list.length;
				pager.innerHTML = list.length ? pagerHtml( st.page, r.pages || 1, total, list.length ) : '';
				sel.sync();
			} );
		}

		function row( x ) {
			var code = +x.status_code, cls = code === 301 || code === 308 ? 'ok' : ( code === 410 || code === 451 ? 'warn' : 'info' );
			return '<tr><td class="rbn-chk-cell" data-l="Select"><label class="rbn-chk"><input type="checkbox" data-row value="' + x.id + '"><span class="rbn-sr">' + esc( x.source_path ) + '</span></label></td>'
				+ '<th scope="row" data-l="From"><code>' + esc( x.source_path ) + '</code></th><td data-l="To"><code>' + esc( x.target_url || '–' ) + '</code></td>'
				+ '<td data-l="Type"><span class="rbn-badge rbn-badge--' + cls + '">' + icon( 'arrows' ) + code + '</span></td><td data-l="Hits" class="rbn-num">' + esc( x.hits ) + '</td><td data-l="Last hit">' + esc( dateText( x.last_hit_at ) ) + '</td>'
				+ '<td data-l="Status">' + ( x.is_active ? '<span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + esc( I.on ) + '</span>' : '<span class="rbn-badge">' + icon( 'pause' ) + esc( I.off ) + '</span>' ) + '</td>'
				+ '<td data-l="Actions" class="rbn-acts"><button type="button" class="rbn-btn rbn-btn--sm" data-edit="' + x.id + '">' + icon( 'pen' ) + esc( I.edit ) + '</button><button type="button" class="rbn-btn rbn-btn--danger rbn-btn--sm" data-del="' + x.id + '">' + icon( 'trash' ) + esc( I.delete ) + '</button></td></tr>';
		}

		on( root, 'click', '[data-retry]', load );
		on( pager, 'click', 'a[data-page]', function ( e, a ) { e.preventDefault(); st.page = +a.getAttribute( 'data-page' ); load(); } );
		filter.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			st.page = 1; st.search = filter.search.value.trim(); st.status_code = filter.status_code.value; st.is_active = filter.is_active.value;
			load();
		} );

		// Add
		var add = $( '[data-redirect-add]', root ), addErr = $( '[data-add-error]', root );
		function fail( box, msg ) { box.hidden = false; box.className = 'rbn-note rbn-note--err'; box.innerHTML = icon( 'x' ) + '<div><p>' + esc( msg ) + '</p></div>'; }
		add.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			addErr.hidden = true;
			var from = add.source_path.value.trim(), to = add.target_url.value.trim(), code = +( $( 'input[name="ra-type"]:checked', add ) || {} ).value;
			if ( from.charAt( 0 ) !== '/' ) { fail( addErr, I.fromRequired ); add.source_path.focus(); return; }
			if ( ! to && code !== 410 ) { fail( addErr, I.toRequired ); add.target_url.focus(); return; }
			api( 'POST', 'redirects', { source_path: from, target_url: to, status_code: code } ).then( function ( r ) {
				if ( r.ok ) { add.reset(); say( 'ok', '<b>' + esc( I.added ) + '</b>' ); st.page = 1; load(); } else { fail( addErr, errMsg( r ) ); }
			} );
		} );

		// Edit
		var editor = $( 'dialog[data-dlg="edit"]', root ), eform = $( '[data-edit-form]', root ), eerr = $( '[data-edit-error]', root ), editId = 0;
		on( tbody, 'click', '[data-edit]', function ( e, b ) {
			editId = +b.getAttribute( 'data-edit' );
			var x = rows[ editId ]; eerr.hidden = true;
			eform.source_path.value = x.source_path; eform.target_url.value = x.target_url || ''; eform.status_code.value = String( x.status_code );
			openDialog( 'edit', b );
		} );
		eform.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var code = +eform.status_code.value;
			if ( eform.source_path.value.trim().charAt( 0 ) !== '/' ) { fail( eerr, I.fromRequired ); return; }
			if ( ! eform.target_url.value.trim() && code !== 410 ) { fail( eerr, I.toRequired ); return; }
			api( 'PATCH', 'redirects/' + editId, { source_path: eform.source_path.value.trim(), target_url: eform.target_url.value.trim(), status_code: code } ).then( function ( r ) {
				if ( r.ok ) { editor.close(); say( 'ok', '<b>' + esc( I.updated ) + '</b>' ); load(); } else { fail( eerr, errMsg( r ) ); }
			} );
		} );

		// Delete (single and bulk) through the confirm dialog
		var pending = [];
		function askDelete( ids, opener ) {
			pending = ids;
			$( '[data-del-title]', root ).textContent = ids.length === 1 ? ( I.delete + ' ' + ( rows[ ids[ 0 ] ] || {} ).source_path + '?' ) : fmt( I.deleteN, ids.length );
			$( '[data-del-body]', root ).textContent = I.deleteBody;
			$( '[data-del-run] span', root ).textContent = ids.length === 1 ? I.delete : fmt( I.deleteNBtn, ids.length );
			openDialog( 'delete', opener );
		}
		on( tbody, 'click', '[data-del]', function ( e, b ) { askDelete( [ +b.getAttribute( 'data-del' ) ], b ); } );
		on( bulk, 'click', '[data-bulk-delete]', function ( e, b ) { askDelete( sel.ids(), b ); } );
		on( root, 'click', '[data-del-run]', function ( e, b ) {
			b.disabled = true;
			Promise.all( pending.map( function ( id ) { return api( 'DELETE', 'redirects/' + id ); } ) ).then( function () {
				b.disabled = false;
				$( 'dialog[data-dlg="delete"]', root ).close();
				say( 'ok', '<b>' + esc( I.removed ) + '</b>' );
				load();
			} );
		} );
		function bulkActive( v ) { Promise.all( sel.ids().map( function ( id ) { return api( 'PATCH', 'redirects/' + id, { is_active: v } ); } ) ).then( load ); }
		on( bulk, 'click', '[data-bulk-on]', function () { bulkActive( true ); } );
		on( bulk, 'click', '[data-bulk-off]', function () { bulkActive( false ); } );

		csv( root, function () { st.page = 1; load(); } );
		load();
	}

	/* CSV import: the file is checked in the browser first (report), the server applies it on confirm. */
	function csv( root, done ) {
		var file = $( '[data-csv-file]', root ), report = $( '[data-csv-report]', root ), run = $( '[data-csv-run]', root ), valid = [];
		on( root, 'click', '[data-csv-open]', function ( e, b ) { file.value = ''; report.innerHTML = ''; run.disabled = true; openDialog( 'csv', b ); } );
		on( root, 'click', '[data-csv-export]', function () {
			request( 'GET', 'redirects/export' ).then( function ( r ) {
				if ( ! r.ok ) { say( 'err', esc( errMsg( r ) ) ); return; }
				var a = doc.createElement( 'a' );
				a.href = URL.createObjectURL( new Blob( [ r.text ], { type: 'text/csv' } ) );
				a.download = 'redirects.csv';
				doc.body.appendChild( a ); a.click(); a.remove();
			} );
		} );
		file.addEventListener( 'change', function () {
			var f = file.files[ 0 ]; valid = []; run.disabled = true; report.innerHTML = '';
			if ( ! f ) { return; }
			if ( f.size > 1048576 ) { report.innerHTML = note( 'err', esc( 'Up to 1 MB.' ), 'alert' ); return; }
			f.text().then( function ( text ) {
				var errors = [], seen = {};
				text.replace( /^﻿/, '' ).split( /\r?\n/ ).forEach( function ( line, i ) {
					if ( ! line.trim() ) { return; }
					var c = line.split( ',' ).map( function ( s ) { return s.trim().replace( /^"|"$/g, '' ); } );
					if ( i === 0 && c[ 0 ].toLowerCase() === 'from' ) { return; }
					var code = c[ 2 ] ? +c[ 2 ] : 301;
					if ( c[ 0 ].charAt( 0 ) !== '/' ) { errors.push( [ i + 1, '“From” does not start with a slash' ] ); } else if ( [ 301, 302, 307, 410, 451 ].indexOf( code ) < 0 ) { errors.push( [ i + 1, 'Type ' + c[ 2 ] + ' is not supported' ] ); } else if ( ! c[ 1 ] && code !== 410 ) { errors.push( [ i + 1, '“To” is empty' ] ); } else if ( seen[ c[ 0 ] ] ) { errors.push( [ i + 1, 'Duplicate of line ' + seen[ c[ 0 ] ] ] ); } else { seen[ c[ 0 ] ] = i + 1; valid.push( line ); }
				} );
				var h = note( valid.length ? 'ok' : 'warn', '<b>' + esc( f.name ) + '</b> ' + esc( I.csvChecked ) + ': <b>' + valid.length + '</b> OK, <b>' + errors.length + '</b> ' + esc( 'invalid' ), 'status' );
				if ( errors.length ) {
					h += '<div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">Lines that need attention</caption><thead><tr><th scope="col">' + esc( I.line ) + '</th><th scope="col">Problem</th></tr></thead><tbody>' + errors.map( function ( x ) { return '<tr><th scope="row" data-l="Line">' + x[ 0 ] + '</th><td data-l="Problem"><span class="rbn-badge rbn-badge--crit">' + icon( 'x' ) + 'Invalid</span> ' + esc( x[ 1 ] ) + '</td></tr>'; } ).join( '' ) + '</tbody></table></div>';
				}
				report.innerHTML = h;
				run.lastChild.nodeValue = fmt( I.importN, valid.length );
				run.disabled = ! valid.length;
			} );
		} );
		run.addEventListener( 'click', function () {
			if ( ! valid.length ) { report.innerHTML = note( 'warn', esc( I.csvNeedsFile ), 'alert' ); return; }
			run.disabled = true;
			api( 'POST', 'redirects/import', { csv: 'from,to,type\n' + valid.join( '\n' ) } ).then( function ( r ) {
				$( 'dialog[data-dlg="csv"]', root ).close();
				if ( r.ok ) {
					var d = r.data || {};
					say( 'ok', '<b>' + esc( ( d.added || 0 ) + ' + ' + ( d.updated || 0 ) ) + '</b> · ' + esc( ( d.skipped || 0 ) + ' ' + I.skipped ) );
					done();
				} else { say( 'err', esc( errMsg( r ) ) ); }
			} );
		} );
	}

	/* ---------- 404 log ---------- */

	var BOT = /(^\/wp-login\.php|^\/xmlrpc\.php|^\/wp-admin|^\/wp-content|^\/wp-includes|\.php$|^\/\.env|^\/\.git)/i;

	function log404( root ) {
		var table = $( '[data-log]', root ), tbody = $( 'tbody', table ), bulk = $( '[data-bulk]', root ), pager = $( '[data-pager]', root ), filter = $( '[data-log-filter]', root );
		var st = { page: 1, search: '', bots: false }, rows = {}, sel = selection( root, table, bulk );

		function load() {
			tbody.innerHTML = skeletonRows( 6 );
			table.setAttribute( 'aria-busy', 'true' );
			stateBox( root, '' );
			api( 'GET', '404-logs?per_page=20&page=' + st.page + '&orderby=hit_count&order=desc' + ( st.search ? '&search=' + encodeURIComponent( st.search ) : '' ) ).then( function ( r ) {
				table.removeAttribute( 'aria-busy' );
				if ( ! r.ok ) { tbody.innerHTML = ''; stateBox( root, '<div class="rbn-card__b">' + note( 'err', '<b>' + esc( I.loadFailed ) + '</b> <button type="button" class="rbn-btn rbn-btn--sm" data-retry>' + esc( I.retry ) + '</button>', 'alert' ) + '</div>' ); return; }
				var list = ( r.data || [] ).filter( function ( x ) { return st.bots || ! BOT.test( x.url_path ); } );
				rows = {};
				list.forEach( function ( x ) { rows[ x.id ] = x; } );
				tbody.innerHTML = list.map( row ).join( '' );
				if ( ! list.length ) { stateBox( root, '<div class="rbn-card__b"><div class="rbn-empty"><span class="rbn-empty__i">' + icon( 'check' ) + '</span><p>' + esc( st.search ? I.noMatch : I.no404 ) + '</p></div></div>' ); }
				pager.innerHTML = list.length ? pagerHtml( st.page, r.pages || 1, r.total >= 0 ? r.total : list.length, list.length ) : '';
				sel.sync();
			} );
		}

		function row( x ) {
			return '<tr data-row-id="' + x.id + '"><td class="rbn-chk-cell" data-l="Select"><label class="rbn-chk"><input type="checkbox" data-row value="' + x.id + '"><span class="rbn-sr">' + esc( x.url_path ) + '</span></label></td>'
				+ '<th scope="row" data-l="Address"><code>' + esc( x.url_path ) + '</code></th><td data-l="Hits" class="rbn-num">' + esc( x.hit_count ) + '</td><td data-l="Referrer">' + esc( x.referer || '–' ) + '</td><td data-l="Last seen">' + esc( dateText( x.last_seen_at ) ) + '</td>'
				+ '<td data-l="Actions" class="rbn-acts"><button type="button" class="rbn-btn rbn-btn--sm" aria-expanded="false" aria-controls="sg-' + x.id + '" data-toggle="' + x.id + '">' + icon( 'arrows' ) + esc( I.redirectTo ) + '</button><button type="button" class="rbn-btn rbn-btn--sm" data-ignore="' + x.id + '">' + icon( 'trash' ) + esc( I.delete ) + '</button></td></tr>';
		}

		on( root, 'click', '[data-retry]', load );
		on( pager, 'click', 'a[data-page]', function ( e, a ) { e.preventDefault(); st.page = +a.getAttribute( 'data-page' ); load(); } );
		filter.addEventListener( 'submit', function ( e ) { e.preventDefault(); st.page = 1; st.search = filter.search.value.trim(); st.bots = filter.bots.checked; load(); } );
		filter.bots.addEventListener( 'change', function () { st.bots = filter.bots.checked; load(); } );

		function closeInline( id, focus ) {
			var r = doc.getElementById( 'sg-' + id ), b = $( '[data-toggle="' + id + '"]', tbody );
			if ( r ) { r.remove(); }
			if ( b ) { b.setAttribute( 'aria-expanded', 'false' ); if ( focus ) { b.focus(); } }
		}

		function create( id, target, onDone ) {
			api( 'POST', '404-logs/' + id + '/redirect', { target_url: target, status_code: 301 } ).then( function ( r ) {
				if ( r.ok ) { say( 'ok', '<b>' + esc( I.created404 ) + '</b> <code>' + esc( rows[ id ].url_path ) + '</code> → <code>' + esc( target ) + '</code>' ); closeInline( id ); load(); } else if ( onDone ) { onDone( errMsg( r ) ); }
			} );
		}

		on( tbody, 'click', '[data-toggle]', function ( e, btn ) {
			var id = +btn.getAttribute( 'data-toggle' ), tr = btn.closest( 'tr' );
			if ( btn.getAttribute( 'aria-expanded' ) === 'true' ) { closeInline( id, true ); return; }
			$$( '[data-toggle][aria-expanded="true"]', tbody ).forEach( function ( o ) { closeInline( +o.getAttribute( 'data-toggle' ) ); } );
			btn.setAttribute( 'aria-expanded', 'true' );
			var x = rows[ id ], inl = doc.createElement( 'tr' );
			inl.className = 'wps-inline'; inl.id = 'sg-' + id;
			inl.innerHTML = '<td colspan="6"><div class="wps-inline__b"><h3>' + esc( I.redirected ) + ' <code>' + esc( x.url_path ) + '</code> →</h3><p class="rbn-help" style="margin:0">' + esc( 'Closest pages on your site. One click creates a 301 redirect.' ) + '</p><ul class="wps-suggest" data-sugg><li>' + esc( I.loading ) + '</li></ul>'
				+ '<div class="wps-inline__row"><label class="rbn-sr" for="sg-own-' + id + '">' + esc( 'Or type another address' ) + '</label><input class="rbn-input rbn-input--mono" id="sg-own-' + id + '" placeholder="/" autocomplete="off"><button type="button" class="rbn-btn rbn-btn--pri" data-create>' + icon( 'plus' ) + esc( 'Create redirect' ) + '</button><button type="button" class="rbn-btn" data-close>' + esc( I.close ) + '</button></div><p class="rbn-err-msg" data-inline-err hidden></p></div></td>';
			tr.parentNode.insertBefore( inl, tr.nextSibling );
			var words = x.url_path.replace( /[-_/]+/g, ' ' ).replace( /\b\d+\b/g, '' ).trim();
			request( 'GET', '' ).then( function () {
				// WordPress core search endpoint gives the closest published pages.
				var root_ = T.restUrl.replace( /wpseokit\/v1\/$/, '' );
				return fetch( root_ + 'wp/v2/search?per_page=3&type=post&search=' + encodeURIComponent( words ), { credentials: 'same-origin', headers: { 'X-WP-Nonce': T.nonce } } ).then( function ( r ) { return r.ok ? r.json() : []; } );
			} ).catch( function () { return []; } ).then( function ( list ) {
				var ul = $( '[data-sugg]', inl );
				ul.innerHTML = ( list || [] ).map( function ( s, i ) {
					var path; try { path = new URL( s.url ).pathname; } catch ( er ) { path = s.url; }
					return '<li><button type="button" class="rbn-btn" data-pick="' + esc( path ) + '"><span><b>' + esc( s.title ) + '</b><code>' + esc( path ) + '</code></span><span>' + ( i === 0 ? '<span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + esc( I.bestMatch ) + '</span>' : icon( 'arrows' ) ) + '</span></button></li>';
				} ).join( '' );
				if ( ! ( list || [] ).length ) { ul.remove(); }
			} );
			$( 'input', inl ).focus();
		} );

		on( tbody, 'click', '[data-pick]', function ( e, b ) { var id = +b.closest( 'tr' ).id.replace( 'sg-', '' ); create( id, b.getAttribute( 'data-pick' ), null ); } );
		on( tbody, 'click', '[data-close]', function ( e, b ) { closeInline( +b.closest( 'tr' ).id.replace( 'sg-', '' ), true ); } );
		on( tbody, 'click', '[data-create]', function ( e, b ) {
			var tr = b.closest( 'tr' ), id = +tr.id.replace( 'sg-', '' ), input = $( 'input', tr ), err = $( '[data-inline-err]', tr ), v = input.value.trim();
			err.hidden = true;
			if ( ! v ) { err.hidden = false; err.textContent = I.toRequired; input.focus(); return; }
			create( id, v, function ( m ) { err.hidden = false; err.textContent = m; } );
		} );
		on( tbody, 'keydown', 'input', function ( e, i ) {
			if ( e.key === 'Escape' ) { closeInline( +i.closest( 'tr' ).id.replace( 'sg-', '' ), true ); }
			if ( e.key === 'Enter' ) { e.preventDefault(); $( '[data-create]', i.closest( 'tr' ) ).click(); }
		} );
		on( tbody, 'click', '[data-ignore]', function ( e, b ) { api( 'DELETE', '404-logs/' + b.getAttribute( 'data-ignore' ) ).then( load ); } );
		on( bulk, 'click', '[data-bulk-delete]', function () { Promise.all( sel.ids().map( function ( id ) { return api( 'DELETE', '404-logs/' + id ); } ) ).then( load ); } );

		var bform = $( '[data-bulk-form]', root ), berr = $( '[data-bulk-error]', root );
		on( bulk, 'click', '[data-bulk-redirect]', function ( e, b ) { berr.hidden = true; bform.reset(); openDialog( 'bulk', b ); } );
		bform.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var to = bform.target_url.value.trim();
			if ( ! to ) { berr.hidden = false; berr.className = 'rbn-note rbn-note--err'; berr.innerHTML = icon( 'x' ) + '<div><p>' + esc( I.toRequired ) + '</p></div>'; bform.target_url.focus(); return; }
			Promise.all( sel.ids().map( function ( id ) { return api( 'POST', '404-logs/' + id + '/redirect', { target_url: to, status_code: 301 } ); } ) ).then( function () {
				$( 'dialog[data-dlg="bulk"]', root ).close(); say( 'ok', '<b>' + esc( I.created404 ) + '</b>' ); load();
			} );
		} );
		load();
	}

	/* ---------- Yoast import ---------- */

	function importer( root ) {
		var $s = function ( k ) { return $( '[data-' + k + ']', root ) || $( '[data-' + k + ']' ); };
		function show( el, v ) { if ( el ) { el.hidden = ! v; } }
		var report = null;

		function check() {
			goTab( 'dry' );
			show( $s( 'import-empty' ), false ); show( $s( 'import-report' ), false ); show( $s( 'import-error' ), false );
			$s( 'import-bar' ).classList.remove( 'is-dirty' );
			show( $s( 'import-busy' ), true );
			api( 'POST', 'import/yoast', { mode: 'dry-run' } ).then( function ( r ) {
				show( $s( 'import-busy' ), false );
				if ( ! r.ok ) {
					show( $s( 'import-error' ), true );
					$s( 'import-error' ).innerHTML = note( 'err', '<b>' + esc( I.loadFailed ) + '</b> ' + esc( errMsg( r ) ) + ' <button type="button" class="rbn-btn rbn-btn--sm" data-import-check>' + esc( I.retry ) + '</button>', 'alert' );
					return;
				}
				report = r.data || {};
				var rowsData = report.rows || [], found = 0, will = 0, conf = 0;
				$( 'tbody', $s( 'import-table' ) ).innerHTML = rowsData.map( function ( x ) {
					found += +x.found || 0; will += +x.will_import || 0; conf += +x.conflicts || 0;
					return '<tr><th scope="row" data-l="Data">' + esc( x.label ) + '</th><td data-l="Found" class="rbn-num">' + esc( x.found ) + '</td><td data-l="Will import" class="rbn-num">' + esc( x.will_import ) + '</td><td data-l="Note">' + esc( x.note || '' ) + '</td></tr>';
				} ).join( '' );
				$s( 'import-summary' ).textContent = found + ' ' + I.found.toLowerCase() + ' · ' + will + ' ' + I.willImport.toLowerCase() + ' · ' + conf + ' ' + I.conflicts.toLowerCase();
				show( $s( 'import-report' ), true ); show( $s( 'import-decide' ), conf > 0 ); show( $s( 'import-wait' ), false );
				$s( 'import-bar' ).classList.add( 'is-dirty' );
				var focus = $s( 'import-report' ).querySelector( 'h2' ); if ( focus ) { focus.setAttribute( 'tabindex', '-1' ); focus.focus(); }
			} );
		}

		show( $s( 'import-decide' ), false );
		on( doc, 'click', '[data-import-check]', function () { check(); } );
		on( doc, 'click', '[data-import-confirm]', function ( e, b ) {
			if ( ! report ) { check(); return; }
			var ack = $s( 'import-ack' ); ack.checked = false; $s( 'import-run' ).disabled = true; openDialog( 'import', b );
		} );
		doc.addEventListener( 'change', function ( e ) { if ( e.target.matches && e.target.matches( '[data-import-ack]' ) ) { $s( 'import-run' ).disabled = ! e.target.checked; } } );
		on( doc, 'click', '[data-import-run]', function ( e, b ) {
			b.disabled = true;
			var ov = $( 'input[name="imp-overwrite"]:checked' );
			api( 'POST', 'import/yoast', { mode: 'apply', overwrite: !! ( ov && ov.value === '1' ) } ).then( function ( r ) {
				$( 'dialog[data-dlg="import"]' ).close();
				if ( ! r.ok ) { say( 'err', '<b>' + esc( I.saveFailed ) + '</b> ' + esc( errMsg( r ) ) ); b.disabled = false; return; }
				$s( 'import-bar' ).classList.remove( 'is-dirty' );
				goTab( 'report' );
				$s( 'import-done' ).innerHTML = note( 'ok', '<b>' + esc( I.importDone ) + '</b> <button type="button" class="rbn-btn rbn-btn--sm" data-import-verify>' + icon( 'scan' ) + esc( I.verify || 'Verify' ) + '</button>', 'status' );
			} );
		} );
		on( doc, 'click', '[data-import-verify]', function ( e, b ) {
			b.disabled = true;
			api( 'POST', 'import/yoast', { mode: 'verify' } ).then( function ( r ) { b.disabled = false; say( r.ok ? 'ok' : 'err', r.ok ? '<b>' + esc( I.verified || 'Verified.' ) + '</b>' : esc( errMsg( r ) ) ); } );
		} );
	}

	/* ---------- Post SEO box ---------- */

	function metabox( box ) {
		var d = box.dataset, $b = function ( s ) { return $( s, box ); };
		var title = $b( '#wps-title' ), desc = $b( '#wps-desc' ), serp = $b( '[data-serp]' ), terms = [];

		function postTitle() {
			if ( window.wp && wp.data && wp.data.select && wp.data.select( 'core/editor' ) ) { try { return wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) || ''; } catch ( e ) { /* classic editor */ } }
			var t = doc.getElementById( 'title' ); return t ? t.value : '';
		}
		function primaryName() {
			var input = $b( '[data-primary-input]' ), id = input ? +input.value : 0, t = terms.filter( function ( x ) { return x.id === id; } )[ 0 ];
			return t ? t.name : '';
		}
		function ctx() {
			var pc = primaryName();
			return { title: postTitle(), sitename: d.site, sep: d.sep || '-', page: '', excerpt: d.excerpt, category: pc, primary_category: pc, sitedesc: '' };
		}
		function level( n, lim ) { // min, good, max
			if ( n === 0 ) { return [ 'warn', I.limShort ]; }
			if ( n < lim[ 0 ] ) { return [ 'warn', I.limShort ]; }
			if ( n <= lim[ 1 ] ) { return [ 'ok', I.limGood ]; }
			if ( n <= lim[ 2 ] ) { return [ 'warn', I.limLong ]; }
			return [ 'bad', I.limOver ];
		}
		function meter( el, text ) {
			var lim = el.getAttribute( 'data-lim' ).split( ',' ).map( Number ), m = doc.getElementById( el.getAttribute( 'data-meter' ) ), n = text.length, lv = level( n, lim );
			m.setAttribute( 'data-level', lv[ 0 ] );
			$( 'i', m ).style.setProperty( '--w', Math.min( 100, Math.round( n / lim[ 2 ] * 100 ) ) + '%' );
			$( 'output', m ).textContent = n + ' / ' + lim[ 1 ] + ' · ' + lv[ 1 ];
		}
		function render() {
			var c = ctx(), t = title.value.trim(), de = desc.value.trim(), src;
			var shownTitle = resolveVars( t || d.tplTitle || '%title% %sep% %sitename%', c );
			var shownDesc = '';
			if ( de ) { shownDesc = resolveVars( de, c ); src = I.sourceHere; } else if ( d.tplDesc ) { shownDesc = resolveVars( d.tplDesc, c ); src = I.sourceTemplate; } else if ( d.excerpt ) { shownDesc = d.excerpt; src = I.sourceExcerpt; } else { shownDesc = ( d.content || '' ).slice( 0, 155 ); src = I.sourceContent; }
			meter( title, resolveVars( t, c ) || shownTitle );
			meter( desc, shownDesc );
			$b( '[data-src] span' ).textContent = src;
			$b( '[data-serp-site]' ).textContent = d.site;
			$b( '[data-serp-url]' ).textContent = d.host + ( d.path || '' ).replace( /\/$/, '' ).split( '/' ).filter( Boolean ).map( function ( s ) { return ' › ' + s; } ).join( '' );
			$b( '[data-serp-title]' ).textContent = shownTitle;
			$b( '[data-serp-desc]' ).textContent = shownDesc;
			var ogt = $b( '#wps-og-t' ), ogd = $b( '#wps-og-d' );
			$b( '[data-og-host]' ).textContent = d.host.toUpperCase();
			$b( '[data-og-title]' ).textContent = ogt.value.trim() || shownTitle;
			$b( '[data-og-desc]' ).textContent = ogd.value.trim() || shownDesc;
			var img = $b( '[data-media-url]' ), og = $b( '[data-og-img]' );
			og.innerHTML = img && img.value ? '<img src="' + esc( img.value ) + '" alt="">' : icon( 'image' );
		}
		box.addEventListener( 'input', render );
		box.addEventListener( 'change', render );
		initVars( box );
		initMedia( box, render );
		on( box, 'change', 'input[name="wps-serp-mode"]', function ( e, r ) { serp.classList.toggle( 'wps-serp--m', r.value === 'm' ); } );

		// Tabs: arrows, Home, End.
		var tabs = $$( '[role=tab]', box );
		function pick( tab, focus ) {
			tabs.forEach( function ( t ) { var on_ = t === tab; t.setAttribute( 'aria-selected', on_ ? 'true' : 'false' ); t.tabIndex = on_ ? 0 : -1; doc.getElementById( t.getAttribute( 'aria-controls' ) ).hidden = ! on_; } );
			if ( focus ) { tab.focus(); }
		}
		tabs.forEach( function ( t, i ) {
			t.addEventListener( 'click', function () { pick( t, false ); } );
			t.addEventListener( 'keydown', function ( e ) {
				var n = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[ e.key ];
				if ( n === undefined ) { return; }
				e.preventDefault(); pick( tabs[ ( n + tabs.length ) % tabs.length ], true );
			} );
		} );

		// Schema fields only for Movie and TVSeries.
		var st = $b( '[data-schema-type]' ), sf = $b( '[data-schema-fields]' );
		if ( st ) { var sync = function () { sf.hidden = ! st.value; }; st.addEventListener( 'change', sync ); sync(); }

		primary( box, d, function ( list ) { terms = list; render(); } );
		render();
		if ( window.wp && wp.data && wp.data.subscribe ) {
			var last = postTitle();
			wp.data.subscribe( function () { var now = postTitle(); if ( now !== last ) { last = now; render(); } } );
		} else { var t0 = doc.getElementById( 'title' ); if ( t0 ) { t0.addEventListener( 'input', render ); } }
	}

	function primary( box, d, onTerms ) {
		var fs = $( '[data-primary]', box );
		if ( ! fs ) { onTerms( [] ); return; }
		var all = JSON.parse( fs.getAttribute( 'data-terms' ) || '[]' ), assigned = JSON.parse( fs.getAttribute( 'data-assigned' ) || '[]' );
		var input = $( '[data-primary-input]', box ), original = +fs.getAttribute( 'data-current' ), warn = $( '[data-url-warn]', box ), prev = $( '[data-urlprev]', box );
		var basePath = d.path || '', baseSlug = ( all.filter( function ( t ) { return t.id === original; } )[ 0 ] || {} ).slug || '';
		onTerms( all );

		function currentIds() {
			if ( window.wp && wp.data && wp.data.select && wp.data.select( 'core/editor' ) ) { try { var c = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'categories' ); if ( Array.isArray( c ) ) { return c; } } catch ( e ) { /* classic */ } }
			var checked = $$( '#categorychecklist input:checked' ).map( function ( i ) { return +i.value; } );
			return checked.length || $( '#categorychecklist' ) ? checked : assigned;
		}
		function draw() {
			var ids = currentIds(), list = all.filter( function ( t ) { return ids.indexOf( t.id ) > -1; } ), cur = +input.value;
			if ( list.length && ! list.some( function ( t ) { return t.id === cur; } ) ) { cur = list[ 0 ].id; input.value = cur; }
			if ( ! list.length ) { input.value = 0; cur = 0; }
			fs.innerHTML = '<legend class="rbn-sr">' + esc( $( '#wps-pc-l', box ).textContent ) + '</legend>' + list.map( function ( t ) {
				return '<label><input type="radio" name="wps-pc" value="' + t.id + '"' + ( t.id === cur ? ' checked' : '' ) + '><span>' + esc( t.name ) + '</span>' + ( t.id === original ? '<span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + 'Current</span>' : '' ) + '</label>';
			} ).join( '' );
			urlPreview( list, cur );
		}
		function urlPreview( list, cur ) {
			var t = list.filter( function ( x ) { return x.id === cur; } )[ 0 ];
			var path = basePath, changed = cur !== original && original > 0;
			if ( t && baseSlug && basePath.indexOf( '/' + baseSlug + '/' ) > -1 ) { path = basePath.replace( '/' + baseSlug + '/', '/' + t.slug + '/' ); }
			prev.hidden = ! t || ! basePath;
			$( 'code', prev ).innerHTML = esc( d.host ) + esc( path ).replace( t ? '/' + esc( t.slug ) + '/' : '\u0000', t ? '/<b>' + esc( t.slug ) + '</b>/' : '' );
			warn.hidden = ! changed || path === basePath;
			if ( warn.hidden ) { $( 'input[name="wpseokit[add_301]"]', warn ).disabled = true; } else { $( 'input[name="wpseokit[add_301]"]', warn ).disabled = false; }
		}
		on( fs, 'change', 'input[type=radio]', function ( e, r ) { input.value = r.value; draw(); } );
		draw();
		var checklist = $( '#categorychecklist' );
		if ( checklist ) { checklist.addEventListener( 'change', draw ); }
		if ( window.wp && wp.data && wp.data.subscribe ) { var last = JSON.stringify( currentIds() ); wp.data.subscribe( function () { var n = JSON.stringify( currentIds() ); if ( n !== last ) { last = n; draw(); } } ); }
	}

	/* ---------- Start ---------- */

	function start() {
		initShell();
		var box = $( '[data-metabox]' );
		if ( box ) { metabox( box ); }
		var screen = $( '[data-screen]' );
		if ( ! screen ) { return; }
		$$( 'dialog[data-dlg]' ).forEach( function ( d ) { if ( ! screen.contains( d ) ) { screen.appendChild( d ); } } );
		initDialogs( doc );
		initCopy( screen );
		initTabs( screen );
		initMenu();
		var name = screen.getAttribute( 'data-screen' );
		$$( '[data-settings-form]', screen ).forEach( function ( f ) { initVars( f ); initSettings( f ); } );
		if ( name === 'dashboard' ) { dashboard( screen ); }
		if ( name === 'schema' ) { schema( screen ); }
		if ( name === 'sitemap' ) { sitemap( screen ); }
		if ( name === 'feed' ) { feed( screen ); }
		if ( name === 'llms' ) { llms( screen ); }
		if ( name === 'redirects' ) { redirects( screen ); }
		if ( name === 'log404' ) { log404( screen ); }
		if ( name === 'import' ) { importer( screen ); }
	}

	if ( doc.readyState === 'loading' ) { doc.addEventListener( 'DOMContentLoaded', start ); } else { start(); }
}() );
