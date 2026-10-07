(function () {
	'use strict';

	var OVERLAY_ID = 'snap-session-login';
	var recovering = false;
	var awaitingLogin = false;
	var loginWatchTimer = null;

	function loginUrl() {
		var base = (window.snapshoter && window.snapshoter.loginUrl) || '/wp-login.php';
		var join = base.indexOf('?') >= 0 ? '&' : '?';
		var redirect = (window.snapshoter && window.snapshoter.pluginAdminUrl) || '';
		var url = base + join + 'interim-login=1';
		if (redirect) {
			url += '&redirect_to=' + encodeURIComponent(redirect);
		}
		return url;
	}

	function overlayOpen() {
		return !!document.getElementById(OVERLAY_ID);
	}

	function removeOverlay() {
		var el = document.getElementById(OVERLAY_ID);
		if (el && el.parentNode) {
			el.parentNode.removeChild(el);
		}
	}

	function ajaxUrl() {
		return (window.snapshoter && window.snapshoter.ajaxUrl) || '/wp-admin/admin-ajax.php';
	}

	function stopLoginWatch() {
		if (loginWatchTimer) {
			clearInterval(loginWatchTimer);
			loginWatchTimer = null;
		}
	}

	function refreshNonce() {
		var body = new FormData();
		body.append('action', 'SNAPSHOTER_refresh_nonce');
		return fetch(ajaxUrl(), {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (json) {
				if (json && json.success && json.data && json.data.nonce && window.snapshoter) {
					window.snapshoter.nonce = String(json.data.nonce);
					return true;
				}
				return false;
			})
			.catch(function () {
				return false;
			});
	}

	function iframeShowsLoginSuccess() {
		try {
			var frame = document.querySelector('#' + OVERLAY_ID + ' iframe');
			if (!frame) {
				return false;
			}
			var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
			if (!doc || !doc.body) {
				return false;
			}
			var cls = String(doc.body.className || '');
			if (cls.indexOf('interim-login-success') !== -1) {
				return true;
			}
			var msg = doc.querySelector('.message, .success');
			if (msg && /logged in successfully/i.test(msg.textContent || '')) {
				return true;
			}
		} catch (e) {
			/* ignore cross-origin */
		}
		return false;
	}

	function setOverlayStatus(text) {
		try {
			var foot = document.querySelector('#' + OVERLAY_ID + ' .snap-session-login__foot');
			if (foot) {
				foot.textContent = text;
			}
		} catch (e) {}
	}

	// After overlay login only - ignore routine heartbeat ticks.
	function recoverSession() {
		if (recovering) {
			return;
		}
		if (!awaitingLogin && !overlayOpen()) {
			return;
		}
		recovering = true;
		awaitingLogin = false;
		stopLoginWatch();
		setOverlayStatus('Login OK - resuming restore status...');

		refreshNonce().finally(function () {
			try {
				window.dispatchEvent(new CustomEvent('snapshoter:session-restored'));
			} catch (e) {}

			removeOverlay();

			var dest =
				(window.snapshoter && window.snapshoter.pluginAdminUrl) ||
				window.location.href;
			try {
				if (dest.indexOf('#') === -1 && window.location.hash) {
					dest = dest + window.location.hash;
				} else if (dest.indexOf('#') === -1) {
					dest = dest + '#backup';
				}
			} catch (e2) {}

			window.location.href = dest;
		});
	}

	function startLoginWatch() {
		stopLoginWatch();
		var ticks = 0;
		loginWatchTimer = setInterval(function () {
			ticks += 1;
			if (ticks > 150) {
				stopLoginWatch();
				return;
			}
			if (!awaitingLogin && !overlayOpen()) {
				stopLoginWatch();
				return;
			}
			if (iframeShowsLoginSuccess()) {
				recoverSession();
				return;
			}
			refreshNonce().then(function (ok) {
				if (ok && (awaitingLogin || overlayOpen())) {
					recoverSession();
				}
			});
		}, 1200);
	}

	function showLogin() {
		if (document.getElementById(OVERLAY_ID)) {
			awaitingLogin = true;
			startLoginWatch();
			return true;
		}

		awaitingLogin = true;

		var ov = document.createElement('div');
		ov.id = OVERLAY_ID;
		ov.setAttribute('role', 'dialog');
		ov.setAttribute('aria-modal', 'true');
		ov.setAttribute('aria-label', 'Log in');
		ov.innerHTML =
			'<div class="snap-session-login__backdrop"></div>' +
			'<div class="snap-session-login__panel">' +
			'<div class="snap-session-login__head">Log in to finish</div>' +
			'<iframe class="snap-session-login__frame" title="WordPress login" src="' +
			loginUrl().replace(/"/g, '&quot;') +
			'"></iframe>' +
			'<div class="snap-session-login__foot">Stay on this page. After login, Snapshoter resumes restore status automatically.</div>' +
			'<button type="button" class="button snap-session-login__close">Close</button>' +
			'</div>';

		if (!document.getElementById('snap-session-login-style')) {
			var style = document.createElement('style');
			style.id = 'snap-session-login-style';
			style.textContent =
				'#' +
				OVERLAY_ID +
				'{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;}' +
				'#' +
				OVERLAY_ID +
				' .snap-session-login__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.45);}' +
				'#' +
				OVERLAY_ID +
				' .snap-session-login__panel{position:relative;background:#fff;border-radius:12px;width:min(420px,94vw);box-shadow:0 20px 50px rgba(0,0,0,.25);overflow:hidden;}' +
				'#' +
				OVERLAY_ID +
				' .snap-session-login__head{padding:14px 16px;font:600 15px/1.3 system-ui,sans-serif;border-bottom:1px solid #e5e7eb;}' +
				'#' +
				OVERLAY_ID +
				' .snap-session-login__frame{width:100%;height:420px;border:0;display:block;background:#fff;}' +
				'#' +
				OVERLAY_ID +
				' .snap-session-login__foot{padding:10px 16px;font:13px/1.4 system-ui,sans-serif;color:#64748b;border-top:1px solid #e5e7eb;}' +
				'#' +
				OVERLAY_ID +
				' .snap-session-login__close{margin:0 16px 14px;cursor:pointer;}';
			document.head.appendChild(style);
		}

		document.body.appendChild(ov);

		var frame = ov.querySelector('iframe');
		if (frame) {
			frame.addEventListener('load', function () {
				if (iframeShowsLoginSuccess()) {
					recoverSession();
				}
			});
		}

		var closeBtn = ov.querySelector('.snap-session-login__close');
		if (closeBtn) {
			closeBtn.addEventListener('click', function () {
				awaitingLogin = false;
				stopLoginWatch();
				removeOverlay();
			});
		}

		startLoginWatch();
		return true;
	}

	window.snapshoterShowLogin = showLogin;
	window.snapshoterRecoverSession = recoverSession;

	window.addEventListener('message', function (ev) {
		try {
			if (!awaitingLogin && !overlayOpen()) {
				return;
			}
			var data = ev && ev.data;
			if (
				data === 'wp-auth-check' ||
				(data && data.event === 'wp-auth-check') ||
				(data && data.action === 'wp-auth-check') ||
				(data && data.loggedIn)
			) {
				recoverSession();
			}
		} catch (e) {}
	});

	if (window.jQuery) {
		window.jQuery(document).on('heartbeat-tick.wp-auth-check', function (e, data) {
			if (!data || data['wp-auth-check'] !== true) {
				return;
			}
			if (!awaitingLogin && !overlayOpen()) {
				return;
			}
			recoverSession();
		});
	}
})();
