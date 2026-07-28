/**
 * Ambient "new chat" notification for WP-admin — chime + floating bubble, shown on any wp-admin
 * page (not just the Live Chat tab), since there's no persistent connection here the way AJOps
 * has. Polls GET /ops/chat/sessions on an interval and compares the newest last_message_at seen
 * against what's stored in localStorage (survives full-page navigations, unlike a SPA's memory).
 */
(function () {
	var config = window.AJCoreChatAdminNotifyConfig;
	if (!config || !config.restUrl) {
		return;
	}

	var STORAGE_KEY = "ajcore_chat_admin_last_seen";
	var POLL_INTERVAL_MS = 15000;
	var DISMISS_MS = 10000;

	function getLastSeen() {
		try { return localStorage.getItem(STORAGE_KEY) || ""; } catch (e) { return ""; }
	}
	function setLastSeen(value) {
		try { localStorage.setItem(STORAGE_KEY, value); } catch (e) { /* ignore */ }
	}

	// ── Sound (same approach as the visitor widget) ─────────────────────────────
	var audioCtx = null;
	function playChime() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if (!Ctx) return;
			if (!audioCtx) audioCtx = new Ctx();
			if (audioCtx.state === "suspended") audioCtx.resume();
			var now = audioCtx.currentTime;
			[880, 1175].forEach(function (freq, i) {
				var osc = audioCtx.createOscillator();
				var gain = audioCtx.createGain();
				osc.connect(gain);
				gain.connect(audioCtx.destination);
				osc.frequency.value = freq;
				var start = now + i * 0.12;
				gain.gain.setValueAtTime(0, start);
				gain.gain.linearRampToValueAtTime(0.18, start + 0.02);
				gain.gain.exponentialRampToValueAtTime(0.001, start + 0.25);
				osc.start(start);
				osc.stop(start + 0.25);
			});
		} catch (e) { /* autoplay can be blocked before any user interaction */ }
	}

	// ── Bubble UI ────────────────────────────────────────────────────────────
	var style = document.createElement("style");
	style.textContent =
		"#ajcore-admin-chat-bubble{position:fixed;bottom:20px;right:20px;z-index:99999;display:flex;align-items:flex-start;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15);padding:12px 16px;max-width:320px;cursor:pointer;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}" +
		"#ajcore-admin-chat-bubble .aj-icon{flex-shrink:0;width:34px;height:34px;border-radius:50%;background:#3157ff;color:#fff;display:flex;align-items:center;justify-content:center;}" +
		"#ajcore-admin-chat-bubble .aj-title{font-size:13px;font-weight:700;color:#111827;}" +
		"#ajcore-admin-chat-bubble .aj-preview{font-size:12px;color:#6b7280;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}";
	document.head.appendChild(style);

	var dismissTimer = null;
	function showBubble(who, preview) {
		var existing = document.getElementById("ajcore-admin-chat-bubble");
		if (existing) existing.remove();

		var el = document.createElement("div");
		el.id = "ajcore-admin-chat-bubble";
		el.innerHTML =
			'<span class="aj-icon">\u{1F4AC}</span>' +
			'<span><span class="aj-title">New message from ' + escapeHtml(who) + '</span>' +
			'<span class="aj-preview">' + escapeHtml(preview) + '</span></span>';
		el.addEventListener("click", function () {
			window.location.href = config.liveChatUrl;
		});
		document.body.appendChild(el);

		if (dismissTimer) clearTimeout(dismissTimer);
		dismissTimer = setTimeout(function () {
			var node = document.getElementById("ajcore-admin-chat-bubble");
			if (node) node.remove();
		}, DISMISS_MS);
	}

	function escapeHtml(s) {
		var d = document.createElement("div");
		d.innerText = s || "";
		return d.innerHTML;
	}

	// ── Polling ──────────────────────────────────────────────────────────────
	function poll() {
		fetch(config.restUrl + "?status=open&per_page=50", {
			headers: { "X-WP-Nonce": config.nonce }
		})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data || !Array.isArray(data.sessions) || !data.sessions.length) return;

				var newest = null;
				data.sessions.forEach(function (s) {
					var ts = s.lastMessageAt || s.createdAt || "";
					if (ts && (!newest || ts > newest.lastMessageAt)) {
						newest = { lastMessageAt: ts, session: s };
					}
				});
				if (!newest) return;

				var lastSeen = getLastSeen();
				if (lastSeen && newest.lastMessageAt > lastSeen) {
					playChime();
					var who = newest.session.visitorName || newest.session.visitorEmail || newest.session.visitorPhone || "a visitor";
					showBubble(who, "New activity in Live Chat");
				}
				// First-ever poll on a fresh browser: just set the baseline, don't notify for
				// pre-existing chats that were already there before this script ever ran.
				setLastSeen(newest.lastMessageAt);
			})
			.catch(function () { /* transient poll failure — try again next interval */ });
	}

	poll();
	setInterval(poll, POLL_INTERVAL_MS);
})();
