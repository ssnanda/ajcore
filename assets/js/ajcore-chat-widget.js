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

	var STORAGE_SESSION = "ajcore_chat_session_uuid";
	var STORAGE_NAME = "ajcore_chat_visitor_name";
	var STORAGE_EMAIL = "ajcore_chat_visitor_email";
	var STORAGE_PHONE = "ajcore_chat_visitor_phone";

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
		"#ajcore-chat-header{background:#3157ff;color:#fff;padding:14px 16px;font-weight:600;font-size:14px;display:flex;justify-content:space-between;align-items:center;}" +
		"#ajcore-chat-close{background:none;border:none;color:#fff;font-size:18px;cursor:pointer;line-height:1;}" +
		"#ajcore-chat-body{flex:1;overflow-y:auto;padding:14px;background:#f8fafc;}" +
		"#ajcore-chat-form{padding:14px;display:flex;flex-direction:column;gap:8px;}" +
		"#ajcore-chat-form input,#ajcore-chat-form textarea{border:1px solid #d1d5db;border-radius:8px;padding:9px 11px;font-size:13px;font-family:inherit;}" +
		"#ajcore-chat-form textarea{resize:none;}" +
		"#ajcore-chat-form button{background:#3157ff;color:#fff;border:none;border-radius:8px;padding:10px;font-size:13px;font-weight:600;cursor:pointer;}" +
		".ajcore-chat-msg{margin:0 0 8px;max-width:82%;padding:8px 11px;border-radius:10px;font-size:13px;line-height:1.4;word-wrap:break-word;}" +
		".ajcore-chat-msg.visitor{background:#3157ff;color:#fff;margin-left:auto;}" +
		".ajcore-chat-msg.staff{background:#e5e7eb;color:#0f172a;margin-right:auto;}" +
		"#ajcore-chat-inputrow{border-top:1px solid #e5e7eb;padding:10px;display:flex;gap:8px;align-items:flex-end;}" +
		"#ajcore-chat-input{flex:1;border:1px solid #d1d5db;border-radius:8px;padding:9px 11px;font-size:13px;font-family:inherit;resize:none;max-height:80px;}" +
		"#ajcore-chat-send{background:#3157ff;color:#fff;border:none;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;}";
	document.head.appendChild(style);

	// ── Markup ───────────────────────────────────────────────────────────────
	var bubble = document.createElement("button");
	bubble.id = "ajcore-chat-bubble";
	bubble.setAttribute("aria-label", "Open chat");
	bubble.textContent = "💬";
	document.body.appendChild(bubble);

	var panel = document.createElement("div");
	panel.id = "ajcore-chat-panel";
	panel.innerHTML =
		'<div id="ajcore-chat-header"><span>Chat with us</span><button id="ajcore-chat-close" aria-label="Close chat">✕</button></div>' +
		'<div id="ajcore-chat-body"></div>';
	document.body.appendChild(panel);

	var header = panel.querySelector("#ajcore-chat-header");
	var body = panel.querySelector("#ajcore-chat-body");
	var closeBtn = panel.querySelector("#ajcore-chat-close");

	bubble.addEventListener("click", function () {
		panel.classList.toggle("open");
	});
	closeBtn.addEventListener("click", function () {
		panel.classList.remove("open");
	});

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
			if (payload && payload.message) {
				appendMessage(payload.message.senderType, payload.message.body);
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

	function sendMessage(text) {
		var payload = {
			body: text,
			visitor_name: visitorName,
			visitor_email: visitorEmail,
			visitor_phone: visitorPhone,
		};
		if (ws && ws.readyState === WebSocket.OPEN) {
			ws.send(JSON.stringify(payload));
		} else {
			pendingSend = payload;
			connect();
		}
		appendMessage("visitor", text);
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

	function renderChatUI() {
		body.innerHTML = "";
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

		connect();
	}

	function renderPreChatForm() {
		var form = document.createElement("div");
		form.id = "ajcore-chat-form";
		form.innerHTML =
			'<input type="text" id="ajcore-chat-name" placeholder="Your name" required>' +
			'<input type="email" id="ajcore-chat-email" placeholder="Email" required>' +
			'<input type="tel" id="ajcore-chat-phone" placeholder="Phone" required>' +
			'<textarea id="ajcore-chat-first-message" rows="3" placeholder="How can we help?" required></textarea>' +
			'<button type="button" id="ajcore-chat-start">Start Chat</button>';
		body.appendChild(form);

		form.querySelector("#ajcore-chat-start").addEventListener("click", function () {
			var name = form.querySelector("#ajcore-chat-name").value.trim();
			var email = form.querySelector("#ajcore-chat-email").value.trim();
			var phone = form.querySelector("#ajcore-chat-phone").value.trim();
			var message = form.querySelector("#ajcore-chat-first-message").value.trim();
			if (!name || !email || !phone || !message) {
				return;
			}
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
})();
