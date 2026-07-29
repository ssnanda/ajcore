/**
 * Self-hosted Live Chat widget. Vanilla JS, no dependencies — loaded on any front-end page of a
 * site with the widget enabled (see ajcore_render_chat_widget() in ajcore.php, which supplies
 * window.AJCoreChatConfig). Connects directly to the AJOps chat server's WebSocket endpoint.
 */
(function () {
	var config = window.AJCoreChatConfig;
	if (!config || !config.serverUrl || !config.siteUuid) {
		return;
	}

	// Desktop/tablet only — the "Mobile" token is what actually distinguishes phones from tablets
	// here: iPadOS Safari's UA reports as a plain desktop Mac (no match), and Android tablets omit
	// "Mobile" the way Android phones include it, so this doesn't accidentally hide the widget on
	// larger touch devices, only phones. Skips creating any DOM/WebSocket for phone visitors
	// entirely, rather than just hiding it with CSS.
	if (/iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini|IEMobile/i.test(navigator.userAgent || "")) {
		return;
	}

	var STORAGE_SESSION = "ajcore_chat_session_uuid";
	var STORAGE_NAME = "ajcore_chat_visitor_name";
	var STORAGE_EMAIL = "ajcore_chat_visitor_email";
	var STORAGE_PHONE = "ajcore_chat_visitor_phone";
	var STORAGE_PANEL_OPEN = "ajcore_chat_panel_open";

	function uuid() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
			var r = (Math.random() * 16) | 0;
			var v = c === "x" ? r : (r & 0x3) | 0x8;
			return v.toString(16);
		});
	}

	function getStored(key) {
		try { return window.localStorage.getItem(key) || ""; } catch (e) { return ""; }
	}
	function setStored(key, value) {
		try { window.localStorage.setItem(key, value); } catch (e) { /* ignore */ }
	}

	var sessionUuid = getStored(STORAGE_SESSION);
	if (!sessionUuid) {
		sessionUuid = uuid();
		setStored(STORAGE_SESSION, sessionUuid);
	}

	var visitorName = getStored(STORAGE_NAME);
	var visitorEmail = getStored(STORAGE_EMAIL);
	var visitorPhone = getStored(STORAGE_PHONE);
	var hasVisitorInfo = !!(visitorName || visitorEmail || visitorPhone);

	// ── Styles ────────────────────────────────────────────────────────────────
	var style = document.createElement("style");
	style.textContent =
		"#ajcore-chat-bubble{position:fixed;bottom:20px;right:20px;width:56px;height:56px;border-radius:50%;background:#3157ff;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.2);z-index:999998;font-size:26px;border:none;}" +
		"#ajcore-chat-panel{position:fixed;bottom:88px;right:20px;width:340px;max-width:calc(100vw - 40px);height:460px;max-height:calc(100vh - 120px);background:#fff;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden;z-index:999999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}" +
		"#ajcore-chat-panel.open{display:flex;}" +
		"#ajcore-chat-header{background:#3157ff;color:#fff;padding:14px 16px;font-weight:600;font-size:14px;display:flex;justify-content:space-between;align-items:center;gap:8px;}" +
		"#ajcore-chat-header-actions{display:flex;align-items:center;gap:10px;}" +
		"#ajcore-chat-notify{background:none;border:none;color:rgba(255,255,255,.9);font-size:15px;cursor:pointer;padding:0;line-height:1;}" +
		"#ajcore-chat-notify.enabled{color:#bbf7d0;}" +
		"#ajcore-chat-end{background:none;border:none;color:rgba(255,255,255,.85);font-size:11px;font-weight:600;cursor:pointer;text-decoration:underline;padding:0;display:none;}" +
		"#ajcore-chat-close{background:none;border:none;color:#fff;font-size:18px;cursor:pointer;line-height:1;}" +
		"#ajcore-chat-body{flex:1;overflow-y:auto;padding:14px;background:#f8fafc;}" +
		"#ajcore-chat-form{padding:14px;display:flex;flex-direction:column;gap:8px;}" +
		"#ajcore-chat-form input,#ajcore-chat-form textarea{border:1px solid #d1d5db;border-radius:8px;padding:9px 11px;font-size:13px;font-family:inherit;}" +
		"#ajcore-chat-form textarea{resize:none;}" +
		"#ajcore-chat-form input.aj-invalid,#ajcore-chat-form textarea.aj-invalid{border-color:#dc2626;background:#fef2f2;}" +
		"#ajcore-chat-form .aj-field-error{display:none;color:#dc2626;font-size:11px;line-height:1.3;margin-top:-4px;}" +
		"#ajcore-chat-form .aj-field-error.show{display:block;}" +
		"#ajcore-chat-form button{background:#3157ff;color:#fff;border:none;border-radius:8px;padding:10px;font-size:13px;font-weight:600;cursor:pointer;}" +
		".ajcore-chat-msg{margin:0 0 8px;max-width:82%;padding:8px 11px;border-radius:10px;font-size:13px;line-height:1.4;word-wrap:break-word;}" +
		".ajcore-chat-msg.visitor{background:#3157ff;color:#fff;margin-left:auto;}" +
		".ajcore-chat-msg.staff{background:#e5e7eb;color:#0f172a;margin-right:auto;}" +
		"#ajcore-chat-inputrow{border-top:1px solid #e5e7eb;padding:10px;display:flex;gap:8px;align-items:flex-end;}" +
		"#ajcore-chat-input{flex:1;border:1px solid #d1d5db;border-radius:8px;padding:9px 11px;font-size:13px;font-family:inherit;resize:none;max-height:80px;}" +
		"#ajcore-chat-send{background:#3157ff;color:#fff;border:none;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;}" +
		"#ajcore-chat-badge{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:#dc2626;color:#fff;font-size:11px;font-weight:700;display:none;align-items:center;justify-content:center;line-height:1;}" +
		"#ajcore-chat-badge.show{display:flex;}" +
		"#ajcore-chat-preview{position:fixed;bottom:88px;right:20px;max-width:260px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:12px 14px;cursor:pointer;z-index:999997;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;display:none;}" +
		"#ajcore-chat-preview.show{display:block;}" +
		"#ajcore-chat-preview .aj-title{font-size:12px;font-weight:700;color:#111827;margin:0 0 2px;}" +
		"#ajcore-chat-preview .aj-body{font-size:12px;color:#4b5563;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}" +
		"#ajcore-chat-offline-banner{background:#fffbeb;color:#92400e;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;font-size:12px;line-height:1.4;margin:0 14px 8px;}";
	document.head.appendChild(style);

	// ── Markup ───────────────────────────────────────────────────────────────
	var bubble = document.createElement("button");
	bubble.id = "ajcore-chat-bubble";
	bubble.setAttribute("aria-label", "Open chat");
	bubble.innerHTML = '<span>💬</span><span id="ajcore-chat-badge"></span>';
	document.body.appendChild(bubble);
	var badge = bubble.querySelector("#ajcore-chat-badge");

	// Outside the panel itself, so a reply is visible even while the panel is closed/minimized.
	var preview = document.createElement("div");
	preview.id = "ajcore-chat-preview";
	document.body.appendChild(preview);

	var panel = document.createElement("div");
	panel.id = "ajcore-chat-panel";
	panel.innerHTML =
		'<div id="ajcore-chat-header"><span>Chat with us</span><div id="ajcore-chat-header-actions"><button id="ajcore-chat-notify" type="button" aria-label="Enable desktop notifications" title="Enable desktop notifications">🔔</button><button id="ajcore-chat-end">End Chat</button><button id="ajcore-chat-close" aria-label="Close chat">✕</button></div></div>' +
		'<div id="ajcore-chat-body"></div>';
	document.body.appendChild(panel);

	var header = panel.querySelector("#ajcore-chat-header");
	var body = panel.querySelector("#ajcore-chat-body");
	var closeBtn = panel.querySelector("#ajcore-chat-close");
	var endChatBtn = panel.querySelector("#ajcore-chat-end");
	var notifyBtn = panel.querySelector("#ajcore-chat-notify");

	var panelOpen = false;
	var unreadCount = 0;
	// Whether the CURRENT session is still open — governs both the "End Chat" button's visibility
	// and the beforeunload warning, so closing the tab only warns while there's actually something
	// active to lose (not forever, just because this visitor has chatted at some point in the past).
	var sessionOpen = false;

	function updateEndChatVisibility() {
		endChatBtn.style.display = (hasVisitorInfo && sessionOpen) ? "inline" : "none";
	}
	var previewDismissTimer = null;

	function updateBadge() {
		if (unreadCount > 0) {
			badge.textContent = String(unreadCount);
			badge.classList.add("show");
		} else {
			badge.classList.remove("show");
		}
	}

	function hidePreview() {
		preview.classList.remove("show");
		if (previewDismissTimer) { clearTimeout(previewDismissTimer); previewDismissTimer = null; }
	}

	function showPreview(text) {
		preview.innerHTML = '<div class="aj-title">New message</div><div class="aj-body"></div>';
		preview.querySelector(".aj-body").textContent = text;
		preview.classList.add("show");
		if (previewDismissTimer) clearTimeout(previewDismissTimer);
		previewDismissTimer = setTimeout(hidePreview, 10000);
	}

	preview.addEventListener("click", function () {
		hidePreview();
		openPanel();
	});

	function openPanel() {
		panel.classList.add("open");
		panelOpen = true;
		unreadCount = 0;
		updateBadge();
		hidePreview();
		setStored(STORAGE_PANEL_OPEN, "1");
	}
	function closePanel() {
		panel.classList.remove("open");
		panelOpen = false;
		setStored(STORAGE_PANEL_OPEN, "");
	}

	bubble.addEventListener("click", function () {
		if (panelOpen) { closePanel(); } else { openPanel(); }
		// A real click is a genuine user gesture — request here too (not just from renderChatUI()
		// at page load for returning visitors, which some browsers silently ignore since it isn't
		// gesture-triggered, leaving permission stuck at "default" forever).
		requestDesktopNotifyPermission();
	});
	closeBtn.addEventListener("click", closePanel);

	// ── WebSocket ────────────────────────────────────────────────────────────
	var ws = null;
	var reconnectTimer = null;
	var pendingSend = null;

	function wsUrl() {
		var base = config.serverUrl.replace(/^http/, "ws");
		return base + "/chat-ws?role=visitor&session_uuid=" + encodeURIComponent(sessionUuid) + "&site_uuid=" + encodeURIComponent(config.siteUuid);
	}

	function connect() {
		// Without this guard, calling connect() while a socket is already CONNECTING (e.g. the
		// pre-chat form's Start Chat handler calls renderChatUI() -> connect(), then immediately
		// sendMessage(), whose own "not open yet" fallback also calls connect()) creates a second
		// socket and orphans the first — when the first one's onopen later fires, it sends on
		// `ws`, which by then points at the second (still-connecting) socket, throwing
		// "Still in CONNECTING state". Only open a new one if there isn't already an active/opening one.
		if (ws && (ws.readyState === WebSocket.CONNECTING || ws.readyState === WebSocket.OPEN)) {
			return;
		}
		var socket;
		try {
			socket = new WebSocket(wsUrl());
		} catch (e) {
			scheduleReconnect();
			return;
		}
		ws = socket;
		socket.onopen = function () {
			if (pendingSend) {
				var toSend = pendingSend;
				pendingSend = null;
				socket.send(JSON.stringify(toSend));
			}
		};
		socket.onmessage = function (event) {
			var payload;
			try { payload = JSON.parse(event.data); } catch (e) { return; }
			if (payload && payload.type === "typing") {
				if (payload.from === "staff") { showStaffTyping(); }
				return;
			}
			if (payload && payload.message) {
				hideStaffTyping();
				if (payload.message.senderType === "visitor" && pendingOwnMessages.length && pendingOwnMessages[0] === payload.message.body) {
					// This is the server echo of a message we already rendered optimistically below
					// — skip re-rendering it (would otherwise double-print), but still consume it
					// from the queue. A "visitor" message that DOESN'T match the front of the queue
					// is a genuine multi-tab case (same visitor, sent from a different tab) and
					// should render normally — see the queue's other consumer in sendMessage().
					pendingOwnMessages.shift();
				} else {
					appendMessage(payload.message.senderType, payload.message.body);
				}
				if (payload.message.senderType === "staff") {
					playChime();
					notifyDesktop("New message", payload.message.body);
					if (!panelOpen) {
						unreadCount += 1;
						updateBadge();
						showPreview(payload.message.body);
					}
				}
			}
		};
		socket.onclose = function () {
			scheduleReconnect();
		};
		socket.onerror = function () {
			socket.close();
		};
	}

	function scheduleReconnect() {
		if (reconnectTimer) return;
		reconnectTimer = setTimeout(function () {
			reconnectTimer = null;
			connect();
		}, 3000);
	}

	// Messages rendered optimistically in sendMessage() below, waiting to be matched against the
	// server's own-message echo (see onmessage above) so that echo doesn't double-print them.
	var pendingOwnMessages = [];

	function sendMessage(text) {
		var payload = {
			body: text,
			visitor_name: visitorName,
			visitor_email: visitorEmail,
			visitor_phone: visitorPhone,
		};
		// Render immediately rather than waiting on AJCore's broadcast echo of the visitor's own
		// message (see notify_ajops_chat(), originally kept "for multi-tab consistency") — on a
		// slow/flaky mobile connection that round trip can lag well behind hitting Send, or drop
		// entirely, making it look like the message never sent at all. The echo is still relied on
		// for genuine multi-tab sync (a different tab sending), so it isn't removed — just deduped
		// against this queue instead of always rendering, to avoid the double-print this used to
		// cause when both the optimistic render and the echo showed the same message.
		appendMessage("visitor", text);
		pendingOwnMessages.push(text);
		if (ws && ws.readyState === WebSocket.OPEN) {
			ws.send(JSON.stringify(payload));
		} else {
			pendingSend = payload;
			connect();
		}
	}

	// ── Typing indicator ─────────────────────────────────────────────────────
	// Purely ephemeral — relayed directly by server.js, never touches AJCore/the DB.
	var typingSendTimer = null;
	function sendTyping() {
		if (!(ws && ws.readyState === WebSocket.OPEN)) return;
		if (typingSendTimer) return; // debounce: at most once every 2s
		ws.send(JSON.stringify({ type: "typing" }));
		typingSendTimer = setTimeout(function () { typingSendTimer = null; }, 2000);
	}

	var staffTypingEl = null;
	var staffTypingClearTimer = null;
	function showStaffTyping() {
		if (!staffTypingEl) {
			staffTypingEl = document.createElement("div");
			staffTypingEl.id = "ajcore-chat-typing";
			staffTypingEl.style.cssText = "font-size:12px;color:#9ca3af;font-style:italic;padding:2px 4px;";
			staffTypingEl.textContent = "Staff is typing…";
		}
		if (!staffTypingEl.parentNode) {
			body.appendChild(staffTypingEl);
			body.scrollTop = body.scrollHeight;
		}
		if (staffTypingClearTimer) clearTimeout(staffTypingClearTimer);
		staffTypingClearTimer = setTimeout(hideStaffTyping, 4000);
	}
	function hideStaffTyping() {
		if (staffTypingEl && staffTypingEl.parentNode) {
			staffTypingEl.parentNode.removeChild(staffTypingEl);
		}
		if (staffTypingClearTimer) { clearTimeout(staffTypingClearTimer); staffTypingClearTimer = null; }
	}

	// ── Native OS-level notification (shows even if this tab is backgrounded, not just the
	// in-page preview bubble) ────────────────────────────────────────────────
	// Permission is requested once the visitor actually starts chatting (renderChatUI(), covers
	// both a fresh Start Chat submit and a returning visitor's tab) — not on page load for every
	// site visitor who never opens the widget.
	function requestDesktopNotifyPermission() {
		if (typeof window.Notification === "undefined") {
			updateNotificationButton();
			return;
		}
		try {
			// On an insecure origin (plain http, not localhost) Firefox throws a synchronous
			// SecurityError here instead of just resolving to "denied" the way Chrome does — left
			// unguarded, that exception aborted the REST of renderChatUI() (the input box + WS
			// connect() that follow this call never ran), breaking the whole chat, not just
			// notifications. Chrome's lenient behavior on http is exactly why this never showed up
			// in Chrome testing.
			if (Notification.permission === "default") {
				var request = Notification.requestPermission();
				if (request && typeof request.then === "function") {
					request.then(updateNotificationButton).catch(updateNotificationButton);
				}
			}
			updateNotificationButton();
		} catch (e) { /* insecure origin or otherwise unsupported — notifications just stay off */ }
	}
	function updateNotificationButton() {
		if (!notifyBtn) return;
		if (typeof window.Notification === "undefined") {
			notifyBtn.style.display = "none";
			return;
		}
		if (Notification.permission === "granted") {
			notifyBtn.classList.add("enabled");
			notifyBtn.textContent = "🔔";
			notifyBtn.title = "Send a test desktop notification";
			return;
		}
		notifyBtn.classList.remove("enabled");
		notifyBtn.textContent = Notification.permission === "denied" ? "🔕" : "🔔";
		notifyBtn.title = Notification.permission === "denied"
			? "Notifications are blocked in this browser's site permissions"
			: "Enable desktop notifications";
	}
	if (notifyBtn) {
		notifyBtn.addEventListener("click", function () {
			if (typeof window.Notification !== "undefined" && Notification.permission === "denied") {
				window.alert("Desktop notifications are blocked for this site. Allow notifications in your browser's site permissions, then reload the page.");
				return;
			}
			if (typeof window.Notification !== "undefined" && Notification.permission === "granted") {
				var testError = notifyDesktop("Chat notifications are working", "You will receive desktop alerts when staff replies.");
				if (testError) {
					window.alert("Desktop notification failed: " + testError);
				}
				return;
			}
			requestDesktopNotifyPermission();
		});
		updateNotificationButton();
	}
	function notifyDesktop(title, body) {
		if (typeof window.Notification === "undefined") return "This browser does not expose the desktop Notification API.";
		if (Notification.permission !== "granted") return "Notification permission is " + Notification.permission + ".";
		// Matches AJOps' own notification behavior — fires on every staff reply regardless of
		// whether the tab is focused, not just while backgrounded.
		try {
			var n = new Notification(title, { body: body });
			n.onclick = function () { window.focus(); n.close(); };
			return null;
		} catch (e) {
			return e && e.message ? e.message : String(e);
		}
	}

	// ── Business hours ───────────────────────────────────────────────────────
	// Reuses the *idea* from AJPhone's automation rules (businessHoursMode), not any shared code —
	// that field exists there but nothing evaluates it against a real schedule yet. This is a
	// genuinely new, minimal evaluator: a single "Mon-Fri 09:00-17:00" range, visitor's local clock.
	var DAY_MAP = { sun: 0, mon: 1, tue: 2, wed: 3, thu: 4, fri: 5, sat: 6 };
	function isWithinBusinessHours() {
		if (!config.businessHoursEnabled || !config.businessHours) return true;
		var m = /^(\w{3})-(\w{3})\s+(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/i.exec(String(config.businessHours).trim());
		if (!m) return true; // malformed config — don't block/annoy visitors over a settings typo
		var startDay = DAY_MAP[m[1].toLowerCase()];
		var endDay = DAY_MAP[m[2].toLowerCase()];
		if (startDay === undefined || endDay === undefined) return true;
		var startMin = parseInt(m[3], 10) * 60 + parseInt(m[4], 10);
		var endMin = parseInt(m[5], 10) * 60 + parseInt(m[6], 10);
		var now = new Date();
		var day = now.getDay();
		var mins = now.getHours() * 60 + now.getMinutes();
		var dayInRange = startDay <= endDay ? (day >= startDay && day <= endDay) : (day >= startDay || day <= endDay);
		var timeInRange = startMin <= endMin ? (mins >= startMin && mins < endMin) : (mins >= startMin || mins < endMin);
		return dayInRange && timeInRange;
	}

	// ── Sound ────────────────────────────────────────────────────────────────
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
		} catch (e) { /* autoplay can be blocked before any user interaction — not worth surfacing */ }
	}

	// ── Pre-chat form validation ─────────────────────────────────────────────
	function isValidEmail(value) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
	}
	function isValidPhone(value) {
		// Reject letters/symbols outright, then require a real dialable digit count — 10 (US local)
		// up to 15 (E.164 max, for international visitors with a country code). The previous ">= 7"
		// floor let through short non-numbers like "70430721" (8 digits, not a real phone number).
		if (!/^[0-9+()\-.\s]+$/.test(value)) return false;
		var digits = value.replace(/[^0-9]/g, "").length;
		return digits >= 10 && digits <= 15;
	}

	// ── Rendering ────────────────────────────────────────────────────────────
	function escapeHtml(s) {
		var d = document.createElement("div");
		d.innerText = s || "";
		return d.innerHTML;
	}

	function appendMessage(senderType, text) {
		var el = document.createElement("div");
		el.className = "ajcore-chat-msg " + (senderType === "staff" ? "staff" : "visitor");
		el.innerHTML = escapeHtml(text);
		body.appendChild(el);
		body.scrollTop = body.scrollHeight;
	}

	// Restores a returning visitor's prior messages on a fresh tab/reload — the widget already
	// reconnects to the same session_uuid, but previously started from an empty panel every time.
	function loadHistory() {
		fetch(config.serverUrl + "/api/chat/history?session_uuid=" + encodeURIComponent(sessionUuid))
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data) return;
				if (Array.isArray(data.messages)) {
					data.messages.forEach(function (m) {
						appendMessage(m.senderType, m.body);
					});
				}
				// Corrects the optimistic "assume open" default below — a returning visitor's
				// session may have been closed by staff or the auto-close cron while they were away.
				if (data.session) {
					sessionOpen = data.session.status === "open";
					updateEndChatVisibility();
				}
			})
			.catch(function () { /* history is a nice-to-have — a failed fetch just starts empty, same as before this existed */ });
	}

	function endChat() {
		if (!window.confirm("End this chat? You can always start a new one.")) return;
		fetch(config.serverUrl + "/api/chat/end", {
			method: "POST",
			headers: { "Content-Type": "application/json" },
			body: JSON.stringify({ session_uuid: sessionUuid }),
		})
			.catch(function () { /* still reset locally below even if the request fails — don't trap the visitor in a session they asked to leave */ })
			.then(function () {
				sessionOpen = false;
				updateEndChatVisibility();
				if (ws) { ws.close(); ws = null; }
				// A fresh UUID now, not just cleared storage — otherwise clicking the bubble again
				// in the same page load would still be a closed session (create_chat_session()
				// looks up by session_uuid and returns the existing, now-closed, row).
				sessionUuid = uuid();
				setStored(STORAGE_SESSION, sessionUuid);
				setStored(STORAGE_NAME, "");
				setStored(STORAGE_EMAIL, "");
				setStored(STORAGE_PHONE, "");
				setStored(STORAGE_PANEL_OPEN, "");
				visitorName = ""; visitorEmail = ""; visitorPhone = ""; hasVisitorInfo = false;
				var oldRow = document.getElementById("ajcore-chat-inputrow");
				if (oldRow) oldRow.remove();
				body.innerHTML = '<div style="padding:20px;text-align:center;color:#6b7280;font-size:13px;">Chat ended. Starting fresh…</div>';
				// Brief confirmation, then reset to a ready-for-a-new-conversation form — without
				// this the panel would stay stuck on the static "ended" message until a full page
				// reload, since content is normally only rendered once at init. renderPreChatForm()
				// appends rather than clearing (unlike renderChatUI()), so clear first.
				setTimeout(function () {
					body.innerHTML = "";
					renderPreChatForm();
				}, 1200);
			});
	}
	endChatBtn.addEventListener("click", endChat);

	function renderChatUI() {
		body.innerHTML = "";
		sessionOpen = true; // optimistic default — loadHistory() below corrects it if stale
		updateEndChatVisibility();
		loadHistory();
		requestDesktopNotifyPermission();
		var row = document.createElement("div");
		row.id = "ajcore-chat-inputrow";
		row.innerHTML =
			'<textarea id="ajcore-chat-input" rows="1" placeholder="Type a message…"></textarea>' +
			'<button id="ajcore-chat-send">Send</button>';
		panel.appendChild(row);

		var input = row.querySelector("#ajcore-chat-input");
		var sendBtn = row.querySelector("#ajcore-chat-send");

		function doSend() {
			var text = (input.value || "").trim();
			if (!text) return;
			sendMessage(text);
			input.value = "";
		}
		sendBtn.addEventListener("click", doSend);
		input.addEventListener("keydown", function (e) {
			if (e.key === "Enter" && !e.shiftKey) {
				e.preventDefault();
				doSend();
			}
		});
		input.addEventListener("input", sendTyping);

		connect();
	}

	function renderPreChatForm() {
		if (!isWithinBusinessHours()) {
			var banner = document.createElement("div");
			banner.id = "ajcore-chat-offline-banner";
			banner.textContent = "We're currently offline — leave a message and we'll reply as soon as we're back.";
			body.appendChild(banner);
		}

		var form = document.createElement("div");
		form.id = "ajcore-chat-form";
		form.innerHTML =
			'<input type="text" id="ajcore-chat-name" placeholder="Your name" required>' +
			'<div class="aj-field-error" id="ajcore-chat-name-error"></div>' +
			'<input type="email" id="ajcore-chat-email" placeholder="you@example.com" required>' +
			'<div class="aj-field-error" id="ajcore-chat-email-error"></div>' +
			'<input type="tel" id="ajcore-chat-phone" placeholder="(555) 123-4567" required>' +
			'<div class="aj-field-error" id="ajcore-chat-phone-error"></div>' +
			'<textarea id="ajcore-chat-first-message" rows="3" placeholder="How can we help?" required></textarea>' +
			'<div class="aj-field-error" id="ajcore-chat-message-error"></div>' +
			'<button type="button" id="ajcore-chat-start">Start Chat</button>';
		body.appendChild(form);

		// Validates one field on blur (and re-validates on input once it's already flagged invalid,
		// so the error clears the moment the visitor fixes it rather than only on the next blur) —
		// catches format mistakes as the visitor moves through the form instead of all at once after
		// they hit Start Chat. Returns the same check so the submit handler below can reuse it as
		// the final gate without duplicating the validation rule.
		function fieldValidator(input, errorEl, validate, message) {
			function run() {
				var ok = validate(input.value.trim());
				input.classList.toggle("aj-invalid", !ok);
				errorEl.textContent = ok ? "" : message;
				errorEl.classList.toggle("show", !ok);
				return ok;
			}
			input.addEventListener("blur", run);
			input.addEventListener("input", function () {
				if (input.classList.contains("aj-invalid")) run();
			});
			return run;
		}

		var nameInput = form.querySelector("#ajcore-chat-name");
		var emailInput = form.querySelector("#ajcore-chat-email");
		var phoneInput = form.querySelector("#ajcore-chat-phone");
		var messageInput = form.querySelector("#ajcore-chat-first-message");

		var validateName = fieldValidator(nameInput, form.querySelector("#ajcore-chat-name-error"),
			function (v) { return v.length > 0; }, "Please enter your name.");
		var validateEmail = fieldValidator(emailInput, form.querySelector("#ajcore-chat-email-error"),
			isValidEmail, "Please enter a valid email address, like you@example.com.");
		var validatePhone = fieldValidator(phoneInput, form.querySelector("#ajcore-chat-phone-error"),
			isValidPhone, "Please enter a valid phone number, like (555) 123-4567.");
		var validateMessage = fieldValidator(messageInput, form.querySelector("#ajcore-chat-message-error"),
			function (v) { return v.length > 0; }, "Please tell us how we can help.");

		form.querySelector("#ajcore-chat-start").addEventListener("click", function () {
			// && (not ||-short-circuit) so every invalid field gets its error shown at once, not just
			// the first one — validateName() etc. already set the classes/messages as a side effect.
			var nameOk = validateName();
			var emailOk = validateEmail();
			var phoneOk = validatePhone();
			var messageOk = validateMessage();
			if (!nameOk || !emailOk || !phoneOk || !messageOk) {
				(nameOk ? (emailOk ? (phoneOk ? messageInput : phoneInput) : emailInput) : nameInput).focus();
				return;
			}

			var name = nameInput.value.trim();
			var email = emailInput.value.trim();
			var phone = phoneInput.value.trim();
			var message = messageInput.value.trim();
			visitorName = name;
			visitorEmail = email;
			visitorPhone = phone;
			setStored(STORAGE_NAME, name);
			setStored(STORAGE_EMAIL, email);
			setStored(STORAGE_PHONE, phone);
			hasVisitorInfo = true;

			renderChatUI();
			sendMessage(message);
		});
	}

	if (hasVisitorInfo) {
		renderChatUI();
	} else {
		renderPreChatForm();
	}
	if (getStored(STORAGE_PANEL_OPEN) === "1") {
		openPanel();
	}

	// Warns before closing the tab only while the CURRENT session is still open (not forever, just
	// because this visitor has chatted at some point in the past — sessionOpen gets corrected by
	// loadHistory()/endChat() as the real session state becomes known). Browsers ignore custom
	// beforeunload text and always show their own generic prompt — there's no way to display "you
	// have an active chat" wording itself, only to trigger that prompt.
	window.addEventListener("beforeunload", function (e) {
		if (!hasVisitorInfo || !sessionOpen) return;
		e.preventDefault();
		e.returnValue = "";
	});
})();
