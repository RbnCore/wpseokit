/* WPSeoKit admin screens. Plain JS, no dependencies. Loaded on the plugin page and the post editor only.
 * Data comes from window.wpseokitUi (restUrl, nonce, i18n, tone). Lists use the plugin REST routes (namespace wpseokit/v1). */
( function () {
	'use strict';

	var T = window.wpseokitUi || {};
	var I = T.i18n || {};
	var doc = document;

	var u = window.RbnUi || {};
	var $ = u.$ || function ( sel, root ) { return ( root || doc ).querySelector( sel ); };
	var $$ = u.$$ || function ( sel, root ) { return Array.prototype.slice.call( ( root || doc ).querySelectorAll( sel ) ); };
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
		// REST answers in the site language unless asked otherwise; _locale=user makes it use the language of the signed-in user.
		var url = T.restUrl + path.replace( /^\//, '' );
		url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + '_locale=user';
		return fetch( url, opts ).then( function ( res ) {
			return res.text().then( function ( txt ) {
				var data = null;
				try { data = txt ? JSON.parse( txt ) : null; } catch ( e ) { data = txt; }
				return { ok: res.ok, status: res.status, data: data, total: parseInt( res.headers.get( 'X-WP-Total' ) || '-1', 10 ), pages: parseInt( res.headers.get( 'X-WP-TotalPages' ) || '1', 10 ), text: txt };
			} );
		}, function () {
			// Network failure: same shape as a failed response, so each screen shows its error state.
			return { ok: false, status: 0, data: null, total: -1, pages: 1, text: '' };
		} );
	}

	/** Every call goes to the real route; a missing or failing route shows the screen error state, never made-up data. */
	function api( method, path, body ) { return request( method, path, body ); }

	function errMsg( r ) {
		return ( r && r.data && r.data.message ) ? r.data.message : ( I.saveFailed );
	}

	function say( type, html ) {
		var toastType = type === 'ok' ? 'success' : ( type === 'err' ? 'error' : ( type === 'warn' ? 'warning' : 'info' ) );
		var text = String( html || '' ).replace( /<[^>]+>/g, '' ).trim();
		if ( window.RbnUi && window.RbnUi.toast ) {
			window.RbnUi.toast( text, { type: toastType } );
			return;
		}
		var region = $( '[data-result]' );
		if ( ! region ) { return; }
		region.innerHTML = note( type, html, type === 'err' ? 'alert' : 'status' );
	}

	/* ---------- Shared behaviour ---------- */

	function initShell() {
		var wrap = $( '.wpseokit-wrap' );
		if ( wrap ) { wrap.classList.add( 'wps-js' ); }
		if ( wrap && T.tone === 'koyu' ) { wrap.setAttribute( 'data-tone', 'koyu' ); }
	}

	/* ---------- Tabs: the shared AdminKit component (rbn-admin-ui) owns roles, arrows, Home/End and the ?tab= / ?sub= address ---------- */

	function initTabs( root ) {
		if ( window.RbnUi ) { window.RbnUi.initTabs( root ); }
	}

	/** Opens a tab of the tool on this page, e.g. goTab( 'dry' ). */
	function goTab( id ) {
		if ( dashApi ) { return dashApi.showSub( 'yoast', id, false ); }
		var tab = $( '[data-rbn-tabs] [data-key="' + id + '"]' );
		if ( ! tab ) { return false; }
		tab.click();
		return true;
	}

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
		on( root, 'click', '.wps-tok[data-insert]', function ( e, btn ) {
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
		var sample = { title: I.previewTitle, sitename: T.siteName || '', sitedesc: '', sep: '-', page: '', excerpt: '', category: '', primary_category: '', date: '', name: '', term_title: I.previewTitle, searchphrase: '…' };
		function refresh( el ) {
			var out = $( '[data-preview-for="' + el.id + '"]' );
			if ( ! out ) { return; }
			var sepEl = $( 'input[name="templates.sep"]:checked' );
			sample.sep = sepEl ? sepEl.value : '-';
			out.innerHTML = '<b>' + esc( resolveVars( el.value, sample ) ) + '</b>';
		}
		$$( '[data-var-target]', root ).forEach( function ( el ) { if ( $( '[data-preview-for="' + el.id + '"]' ) ) { refresh( el ); } } );
		root.addEventListener( 'input', function ( e ) { if ( e.target.matches && e.target.matches( '[data-var-target]' ) ) { refresh( e.target ); } } );
		// Character count under single-line text fields.
		root.addEventListener( 'input', function ( e ) {
			var el = e.target, out = el.matches && el.matches( '[data-count]' ) ? $( '[data-count-for="' + el.id + '"]', root ) : null;
			if ( out ) { out.textContent = el.value.length + ' / ' + el.maxLength; }
		} );
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

	/* ---------- Dashboard tabs: two levels, ?tab= and ?sub= in the address, no reload ---------- */

	var dashApi = null;

	function sp( s ) {
		var a = Array.prototype.slice.call( arguments, 1 ), i = 0;
		return String( s ).replace( /%(?:(\d+)\$)?[sd]/g, function ( m, n ) { var v = a[ n ? n - 1 : i++ ]; return v === undefined ? '' : v; } );
	}

	/** Dashboard tabs are the shared component; this adds "go to" links and tells lazy panels when they are shown. */
	function dashTabs( screen ) {
		var topList = $( '[data-dtabs]', screen );
		if ( ! topList ) { return null; }
		function subList( top ) { return $( '[data-subtabs="' + top + '"]', screen ); }
		function tabsOf( list ) { return $$( '[role="tab"]', list ); }
		function current() {
			return tabsOf( topList ).filter( function ( t ) { return t.getAttribute( 'aria-selected' ) === 'true'; } )[ 0 ] || tabsOf( topList )[ 0 ];
		}
		function showTop( id, focus ) {
			var tab = $( '[data-top="' + id + '"]', topList );
			if ( ! tab ) { return false; }
			tab.click();
			if ( focus ) { tab.focus(); }
			return true;
		}
		function showSub( top, id, focus ) {
			var sl = subList( top ), tab = sl ? $( '[data-sub="' + id + '"]', sl ) : null;
			if ( ! showTop( top, false ) || ! tab ) { return false; }
			tab.click();
			if ( focus ) { tab.focus(); }
			return true;
		}
		function go( spec, heading ) {
			var p = String( spec ).split( ':' ), ok = p[ 1 ] ? showSub( p[ 0 ], p[ 1 ], false ) : showTop( p[ 0 ], false );
			if ( ok && heading ) {
				var top = doc.getElementById( 'dpan-' + p[ 0 ] ), sub = top && p[ 1 ] ? doc.getElementById( 'spn-' + p[ 0 ] + '-' + p[ 1 ] ) : null, h = $( 'h2', sub || top );
				if ( h ) { h.setAttribute( 'tabindex', '-1' ); h.focus(); }
			}
			return ok;
		}
		screen.addEventListener( 'click', function ( e ) {
			var g = e.target.closest ? e.target.closest( '[data-goto]' ) : null;
			if ( g && screen.contains( g ) ) { e.preventDefault(); go( g.getAttribute( 'data-goto' ), true ); }
		} );
		// Panels that load on first view listen for this; the component only toggles [hidden].
		var queued = false;
		new MutationObserver( function () {
			if ( queued ) { return; }
			queued = true;
			Promise.resolve().then( function () {
				queued = false;
				screen.dispatchEvent( new CustomEvent( 'wps:tab', { detail: { top: current().getAttribute( 'data-top' ) } } ) );
			} );
		} ).observe( screen, { subtree: true, attributes: true, attributeFilter: [ 'hidden' ] } );
		return { go: go, showTop: showTop, showSub: showSub, current: function () { return current().getAttribute( 'data-top' ); } };
	}

	function dashHref( top, sub ) {
		var u = new URL( T.pageUrl, window.location.href );
		if ( top && top !== 'overview' ) { u.searchParams.set( 'tab', top ); }
		if ( sub ) { u.searchParams.set( 'sub', sub ); }
		return u.toString();
	}
	function toolHref( tool ) {
		var u = new URL( T.pageUrl, window.location.href );
		u.searchParams.set( 'tool', tool );
		return u.toString();
	}

	/** Date and time as the browser shows them; text the browser cannot read stays as it is. */
	function when( s ) {
		var d = new Date( /^\d+$/.test( String( s ) ) ? +s * 1000 : String( s ).replace( ' ', 'T' ) );
		return isNaN( d.getTime() ) ? String( s ) : d.toLocaleString( undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' } );
	}
	function day( s ) {
		var d = new Date( s + 'T00:00:00' );
		return isNaN( d.getTime() ) ? String( s ) : d.toLocaleDateString( undefined, { day: 'numeric', month: 'short' } );
	}
	function busy( text ) { return '<p class="wps-busy" role="status"><span class="wps-spin" aria-hidden="true"></span>' + esc( text ) + '</p>'; }

	/* ---------- SEO score: Overview, Recommendations, Posts, How it is calculated ---------- */

	function score( screen ) {
		var sum = $( '[data-score-summary]', screen ), S = { site: null, recs: null, posts: null, rules: null };
		var BAND = { good: [ 'ok', 'check', 'bandGood' ], fair: [ 'warn', 'alert', 'bandFair' ], poor: [ 'err', 'alert', 'bandPoor' ] };
		var LEVEL = { critical: [ 'err', 'lvCritical' ], warning: [ 'warn', 'lvWarning' ], info: [ 'mute', 'lvInfo' ] };
		var ORDER = { critical: 0, warning: 1, info: 2 };
		function view( name ) { return $( '[data-score-view="' + name + '"]', screen ); }
		function bandOf( b ) { return BAND[ b ] || BAND.poor; }
		function levelBadge( l ) { var m = LEVEL[ l ] || LEVEL.info; return '<span class="rbn-badge rbn-badge--' + m[ 0 ] + '">' + icon( l === 'critical' ? 'alert' : ( l === 'warning' ? 'info' : 'help' ) ) + esc( I[ m[ 1 ] ] ) + '</span>'; }
		function paint( root ) {
			$$( '[data-p]', root ).forEach( function ( c ) { c.style.setProperty( '--p', c.getAttribute( 'data-p' ) ); } );
			$$( '[data-w]', root ).forEach( function ( c ) { c.style.setProperty( '--w', c.getAttribute( 'data-w' ) ); } );
		}

		function ring( site ) {
			var b = bandOf( site.band && site.band.id ), label = ( site.band && site.band.label ) || I[ b[ 2 ] ];
			return '<div class="wps-score__fig"><div class="wps-ring wps-ring--' + ( b[ 0 ] ) + '"><div class="wps-ring__svg" role="img" aria-label="' + esc( sp( I.scoreOutOf, site.score, label ) ) + '"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="wps-ring__track" cx="60" cy="60" r="52"></circle><circle class="wps-ring__val" cx="60" cy="60" r="52" data-p="' + esc( site.score ) + '"></circle></svg><div class="wps-ring__n"><b>' + esc( site.score ) + '</b><span>/ 100</span></div></div></div><span class="rbn-badge rbn-badge--' + b[ 0 ] + '">' + icon( b[ 1 ] ) + esc( label ) + '</span></div>';
		}
		function breakdown( site ) {
			var out = '<p class="wps-score__lead">' + esc( I.siteScore ) + ': <b>' + esc( site.config ? site.config.score : '–' ) + '</b>';
			if ( site.posts && site.posts.mean !== null && site.posts.mean !== undefined ) { out += ' · ' + esc( I.postsScore ) + ': <b>' + esc( Math.round( site.posts.mean ) ) + '</b> (' + esc( sp( I.postsSampled, site.posts.scored || 0, site.posts.sampled || 0 ) ) + ')'; } else { out += ' · ' + esc( I.noPosts ); }
			return out + '</p>';
		}
		function stamp( site ) {
			var t = site.calculated_at ? when( site.calculated_at ) : '';
			return '<p class="wps-score__when">' + esc( site.stale ? sp( I.calcStale, t ) : sp( I.calcOn, t ) ) + '</p>';
		}

		/** Summary card of the Overview tab: the whole card is one link to the score. */
		function drawSummary( site ) {
			if ( ! sum ) { return; }
			sum.innerHTML = '<section class="rbn-card wps-score wps-sum"><div class="rbn-card__b"><div class="wps-score__grid">' + ring( site ) + '<div><h2 class="wps-score__h"><a class="wps-stretch" href="' + esc( dashHref( 'score' ) ) + '" data-goto="score">' + esc( I.scoreHead ) + '</a></h2>' + breakdown( site ) + stamp( site ) + '<p class="wps-sum__go">' + esc( I.details ) + icon( 'chevr' ) + '</p></div></div></div></section>';
			paint( sum );
		}
		function drawSummaryEmpty() {
			if ( ! sum ) { return; }
			sum.innerHTML = '<section class="rbn-card wps-score"><div class="rbn-card__b"><h2 class="wps-score__h">' + esc( I.scoreHead ) + '</h2><p><b>' + esc( I.scoreNone ) + '</b> ' + esc( I.scoreNoneText ) + '</p><p><button type="button" class="rbn-btn rbn-btn--pri" data-recalc>' + icon( 'scan' ) + esc( I.calcNow ) + '</button></p></div></section>';
		}
		function drawSummaryError() {
			if ( ! sum ) { return; }
			sum.innerHTML = '<section class="rbn-card wps-score"><div class="rbn-card__b"><h2 class="wps-score__h">' + esc( I.scoreHead ) + '</h2>' + note( 'err', '<b>' + esc( I.calcFailed ) + '</b> ' + esc( I.loadFailed ), 'alert' ) + '<p><button type="button" class="rbn-btn" data-recalc>' + icon( 'undo' ) + esc( I.retry ) + '</button></p></div></section>';
		}

		/* -- recommendations -- */

		function fixTarget( it ) {
			var t = it.target || '', n = I.toolNames || {};
			if ( it.fix === 'external' || /^hosting/i.test( t ) ) { return null; }
			if ( it.scope === 'post' ) { return { goto: 'score:posts', href: dashHref( 'score', 'posts' ), label: I.reviewPosts }; }
			var tool = /404/.test( t ) ? 'log404' : /Redirects/.test( t ) ? 'redirects' : /Sitemap/.test( t ) ? 'sitemap' : /Feed/.test( t ) ? 'feed' : /llms/i.test( t ) ? 'llms' : /Schema/.test( t ) ? 'schema' : '';
			if ( tool ) { return { href: toolHref( tool ), label: sp( I.fixIn, n[ tool ] || tool ) }; }
			if ( /Settings|Social/.test( t ) ) { return { goto: 'settings:general', href: dashHref( 'settings', 'general' ), label: sp( I.fixIn, n.settings ) }; }
			return null;
		}
		function recHtml( it ) {
			var fx = fixTarget( it ), m = typeof it.measured === 'string' || typeof it.measured === 'number' ? String( it.measured ) : '';
			if ( it.scope === 'post' ) { m = sp( I.failingOn, it.failing, it.applicable ); }
			var out = '<li class="wps-rec" data-rule="' + esc( it.rule_id ) + '" data-scope="' + esc( it.scope ) + '" data-level="' + esc( it.level ) + '"><div class="wps-rec__main"><p class="wps-rec__top">' + levelBadge( it.level ) + '<span class="rbn-badge rbn-badge--mute">' + esc( it.scope === 'post' ? I.scopePosts : I.scopeSite ) + '</span><span class="rbn-badge rbn-badge--mute">' + esc( sp( I.weight, it.weight ) ) + '</span></p><h3 class="wps-rec__t">' + esc( it.title ) + '</h3>' + ( m ? '<p class="wps-rec__m">' + esc( m ) + '</p>' : '' ) + '<p class="wps-rec__d">' + esc( it.text ) + '</p></div><div class="wps-rec__act">';
			if ( fx ) { out += '<a class="rbn-btn rbn-btn--pri" href="' + esc( fx.href ) + '"' + ( fx.goto ? ' data-goto="' + esc( fx.goto ) + '"' : '' ) + '>' + esc( fx.label ) + '<span class="rbn-sr"> ' + esc( sp( I.fixFor, it.title ) ) + '</span></a>'; }
			if ( it.scope === 'site' ) {
				if ( it.dismissible === false ) { out += '<p class="wps-rec__lock">' + icon( 'lock' ) + esc( I.noHide ) + '</p>'; } else if ( it.dismissed ) { out += '<button type="button" class="rbn-btn" data-rec-restore="' + esc( it.rule_id ) + '">' + icon( 'undo' ) + esc( I.restore ) + '<span class="rbn-sr"> ' + esc( sp( I.fixFor, it.title ) ) + '</span></button>'; } else { out += '<button type="button" class="rbn-btn" data-rec-hide="' + esc( it.rule_id ) + '">' + icon( 'eye' ) + esc( I.hide ) + '<span class="rbn-sr"> ' + esc( sp( I.fixFor, it.title ) ) + '</span></button>'; }
			} else if ( it.level === 'critical' ) { out += '<p class="wps-rec__lock">' + icon( 'lock' ) + esc( I.noHide ) + '</p>'; }
			return out + '</div></li>';
		}
		function allRecs() {
			var items = ( S.recs && S.recs.items ? S.recs.items : [] ).slice();
			( S.posts && S.posts.rules ? S.posts.rules : [] ).forEach( function ( r ) {
				if ( r.failing > 0 ) { items.push( { rule_id: r.rule_id, scope: 'post', level: r.level, weight: r.weight, title: r.title, text: r.text, fix: r.fix, target: r.target, failing: r.failing, applicable: r.applicable, dismissible: false } ); }
			} );
			items.sort( function ( a, b ) { return ( ORDER[ a.level ] - ORDER[ b.level ] ) || ( b.weight - a.weight ); } );
			return items;
		}
		function drawRecs() {
			var box = view( 'recommendations' );
			if ( ! box ) { return; }
			if ( ! S.recs ) { return; }
			var items = allRecs(), act = items.filter( function ( i ) { return ! i.dismissed; } ), hid = items.filter( function ( i ) { return i.dismissed; } );
			var out = '';
			if ( ! act.length ) {
				out = '<section class="rbn-card"><div class="rbn-card__b"><div class="rbn-empty"><span class="rbn-empty__i">' + icon( 'check' ) + '</span><h3>' + esc( I.recsNone ) + '</h3><p>' + esc( I.recsNoneText ) + '</p></div></div></section>';
			} else {
				out = '<div class="wps-recbar"><p>' + esc( sp( I.recsCount, act.length ) ) + '</p></div><ol class="wps-recs">' + act.map( recHtml ).join( '' ) + '</ol>';
			}
			if ( hid.length ) { out += '<details class="wps-hidden"><summary>' + icon( 'eye' ) + '<span>' + esc( sp( I.hiddenHead, hid.length ) ) + '</span></summary><ol class="wps-recs">' + hid.map( recHtml ).join( '' ) + '</ol></details>'; }
			out += '<p class="rbn-help" role="status" data-rec-live></p>';
			var open = $( '.wps-hidden', box ) && $( '.wps-hidden', box ).open;
			box.innerHTML = out;
			if ( open && $( '.wps-hidden', box ) ) { $( '.wps-hidden', box ).open = true; }
		}

		/* -- overview view -- */

		function drawOverview() {
			var box = view( 'overview' );
			if ( ! box || ! S.site ) { return; }
			var site = S.site, out = '<section class="rbn-card wps-score"><div class="rbn-card__b"><div class="wps-score__grid">' + ring( site ) + '<div><h2 class="wps-score__h">' + esc( I.scoreHead ) + '</h2>' + breakdown( site ) + stamp( site );
			if ( site.cap_applied && site.cap_note ) { out += note( 'warn', esc( site.cap_note ) ); }
			if ( site.environment_note ) { out += '<p class="rbn-help wps-score__note">' + esc( site.environment_note ) + '</p>'; }
			out += '<p class="wps-score__note"><button type="button" class="rbn-btn" data-recalc>' + icon( 'undo' ) + esc( I.recalc ) + '</button></p></div></div></div></section>';
			var top = allRecs().filter( function ( i ) { return ! i.dismissed; } ).slice( 0, 3 );
			if ( top.length ) { out += '<h3 class="wps-score__sub">' + esc( sp( I.recsCount, top.length ) ) + '</h3><ol class="wps-recs wps-recs--mini">' + top.map( recHtml ).join( '' ) + '</ol>'; }
			box.innerHTML = out;
			paint( box );
		}

		/* -- posts view -- */

		function drawPosts() {
			var box = view( 'posts' );
			if ( ! box || ! S.posts ) { return; }
			var posts = S.posts.posts || [];
			var sel = '<div class="wps-filterbar"><div class="rbn-field"><label for="wps-pb">' + esc( I.colBand ) + '</label><select class="rbn-select" id="wps-pb" data-post-band><option value="all">' + esc( I.allBands ) + '</option><option value="good">' + esc( I.bandGood ) + '</option><option value="fair">' + esc( I.bandFair ) + '</option><option value="poor">' + esc( I.bandPoor ) + '</option></select></div><p class="rbn-help" role="status" data-posts-live></p></div>';
			if ( ! posts.length ) { box.innerHTML = '<section class="rbn-card"><div class="rbn-card__b"><div class="rbn-empty"><span class="rbn-empty__i">' + icon( 'doc' ) + '</span><h3>' + esc( I.noPosts ) + '</h3></div></div></section>'; return; }
			var rows = posts.map( function ( p ) {
				var b = bandOf( p.band );
				return '<tr data-post-row data-band="' + esc( p.band ) + '"><th scope="row" data-label="' + esc( I.colPost ) + '">' + esc( p.title || '#' + p.id ) + '</th><td data-label="' + esc( I.colScore ) + '"><span class="wps-num">' + esc( p.score ) + '</span></td><td data-label="' + esc( I.colBand ) + '"><span class="rbn-badge rbn-badge--' + b[ 0 ] + '">' + icon( b[ 1 ] ) + esc( I[ b[ 2 ] ] ) + '</span></td><td data-label="' + esc( I.colFailing ) + '">' + ( p.failing.length ? esc( p.failing.join( ', ' ) ) : esc( I.noFailing ) ) + '</td><td data-label="' + esc( I.action ) + '">' + ( p.edit ? '<a class="rbn-btn rbn-btn--sm" href="' + esc( p.edit ) + '">' + esc( I.editPost ) + '<span class="rbn-sr"> ' + esc( sp( I.fixFor, p.title ) ) + '</span></a>' : '' ) + '</td></tr>';
			} ).join( '' );
			box.innerHTML = sel + '<section class="rbn-card"><div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' + esc( I.colPost + ' / ' + I.colScore ) + '</caption><thead><tr><th scope="col">' + esc( I.colPost ) + '</th><th scope="col">' + esc( I.colScore ) + '</th><th scope="col">' + esc( I.colBand ) + '</th><th scope="col">' + esc( I.colFailing ) + '</th><th scope="col">' + esc( I.action ) + '</th></tr></thead><tbody>' + rows + '</tbody></table></div></div></section>';
		}

		/* -- how view: the rule catalogue comes from the server -- */

		function pct( n ) { return Math.round( Number( n ) * 100 ); }
		function howStates() {
			var st = [ [ 'ok', 'check', 'stPass', 'stPassVal' ], [ 'warn', 'info', 'stPartial', 'stPartialVal' ], [ 'err', 'x', 'stFail', 'stFailVal' ], [ 'mute', 'help', 'stNa', 'stNaVal' ] ];
			return '<ul class="wps-states">' + st.map( function ( x ) { return '<li><span class="rbn-badge rbn-badge--' + x[ 0 ] + '">' + icon( x[ 1 ] ) + esc( I[ x[ 2 ] ] ) + '</span><span class="wps-states__v">' + esc( I[ x[ 3 ] ] ) + '</span></li>'; } ).join( '' ) + '</ul>';
		}
		function howStep( n, head, text, body ) {
			return '<li class="wps-step"><section class="rbn-card"><div class="rbn-card__b"><p class="wps-step__n"><span class="rbn-badge rbn-badge--info">' + esc( sp( I.howStep, n ) ) + '</span></p><h3 class="wps-step__h">' + esc( head ) + '</h3><p>' + esc( text ) + '</p>' + body + '</div></section></li>';
		}
		function howBlend( R ) {
			var b = R.site_blend || { config: 0.65, posts: 0.35 };
			var part = function ( cls, w, label ) { return '<li class="wps-blend__' + cls + '" data-w="' + esc( pct( w ) ) + '"><b>' + esc( pct( w ) ) + '%</b><span>' + esc( label ) + '</span></li>'; };
			return '<ul class="wps-blend">' + part( 'a', b.config, I.siteScore ) + part( 'b', b.posts, I.postsScore ) + '</ul><p class="rbn-help">' + esc( sp( I.howSample, R.post_sample || 200 ) ) + '</p>';
		}
		function howBands( R ) {
			var list = R.bands || [], you = S.site ? Math.round( Number( S.site.score ) ) : null, names = [], bar = '', legend = '';
			list.forEach( function ( b ) {
				var m = bandOf( b.id ), label = I[ m[ 2 ] ];
				names.push( label + ' ' + b.min + '–' + b.max );
				bar += '<span class="wps-meter__seg wps-meter__seg--' + m[ 0 ] + '" data-w="' + esc( b.max - b.min + 1 ) + '"></span>';
				legend += '<li><span class="rbn-badge rbn-badge--' + m[ 0 ] + '">' + icon( m[ 1 ] ) + esc( label ) + '</span> <span class="rbn-help">' + esc( b.min ) + '–' + esc( b.max ) + '</span></li>';
			} );
			var pin = you === null ? '' : '<span class="wps-meter__pin" data-p="' + esc( you ) + '"><b>' + esc( sp( I.bandsYou, you ) ) + '</b>' + icon( 'chevd' ) + '</span>';
			return '<div class="wps-meter' + ( pin ? ' wps-meter--pin' : '' ) + '"><div class="wps-meter__bar" role="img" aria-label="' + esc( sp( I.bandsAria, names.join( ', ' ) ) + ( you === null ? '' : '. ' + sp( I.bandsYou, you ) ) ) + '">' + bar + '</div>' + pin + '</div><ul class="wps-bands">' + legend + '</ul>';
		}
		function drawHow() {
			var box = view( 'how' );
			if ( ! box || ! S.rules ) { return; }
			var R = S.rules, rows = [], crit = [];
			[ 'site', 'post' ].forEach( function ( scope ) {
				( R.rules && R.rules[ scope ] ? R.rules[ scope ] : [] ).forEach( function ( r ) {
					var lv = LEVEL[ r.level ] || LEVEL.info;
					if ( r.level === 'critical' ) { crit.push( '<li>' + esc( r.title ) + ' <span class="rbn-help">(' + esc( r.weight ) + ')</span></li>' ); }
					rows.push( '<tr><th scope="row" data-label="' + esc( I.colRule ) + '">' + esc( r.title ) + '</th><td data-label="' + esc( I.colApplies ) + '">' + esc( scope === 'post' ? I.scopePosts : I.scopeSite ) + '</td><td data-label="' + esc( I.colLevel ) + '"><span class="rbn-badge rbn-badge--mute">' + esc( I[ lv[ 1 ] ] ) + '</span></td><td data-label="' + esc( I.colWeight ) + '" class="rbn-num">' + esc( r.weight ) + '</td></tr>' );
				} );
			} );
			var steps = howStep( 1, I.howS1, I.howS1Text, howStates() ) + howStep( 2, I.howS2, I.howS2Text, '<p class="wps-formula__l">' + esc( I.howFormulaLabel ) + '</p><p class="wps-formula"><code>' + esc( I.howFormula ) + '</code></p>' ) + howStep( 3, I.howS3, I.howS3Text, howBlend( R ) );
			var cap = note( 'warn', '<b>' + esc( I.capHead ) + '.</b> ' + esc( sp( I.capText, R.critical_cap || 79 ) ) ) + ( crit.length ? '<p class="wps-cap__l">' + esc( I.capRules ) + '</p><ul class="wps-cap">' + crit.join( '' ) + '</ul>' : '' );
			box.innerHTML = '<section class="rbn-card wps-how"><div class="rbn-card__b"><h2>' + esc( I.howHead ) + '</h2><p class="wps-how__lead">' + esc( I.howLead ) + '</p></div></section><ol class="wps-steps">' + steps + '</ol><section class="rbn-card wps-how"><div class="rbn-card__b">' + cap + '</div></section><section class="rbn-card wps-how"><div class="rbn-card__b"><h2>' + esc( I.bandsHead ) + '</h2><p>' + esc( I.bandsText ) + '</p>' + howBands( R ) + '</div></section><section class="rbn-card wps-catalog"><div class="rbn-card__h"><h2>' + esc( I.rulesHead ) + '</h2></div><div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' + esc( I.rulesHead ) + '</caption><thead><tr><th scope="col">' + esc( I.colRule ) + '</th><th scope="col">' + esc( I.colApplies ) + '</th><th scope="col">' + esc( I.colLevel ) + '</th><th scope="col" class="rbn-num">' + esc( I.colWeight ) + '</th></tr></thead><tbody>' + rows.join( '' ) + '</tbody></table></div></div></section>';
			paint( box );
		}

		function drawAll() { drawOverview(); drawRecs(); drawPosts(); drawHow(); }
		function failAll() {
			$$( '[data-score-view]', screen ).forEach( function ( b ) {
				b.innerHTML = note( 'err', '<b>' + esc( I.loadFailed ) + '</b>', 'alert' ) + '<p><button type="button" class="rbn-btn" data-recalc="load">' + icon( 'undo' ) + esc( I.retry ) + '</button></p>';
			} );
		}
		function emptyAll() {
			$$( '[data-score-view]', screen ).forEach( function ( b ) {
				if ( b.getAttribute( 'data-score-view' ) === 'how' ) { return; }
				b.innerHTML = '<section class="rbn-card"><div class="rbn-card__b"><div class="rbn-empty"><span class="rbn-empty__i">' + icon( 'seo' ) + '</span><h3>' + esc( I.scoreNone ) + '</h3><p>' + esc( I.scoreNoneText ) + '</p><button type="button" class="rbn-btn rbn-btn--pri" data-recalc>' + icon( 'scan' ) + esc( I.calcNow ) + '</button></div></div></section>';
			} );
		}

		function loadRest() {
			return Promise.all( [
				api( 'GET', 'score/recommendations?scope=site&include_dismissed=1' ),
				api( 'GET', 'score/posts' ),
				api( 'GET', 'score/rules' )
			] ).then( function ( r ) {
				if ( r[ 0 ].ok ) { S.recs = r[ 0 ].data; }
				if ( r[ 1 ].ok ) { S.posts = r[ 1 ].data; }
				if ( r[ 2 ].ok ) { S.rules = r[ 2 ].data; }
				if ( ! r[ 0 ].ok || ! r[ 1 ].ok ) { failAll(); return; }
				drawAll();
			} );
		}
		function load() {
			if ( sum && sum.getAttribute( 'data-calculated' ) === '0' && ! S.site ) { drawSummaryEmpty(); emptyAll(); api( 'GET', 'score/rules' ).then( function ( r ) { if ( r.ok ) { S.rules = r.data; drawHow(); } } ); return Promise.resolve(); }
			return api( 'GET', 'score/site' ).then( function ( r ) {
				if ( ! r.ok || ! r.data ) { drawSummaryError(); failAll(); return; }
				S.site = r.data;
				if ( sum ) { sum.setAttribute( 'data-calculated', '1' ); }
				drawSummary( S.site );
				return loadRest();
			} );
		}
		function recalc( btn ) {
			var live = $( '[data-score-error]', screen );
			if ( btn ) { btn.disabled = true; btn.innerHTML = '<span class="wps-spin" aria-hidden="true"></span>' + esc( I.calculating ); }
			if ( live ) { live.hidden = true; }
			api( 'POST', 'score/site/recalculate' ).then( function ( r ) {
				if ( ! r.ok || ! r.data ) {
					if ( btn ) { btn.disabled = false; btn.innerHTML = icon( 'undo' ) + esc( I.retry ); }
					if ( live ) { live.hidden = false; }
					if ( ! S.site ) { drawSummaryError(); }
					return;
				}
				S.site = r.data;
				if ( sum ) { sum.setAttribute( 'data-calculated', '1' ); }
				drawSummary( S.site );
				loadRest();
			} );
		}

		on( screen, 'click', '[data-recalc]', function ( e, b ) {
			if ( b.getAttribute( 'data-recalc' ) === 'load' ) { load(); } else { recalc( b ); }
		} );
		on( screen, 'click', '[data-rec-hide]', function ( e, b ) {
			var id = b.getAttribute( 'data-rec-hide' );
			b.disabled = true;
			api( 'POST', 'score/dismiss', { rule_id: id, post_id: 0 } ).then( function ( r ) {
				if ( ! r.ok ) { b.disabled = false; return; }
				api( 'GET', 'score/recommendations?scope=site&include_dismissed=1' ).then( function ( q ) {
					if ( q.ok ) { S.recs = q.data; drawRecs(); drawOverview(); }
					var live = $( '[data-rec-live]', screen ); if ( live ) { live.textContent = I.hidden; }
				} );
			} );
		} );
		on( screen, 'click', '[data-rec-restore]', function ( e, b ) {
			var id = b.getAttribute( 'data-rec-restore' );
			b.disabled = true;
			api( 'DELETE', 'score/dismiss/' + encodeURIComponent( id ) ).then( function ( r ) {
				if ( ! r.ok ) { b.disabled = false; return; }
				api( 'GET', 'score/recommendations?scope=site&include_dismissed=1' ).then( function ( q ) {
					if ( q.ok ) { S.recs = q.data; drawRecs(); drawOverview(); }
				} );
			} );
		} );
		on( screen, 'change', '[data-post-band]', function ( e, s ) {
			var n = 0;
			$$( '[data-post-row]', screen ).forEach( function ( row ) {
				var show = s.value === 'all' || row.getAttribute( 'data-band' ) === s.value;
				row.hidden = ! show;
				if ( show ) { n++; }
			} );
			var live = $( '[data-posts-live]', screen ); if ( live ) { live.textContent = sp( I.postsSampled, n, ( S.posts.posts || [] ).length ); }
		} );

		load();
	}

	/* ---------- Reports: trend charts drawn as SVG from the stored snapshots ---------- */

	function reports( screen ) {
		var root = $( '[data-reports]', screen );
		if ( ! root ) { return; }
		var days = 30, started = false, busyPoll = 0;
		var LABEL = {
			'score.site': I.repScore, 'score.config': I.siteScore, 'score.posts': I.postsScore, 'redirects.active': I.repActive, 'redirects.hits': sp( I.repTotal, I.repRedirHits ),
			'not_found.addresses': I.repAddresses, 'not_found.hits': sp( I.repTotal, I.repHits404 ), 'not_found.new_24h': I.repNew24, 'sitemap.total': I.repUrls, 'meta.title_pct': I.repTitles, 'meta.description_pct': I.repDescs,
			'rules.total': I.repRulesTotal, 'rules.passed': I.repRulesPassed, 'rules.remaining': I.repRulesLeft, 'rules.na': I.repRulesNa, 'meta.posts': I.repPostsCount
		};
		function schemaName( k ) {
			var n = k.slice( 7 );
			return ( I.schemaTypes && I.schemaTypes[ n ] ) || n;
		}
		function label( k ) {
			if ( LABEL[ k ] ) { return LABEL[ k ]; }
			if ( k.indexOf( 'sitemap.group.' ) === 0 ) { return sp( I.repSitemapOf, k.slice( 14 ) ); }
			if ( k.indexOf( 'schema.' ) === 0 ) { return sp( I.repSchemaOf, schemaName( k ) ); }
			return k.replace( /[._]/g, ' ' );
		}
		function state( name ) {
			$$( '[data-rep]', root ).forEach( function ( b ) { b.hidden = b.getAttribute( 'data-rep' ) !== name; } );
		}
		function num( v ) { return v === null || v === undefined ? I.repNoValue : String( Math.round( v * 10 ) / 10 ); }

		function geometry( d, keys, o ) {
			var pts = d.points, span = Math.max( 1, d.range - 1 ), from = new Date( d.from + 'T00:00:00' ).getTime(), all = [];
			keys.forEach( function ( k ) { pts.forEach( function ( p ) { if ( p.values[ k ] !== null && p.values[ k ] !== undefined ) { all.push( +p.values[ k ] ); } } ); } );
			var lo = o.min !== undefined ? o.min : 0, hi = o.max !== undefined ? o.max : Math.max.apply( null, all.concat( [ 1 ] ) );
			if ( hi <= lo ) { hi = lo + 1; }
			function X( p ) { return Math.round( ( ( new Date( p.date + 'T00:00:00' ).getTime() - from ) / 864e5 ) / span * 600 * 10 ) / 10; }
			function Y( v ) { return Math.round( ( 152 - ( ( v - lo ) / ( hi - lo ) ) * 144 ) * 10 ) / 10; }
			return { X: X, Y: Y, lo: lo, hi: hi };
		}
		/** One polyline per run of consecutive days with a value: a missing day breaks the line instead of being guessed. */
		function paths( d, key, g ) {
			var line = '', dots = '', run = [], prev = null;
			function flush() {
				if ( run.length > 1 ) { line += 'M' + run.map( function ( q ) { return q[ 0 ] + ' ' + q[ 1 ]; } ).join( ' L' ); }
				run = [];
			}
			d.points.forEach( function ( p ) {
				var v = p.values[ key ];
				if ( v === null || v === undefined ) { flush(); prev = null; return; }
				var t = new Date( p.date + 'T00:00:00' ).getTime();
				if ( prev !== null && Math.round( ( t - prev ) / 864e5 ) > 1 ) { flush(); }
				prev = t;
				var q = [ g.X( p ), g.Y( +v ) ];
				run.push( q );
				dots += 'M' + q[ 0 ] + ' ' + q[ 1 ] + ' l0 0';
			} );
			flush();
			return { line: line, dots: dots };
		}
		function lastVal( d, key ) {
			for ( var i = d.points.length - 1; i >= 0; i-- ) { var v = d.points[ i ].values[ key ]; if ( v !== null && v !== undefined ) { return v; } }
			return null;
		}
		function hasData( d, key ) { return lastVal( d, key ) !== null; }
		function changeText( d, key ) {
			var s = d.summary && d.summary[ key ];
			if ( ! s || s.change === null || s.change === undefined ) { return ''; }
			return ( s.change > 0 ? '+' : '' ) + num( s.change ) + ' · ' + day( d.from ) + ' – ' + day( d.to );
		}

		function chart( d, o ) {
			var keys = o.keys.filter( function ( k ) { return hasData( d, k ); } );
			if ( ! keys.length ) { return ''; }
			var g = geometry( d, keys, o ), svg = '<svg class="wps-chart__svg" viewBox="0 0 600 160" preserveAspectRatio="none" role="img" aria-label="' + esc( o.title ) + '">';
			[ 8, 80, 152 ].forEach( function ( y ) { svg += '<line class="wps-chart__grid" x1="0" y1="' + y + '" x2="600" y2="' + y + '"></line>'; } );
			var rows = '';
			keys.forEach( function ( k, i ) {
				var p = paths( d, k, g ), c = i ? ' wps-chart__ln--2' : '', pc = i ? ' wps-chart__pt--2' : '';
				if ( i === 0 && p.line ) { svg += '<path class="wps-chart__area" d="' + p.line + ' L' + g.X( d.points[ d.points.length - 1 ] ) + ' 152 L' + g.X( d.points[ 0 ] ) + ' 152 Z"></path>'; }
				svg += ( p.line ? '<path class="wps-chart__ln' + c + '" d="' + p.line + '"></path>' : '' ) + '<path class="wps-chart__pt' + pc + '" d="' + p.dots + '"></path>';
			} );
			svg += '</svg>';
			var head = '<tr><th scope="col">' + esc( I.colDate ) + '</th>' + keys.map( function ( k ) { return '<th scope="col" class="rbn-num">' + esc( label( k ) ) + '</th>'; } ).join( '' ) + '</tr>';
			d.points.forEach( function ( p ) { rows += '<tr><th scope="row" data-label="' + esc( I.colDate ) + '">' + esc( p.date ) + '</th>' + keys.map( function ( k ) { return '<td data-label="' + esc( label( k ) ) + '" class="rbn-num">' + esc( num( p.values[ k ] ) ) + '</td>'; } ).join( '' ) + '</tr>'; } );
			var lead = keys.map( function ( k, i ) { return i ? '<span>' + esc( label( k ) + ': ' + num( lastVal( d, k ) ) ) + '</span>' : '<strong>' + esc( num( lastVal( d, k ) ) ) + '</strong><span>' + esc( label( k ) + ( o.suffix || '' ) ) + '</span>'; } ).join( '' );
			var ch = changeText( d, keys[ 0 ] );
			var lg = keys.length > 1 ? '<ul class="wps-chart__lg">' + keys.map( function ( k, i ) { return '<li' + ( i ? ' class="is-2"' : '' ) + '><svg viewBox="0 0 36 12" aria-hidden="true"><line x1="0" y1="6" x2="36" y2="6"></line></svg>' + esc( label( k ) + ' (' + ( i ? I.repDash : I.repLine ).toLowerCase() + ')' ) + '</li>'; } ).join( '' ) + '</ul>' : '';
			return '<li' + ( o.wide ? ' class="wps-wide"' : '' ) + '><section class="rbn-card"><div class="rbn-card__h"><h2>' + esc( o.title ) + '</h2>' + ( o.text ? '<p>' + esc( o.text ) + '</p>' : '' ) + '</div><div class="rbn-card__b"><figure class="wps-chart"><figcaption class="rbn-sr">' + esc( o.title ) + '</figcaption><p class="wps-chart__k">' + lead + ( ch ? '<span>' + esc( ch ) + '</span>' : '' ) + '</p><div class="wps-chart__plot"><div class="wps-chart__y" aria-hidden="true"><span>' + esc( Math.round( g.hi ) ) + '</span><span>' + esc( Math.round( g.lo ) ) + '</span></div>' + svg + '<div class="wps-chart__x" aria-hidden="true"><span>' + esc( day( d.from ) ) + '</span><span>' + esc( day( d.to ) ) + '</span></div></div>' + lg + '<details><summary>' + esc( I.repTable ) + '</summary><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' + esc( o.title ) + '</caption><thead>' + head + '</thead><tbody>' + rows + '</tbody></table></div></details></figure></div></section></li>';
		}
		function bars( d, o ) {
			var items = o.keys.filter( function ( k ) { return hasData( d, k ); } );
			if ( ! items.length ) { return ''; }
			var max = o.pct ? 100 : Math.max.apply( null, items.map( function ( k ) { return +lastVal( d, k ); } ).concat( [ 1 ] ) );
			return '<li><section class="rbn-card"><div class="rbn-card__h"><h2>' + esc( o.title ) + '</h2><p>' + esc( sp( I.repMeta, d.latest ? when( d.latest.taken_at ) : '' ) ) + '</p></div><div class="rbn-card__b"><ul class="wps-hbars">' + items.map( function ( k, i ) {
				var v = +lastVal( d, k ), posts = +lastVal( d, 'meta.posts' ), abs = o.counts && posts > 0 ? ' (' + Math.round( v * posts / 100 ) + ' / ' + posts + ')' : '';
				return '<li' + ( i % 2 && ! o.counts ? ' class="is-2"' : '' ) + '><span>' + esc( ( o.names ? o.names( k ) : label( k ) ) + abs ) + '</span><span class="wps-hbars__t" aria-hidden="true"><i data-w="' + esc( Math.max( 0, Math.min( 100, v / max * 100 ) ) ) + '"></i></span><span class="wps-hbars__v">' + esc( num( v ) + ( o.pct ? '%' : '' ) ) + '</span>' + '</li>';
			} ).join( '' ) + '</ul>' + ( o.help ? '<p class="rbn-help">' + esc( o.help ) + '</p>' : '' ) + '</div></section></li>';
		}
		function summaryCard( d ) {
			var keys = Object.keys( d.summary || {} ).filter( function ( k ) { var s = d.summary[ k ]; return s.start !== null || s.end !== null; } );
			if ( ! keys.length ) { return ''; }
			return '<li class="wps-wide"><section class="rbn-card"><div class="rbn-card__h"><h2>' + esc( I.repSummary ) + '</h2><p>' + esc( I.repGap ) + '</p></div><div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' + esc( I.repSummary ) + '</caption><thead><tr><th scope="col">' + esc( I.colMetric ) + '</th><th scope="col" class="rbn-num">' + esc( I.colStart ) + '</th><th scope="col" class="rbn-num">' + esc( I.colEnd ) + '</th><th scope="col" class="rbn-num">' + esc( I.colChange ) + '</th></tr></thead><tbody>' + keys.map( function ( k ) {
				var s = d.summary[ k ];
				return '<tr><th scope="row" data-label="' + esc( I.colMetric ) + '">' + esc( label( k ) ) + '</th><td data-label="' + esc( I.colStart ) + '" class="rbn-num">' + esc( num( s.start ) ) + '</td><td data-label="' + esc( I.colEnd ) + '" class="rbn-num">' + esc( num( s.end ) ) + '</td><td data-label="' + esc( I.colChange ) + '" class="rbn-num">' + ( s.change === null ? esc( I.repNoValue ) : esc( ( s.change > 0 ? '+' : '' ) + num( s.change ) ) ) + '</td></tr>';
			} ).join( '' ) + '</tbody></table></div></div></section></li>';
		}

		function valuesCard( d ) {
			var keys = Object.keys( d.summary || {} ).filter( function ( k ) { return d.summary[ k ].end !== null; } );
			if ( ! keys.length ) { return ''; }
			return '<li class="wps-wide"><section class="rbn-card"><div class="rbn-card__h"><h2>' + esc( I.repSummary ) + '</h2></div><div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' + esc( I.repSummary ) + '</caption><thead><tr><th scope="col">' + esc( I.colMetric ) + '</th><th scope="col" class="rbn-num">' + esc( I.repValue ) + '</th></tr></thead><tbody>' + keys.map( function ( k ) { return '<tr><th scope="row" data-label="' + esc( I.colMetric ) + '">' + esc( label( k ) ) + '</th><td data-label="' + esc( I.repValue ) + '" class="rbn-num">' + esc( num( d.summary[ k ].end ) ) + '</td></tr>'; } ).join( '' ) + '</tbody></table></div></div></section></li>';
		}
		function draw( d ) {
			var meta = $( '[data-rep-meta]', root ), box = $( '[data-rep-charts]', root ), one = $( '[data-rep-one]', root ), main = $( '[data-rep-main]', root );
			var meta_txt = [];
			if ( d.latest ) { meta_txt.push( sp( I.repMeta, when( d.latest.taken_at ) ) ); }
			if ( meta ) { meta.textContent = meta_txt.join( ' · ' ); }
			var schemaKeys = ( d.columns || [] ).map( function ( c ) { return c.key; } ).filter( function ( k ) { return k.indexOf( 'schema.' ) === 0; } );
			if ( d.points.length === 1 ) { box.innerHTML = '<ul class="wps-reports">' + bars( d, { keys: [ 'meta.title_pct', 'meta.description_pct' ], title: I.repCoverage, pct: true, counts: true, help: I.repCoverageHelp } ) + bars( d, { keys: schemaKeys, title: I.repSchema, names: schemaName } ) + valuesCard( d ) + '</ul>'; finish( d, box, one, main ); return; }
			var html = chart( d, { keys: [ 'score.site' ], title: I.repScore, text: I.repGap, wide: true, min: 0, max: 100, suffix: ' / 100' } )
				+ chart( d, { keys: [ 'redirects.active', 'not_found.hits' ], title: I.repRedirects, text: I.repGap } )
				+ chart( d, { keys: [ 'sitemap.total' ], title: I.repSitemap, text: I.repGap } )
				+ bars( d, { keys: [ 'meta.title_pct', 'meta.description_pct' ], title: I.repCoverage, pct: true, counts: true, help: I.repCoverageHelp } )
				+ bars( d, { keys: schemaKeys, title: I.repSchema, names: schemaName } )
				+ summaryCard( d );
			box.innerHTML = '<ul class="wps-reports">' + html + '</ul>';
			finish( d, box, one, main );
		}
		function finish( d, box, one, main ) {
			$$( '[data-w]', box ).forEach( function ( i ) { i.style.setProperty( '--w', i.getAttribute( 'data-w' ) + '%' ); } );
			one.hidden = d.points.length !== 1;
			if ( d.points.length === 1 ) { one.innerHTML = note( 'info', esc( I.repOne ) ); }
			main.hidden = false;
			var csv = $( '[data-csv-link]', root );
			if ( csv ) { csv.href = T.restUrl + 'reports/export?range=' + days + '&_wpnonce=' + encodeURIComponent( T.nonce ); csv.setAttribute( 'download', '' ); }
			state( 'ok' );
		}
		function load() {
			state( 'loading' );
			return api( 'GET', 'reports?range=' + days ).then( function ( r ) {
				if ( ! r.ok || ! r.data || r.data.status === 'error' ) { state( 'error' ); return; }
				var d = r.data;
				if ( d.status === 'preparing' || ( d.status === 'no_data' && d.snapshot_scheduled ) ) { state( 'preparing' ); poll(); return; }
				if ( d.status === 'no_data' ) { state( 'nodata' ); return; }
				draw( d );
			} );
		}
		function poll() {
			if ( busyPoll++ > 8 ) { busyPoll = 0; state( 'nodata' ); return; }
			setTimeout( function () {
				api( 'GET', 'reports?range=' + days ).then( function ( r ) {
					if ( r.ok && r.data && r.data.status === 'ok' ) { busyPoll = 0; draw( r.data ); } else { poll(); }
				} );
			}, 3000 );
		}
		function snapshot( btn ) {
			var old = btn.innerHTML;
			btn.disabled = true;
			btn.innerHTML = '<span class="wps-spin" aria-hidden="true"></span>' + esc( I.repTaking );
			api( 'POST', 'reports/snapshot' ).then( function ( r ) {
				btn.disabled = false;
				btn.innerHTML = old;
				if ( ! r.ok ) { state( 'error' ); return; }
				if ( r.status === 202 ) { state( 'preparing' ); busyPoll = 0; poll(); return; }
				load();
			} );
		}

		on( root, 'click', '[data-snapshot]', function ( e, b ) { snapshot( b ); } );
		on( root, 'click', '[data-rep-retry]', function () { load(); } );
		on( root, 'click', '[data-print]', function () { window.print(); } );
		on( root, 'change', 'input[name="rep-range"]', function ( e, r ) { days = parseInt( r.value, 10 ) || 30; load(); } );
		function maybeStart() {
			if ( started ) { return; }
			var p = doc.getElementById( 'dpan-reports' );
			if ( p && ! p.hidden ) { started = true; load(); }
		}
		screen.addEventListener( 'wps:tab', maybeStart );
		maybeStart();
	}

	/* ---------- Logs: the four sub-tabs are AdminKit's shared log list (adminkit-core-logs), one channel each ---------- */

	function logs( screen ) {
		function loadNew404() {
			var box = $( '[data-log-note]', screen );
			if ( ! box ) { return; }
			api( 'GET', '404-logs?per_page=100&orderby=first_seen_at&order=desc' ).then( function ( r ) {
				var n = 0, cut = Date.now() - 864e5;
				if ( r.ok && Array.isArray( r.data ) ) { r.data.forEach( function ( l ) { if ( new Date( l.first_seen_at ).getTime() >= cut ) { n++; } } ); }
				box.innerHTML = n ? '<div class="rbn-note">' + icon( 'info' ) + '<div><p><b>' + esc( sp( n === 1 ? I.logNew404One : I.logNew404, n ) ) + '</b> ' + esc( I.logNew404Text ) + '</p><div class="rbn-note__act"><a class="rbn-btn" href="' + esc( toolHref( 'log404' ) ) + '">' + icon( 'alert' ) + esc( I.logOpen404 ) + '</a></div></div></div>' : '';
			} );
		}
		/* A list starts when its sub-tab is first shown (the component script is deferred, so wait for it). */
		function start() {
			if ( ! window.RbnKitLogs ) { return; }
			$$( '[data-wps-logs]', screen ).forEach( function ( n ) {
				if ( n.classList.contains( 'rbnkit-logs' ) || n.closest( '[hidden]' ) ) { return; }
				window.RbnKitLogs.init( n );
				if ( n.getAttribute( 'data-wps-logs' ) === 'activity' ) { loadNew404(); }
			} );
		}
		screen.addEventListener( 'wps:tab', start );
		if ( doc.readyState === 'complete' ) { start(); } else { window.addEventListener( 'load', start ); }
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
			line( 'redirects', fmt( I.cardRedirects, n ) );
		} );
		api( 'GET', '404-logs?per_page=5&orderby=hit_count&order=desc' ).then( function ( r ) {
			var tb = $( '[data-activity] tbody' );
			if ( ! r.ok ) { line( 'log404', I.loadFailed ); if ( tb ) { tb.innerHTML = '<tr><td colspan="3">' + esc( I.loadFailed ) + '</td></tr>'; } return; }
			var n = r.total >= 0 ? r.total : ( r.data || [] ).length;
			setStat( 'log', String( n ) );
			line( 'log404', fmt( I.cardLog, n ) );
			if ( n ) { badge( 'log404', 'warn', 'alert', I.needsLook ); } else { badge( 'log404', 'ok', 'check', I.clean ); }
			if ( tb ) {
				var url = T.pageUrl + ( T.pageUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'tool=log404';
				tb.innerHTML = ( r.data || [] ).length ? r.data.map( function ( l ) {
					return '<tr><td data-label="' + esc( I.event ) + '"><code>' + esc( l.url_path ) + '</code></td><td data-label="' + esc( I.hits ) + '" class="rbn-num">' + esc( l.hit_count ) + '</td><td data-label="' + esc( I.action ) + '" class="rbn-acts"><a class="rbn-btn rbn-btn--sm" href="' + esc( url ) + '">' + esc( I.fix ) + '</a></td></tr>';
				} ).join( '' ) : '<tr><td colspan="3">' + esc( I.no404 ) + '</td></tr>';
			}
		} );
		api( 'GET', 'import/state' ).then( function ( r ) {
			var b = $( '[data-import-state]' );
			if ( b && r.ok && r.data && r.data.state && r.data.state !== 'none' ) {
				b.innerHTML = '<span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + esc( r.data.state === 'applied' ? ( I.importDone ) : r.data.state ) + '</span>';
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
				out.textContent = blocks.length ? blocks.join( '\n\n' ) : ( I.noSchema );
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
			chk.textContent = r.data.enabled ? ( I.feedOn ) : ( I.feedOff );
		} );
		api( 'GET', 'feed/preview' ).then( function ( r ) {
			if ( ! sample ) { return; }
			sample.textContent = r.ok && r.data && r.data.sample_item_xml ? r.data.sample_item_xml : ( r.status === 404 ? ( I.noPosts ) : I.loadFailed );
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
					return '<tr><th scope="row" data-label="' + esc( I.dlFile ) + '"><code>' + esc( i.name ) + '</code></th><td data-label="' + esc( I.dlUrls ) + '" class="rbn-num">' + esc( i.count ) + '</td><td data-label="' + esc( I.dlLastmod ) + '">' + esc( i.lastmod ? new Date( i.lastmod ).toLocaleString() : '–' ) + '</td><td data-label="' + esc( I.dlStatus ) + '"><span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + esc( I.dlOk ) + '</span></td></tr>';
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
		var h = '<span class="rbn-pager__n">' + esc( shown + ' / ' + total + ' · ' + sp( I.pageOf, pg, pages ) ) + '</span>';
		h += '<button type="button" class="rbn-btn rbn-btn--sm" data-page="' + ( pg - 1 ) + '"' + ( pg > 1 ? '' : ' disabled' ) + '>' + esc( I.pagePrev ) + '</button>';
		return h + '<button type="button" class="rbn-btn rbn-btn--sm" data-page="' + ( pg + 1 ) + '"' + ( pg < pages ? '' : ' disabled' ) + '>' + esc( I.pageNext ) + '</button>';
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
			return '<tr><td class="wps-chk-cell" data-label="' + esc( I.dlSelect ) + '"><label class="rbn-check"><input type="checkbox" data-row value="' + x.id + '"><span class="rbn-sr">' + esc( x.source_path ) + '</span></label></td>'
				+ '<th scope="row" data-label="' + esc( I.dlFrom ) + '"><code>' + esc( x.source_path ) + '</code></th><td data-label="' + esc( I.dlTo ) + '"><code>' + esc( x.target_url || '–' ) + '</code></td>'
				+ '<td data-label="' + esc( I.dlType ) + '"><span class="rbn-badge rbn-badge--' + cls + '">' + icon( 'arrows' ) + code + '</span></td><td data-label="' + esc( I.hits ) + '" class="rbn-num">' + esc( x.hits ) + '</td><td data-label="' + esc( I.dlLastHit ) + '">' + esc( dateText( x.last_hit_at ) ) + '</td>'
				+ '<td data-label="' + esc( I.dlStatus ) + '">' + ( x.is_active ? '<span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + esc( I.on ) + '</span>' : '<span class="rbn-badge">' + icon( 'pause' ) + esc( I.off ) + '</span>' ) + '</td>'
				+ '<td data-label="' + esc( I.dlActions ) + '" class="rbn-acts"><button type="button" class="rbn-btn rbn-btn--sm" data-edit="' + x.id + '">' + icon( 'pen' ) + esc( I.edit ) + '</button><button type="button" class="rbn-btn rbn-btn--danger rbn-btn--sm" data-del="' + x.id + '">' + icon( 'trash' ) + esc( I.delete ) + '</button></td></tr>';
		}

		on( root, 'click', '[data-retry]', load );
		on( pager, 'click', 'button[data-page]', function ( e, a ) { e.preventDefault(); st.page = +a.getAttribute( 'data-page' ); load(); } );
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

		// Delete (single and bulk) through RbnUi.confirm modal (WPRJ-667)
		function askDelete( ids ) {
			if ( ! ids || ! ids.length ) { return; }
			if ( ! window.RbnUi || ! window.RbnUi.confirm ) { return; }
			var single = ids.length === 1;
			var title = single ? ( I.delete + ' ' + ( rows[ ids[ 0 ] ] || {} ).source_path + '?' ) : fmt( I.deleteN, ids.length );
			var btnLabel = single ? I.delete : fmt( I.deleteNBtn, ids.length );
			window.RbnUi.confirm( {
				title: title,
				message: I.deleteBody,
				tone: 'danger',
				confirmLabel: btnLabel,
				confirmText: btnLabel,
				cancelLabel: I.keep,
				cancelText: I.keep
			} ).then( function ( ok ) {
				if ( ! ok ) { return; }
				Promise.all( ids.map( function ( id ) { return api( 'DELETE', 'redirects/' + id ); } ) ).then( function () {
					if ( window.RbnUi && window.RbnUi.toast ) {
						window.RbnUi.toast( I.removed, { type: 'success' } );
					} else {
						say( 'ok', '<b>' + esc( I.removed ) + '</b>' );
					}
					load();
				} );
			} );
		}
		on( tbody, 'click', '[data-del]', function ( e, b ) { askDelete( [ +b.getAttribute( 'data-del' ) ] ); } );
		on( bulk, 'click', '[data-bulk-delete]', function () { askDelete( sel.ids() ); } );
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
			if ( f.size > 1048576 ) { report.innerHTML = note( 'err', esc( I.csvTooBig ), 'alert' ); return; }
			f.text().then( function ( text ) {
				var errors = [], seen = {};
				text.replace( /^﻿/, '' ).split( /\r?\n/ ).forEach( function ( line, i ) {
					if ( ! line.trim() ) { return; }
					var c = line.split( ',' ).map( function ( s ) { return s.trim().replace( /^"|"$/g, '' ); } );
					if ( i === 0 && c[ 0 ].toLowerCase() === 'from' ) { return; }
					var code = c[ 2 ] ? +c[ 2 ] : 301;
					if ( c[ 0 ].charAt( 0 ) !== '/' ) { errors.push( [ i + 1, I.csvNoSlash ] ); } else if ( [ 301, 302, 307, 410, 451 ].indexOf( code ) < 0 ) { errors.push( [ i + 1, String( I.csvBadType ).replace( '%s', c[ 2 ] ) ] ); } else if ( ! c[ 1 ] && code !== 410 ) { errors.push( [ i + 1, I.csvToEmpty ] ); } else if ( seen[ c[ 0 ] ] ) { errors.push( [ i + 1, fmt( I.csvDuplicate, seen[ c[ 0 ] ] ) ] ); } else { seen[ c[ 0 ] ] = i + 1; valid.push( line ); }
				} );
				var h = note( valid.length ? 'ok' : 'warn', '<b>' + esc( f.name ) + '</b> ' + esc( I.csvChecked ) + ': <b>' + valid.length + '</b> OK, <b>' + errors.length + '</b> ' + esc( 'invalid' ), 'status' );
				if ( errors.length ) {
					h += '<div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' + esc( I.csvAttention ) + '</caption><thead><tr><th scope="col">' + esc( I.line ) + '</th><th scope="col">' + esc( I.problem ) + '</th></tr></thead><tbody>' + errors.map( function ( x ) { return '<tr><th scope="row" data-label="' + esc( I.line ) + '">' + x[ 0 ] + '</th><td data-label="' + esc( I.problem ) + '"><span class="rbn-badge rbn-badge--err">' + icon( 'x' ) + esc( I.invalid ) + '</span> ' + esc( x[ 1 ] ) + '</td></tr>'; } ).join( '' ) + '</tbody></table></div>';
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
			return '<tr data-row-id="' + x.id + '"><td class="wps-chk-cell" data-label="' + esc( I.dlSelect ) + '"><label class="rbn-check"><input type="checkbox" data-row value="' + x.id + '"><span class="rbn-sr">' + esc( x.url_path ) + '</span></label></td>'
				+ '<th scope="row" data-label="' + esc( I.dlAddress ) + '"><code>' + esc( x.url_path ) + '</code></th><td data-label="' + esc( I.hits ) + '" class="rbn-num">' + esc( x.hit_count ) + '</td><td data-label="' + esc( I.dlReferrer ) + '">' + esc( x.referer || '–' ) + '</td><td data-label="' + esc( I.dlLastSeen ) + '">' + esc( dateText( x.last_seen_at ) ) + '</td>'
				+ '<td data-label="' + esc( I.dlActions ) + '" class="rbn-acts"><button type="button" class="rbn-btn rbn-btn--sm" aria-expanded="false" aria-controls="sg-' + x.id + '" data-toggle="' + x.id + '">' + icon( 'arrows' ) + esc( I.redirectTo ) + '</button><button type="button" class="rbn-btn rbn-btn--sm" data-ignore="' + x.id + '">' + icon( 'trash' ) + esc( I.delete ) + '</button></td></tr>';
		}

		on( root, 'click', '[data-retry]', load );
		on( pager, 'click', 'button[data-page]', function ( e, a ) { e.preventDefault(); st.page = +a.getAttribute( 'data-page' ); load(); } );
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
			inl.innerHTML = '<td colspan="6"><div class="wps-inline__b"><h3>' + esc( I.redirected ) + ' <code>' + esc( x.url_path ) + '</code> →</h3><p class="rbn-help">' + esc( 'Closest pages on your site. One click creates a 301 redirect.' ) + '</p><ul class="wps-suggest" data-sugg><li>' + esc( I.loading ) + '</li></ul>'
				+ '<div class="wps-inline__row"><label class="rbn-sr" for="sg-own-' + id + '">' + esc( I.otherAddress ) + '</label><input class="rbn-input rbn-input--mono" id="sg-own-' + id + '" placeholder="/" autocomplete="off"><button type="button" class="rbn-btn rbn-btn--pri" data-create>' + icon( 'plus' ) + esc( I.createRedirect ) + '</button><button type="button" class="rbn-btn" data-close>' + esc( I.close ) + '</button></div><p class="rbn-field__err" data-inline-err hidden></p></div></td>';
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
		on( tbody, 'click', '[data-ignore]', function ( e, b ) {
			api( 'DELETE', '404-logs/' + b.getAttribute( 'data-ignore' ) ).then( function () {
				if ( window.RbnUi && window.RbnUi.toast ) {
					window.RbnUi.toast( I.removed, { type: 'success' } );
				}
				load();
			} );
		} );
		on( bulk, 'click', '[data-bulk-delete]', function () {
			var ids = sel.ids();
			if ( ! ids.length ) { return; }
			if ( ! window.RbnUi || ! window.RbnUi.confirm ) { return; }
			window.RbnUi.confirm( {
				title: fmt( I.deleteN, ids.length ),
				message: I.deleteBody,
				tone: 'danger',
				confirmLabel: fmt( I.deleteNBtn, ids.length ),
				confirmText: fmt( I.deleteNBtn, ids.length ),
				cancelLabel: I.keep,
				cancelText: I.keep
			} ).then( function ( ok ) {
				if ( ! ok ) { return; }
				Promise.all( ids.map( function ( id ) { return api( 'DELETE', '404-logs/' + id ); } ) ).then( function () {
					if ( window.RbnUi && window.RbnUi.toast ) {
						window.RbnUi.toast( I.removed, { type: 'success' } );
					}
					load();
				} );
			} );
		} );

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

		/* Draws a dry-run report into the Dry run table; returns the number of conflicts. */
		function fillDry( rep ) {
			var found = 0, will = 0, conf = 0;
			$( 'tbody', $s( 'import-table' ) ).innerHTML = ( rep.rows || [] ).map( function ( x ) {
				found += +x.found || 0; will += +x.will_import || 0; conf += +x.conflicts || 0;
				return '<tr><th scope="row" data-label="' + esc( I.importData ) + '">' + esc( x.label ) + '</th><td data-label="' + esc( I.found ) + '" class="rbn-num">' + esc( x.found ) + '</td><td data-label="' + esc( I.willImport ) + '" class="rbn-num">' + esc( x.will_import ) + '</td><td data-label="' + esc( I.importNote ) + '">' + esc( x.note || '' ) + '</td></tr>';
			} ).join( '' );
			$s( 'import-summary' ).textContent = found + ' ' + I.found.toLowerCase() + ' · ' + will + ' ' + I.willImport.toLowerCase() + ' · ' + conf + ' ' + I.conflicts.toLowerCase();
			return conf;
		}

		/* The report of an applied import: what was written and what already matched. */
		function appliedTable( rep ) {
			var rows = ( rep.rows || [] ).map( function ( x ) {
				return '<tr><th scope="row" data-label="' + esc( I.importData ) + '">' + esc( x.label ) + '</th><td data-label="' + esc( I.found ) + '" class="rbn-num">' + esc( x.found ) + '</td><td data-label="' + esc( I.importWritten ) + '" class="rbn-num">' + esc( x.will_import ) + '</td><td data-label="' + esc( I.importSame ) + '" class="rbn-num">' + esc( x.same ) + '</td><td data-label="' + esc( I.importNote ) + '">' + esc( x.note || '' ) + '</td></tr>';
			} ).join( '' );
			return '<section class="rbn-card"><div class="rbn-card__h"><h2>' + esc( I.importRowsCap ) + '</h2></div><div class="rbn-card__b rbn-card__b--flush"><div class="rbn-tw"><table class="rbn-table rbn-table--stack"><caption class="rbn-sr">' + esc( I.importRowsCap ) + '</caption><thead><tr><th scope="col">' + esc( I.importData ) + '</th><th scope="col" class="rbn-num">' + esc( I.found ) + '</th><th scope="col" class="rbn-num">' + esc( I.importWritten ) + '</th><th scope="col" class="rbn-num">' + esc( I.importSame ) + '</th><th scope="col">' + esc( I.importNote ) + '</th></tr></thead><tbody>' + rows + '</tbody></table></div></div></section>';
		}

		/* The stored state (`GET import/state`) decides what every sub-tab shows when the screen opens. */
		function restore() {
			return api( 'GET', 'import/state' ).then( function ( r ) {
				var s = r.ok ? r.data : null, rep = s && s.report;
				if ( ! rep || ! s.state || s.state === 'none' ) { return; }
				if ( s.state === 'dry-run' ) {
					report = rep;
					var conf = fillDry( rep );
					show( $s( 'import-empty' ), false ); show( $s( 'import-report' ), true ); show( $s( 'import-decide' ), conf > 0 ); show( $s( 'import-wait' ), false );
					$s( 'import-bar' ).classList.add( 'is-dirty' );
					return;
				}
				var ver = '';
				if ( s.verified_at ) { ver = sp( s.verified_ok ? I.importVerifiedOn : I.importVerifiedNo, when( s.verified_at ) ); }
				var head = '<b>' + esc( sp( I.importAppliedOn, when( s.applied_at || s.time ) ) ) + '</b> ' + esc( ver );
				var again = '<button type="button" class="rbn-btn rbn-btn--sm" data-import-check>' + esc( I.importCheckAgain ) + '</button>';
				$s( 'import-state' ).innerHTML = note( 'ok', head + ' <a class="rbn-btn rbn-btn--sm" href="' + esc( dashHref( 'yoast', 'report' ) ) + '" data-goto="yoast:report">' + esc( I.importViewReport ) + '</a> ' + again, 'status' );
				show( $s( 'import-start' ), false );
				$s( 'import-done' ).innerHTML = note( 'ok', head + ' <button type="button" class="rbn-btn rbn-btn--sm" data-import-verify>' + icon( 'scan' ) + esc( I.verify ) + '</button>', 'status' ) + appliedTable( rep );
				var stale = $( 'p', $s( 'import-empty' ) );
				if ( stale ) { stale.innerHTML = esc( I.importAppliedDry ) + ' ' + again; }
				$s( 'import-wait' ).innerHTML = note( 'info', '<b>' + esc( I.importAppliedTitle ) + '</b> ' + esc( I.importAppliedRun ) + ' ' + again, 'status' );
				show( $s( 'import-wait' ), true );
			} );
		}

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
				var conf = fillDry( report );
				show( $s( 'import-report' ), true ); show( $s( 'import-decide' ), conf > 0 ); show( $s( 'import-wait' ), false );
				$s( 'import-bar' ).classList.add( 'is-dirty' );
				var focus = $s( 'import-report' ).querySelector( 'h2' ); if ( focus ) { focus.setAttribute( 'tabindex', '-1' ); focus.focus(); }
			} );
		}

		show( $s( 'import-decide' ), false );
		on( doc, 'click', '[data-import-check]', function () { check(); } );
		on( doc, 'click', '[data-import-confirm]', function ( e, b ) {
			if ( ! report ) { check(); return; }
			if ( ! window.RbnUi || ! window.RbnUi.confirm ) { return; }
			var sumEl = $s( 'import-summary' );
			var details = sumEl ? sumEl.textContent.trim() : '';
			window.RbnUi.confirm( {
				title: I.importTitle || 'Import Yoast data?',
				message: I.importMsg || 'WPSeoKit will write titles, descriptions, primary categories and settings. Your Yoast data stays where it is.',
				tone: 'default',
				confirmLabel: I.import || 'Import',
				confirmText: I.import || 'Import',
				cancelLabel: I.cancel || 'Cancel',
				cancelText: I.cancel || 'Cancel',
				details: details
			} ).then( function ( ok ) {
				if ( ! ok ) { return; }
				b.disabled = true;
				var ov = $( 'input[name="imp-overwrite"]:checked' );
				api( 'POST', 'import/yoast', { mode: 'apply', overwrite: !! ( ov && ov.value === '1' ) } ).then( function ( r ) {
					b.disabled = false;
					if ( ! r.ok ) {
						say( 'err', '<b>' + esc( I.saveFailed ) + '</b> ' + esc( errMsg( r ) ) );
						return;
					}
					$s( 'import-bar' ).classList.remove( 'is-dirty' );
					if ( window.RbnUi && window.RbnUi.toast ) {
						window.RbnUi.toast( I.importDone, { type: 'success' } );
					}
					goTab( 'report' );
					$s( 'import-done' ).innerHTML = note( 'ok', '<b>' + esc( I.importDone ) + '</b> <button type="button" class="rbn-btn rbn-btn--sm" data-import-verify>' + icon( 'scan' ) + esc( I.verify ) + '</button>', 'status' );
					restore();
				} );
			} );
		} );
		on( doc, 'click', '[data-import-verify]', function ( e, b ) {
			b.disabled = true;
			api( 'POST', 'import/yoast', { mode: 'verify' } ).then( function ( r ) { b.disabled = false; say( r.ok ? 'ok' : 'err', r.ok ? '<b>' + esc( I.verified ) + '</b>' : esc( errMsg( r ) ) ); restore(); } );
		} );
		if ( $s( 'import-done' ) ) { restore(); }
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

		initTabs( box );

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
				return '<label class="rbn-check"><input type="radio" name="wps-pc" value="' + t.id + '"' + ( t.id === cur ? ' checked' : '' ) + '><span>' + esc( t.name ) + '</span>' + ( t.id === original ? '<span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + 'Current</span>' : '' ) + '</label>';
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

	/* ---------- Redirects: Preferred address (WPRJ-580) ---------- */

	function address( root ) {
		var box = $( '[data-addr]', root );
		if ( ! box ) { return; }
		var A = I.addr || {};
		var form = $( '[data-addr-form]', box ), hostIn = $( '#pa-host', box ), hostErr = $( '[data-addr-host-err]', box );
		var out = $( '[data-audit-out]', box ), live = $( '[data-audit-live]', box ), runBtn = $( '[data-audit-run]', box );
		var status = null, audit = null, snip = null, snipTimer = 0, snipSeq = 0;
		var HOST = /^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/;

		function sub( s, v ) { return String( s ).replace( /%s|%d/, v ); }
		function scheme() { var c = $( 'input[name="pa-sch"]:checked', box ); return c ? c.value : 'auto'; }
		function hostVal() { return hostIn.value.trim().toLowerCase(); }
		function hostOk() { var h = hostVal(); return h === '' || h === 'localhost' || HOST.test( h ); }
		function override() { return { redirects: { preferred_scheme: scheme(), preferred_host: hostOk() ? hostVal() : '' } }; }
		function autoScheme() { return status && status.preferred && status.preferred.auto ? status.preferred.auto.scheme : 'https'; }
		function autoHost() { return status && status.preferred && status.preferred.auto ? status.preferred.auto.host : ''; }

		function renderInuse() {
			var auto = $( '[data-addr-auto]', box ), inuse = $( '[data-addr-inuse]', box );
			if ( auto ) { auto.textContent = status ? sub( A.autoNow, autoScheme() ) : ( A.auto ); }
			if ( ! status || ! hostOk() ) { inuse.hidden = true; return; }
			var s = scheme() === 'auto' ? autoScheme() : scheme(), h = hostVal() || autoHost();
			$( '[data-addr-origin]', box ).textContent = s + '://' + h;
			inuse.hidden = false;
		}

		function checkHost() {
			var ok = hostOk();
			hostIn.setAttribute( 'aria-invalid', ok ? 'false' : 'true' );
			hostIn.setAttribute( 'aria-describedby', ok ? 'pa-host-h' : 'pa-host-h pa-host-e' );
			hostErr.hidden = ok;
			return ok;
		}

		/* -- status -- */

		function loadStatus() {
			var warn = $( '[data-addr-warn]', box );
			return api( 'GET', 'preferred-address/status' ).then( function ( r ) {
				if ( ! r.ok || ! r.data ) { warn.innerHTML = note( 'err', esc( A.statusFailed ), 'alert' ); return; }
				status = r.data;
				warn.innerHTML = ( status.warnings || [] ).map( function ( w ) { return note( 'warn', esc( w ) ); } ).join( '' );
				renderInuse();
				renderFallback();
			} );
		}

		/* -- save preference -- */

		var saved = $( '[data-addr-saved]', box );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! checkHost() ) { hostIn.focus(); return; }
			var btn = $( 'button[type="submit"]', form );
			btn.disabled = true;
			form.setAttribute( 'aria-busy', 'true' );
			saved.textContent = '';
			api( 'POST', 'settings', override() ).then( function ( r ) {
				btn.disabled = false;
				form.removeAttribute( 'aria-busy' );
				if ( ! r.ok ) { saved.textContent = A.prefFailed + ' ' + errMsg( r ); return; }
				saved.textContent = A.prefSaved;
				return loadStatus().then( loadSnippets );
			} ).catch( function () { btn.disabled = false; form.removeAttribute( 'aria-busy' ); saved.textContent = A.prefFailed; } );
		} );
		form.addEventListener( 'input', function () { saved.textContent = ''; checkHost(); renderInuse(); queueSnippets(); } );
		form.addEventListener( 'change', function () { saved.textContent = ''; renderInuse(); queueSnippets(); } );

		/* -- audit -- */

		function badge( id ) {
			var v = ( A.verdicts || {} )[ id ] || { label: id, tone: 'mute', icon: 'info' };
			return '<span class="rbn-badge rbn-badge--' + esc( v.tone ) + '">' + icon( v.icon ) + esc( v.label ) + '</span>';
		}

		function vsum( c ) {
			var ok = c.ok || 0, un = c.unreachable || 0, fix = 0, parts = [], html = '';
			Object.keys( c ).forEach( function ( k ) { if ( k !== 'ok' && k !== 'unreachable' ) { fix += c[ k ]; } } );
			if ( ok ) { parts.push( sub( A.nOk, ok ) ); html += '<span class="rbn-badge rbn-badge--ok">' + icon( 'check' ) + esc( sub( A.nOk, ok ) ) + '</span>'; }
			if ( fix ) { parts.push( sub( A.nFix, fix ) ); html += '<span class="rbn-badge rbn-badge--warn">' + icon( 'alert' ) + esc( sub( A.nFix, fix ) ) + '</span>'; }
			if ( un ) { parts.push( sub( A.nUnreach, un ) ); html += '<span class="rbn-badge rbn-badge--mute">' + icon( 'octo' ) + esc( sub( A.nUnreach, un ) ) + '</span>'; }
			return { html: '<p class="wps-vsum">' + html + '</p>', text: parts.join( ', ' ), fix: fix };
		}

		function hopsList( hops ) {
			if ( ! hops || hops.length < 2 ) { return ''; }
			return '<ol class="wps-hops">' + hops.map( function ( h, i ) {
				var last = i === hops.length - 1;
				return '<li><span>' + esc( sub( A.stepN, i + 1 ) ) + '</span> <code>' + esc( h.code || '—' ) + '</code>' + ( last ? '' : ' <span>' + esc( A.stepTo ) + '</span> <code>' + esc( h.location ) + '</code>' + ( h.by ? ' <span>' + esc( sub( A.viaOther, h.by ) ) + '</span>' : '' ) ) + '</li>';
			} ).join( '' ) + '</ol>';
		}

		function variantRow( v ) {
			var first = v.hops && v.hops[ 0 ] ? v.hops[ 0 ] : { code: 0 };
			var goes = v.verdict === 'unreachable' ? '—' : ( v.redirects ? '<code>' + esc( v.final_url ) + '</code>' : esc( A.staysHere ) );
			return '<tr><th scope="row" data-label="' + esc( A.colAddress ) + '"><code>' + esc( v.url ) + '</code>' + ( v.is_preferred ? ' <span class="rbn-badge rbn-badge--info">' + esc( A.preferredTag ) + '</span>' : '' ) + '</th>'
				+ '<td data-label="' + esc( A.colStatus ) + '">' + ( first.code ? esc( first.code ) : '—' ) + '</td>'
				+ '<td data-label="' + esc( A.colGoes ) + '">' + goes + ( v.redirects > 1 || v.verdict === 'loop' ? hopsList( v.hops ) : '' ) + '</td>'
				+ '<td data-label="' + esc( A.colRedirects ) + '">' + esc( v.redirects ) + '</td>'
				+ '<td data-label="' + esc( A.colVerdict ) + '">' + badge( v.verdict ) + ( v.verdict === 'ok' ? '' : '<span class="wps-cell-sub">' + esc( v.message ) + '</span>' ) + '</td></tr>';
		}

		function otherRows( c ) {
			var rows = [];
			function row( label, tone, ic, text, detail ) {
				rows.push( '<tr><th scope="row" data-label="' + esc( A.colCheck ) + '">' + esc( label ) + '</th><td data-label="' + esc( A.colResult ) + '"><span class="rbn-badge rbn-badge--' + tone + '">' + icon( ic ) + esc( text ) + '</span>' + ( detail ? '<span class="wps-cell-sub">' + detail + '</span>' : '' ) + '</td></tr>' );
			}
			function match( label, yes, val ) { row( label, yes ? 'ok' : 'warn', yes ? 'check' : 'alert', yes ? A.matches : A.differs, val ? '<code>' + esc( val ) + '</code>' : '' ); }
			match( A.chkHome, c.home_matches_preferred, c.home );
			match( A.chkSiteurl, c.siteurl_matches_preferred, c.siteurl );
			var can = c.canonical || {};
			if ( can.status === 'missing' ) { row( A.chkCanonical, 'warn', 'alert', A.missing, '' ); } else if ( can.href ) { match( A.chkCanonical, can.matches, can.href ); } else { row( A.chkCanonical, 'mute', 'octo', A.notAvailable, '' ); }
			[ [ 'sitemap', A.chkSitemap ], [ 'feed', A.chkFeed ] ].forEach( function ( p ) {
				var x = c[ p[ 0 ] ] || {}, n = x.mismatches || 0;
				if ( x.status !== 'checked' ) { row( p[ 1 ], 'mute', 'octo', A.notAvailable, '' ); return; }
				row( p[ 1 ], n ? 'warn' : 'ok', n ? 'alert' : 'check', n ? A.differs : A.matches, n ? esc( sub( A.linksDiffer, n ) ) : esc( A.linksFine ) );
			} );
			var h = c.hsts || {};
			row( A.chkHsts, 'info', 'info', A.information, h.present ? esc( A.hstsSet ) + ' <code>' + esc( h.header ) + '</code>' : esc( A.hstsNone ) );
			return rows.join( '' );
		}

		function renderAudit( d ) {
			var s = vsum( d.verdict_counts || {} ), notes = '';
			if ( s.fix === 0 ) { notes += note( 'ok', '<b>' + esc( A.allGood ) + '</b> ' + esc( A.nothingToFix ) ); }
			else {
				var items = ( d.variants || [] ).filter( function ( v ) { return v.verdict !== 'ok' && v.verdict !== 'unreachable'; } );
				notes += '<div class="rbn-note rbn-note--warn">' + icon( 'alert' ) + '<div><p><b>' + esc( A.attention ) + '</b></p><ul class="wps-ul">' + items.map( function ( v ) { return '<li><code>' + esc( v.url ) + '</code> ' + esc( v.message ) + '</li>'; } ).join( '' ) + '</ul></div></div>';
			}
			if ( ( d.warnings || [] ).length ) {
				notes += '<div class="rbn-note">' + icon( 'info' ) + '<div><p><b>' + esc( A.knowThis ) + '</b></p><ul class="wps-ul">' + d.warnings.map( function ( w ) { return '<li>' + esc( w ) + '</li>'; } ).join( '' ) + '</ul></div></div>';
			}
			out.innerHTML = '<div class="wps-stack">' + s.html + '<p class="rbn-help">' + esc( sub( A.checkedAt, new Date().toLocaleTimeString() ) ) + '</p>' + notes
				+ '<h3 class="wps-h3" id="pa-v-t">' + esc( A.fourVersions ) + '</h3><div class="rbn-tw"><table class="rbn-table rbn-table--stack" aria-labelledby="pa-v-t"><thead><tr><th scope="col">' + esc( A.colAddress ) + '</th><th scope="col">' + esc( A.colStatus ) + '</th><th scope="col">' + esc( A.colGoes ) + '</th><th scope="col">' + esc( A.colRedirects ) + '</th><th scope="col">' + esc( A.colVerdict ) + '</th></tr></thead><tbody>' + ( d.variants || [] ).map( variantRow ).join( '' ) + '</tbody></table></div>'
				+ '<h3 class="wps-h3" id="pa-o-t">' + esc( A.otherChecks ) + '</h3><div class="rbn-tw"><table class="rbn-table rbn-table--stack" aria-labelledby="pa-o-t"><thead><tr><th scope="col">' + esc( A.colCheck ) + '</th><th scope="col">' + esc( A.colResult ) + '</th></tr></thead><tbody>' + otherRows( d.consistency || {} ) + '</tbody></table></div></div>';
			live.textContent = sub( A.auditDone, s.text );
		}

		runBtn.addEventListener( 'click', function () {
			if ( ! checkHost() ) { hostIn.focus(); return; }
			runBtn.disabled = true;
			runBtn.setAttribute( 'aria-busy', 'true' );
			out.setAttribute( 'aria-busy', 'true' );
			out.innerHTML = '<p class="rbn-help">' + esc( A.checking ) + '</p>';
			live.textContent = A.checking;
			api( 'POST', 'preferred-address/audit', override() ).then( function ( r ) {
				runBtn.disabled = false;
				runBtn.removeAttribute( 'aria-busy' );
				out.removeAttribute( 'aria-busy' );
				if ( ! r.ok || ! r.data || ! r.data.variants ) {
					out.innerHTML = '<div class="rbn-note rbn-note--err" role="alert">' + icon( 'x' ) + '<div><p><b>' + esc( A.runFailedT ) + '</b> ' + esc( A.runFailedB ) + '</p></div></div>';
					live.textContent = A.runFailedT;
					return;
				}
				audit = r.data;
				$( 'span', runBtn ).textContent = A.runAgain;
				renderAudit( audit );
				renderFallback();
				var t = $( '#pa-v-t', out );
				if ( t ) { t.setAttribute( 'tabindex', '-1' ); t.focus(); }
			} );
		} );

		/* -- server rule -- */

		var ruleState = $( '[data-rule-state]', box ), ruleBody = $( '[data-rule-body]', box ), copyBtn = $( '[data-rule-copy]', box ), copied = $( '[data-rule-copied]', box );
		function server() { var c = $( 'input[name="pa-srv"]:checked', box ); return c ? c.value : 'apache'; }

		function renderRule() {
			var sv = server();
			$$( '[data-srv]', box ).forEach( function ( el ) { el.hidden = el.getAttribute( 'data-srv' ) !== sv; } );
			$( 'span', copyBtn ).textContent = sv === 'apache' ? A.copyApache : A.copyNginx;
			copied.textContent = '';
			if ( ! snip ) { return; }
			$( '[data-rule-table]', box ).innerHTML = ( snip.table || [] ).map( function ( r ) {
				var x = r[ sv ] || {};
				return '<tr><th scope="row" data-label="' + esc( A.colAddress ) + '"><code>' + esc( r.from ) + '</code></th><td data-label="' + esc( A.colGoes ) + '">' + ( x.hops ? '<code>' + esc( x.to ) + '</code>' : esc( A.staysHere ) ) + '</td><td data-label="' + esc( A.colRedirects ) + '">' + esc( x.hops || 0 ) + '</td></tr>';
			} ).join( '' );
		}

		function loadSnippets() {
			if ( ! hostOk() ) {
				snip = null;
				ruleBody.hidden = true;
				ruleState.innerHTML = '<div class="rbn-empty"><h3 class="wps-h3">' + esc( A.noRuleT ) + '</h3><p>' + esc( A.noRuleB ) + '</p></div>';
				return Promise.resolve();
			}
			var seq = ++snipSeq;
			ruleState.innerHTML = '<p class="rbn-help">' + esc( A.ruleLoading ) + '</p>';
			return api( 'POST', 'preferred-address/snippets', override() ).then( function ( r ) {
				if ( seq !== snipSeq ) { return; }
				if ( ! r.ok || ! r.data || ! r.data.apache ) { snip = null; ruleBody.hidden = true; ruleState.innerHTML = note( 'err', '<b>' + esc( A.ruleFailed ) + '</b>', 'alert' ); return; }
				snip = r.data;
				ruleState.innerHTML = '';
				ruleBody.hidden = false;
				$( '[data-code="apache"]', box ).textContent = snip.apache;
				$( '[data-code="nginx"]', box ).textContent = snip.nginx;
				$( '[data-rule-panel]', box ).textContent = snip.panel;
				$( '[data-rule-warn]', box ).innerHTML = ( snip.warnings || [] ).map( function ( w ) { return '<li>' + esc( w ) + '</li>'; } ).join( '' );
				renderRule();
			} );
		}
		function queueSnippets() { clearTimeout( snipTimer ); snipTimer = setTimeout( loadSnippets, 400 ); }

		on( box, 'change', 'input[name="pa-srv"]', renderRule );
		copyBtn.addEventListener( 'click', function () {
			var sv = server(), code = $( '[data-code="' + sv + '"]', box );
			var ok = function () { copied.textContent = sv === 'apache' ? A.copiedApache : A.copiedNginx; };
			var fail = function () {
				var rg = doc.createRange(), sel = window.getSelection();
				rg.selectNodeContents( code );
				sel.removeAllRanges();
				sel.addRange( rg );
				try { if ( doc.execCommand( 'copy' ) ) { ok(); return; } } catch ( e ) { /* falls through to the hint */ }
				copied.textContent = A.copyFailed;
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( code.textContent ).then( ok, fail ); } else { fail(); }
		} );

		/* -- PHP fallback -- */

		var fbState = $( '[data-fb-state]', box ), fbSaved = $( '[data-fb-saved]', box ), php = $( '#pa-php', box ), proxy = $( '#pa-proxy', box );

		function chainFound() {
			return !! audit && ( audit.variants || [] ).some( function ( v ) { return v.verdict === 'chain' || v.verdict === 'loop' || v.verdict === 'https_downgrade'; } );
		}

		function renderFallback() {
			var html, lock = false;
			if ( status && status.multisite ) { html = note( 'warn', esc( A.fbMulti ) ); lock = true; }
			else if ( chainFound() ) {
				if ( php.checked ) { html = '<div class="rbn-note rbn-note--warn" role="alert">' + icon( 'alert' ) + '<div><p><b>' + esc( A.fbOnChainT ) + '</b> ' + esc( A.fbOnChainB ) + '</p><p class="rbn-note__act"><button type="button" class="rbn-btn" data-fb-off>' + esc( A.fbOff ) + '</button></p></div></div>'; }
				else { html = note( 'warn', '<b>' + esc( A.fbLockedT ) + '</b> ' + esc( A.fbLockedB ) ); lock = true; }
			} else if ( audit && ! ( audit.variants || [] ).some( function ( v ) { return v.verdict !== 'ok' && v.verdict !== 'unreachable'; } ) ) { html = note( 'info', '<b>' + esc( A.fbNotNeededT ) + '</b> ' + esc( A.fbNotNeededB ) ); }
			else if ( audit ) { html = note( 'info', esc( A.fbMaybe ) ); }
			else { html = note( 'info', esc( A.fbFirst ) ); }
			fbState.innerHTML = html;
			php.disabled = lock && ! php.checked;
			proxy.disabled = ! php.checked;
		}

		function saveFb( input, prev ) {
			var key = input.getAttribute( 'data-fb' ), body = { redirects: {} };
			body.redirects[ key ] = input.checked;
			input.disabled = true;
			fbSaved.textContent = '';
			return api( 'POST', 'settings', body ).then( function ( r ) {
				input.disabled = false;
				if ( ! r.ok ) { input.checked = prev; fbSaved.textContent = I.saveFailed + ' ' + errMsg( r ); } else { fbSaved.textContent = A.fbSaved; }
				renderFallback();
			}, function () { input.disabled = false; input.checked = prev; renderFallback(); } );
		}
		[ php, proxy ].forEach( function ( input ) {
			input.addEventListener( 'change', function () { saveFb( input, ! input.checked ); } );
		} );
		on( box, 'click', '[data-fb-off]', function () { php.checked = false; saveFb( php, true ).then( function () { php.focus(); } ); } );

		/* -- start -- */

		checkHost();
		renderFallback();
		loadStatus().then( loadSnippets );
		if ( /[?&]tab=pref(&|$)/.test( window.location.search ) ) { runBtn.focus(); }
	}

	/* Preferred address apply / undo confirmation modals (WPRJ-667) */
	function initPreferredAddressConfirm() {
		doc.addEventListener( 'submit', function ( e ) {
			var form = e.target;
			if ( ! form || ! form.matches || ! form.matches( 'form[data-pa-confirm]' ) ) { return; }
			if ( ! window.RbnUi || ! window.RbnUi.confirm ) { return; }
			var action = form.getAttribute( 'data-pa-confirm' );
			e.preventDefault();
			if ( action === 'apply' ) {
				window.RbnUi.confirm( {
					title: I.applyBtn || 'Apply this address',
					message: I.applyConfirmMsg || 'You will have to log in again.',
					tone: 'default',
					confirmLabel: I.applyBtn || 'Apply this address',
					confirmText: I.applyBtn || 'Apply this address',
					cancelLabel: I.cancel || 'Cancel',
					cancelText: I.cancel || 'Cancel'
				} ).then( function ( ok ) {
					if ( ok ) { form.submit(); }
				} );
			} else if ( action === 'undo' ) {
				window.RbnUi.confirm( {
					title: I.undoBtn || 'Go back to the previous address',
					message: I.undoConfirmMsg || 'The previous address is in effect again.',
					tone: 'danger',
					confirmLabel: I.undoBtn || 'Go back to the previous address',
					confirmText: I.undoBtn || 'Go back to the previous address',
					cancelLabel: I.cancel || 'Cancel',
					cancelText: I.cancel || 'Cancel'
				} ).then( function ( ok ) {
					if ( ok ) { form.submit(); }
				} );
			}
		} );
	}

	/* ---------- Start ---------- */

	function start() {
		initShell();
		initPreferredAddressConfirm();
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
		if ( name === 'dashboard' ) { dashApi = dashTabs( screen ); dashboard( screen ); score( screen ); reports( screen ); logs( screen ); importer( screen ); }
		if ( name === 'schema' ) { schema( screen ); }
		if ( name === 'sitemap' ) { sitemap( screen ); }
		if ( name === 'feed' ) { feed( screen ); }
		if ( name === 'llms' ) { llms( screen ); }
		if ( name === 'redirects' ) { redirects( screen ); address( screen ); }
		if ( name === 'log404' ) { log404( screen ); }
		if ( name === 'import' ) { importer( screen ); }
	}

	if ( doc.readyState === 'loading' ) { doc.addEventListener( 'DOMContentLoaded', start ); } else { start(); }
}() );
