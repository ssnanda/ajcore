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

	// Bare domain (no protocol/path) — every prepopulated "Text Us" SMS body mentions it, so
	// whoever answers the text immediately knows which site the visitor is asking about, without
	// having to ask (staff run this widget on more than one site).
	function siteHost() {
		try { return window.location.hostname || ""; } catch (e) { return ""; }
	}

	function defaultTextUsMessage() {
		var host = siteHost();
		return host
			? "Hi, I am on your website " + host + " and I have some questions."
			: "Hi, I am on your website and I have some questions.";
	}

	// ── Passive engagement popup ────────────────────────────────────────────
	// A soft, one-time nudge for a visitor who's been on the page a while without reaching out —
	// "you decide" on the exact form, this is a small dismissible yes/no callout rather than a
	// full-screen splash, so it doesn't interrupt whatever the visitor's doing. Shared by both the
	// mobile TEXT bubble and the desktop CHAT widget branches below (each calls this once, passing
	// how to build that device's SMS href) so the popup/dismissal logic isn't duplicated twice.
	var STORAGE_ENGAGE_DISMISSED = "ajcore_engage_dismissed";
	// Configurable from CP Settings > Live Chat > Engagement Popup; falls back to the original
	// always-on 25s if an older AJCore (before that setting existed) ever serves this file.
	var ENGAGE_DELAY_MS = typeof config.engagePopupDelayMs === "number" ? config.engagePopupDelayMs : 25000;

	function maybeShowEngagementPopup(getSmsHref) {
		if (config.engagePopupEnabled === false) return;
		if (getStored(STORAGE_ENGAGE_DISMISSED) === "1") return;

		setTimeout(function () {
			if (getStored(STORAGE_ENGAGE_DISMISSED) === "1") return;
			// Desktop only: skip the nudge if the panel's already open, or if they have a currently
			// ACTIVE session — checking sessionOpen rather than hasVisitorInfo deliberately: hasVisitorInfo
			// is set the first time anyone ever fills the pre-chat form and never clears itself, so it
			// was permanently suppressing this popup for any returning visitor forever, even ones whose
			// last chat ended ages ago (this is what silently broke it during testing). By the time this
			// 25s timer fires, loadHistory()'s fetch (kicked off at page load) has long since corrected
			// sessionOpen's optimistic page-load default to the real value.
			if (typeof panelOpen !== "undefined" && panelOpen) return;
			if (typeof sessionOpen !== "undefined" && sessionOpen) return;
			showEngagementPopup(getSmsHref);
		}, ENGAGE_DELAY_MS);
	}

	function dismissEngagementPopup(popupEl, remember) {
		if (remember) setStored(STORAGE_ENGAGE_DISMISSED, "1");
		if (popupEl && popupEl.parentNode) popupEl.parentNode.removeChild(popupEl);
	}

	function showEngagementPopup(getSmsHref) {
		var host = siteHost();
		var style = document.createElement("style");
		style.textContent =
			"#ajcore-engage-popup{position:fixed;bottom:88px;right:20px;max-width:270px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:14px 16px;z-index:999997;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}" +
			"#ajcore-engage-popup .aj-engage-close{position:absolute;top:6px;right:8px;background:none;border:none;color:#9ca3af;font-size:14px;cursor:pointer;line-height:1;padding:2px;}" +
			"#ajcore-engage-popup .aj-engage-text{font-size:13px;color:#111827;margin:0 0 10px;padding-right:12px;line-height:1.4;}" +
			"#ajcore-engage-popup .aj-engage-actions{display:flex;gap:8px;}" +
			"#ajcore-engage-popup button.aj-engage-btn{flex:1;border-radius:8px;padding:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;}" +
			"#ajcore-engage-yes{background:#3157ff;color:#fff;}" +
			"#ajcore-engage-no{background:#f1f5f9;color:#475569;}";
		document.head.appendChild(style);

		var popup = document.createElement("div");
		popup.id = "ajcore-engage-popup";
		popup.innerHTML =
			'<button type="button" class="aj-engage-close" aria-label="Dismiss">✕</button>' +
			'<p class="aj-engage-text">👋 Got a question' + (host ? " about " + escapeHtml(host) : "") + '? We usually reply in minutes — want to text us?</p>' +
			'<div class="aj-engage-actions">' +
				'<button type="button" class="aj-engage-btn" id="ajcore-engage-no">No thanks</button>' +
				'<button type="button" class="aj-engage-btn" id="ajcore-engage-yes">Yes, text us</button>' +
			'</div>';
		document.body.appendChild(popup);

		popup.querySelector(".aj-engage-close").addEventListener("click", function () { dismissEngagementPopup(popup, true); });
		popup.querySelector("#ajcore-engage-no").addEventListener("click", function () { dismissEngagementPopup(popup, true); });
		popup.querySelector("#ajcore-engage-yes").addEventListener("click", function () {
			dismissEngagementPopup(popup, true);
			window.location.href = getSmsHref();
		});

		// Same unobtrusive auto-dismiss as the staff-reply preview bubble further down — but doesn't
		// mark it "remembered", so it's still eligible to show again on a later visit if ignored here.
		setTimeout(function () { dismissEngagementPopup(popup, false); }, 20000);
	}

	/** Staff asked to text this visitor: a card with a real sms: link. Tapping the link is the user gesture iOS needs. */
	function showTextPrompt(smsHref) {
		var existing = document.getElementById("ajcore-text-prompt");
		if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
		if (!document.getElementById("ajcore-text-prompt-style")) {
			var style = document.createElement("style");
			style.id = "ajcore-text-prompt-style";
			style.textContent =
				"#ajcore-text-prompt{position:fixed;left:16px;right:16px;bottom:84px;max-width:420px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 14px 40px rgba(0,0,0,.28);padding:16px;z-index:999999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}" +
				"#ajcore-text-prompt p{font-size:15px;color:#111827;margin:0 0 12px;line-height:1.4;}" +
				"#ajcore-text-prompt .aj-tp-actions{display:flex;gap:10px;}" +
				"#ajcore-text-prompt a.aj-tp-go{flex:2;display:flex;align-items:center;justify-content:center;background:#3157ff;color:#fff;border-radius:10px;padding:12px;font-size:15px;font-weight:700;text-decoration:none;}" +
				"#ajcore-text-prompt button.aj-tp-no{flex:1;background:#f1f5f9;color:#475569;border:none;border-radius:10px;padding:12px;font-size:14px;font-weight:600;cursor:pointer;}";
			document.head.appendChild(style);
		}
		var card = document.createElement("div");
		card.id = "ajcore-text-prompt";
		card.innerHTML =
			'<p>💬 Our team would like to chat with you by text. Tap below to open Messages.</p>' +
			'<div class="aj-tp-actions">' +
				'<button type="button" class="aj-tp-no">Not now</button>' +
				'<a class="aj-tp-go" href="' + escapeHtml(smsHref) + '">Text us</a>' +
			'</div>';
		document.body.appendChild(card);
		function close() { if (card.parentNode) card.parentNode.removeChild(card); }
		card.querySelector(".aj-tp-no").addEventListener("click", close);
		card.querySelector(".aj-tp-go").addEventListener("click", function () { setTimeout(close, 400); });
	}

	// ── "Live Visitors" self-identify prompt ────────────────────────────────
	// Same soft, one-time, dismissible-corner-card pattern as the engagement popup above, but asking
	// to leave name/email/phone rather than "want to text us?" — gated server-side by AJCore's
	// visitor_identify_enabled site setting (config.identifyEnabled), off by default. Submits over
	// the presence socket (sendIdentify, set inside startPresence()) rather than a direct HTTP call,
	// so it's authenticated the same way every other visitor-origin write already is: AJOps' server
	// relays it to AJCore using the service account, never the visitor's own browser.
	// A dismissal used to be a permanent "ajcore_identify_dismissed=1" block for that browser
	// forever. Now it's a timestamp + cooldown instead — a visitor who says "No thanks" but then
	// keeps browsing well past the cooldown window is a different signal than one who left, so
	// they're worth asking again rather than being silently written off for good.
	var STORAGE_IDENTIFY_DISMISSED_AT = "ajcore_identify_dismissed_at";
	var STORAGE_IDENTIFY_SUBMITTED = "ajcore_identify_submitted";
	var IDENTIFY_REASK_COOLDOWN_MS = 20 * 60 * 1000; // 20 minutes
	// Configurable from CP Settings > Live Chat > Live Visitors; falls back to 55s if an older
	// AJCore (before that setting existed) ever serves this file.
	var IDENTIFY_DELAY_MS = typeof config.identifyDelayMs === "number" ? config.identifyDelayMs : 55000;
	var IDENTIFY_RETRY_MS = 3000;
	var IDENTIFY_MAX_WAIT_MS = 60000;

	// Null once never dismissed / cooldown has fully elapsed; otherwise the ms still remaining.
	function identifyCooldownRemainingMs() {
		var at = parseInt(getStored(STORAGE_IDENTIFY_DISMISSED_AT) || "0", 10);
		if (!at) return 0;
		var remaining = IDENTIFY_REASK_COOLDOWN_MS - (Date.now() - at);
		return remaining > 0 ? remaining : 0;
	}

	function maybeShowIdentifyPopup() {
		if (!config.identifyEnabled) return;
		if (getStored(STORAGE_IDENTIFY_SUBMITTED) === "1") return;

		var waitedForEngagePopup = 0;
		function attempt() {
			if (getStored(STORAGE_IDENTIFY_SUBMITTED) === "1") return;
			if (typeof panelOpen !== "undefined" && panelOpen) return;
			// hasVisitorInfo means the desktop pre-chat form already collected this — asking again
			// would be redundant. Only meaningful on desktop (undefined on mobile, which has no
			// pre-chat form), same typeof guard the engagement popup above uses for the same reason.
			if (typeof hasVisitorInfo !== "undefined" && hasVisitorInfo) return;
			// Still cooling down from a "No thanks" — check back exactly when the cooldown ends
			// rather than giving up, so a visitor who stays on the site through the whole window
			// still gets asked again without needing a fresh page load.
			var cooldown = identifyCooldownRemainingMs();
			if (cooldown > 0) {
				setTimeout(attempt, cooldown);
				return;
			}
			// Don't stack two corner popups on top of each other — if the "want to text us?" nudge is
			// currently showing, wait it out rather than giving up: both delays are now staff-
			// configurable (CP Settings > Live Chat), so no fixed gap between them is guaranteed —
			// this is what silently ate the popup entirely when the two delays happened to coincide.
			if (document.getElementById("ajcore-engage-popup")) {
				if (waitedForEngagePopup < IDENTIFY_MAX_WAIT_MS) {
					waitedForEngagePopup += IDENTIFY_RETRY_MS;
					setTimeout(attempt, IDENTIFY_RETRY_MS);
				}
				return;
			}
			showIdentifyPopup();
		}
		setTimeout(attempt, IDENTIFY_DELAY_MS);
	}

	function showIdentifyPopup() {
		var style = document.createElement("style");
		style.textContent =
			"#ajcore-identify-popup{position:fixed;bottom:88px;right:20px;max-width:280px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:14px 16px;z-index:999997;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}" +
			"#ajcore-identify-popup .aj-identify-close{position:absolute;top:6px;right:8px;background:none;border:none;color:#9ca3af;font-size:14px;cursor:pointer;line-height:1;padding:2px;}" +
			"#ajcore-identify-popup .aj-identify-text{font-size:13px;color:#111827;margin:0 0 10px;padding-right:12px;line-height:1.4;}" +
			// font-size must stay >=16px — anything smaller makes iOS Safari auto-zoom the viewport on
			// focus, which inside a position:fixed popup like this one is what shows up as "the page
			// jumps/scrolls to the top" the instant you tap into a field.
			"#ajcore-identify-popup input{display:block;width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:9px;font-size:16px;margin-bottom:6px;font-family:inherit;}" +
			"#ajcore-identify-popup .aj-identify-actions{display:flex;gap:8px;margin-top:4px;}" +
			"#ajcore-identify-popup button.aj-identify-btn{flex:1;border-radius:8px;padding:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;}" +
			"#ajcore-identify-submit{background:#3157ff;color:#fff;}" +
			"#ajcore-identify-submit:disabled{background:#93a3ff;cursor:not-allowed;}" +
			"#ajcore-identify-no{background:#f1f5f9;color:#475569;}" +
			"#ajcore-identify-popup .aj-identify-thanks{font-size:13px;color:#065f46;margin:0;}" +
			"#ajcore-identify-popup .aj-identify-error{font-size:12px;color:#b91c1c;margin:6px 0 0;}";
		document.head.appendChild(style);

		var popup = document.createElement("div");
		popup.id = "ajcore-identify-popup";
		popup.innerHTML =
			'<button type="button" class="aj-identify-close" aria-label="Dismiss">✕</button>' +
			'<p class="aj-identify-text">Want us to follow up about your visit? Leave your info below — totally optional.</p>' +
			'<input type="text" id="ajcore-identify-name" placeholder="Name" autocomplete="name">' +
			'<input type="email" id="ajcore-identify-email" placeholder="you@example.com" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false">' +
			'<input type="tel" id="ajcore-identify-phone" placeholder="(704) 555-0123" autocomplete="tel" inputmode="tel" maxlength="20">' +
			'<div class="aj-identify-actions">' +
				'<button type="button" class="aj-identify-btn" id="ajcore-identify-no">No thanks</button>' +
				'<button type="button" class="aj-identify-btn" id="ajcore-identify-submit" disabled>Send</button>' +
			"</div>";
		document.body.appendChild(popup);

		var nameInput = popup.querySelector("#ajcore-identify-name");
		var emailInput = popup.querySelector("#ajcore-identify-email");
		var phoneInput = popup.querySelector("#ajcore-identify-phone");
		var submitBtn = popup.querySelector("#ajcore-identify-submit");

		// At least one way to reach them — a name alone isn't actionable for staff, and AJCore
		// rejects an identify submission with neither anyway (see identify_visitor() server-side).
		function refreshSubmitState() {
			submitBtn.disabled = !(emailInput.value.trim() || phoneInput.value.trim());
		}
		emailInput.addEventListener("input", refreshSubmitState);
		phoneInput.addEventListener("input", refreshSubmitState);
		bindPhoneFormat(phoneInput);

		function close() {
			if (popup.parentNode) popup.parentNode.removeChild(popup);
		}

		popup.querySelector(".aj-identify-close").addEventListener("click", function () {
			setStored(STORAGE_IDENTIFY_DISMISSED_AT, String(Date.now()));
			close();
		});
		popup.querySelector("#ajcore-identify-no").addEventListener("click", function () {
			setStored(STORAGE_IDENTIFY_DISMISSED_AT, String(Date.now()));
			close();
		});
		var errorEl = document.createElement("p");
		errorEl.className = "aj-identify-error";
		errorEl.style.display = "none";
		popup.appendChild(errorEl);

		var ACK_TIMEOUT_MS = 8000;

		function showError(message, logDetail) {
			// Logged for diagnostics (e.g. "AJOps' server.js or AJCore doesn't have this build yet")
			// — the visitor only ever sees the plain-language message below.
			console.error("[ajcore-chat-widget] identify submission failed:", logDetail);
			errorEl.textContent = message;
			errorEl.style.display = "block";
			nameInput.disabled = false;
			emailInput.disabled = false;
			phoneInput.disabled = false;
			submitBtn.disabled = false;
			submitBtn.textContent = "Send";
		}

		submitBtn.addEventListener("click", function () {
			var name = nameInput.value.trim() ? tidyName(nameInput.value) : "";
			var email = normalizeEmail(emailInput.value);
			var phone = formatPhone(phoneInput.value);
			if (!email && !phone) return;
			var problem = (name && !isValidName(name)) ? "Please enter your real name."
				: (email && emailProblem(email)) ? emailProblem(email)
				: (phone && !isValidPhone(phone)) ? "Please enter a valid phone number, like (704) 555-0123." : "";
			if (problem) {
				errorEl.textContent = problem;
				errorEl.style.display = "block";
				return;
			}

			errorEl.style.display = "none";
			nameInput.disabled = true;
			emailInput.disabled = true;
			phoneInput.disabled = true;
			submitBtn.disabled = true;
			submitBtn.textContent = "Sending…";

			var sent = sendIdentify ? sendIdentify({ name: name, email: email, phone: phone }) : false;
			if (!sent) {
				showError("Couldn't send right now — please try again.", "presence socket not open (sendIdentify returned false)");
				return;
			}

			var timedOut = setTimeout(function () {
				identifyAckHandler = null;
				showError("Couldn't confirm this went through — please try again.", "no identify_ack received within " + ACK_TIMEOUT_MS + "ms (AJOps and/or AJCore may not have this build deployed yet)");
			}, ACK_TIMEOUT_MS);

			identifyAckHandler = function (success) {
				clearTimeout(timedOut);
				if (!success) {
					showError("Something went wrong — please try again.", "AJCore returned success:false for the identify submission");
					return;
				}
				setStored(STORAGE_IDENTIFY_SUBMITTED, "1");
				popup.innerHTML = '<p class="aj-identify-thanks">Thanks' + (name ? ", " + escapeHtml(name) : "") + "! We'll be in touch.</p>";
				setTimeout(close, 4000);
			};
		});

		setTimeout(function () {
			if (getStored(STORAGE_IDENTIFY_SUBMITTED) !== "1" && !identifyAckHandler) close();
		}, 30000);
	}

	// ── Staff-pushed banner (predefined templates, sent live from AJOps' Live Monitor) ─────────
	// Same slide-in-corner-card visual language as the engagement popup, but content comes entirely
	// from the "show_banner" WS push (see startPresence() below) — staff picks a template, this just
	// renders whatever title/body arrives. Works identically for mobile (TEXT-bubble-only) and
	// desktop visitors since it only touches document.body, nothing chat-panel-specific.
	function showPushedBanner(title, body) {
		var existing = document.getElementById("ajcore-pushed-banner");
		if (existing && existing.parentNode) existing.parentNode.removeChild(existing);

		var style = document.createElement("style");
		style.textContent =
			"#ajcore-pushed-banner{position:fixed;bottom:88px;right:20px;max-width:280px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:14px 16px;z-index:999997;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}" +
			"#ajcore-pushed-banner .aj-banner-close{position:absolute;top:6px;right:8px;background:none;border:none;color:#9ca3af;font-size:14px;cursor:pointer;line-height:1;padding:2px;}" +
			"#ajcore-pushed-banner .aj-banner-title{font-size:13px;font-weight:700;color:#111827;margin:0 0 4px;padding-right:14px;}" +
			"#ajcore-pushed-banner .aj-banner-body{font-size:13px;color:#374151;line-height:1.4;margin:0;}";
		document.head.appendChild(style);

		var banner = document.createElement("div");
		banner.id = "ajcore-pushed-banner";
		banner.innerHTML =
			'<button type="button" class="aj-banner-close" aria-label="Dismiss">✕</button>' +
			( title ? '<p class="aj-banner-title"></p>' : '' ) +
			( body ? '<p class="aj-banner-body"></p>' : '' );
		document.body.appendChild(banner);
		// Set as text (not innerHTML) — title/body are staff-authored but arrive over the wire, same
		// caution as chat messages elsewhere in this file.
		if (title) banner.querySelector(".aj-banner-title").textContent = title;
		if (body) banner.querySelector(".aj-banner-body").textContent = body;

		banner.querySelector(".aj-banner-close").addEventListener("click", function () {
			if (banner.parentNode) banner.parentNode.removeChild(banner);
		});
		setTimeout(function () {
			if (banner.parentNode) banner.parentNode.removeChild(banner);
		}, 20000);
	}

	// ── Visitor-facing "visit timer" ────────────────────────────────────────
	// A tiny, unlabeled, barely-there number in a page corner showing cumulative seconds on this
	// site across every visit, not just this one — gated by config.timerEnabled (CP Settings > Live
	// Chat > Visit Timer, off by default). Seeded once from the "visit_stats" WS push (server.js,
	// sent right after AJCore resolves the real cumulative total) and ticks up locally every second
	// after that, same base+baseAt pattern used for the identify popup's own ack timeout elsewhere
	// in this file — no reason to round-trip AJCore every second just to redraw a number.
	var visitTimerBaseSeconds = 0;
	var visitTimerBaseAt = 0;
	var visitTimerEl = null;

	function renderVisitTimer() {
		if (!visitTimerEl) return;
		var seconds = visitTimerBaseSeconds + Math.floor((Date.now() - visitTimerBaseAt) / 1000);
		visitTimerEl.textContent = String(seconds);
	}

	function handleVisitStats(totalSeconds) {
		if (!config.timerEnabled) return;
		visitTimerBaseSeconds = totalSeconds;
		visitTimerBaseAt = Date.now();
		if (!visitTimerEl) {
			var style = document.createElement("style");
			// Deliberately no card/border/background — just faint text sitting on the page, opposite
			// corner from the chat bubble/popups (all bottom-right) so it never collides with them.
			style.textContent =
				"#ajcore-visit-timer{position:fixed;bottom:8px;left:10px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:10px;color:#9ca3af;opacity:.55;z-index:999996;pointer-events:none;user-select:none;}";
			document.head.appendChild(style);
			visitTimerEl = document.createElement("div");
			visitTimerEl.id = "ajcore-visit-timer";
			document.body.appendChild(visitTimerEl);
			setInterval(renderVisitTimer, 1000);
		}
		renderVisitTimer();
	}

	// ── Passive visitor presence ("Live Monitor" in AJOps) ────────────────────
	// Deliberately ahead of the mobile/desktop branch below — presence tracks every visitor who
	// loads the page, not just ones who get the full chat widget. Entirely separate connection from
	// the chat session WebSocket further down: opens immediately, independent of whether this
	// visitor ever opens the chat bubble at all. handleOpenChat is device-specific (called by each
	// branch below, once its own UI exists) so a staff "prompt chat" push does the right thing
	// whether this visitor has the full widget or just the mobile TEXT bubble.
	var STORAGE_VISITOR_UUID = "ajcore_visitor_uuid";
	var visitorUuid = getStored(STORAGE_VISITOR_UUID);
	if (!visitorUuid) {
		visitorUuid = uuid();
		setStored(STORAGE_VISITOR_UUID, visitorUuid);
	}

	// Set by startPresence() below, once its presenceWs closure exists — posts an "identify"
	// message down the visitor's already-open presence socket (see showIdentifyPopup() further
	// down). null/no-op before startPresence() runs, or whenever the socket happens to be
	// reconnecting when the visitor hits Send; that's fine, showIdentifyPopup()'s own submit
	// handler treats a false return the same as a failure/timeout.
	var sendIdentify = null;

	// One-shot callback showIdentifyPopup() registers right before calling sendIdentify(), invoked
	// from presenceWs.onmessage below when AJOps relays AJCore's real "did this actually save"
	// answer back down the wire — this is what makes Send report success/failure honestly instead
	// of always showing "Thanks!" the instant it's clicked, regardless of what happened server-side.
	var identifyAckHandler = null;

	function startPresence(handleOpenChat) {
		var presenceWs = null;
		var reconnectTimer = null;

		sendIdentify = function (info) {
			if (presenceWs && presenceWs.readyState === WebSocket.OPEN) {
				presenceWs.send(JSON.stringify({ type: "identify", name: info.name, email: info.email, phone: info.phone }));
				return true;
			}
			return false;
		};

		function url() {
			var base = config.serverUrl.replace(/^http/, "ws");
			return base + "/chat-ws?role=presence" +
				"&visitor_uuid=" + encodeURIComponent(visitorUuid) +
				"&site_uuid=" + encodeURIComponent(config.siteUuid) +
				"&page=" + encodeURIComponent(window.location.href) +
				"&referrer=" + encodeURIComponent(document.referrer || "");
		}

		function scheduleReconnect() {
			if (reconnectTimer) return;
			reconnectTimer = setTimeout(function () {
				reconnectTimer = null;
				connect();
			}, 5000);
		}

		function connect() {
			try {
				presenceWs = new WebSocket(url());
			} catch (e) {
				scheduleReconnect();
				return;
			}
			presenceWs.onmessage = function (event) {
				var payload;
				try { payload = JSON.parse(event.data); } catch (e) { return; }
				if (payload && payload.type === "open_chat" && handleOpenChat) {
					handleOpenChat(payload.message || "");
				} else if (payload && payload.type === "ask_info") {
					// Staff asked for contact info (phone visitors have no chat panel): show the details card.
					if (!document.getElementById("ajcore-identify-popup")) showIdentifyPopup();
				} else if (payload && payload.type === "show_banner") {
					showPushedBanner(payload.title || "", payload.body || "");
				} else if (payload && payload.type === "identify_ack" && identifyAckHandler) {
					var handler = identifyAckHandler;
					identifyAckHandler = null;
					handler(!!payload.success);
				} else if (payload && payload.type === "visit_stats") {
					handleVisitStats(Number(payload.totalSeconds) || 0);
				}
			};
			presenceWs.onclose = function () {
				presenceWs = null;
				scheduleReconnect();
			};
			presenceWs.onerror = function () {
				if (presenceWs) presenceWs.close();
			};
		}
		connect();

		// A visitor who leaves this tab backgrounded for a while, then returns, may find the socket
		// already dead (mobile Safari/Chrome both suspend background WS connections) — check and
		// reconnect on refocus rather than waiting for the visitor to notice they've gone "offline".
		document.addEventListener("visibilitychange", function () {
			if (document.visibilityState === "visible" && (!presenceWs || presenceWs.readyState === WebSocket.CLOSED)) {
				connect();
			}
		});
	}

	// Desktop/tablet only past this point — the "Mobile" token is what actually distinguishes
	// phones from tablets here: iPadOS Safari's UA reports as a plain desktop Mac (no match), and
	// Android tablets omit "Mobile" the way Android phones include it, so this doesn't
	// accidentally affect larger touch devices, only phones.
	//
	// Phones get a lightweight standalone "TEXT" bubble instead of the full CHAT widget — no
	// DOM/WebSocket for the chat panel/session machinery below at all, just a floating button in
	// the same spot that hands off straight to SMS. This is a plugin-level default (every site
	// embedding this widget gets it automatically), not something themes need to add per-site.
	if (/iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini|IEMobile/i.test(navigator.userAgent || "")) {
		var mobileTextNumber = "+17043072135";
		var mobileStyle = document.createElement("style");
		mobileStyle.textContent =
			"#ajcore-chat-bubble{position:fixed;bottom:20px;right:20px;min-width:64px;height:44px;padding:0 18px;border-radius:22px;background:#3157ff;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.2);z-index:999998;font-size:13px;font-weight:700;letter-spacing:.06em;border:none;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;text-decoration:none;}";
		document.head.appendChild(mobileStyle);

		var mobileBubble = document.createElement("a");
		mobileBubble.id = "ajcore-chat-bubble";
		mobileBubble.setAttribute("aria-label", "Text us");
		mobileBubble.textContent = "TEXT";
		mobileBubble.href = "sms:" + mobileTextNumber + "?body=" + encodeURIComponent(defaultTextUsMessage());
		document.body.appendChild(mobileBubble);
		// A staff "prompt chat" push has no widget panel to open on mobile — send them straight to
		// the same SMS handoff the TEXT bubble itself offers, with an optional staff-provided lead-in.
		// iOS Safari refuses to open sms: from a script ("address is invalid") unless it comes from the visitor's own
		// tap, so a staff push shows a prompt with a real link they tap instead of navigating on its own.
		startPresence(function (message) {
			var body = message || defaultTextUsMessage();
			showTextPrompt("sms:" + mobileTextNumber + "?body=" + encodeURIComponent(body));
		});
		maybeShowEngagementPopup(function () { return mobileBubble.href; });
		maybeShowIdentifyPopup();
		return;
	}

	var STORAGE_SESSION = "ajcore_chat_session_uuid";
	var STORAGE_NAME = "ajcore_chat_visitor_name";
	var STORAGE_EMAIL = "ajcore_chat_visitor_email";
	var STORAGE_PHONE = "ajcore_chat_visitor_phone";
	var STORAGE_PANEL_OPEN = "ajcore_chat_panel_open";

	var sessionUuid = getStored(STORAGE_SESSION);
	if (!sessionUuid) {
		sessionUuid = uuid();
		setStored(STORAGE_SESSION, sessionUuid);
	}

	// A logged-in Client Portal customer's identity (server-supplied via config.knownName/
	// knownEmail/knownPhone — see ajcore_render_chat_widget()) always wins over whatever's cached
	// in localStorage from some earlier anonymous visit on this same browser, since it's the
	// actual account they're signed into right now, not a guess. Not written back to localStorage
	// here — it's recomputed fresh from the server on every logged-in pageview, and leaving a
	// signed-out visitor's cached values alone avoids a signed-in customer's name/email lingering
	// in localStorage for whoever uses this browser next.
	var visitorName = config.knownName || getStored(STORAGE_NAME);
	var visitorEmail = config.knownEmail || getStored(STORAGE_EMAIL);
	var visitorPhone = config.knownPhone || getStored(STORAGE_PHONE);
	var hasVisitorInfo = !!(visitorName || visitorEmail || visitorPhone);

	// ── Styles ────────────────────────────────────────────────────────────────
	var style = document.createElement("style");
	style.textContent =
		"#ajcore-chat-bubble{position:fixed;bottom:20px;right:20px;min-width:64px;height:44px;padding:0 18px;border-radius:22px;background:#3157ff;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.2);z-index:999998;font-size:13px;font-weight:700;letter-spacing:.06em;border:none;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}" +
		"#ajcore-chat-bubble.offline{background:#0f172a;}" +
		"#ajcore-chat-panel{position:fixed;bottom:88px;right:20px;width:340px;max-width:calc(100vw - 40px);height:460px;max-height:calc(100vh - 120px);background:#fff;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden;z-index:999999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}" +
		"#ajcore-chat-panel.open{display:flex;}" +
		"#ajcore-chat-header{background:#3157ff;color:#fff;padding:14px 16px;font-weight:600;font-size:14px;display:flex;justify-content:space-between;align-items:center;gap:8px;}" +
		"#ajcore-chat-header-actions{display:flex;align-items:center;gap:10px;}" +
		"#ajcore-chat-textus{color:#fff;font-size:11px;font-weight:600;text-decoration:underline;white-space:nowrap;cursor:pointer;}" +
		"#ajcore-chat-notify{background:none;border:none;color:rgba(255,255,255,.9);font-size:15px;cursor:pointer;padding:0;line-height:1;}" +
		"#ajcore-chat-notify.enabled{color:#bbf7d0;}" +
		"#ajcore-chat-end{background:#fff;border:1px solid #d1d5db;border-radius:8px;color:#6b7280;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;padding:9px 12px;white-space:nowrap;display:none;}" +
		"#ajcore-chat-end:hover{background:#fef2f2;border-color:#fca5a5;color:#b91c1c;}" +
		"#ajcore-chat-close{background:none;border:none;color:#fff;font-size:18px;cursor:pointer;line-height:1;}" +
		"#ajcore-chat-body{flex:1;overflow-y:auto;padding:14px;background:#f8fafc;}" +
		"#ajcore-chat-form{padding:14px;display:flex;flex-direction:column;gap:8px;}" +
		"#ajcore-chat-form input,#ajcore-chat-form textarea{border:1px solid #d1d5db;border-radius:8px;padding:9px 11px;font-size:13px;font-family:inherit;}" +
		"#ajcore-chat-form textarea{resize:none;}" +
		".aj-invalid{border-color:#dc2626 !important;background:#fef2f2;}" +
		".aj-field-error{display:none;color:#dc2626;font-size:11px;line-height:1.3;margin-top:-4px;}" +
		".aj-field-error.show{display:block;}" +
		"#ajcore-chat-form button{background:#3157ff;color:#fff;border:none;border-radius:8px;padding:10px;font-size:13px;font-weight:600;cursor:pointer;}" +
		"#ajcore-chat-end-overlay{position:absolute;inset:0;background:rgba(255,255,255,.98);display:none;align-items:center;justify-content:center;padding:20px;z-index:6;}" +
		"#ajcore-chat-end-overlay.show{display:flex;}" +
		".aj-end-modal{width:100%;display:flex;flex-direction:column;gap:8px;}" +
		".aj-end-title{font-size:14px;font-weight:700;color:#0f172a;margin:0;}" +
		".aj-end-sub{font-size:12px;color:#6b7280;margin:0 0 4px;}" +
		".aj-end-option{display:flex;align-items:center;gap:8px;font-size:13px;color:#111827;cursor:pointer;}" +
		"#aj-end-email,#aj-end-phone{border:1px solid #d1d5db;border-radius:8px;padding:9px 11px;font-size:13px;font-family:inherit;width:100%;box-sizing:border-box;}" +
		".aj-end-actions{display:flex;gap:8px;margin-top:6px;}" +
		".aj-end-actions button{flex:1;border-radius:8px;padding:9px;font-size:13px;font-weight:600;cursor:pointer;border:none;}" +
		"#aj-end-cancel{background:#e5e7eb;color:#111827;}" +
		"#aj-end-confirm{background:#dc2626;color:#fff;}" +
		".ajcore-chat-msg{margin:0 0 8px;max-width:82%;padding:8px 11px;border-radius:10px;font-size:13px;line-height:1.4;word-wrap:break-word;white-space:pre-wrap;}" +
		".ajcore-chat-msg a{color:inherit;text-decoration:underline;word-break:break-all;}" +
		".ajcore-chat-meta{font-size:10px;color:#9ca3af;margin:-4px 2px 8px;}" +
		".ajcore-chat-meta.visitor{text-align:right;}" +
		".ajcore-chat-msg.aj-dots{display:inline-flex;gap:4px;align-items:center;padding:11px 12px;}" +
		".ajcore-chat-msg.aj-dots span{width:6px;height:6px;border-radius:50%;background:#9ca3af;animation:ajdots 1.2s infinite ease-in-out;}" +
		".ajcore-chat-msg.aj-dots span:nth-child(2){animation-delay:.2s;}.ajcore-chat-msg.aj-dots span:nth-child(3){animation-delay:.4s;}" +
		"@keyframes ajdots{0%,60%,100%{opacity:.25;transform:translateY(0);}30%{opacity:1;transform:translateY(-3px);}}" +
		"#ajcore-chat-chips,.aj-chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px;}" +
		"#ajcore-chat-panel .aj-chip,#ajcore-chat-form button.aj-chip{background:#fff;border:1px solid #3157ff;color:#3157ff;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;}" +
		"#ajcore-chat-panel .aj-chip:hover,#ajcore-chat-form button.aj-chip:hover{background:#3157ff;color:#fff;}" +
		".aj-status{font-size:11px;color:#6b7280;text-align:center;margin:0 0 10px;}" +
		".aj-status.online:before{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;background:#22c55e;margin-right:6px;vertical-align:1px;}" +
		".aj-pick-hint{font-size:12px;color:#6b7280;margin:2px 0 -2px;}" +
		"#ajcore-chat-panel .aj-chip.on,#ajcore-chat-form button.aj-chip.on{background:#3157ff;color:#fff;}" +
		"#ajcore-chat-form #ajcore-chat-start{position:sticky;bottom:0;box-shadow:0 -8px 10px -4px #f8fafc;z-index:2;}" +
		".aj-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0;pointer-events:none;}" +
		".aj-inwrap{flex:1;position:relative;display:flex;}" +
		".aj-inwrap textarea{width:100%;box-sizing:border-box;}" +
		"#ajcore-chat-count{position:absolute;right:8px;bottom:3px;font-size:10px;color:#9ca3af;display:none;pointer-events:none;}" +
		"#ajcore-chat-send:disabled{opacity:.45;cursor:default;}" +
		".aj-rate{display:flex;align-items:center;gap:8px;font-size:12px;color:#6b7280;}" +
		".aj-rate button{background:#fff;border:1px solid #d1d5db;border-radius:8px;font-size:16px;padding:3px 9px;cursor:pointer;}" +
		".aj-rate button.on{border-color:#3157ff;background:#eef2ff;}" +
		".ajcore-chat-msg.visitor{background:#3157ff;color:#fff;margin-left:auto;}" +
		".ajcore-chat-msg.staff{background:#e5e7eb;color:#0f172a;margin-right:auto;}" +
		"#ajcore-chat-inputrow{border-top:1px solid #e5e7eb;padding:10px;display:flex;gap:8px;align-items:flex-end;}" +
		"#ajcore-chat-input{flex:1;border:1px solid #d1d5db;border-radius:8px;padding:9px 11px;font-size:13px;font-family:inherit;resize:none;max-height:110px;overflow-y:auto;}" +
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
	bubble.innerHTML = '<span id="ajcore-chat-bubble-label">CHAT</span><span id="ajcore-chat-badge"></span>';
	document.body.appendChild(bubble);
	var badge = bubble.querySelector("#ajcore-chat-badge");
	var bubbleLabel = bubble.querySelector("#ajcore-chat-bubble-label");

	// Swaps the launcher's label/color to "TEXT" outside business hours so a visitor sees at a
	// glance that live chat won't be answered right now and can go straight to SMS instead of
	// opening the panel to discover the offline banner. No-op (always "CHAT") when the site owner
	// hasn't turned business hours on, matching isWithinBusinessHours()'s own default-open behavior.
	function updateBubbleLabel() {
		var offline = !isWithinBusinessHours();
		bubbleLabel.textContent = offline ? "TEXT" : "CHAT";
		bubble.classList.toggle("offline", offline);
		bubble.setAttribute("aria-label", offline ? "Text us" : "Open chat");
	}
	updateBubbleLabel();
	if (config.businessHoursEnabled) {
		setInterval(updateBubbleLabel, 60000);
	}

	// Outside the panel itself, so a reply is visible even while the panel is closed/minimized.
	var preview = document.createElement("div");
	preview.id = "ajcore-chat-preview";
	document.body.appendChild(preview);

	var panel = document.createElement("div");
	panel.id = "ajcore-chat-panel";
	panel.innerHTML =
		'<div id="ajcore-chat-header"><span>Chat with us</span><div id="ajcore-chat-header-actions"><a id="ajcore-chat-textus" href="#" aria-label="Text us instead of chatting">Text Us</a><button id="ajcore-chat-notify" type="button" aria-label="Enable desktop notifications" title="Enable desktop notifications">🔔</button><button id="ajcore-chat-close" aria-label="Close chat">✕</button></div></div>' +
		'<div id="ajcore-chat-body"></div>';
	document.body.appendChild(panel);

	var header = panel.querySelector("#ajcore-chat-header");
	var body = panel.querySelector("#ajcore-chat-body");
	var closeBtn = panel.querySelector("#ajcore-chat-close");
	var endChatBtn = document.createElement("button"); // lives in the input row, right of Send
	endChatBtn.id = "ajcore-chat-end";
	endChatBtn.type = "button";
	endChatBtn.textContent = "End Chat";
	var notifyBtn = panel.querySelector("#ajcore-chat-notify");
	var textUsBtn = panel.querySelector("#ajcore-chat-textus");

	// Same number the portal's own "Text Us" quick action already uses (class-ajforms.php) — lets a
	// visitor bail out of the web widget and text the business directly instead, from right where
	// they already are. href is computed fresh on click (not just once at init) since visitorName
	// isn't known yet until the pre-chat form is submitted.
	var TEXT_US_NUMBER = "+17043072135";
	function textUsHref(msg) {
		return "sms:" + TEXT_US_NUMBER + "?body=" + encodeURIComponent(msg);
	}
	textUsBtn.addEventListener("click", function () {
		var host = siteHost();
		var onSite = host ? " on your website " + host : " on your website";
		var msg = visitorName
			? "Hi, I was chatting" + onSite + " and wanted to switch to texting instead. My name is " + visitorName + "."
			: "Hi, I was chatting" + onSite + " and wanted to switch to texting instead.";
		textUsBtn.href = textUsHref(msg);
	});

	var panelOpen = false;
	var unreadCount = 0;
	// Whether the CURRENT session is still open — governs both the "End Chat" button's visibility
	// and the beforeunload warning, so closing the tab only warns while there's actually something
	// active to lose (not forever, just because this visitor has chatted at some point in the past).
	var sessionOpen = false;

	function updateEndChatVisibility() {
		endChatBtn.style.display = (hasVisitorInfo && sessionOpen) ? "inline-block" : "none";
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
		clearTitleAlert();
		setStored(STORAGE_PANEL_OPEN, "1");
	}
	function closePanel() {
		panel.classList.remove("open");
		panelOpen = false;
		setStored(STORAGE_PANEL_OPEN, "");
	}

	bubble.addEventListener("click", function () {
		if (!isWithinBusinessHours()) {
			var host = siteHost();
			var onSite = host ? " on your website " + host : " on your website";
			var msg = visitorName
				? "Hi, I'd like to text instead of chatting online" + onSite + ". My name is " + visitorName + "."
				: "Hi, I'd like to text instead of chatting online" + onSite + ".";
			window.location.href = textUsHref(msg);
			return;
		}
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
				if (payload.message.senderType === "staff") hideReplyDots();
				if (payload.message.senderType === "visitor" && pendingOwnMessages.length && pendingOwnMessages[0] === payload.message.body) {
					// This is the server echo of a message we already rendered optimistically below
					// — skip re-rendering it (would otherwise double-print), but still consume it
					// from the queue. A "visitor" message that DOESN'T match the front of the queue
					// is a genuine multi-tab case (same visitor, sent from a different tab) and
					// should render normally — see the queue's other consumer in sendMessage().
					pendingOwnMessages.shift();
					if (pendingMetas.length) { var pm = pendingMetas.shift(); setMetaStatus(pm.meta, pm.time, "Delivered"); }
				} else {
					var shown = appendMessage(payload.message.senderType, payload.message.body, { time: new Date() });
					if (shown && payload.message.senderType === "staff") {
						var chips = deriveChips(payload.message.body);
						if (chips.length) showChips(chips, function (label) { clearChips(); sendMessage(label); });
					}
				}
				if (payload.message.senderType === "staff") {
					if (document.hidden || !panelOpen) { titleUnread += 1; refreshTitle(); }
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
		var now = new Date();
		var shown = appendMessage("visitor", text, { time: now, status: "Sending…" });
		pendingOwnMessages.push(text);
		if (shown && shown.meta) pendingMetas.push({ meta: shown.meta, time: now });
		if (ws && ws.readyState === WebSocket.OPEN) {
			ws.send(JSON.stringify(payload));
			if (shown) setMetaStatus(shown.meta, now, "Sent");
		} else {
			pendingSend = payload;
			connect();
		}
		showReplyDots();
	}
	var pendingMetas = [];

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
	// Throwaway / placeholder domains and local parts that are never a real contact.
	var JUNK_EMAIL_DOMAINS = /^(example\.(com|org|net)|test\.com|testing\.com|email\.com|mail\.com|domain\.com|none\.com|noemail\.com|fake\.com|asdf\.com|abc\.com|xyz\.com|a\.com|aa\.com|mailinator\.com|guerrillamail\.[a-z]+|10minutemail\.[a-z]+|tempmail\.[a-z]+|temp-mail\.org|yopmail\.com|trashmail\.com|sharklasers\.com|getnada\.com|throwawaymail\.com|dispostable\.com|maildrop\.cc)$/;
	var JUNK_EMAIL_LOCALS = /^(test|testing|tester|asdf|asdfg|asdfgh|qwerty|qwe|abc|abcd|abc123|aaa|xxx|xyz|none|noemail|no|na|nope|fake|fakeemail|email|name|user|me|my|you|yes|null|nil|nobody|nothing|temp|junk|spam|sample|example|1234|12345|123456)$/;
	var EMAIL_DOMAIN_TYPOS = {
		"gmial.com": "gmail.com", "gmai.com": "gmail.com", "gmail.co": "gmail.com", "gamil.com": "gmail.com",
		"gnail.com": "gmail.com", "gmail.con": "gmail.com", "gmail.cm": "gmail.com", "gmil.com": "gmail.com",
		"yaho.com": "yahoo.com", "yahooo.com": "yahoo.com", "yahoo.co": "yahoo.com", "yahoo.con": "yahoo.com",
		"hotmial.com": "hotmail.com", "hotmai.com": "hotmail.com", "hotmail.con": "hotmail.com",
		"outlok.com": "outlook.com", "outlook.con": "outlook.com", "iclod.com": "icloud.com", "icloud.con": "icloud.com"
	};
	function normalizeEmail(value) {
		return String(value || "").trim().toLowerCase().replace(/\s+/g, "");
	}
	// Returns "" when the address looks real, otherwise a short plain-language reason.
	function emailProblem(value) {
		var v = normalizeEmail(value);
		if (!v) return "Please enter your email address.";
		var m = /^([a-z0-9._%+\-]+)@([a-z0-9.\-]+)$/.exec(v);
		var fallback = "Please enter a valid email address, like you@example.com.";
		if (!m) return fallback;
		var local = m[1], domain = m[2];
		if (local.length < 2 || local.length > 64 || /^\.|\.$|\.\./.test(local)) return fallback;
		var labels = domain.split(".");
		if (labels.length < 2 || domain.length > 253) return fallback;
		for (var i = 0; i < labels.length; i++) {
			if (!/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/.test(labels[i])) return fallback;
		}
		if (!/^[a-z]{2,24}$/.test(labels[labels.length - 1])) return fallback;
		if (EMAIL_DOMAIN_TYPOS[domain]) return "Did you mean " + local + "@" + EMAIL_DOMAIN_TYPOS[domain] + "?";
		if (JUNK_EMAIL_DOMAINS.test(domain) || JUNK_EMAIL_LOCALS.test(local) || /^(.)\1+$/.test(local.replace(/[._%+\-]/g, ""))) {
			return "Please use your real email address so we can reach you.";
		}
		return "";
	}
	function isValidEmail(value) {
		return emailProblem(value) === "";
	}
	// Live "(704) 555-0123" formatting for US/Canada numbers; "+" numbers (international) are kept
	// as typed with only junk characters removed.
	function formatPhone(value) {
		var raw = String(value || "");
		if (/^\s*\+(?!1\b)/.test(raw) && !/^\s*\+1[\s\-.(]?\d/.test(raw)) {
			return "+" + raw.replace(/[^0-9]/g, "").slice(0, 15);
		}
		var d = raw.replace(/[^0-9]/g, "");
		if (d.length > 10 && d.charAt(0) === "1") d = d.slice(1);
		d = d.slice(0, 10);
		if (d.length === 0) return "";
		if (d.length < 4) return "(" + d;
		if (d.length < 7) return "(" + d.slice(0, 3) + ") " + d.slice(3);
		return "(" + d.slice(0, 3) + ") " + d.slice(3, 6) + "-" + d.slice(6);
	}
	function bindPhoneFormat(input) {
		input.addEventListener("input", function () {
			var f = formatPhone(input.value);
			if (f !== input.value) input.value = f;
		});
	}
	function isValidName(value) {
		var v = String(value || "").trim();
		if (v.length < 2 || v.length > 80) return false;
		if (!/[A-Za-z\u00C0-\u024F]{2}/.test(v)) return false;
		if (/\d{3,}|[<>@{}\[\]\\\/]/.test(v)) return false;
		if (/^(.)\1+$/.test(v.replace(/\s/g, ""))) return false;
		return !/^(test|testing|asdf|asdfg|qwerty|abc|none|fake|name|na|no|xxx)$/i.test(v);
	}
	// "sandip nanda" / "SANDIP NANDA" -> "Sandip Nanda"; mixed case (McDonald) is left alone.
	function tidyName(value) {
		var v = String(value || "").trim().replace(/\s+/g, " ");
		if (v === v.toLowerCase() || v === v.toUpperCase()) {
			v = v.toLowerCase().replace(/(^|[\s\-'])([a-z\u00e0-\u024f])/g, function (_, a, b) { return a + b.toUpperCase(); });
		}
		return v;
	}
	function isValidPhone(value) {
		// Reject letters/symbols outright, then require a real dialable digit count — 10 (US local)
		// up to 15 (E.164 max, for international visitors with a country code).
		if (!/^[0-9+()\-.\s]+$/.test(value)) return false;
		var digits = value.replace(/[^0-9]/g, "");
		if (digits.length < 10 || digits.length > 15) return false;
		// Same digit repeated the whole way through ("1111111111", "111111111111") is never a real
		// number at any length, so this check runs before/independent of the NANP check below.
		if (/^(\d)\1+$/.test(digits)) return false;
		// A bare 10-digit number, or 11 with a leading country code "1", is meant to be a North
		// American number — apply NANP's actual structure: both the area code and exchange code
		// must start with 2-9 (0/1 are reserved prefixes), which is what actually flags something
		// like "1231231234" as fake even though it has the right digit count and no repeated digit.
		// International numbers of other lengths skip this (their own numbering rules don't apply).
		var nanp = digits.length === 11 && digits.charAt(0) === "1" ? digits.slice(1) : digits;
		if (nanp.length === 10 && !/^[2-9]\d{2}[2-9]\d{6}$/.test(nanp)) return false;
		return true;
	}

	// ── Rendering ────────────────────────────────────────────────────────────
	function escapeHtml(s) {
		var d = document.createElement("div");
		d.innerText = s || "";
		return d.innerHTML;
	}

	var LEAD_LINE = "Your details have been shared with our team.";
	var leadLineShown = false;
	var lastGroup = null; // { sender, minute, metaEl }

	// Escapes first, then turns **bold**, links, emails and phone numbers into markup — never
	// inserts raw message text as HTML.
	function formatBody(text) {
		var safe = escapeHtml(text);
		return safe.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)])|([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})|((?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4})|\*\*([^*\n]+)\*\*/g, function (m, url, email, phone, bold) {
			if (url) return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + "</a>";
			if (email) return '<a href="mailto:' + email + '">' + email + "</a>";
			if (phone) return '<a href="tel:' + phone.replace(/[^0-9+]/g, "") + '">' + phone + "</a>";
			if (bold) return "<strong>" + bold + "</strong>";
			return m;
		});
	}

	function clearChips() {
		var old = body.querySelectorAll(".aj-chips");
		for (var i = 0; i < old.length; i++) old[i].parentNode.removeChild(old[i]);
	}

	function fmtTime(d) {
		var h = d.getHours(), m = d.getMinutes();
		return (h % 12 || 12) + ":" + (m < 10 ? "0" : "") + m + " " + (h < 12 ? "AM" : "PM");
	}

	// opts.time (Date) shows a small time under the last message of a group; opts.status adds
	// "Sending…/Sent" for the visitor's own messages. Returns { el, meta } (or null if skipped).
	function appendMessage(senderType, text, opts) {
		opts = opts || {};
		var sender = senderType === "staff" ? "staff" : "visitor";
		clearChips();
		if (sender === "staff") {
			// The system appends the "details shared" confirmation on every lead save; once is enough.
			if (text.indexOf(LEAD_LINE) !== -1) {
				if (leadLineShown) text = text.replace(LEAD_LINE, "").replace(/\s+$/, "");
				else leadLineShown = true;
				if (!text.trim()) return null;
			}
		}
		var el = document.createElement("div");
		el.className = "ajcore-chat-msg " + sender;
		el.innerHTML = formatBody(text);
		body.appendChild(el);
		var meta = null;
		if (opts.time || opts.status) {
			var minute = opts.time ? Math.floor(opts.time.getTime() / 60000) : null;
			if (lastGroup && lastGroup.sender === sender && lastGroup.minute === minute && lastGroup.metaEl && lastGroup.metaEl.parentNode) {
				lastGroup.metaEl.parentNode.removeChild(lastGroup.metaEl);
			}
			meta = document.createElement("div");
			meta.className = "ajcore-chat-meta " + sender;
			var t = opts.time ? fmtTime(opts.time) : "";
			meta.textContent = opts.status ? (t ? t + " · " : "") + opts.status : t;
			body.appendChild(meta);
			lastGroup = { sender: sender, minute: minute, metaEl: meta };
		} else {
			lastGroup = null;
		}
		body.scrollTop = body.scrollHeight;
		return { el: el, meta: meta };
	}

	function setMetaStatus(meta, time, status) {
		if (meta) meta.textContent = fmtTime(time) + " · " + status;
	}

	// ── Quick-reply chips ────────────────────────────────────────────────────
	// Derives tappable answers from the assistant's last question: "A, B, or C?" becomes chips for
	// A/B/C, a plain yes/no question becomes Yes / No. Anything else shows no chips.
	function deriveChips(text) {
		var plain = String(text || "").replace(LEAD_LINE, "").trim();
		var sentences = plain.match(/[^.!?\n]+\?/g);
		if (!sentences) return [];
		var q = sentences[sentences.length - 1].trim();
		if (!/\sor\s/i.test(q)) {
			return /^(do|does|did|would|are|is|can|could|will|have|should|may|want|shall)\b/i.test(q) && q.length < 160 ? ["Yes", "No"] : [];
		}
		var parts = q.replace(/\?$/, "").split(/,\s*(?:or\s+)?|\s+or\s+/i).map(function (x) { return x.trim(); }).filter(Boolean);
		if (parts.length < 2 || parts.length > 4) return [];
		parts[0] = parts[0].replace(/^.*\b(?:about|for|in|is it|are you|to|between|either|prefer|like|want|need|choose|a|an|the)\s+/i, "");
		for (var i = 0; i < parts.length; i++) {
			parts[i] = parts[i].replace(/^(a|an|the)\s+/i, "");
			if (!parts[i] || parts[i].length > 30 || parts[i].split(/\s+/).length > 4) return [];
			parts[i] = parts[i].charAt(0).toUpperCase() + parts[i].slice(1);
		}
		return parts;
	}
	function showChips(labels, onPick) {
		clearChips();
		if (!labels || !labels.length) return;
		var wrap = document.createElement("div");
		wrap.className = "aj-chips";
		labels.forEach(function (label) {
			var b = document.createElement("button");
			b.type = "button";
			b.className = "aj-chip";
			b.textContent = label;
			b.addEventListener("click", function () { onPick(label); });
			wrap.appendChild(b);
		});
		body.appendChild(wrap);
		body.scrollTop = body.scrollHeight;
	}

	// ── "Replying…" dots while waiting for the assistant/staff ───────────────
	var dotsEl = null, dotsTimer = null;
	function showReplyDots() {
		hideReplyDots();
		dotsEl = document.createElement("div");
		dotsEl.className = "ajcore-chat-msg staff aj-dots";
		dotsEl.innerHTML = "<span></span><span></span><span></span>";
		body.appendChild(dotsEl);
		body.scrollTop = body.scrollHeight;
		dotsTimer = setTimeout(hideReplyDots, 30000);
	}
	function hideReplyDots() {
		if (dotsEl && dotsEl.parentNode) dotsEl.parentNode.removeChild(dotsEl);
		dotsEl = null;
		if (dotsTimer) { clearTimeout(dotsTimer); dotsTimer = null; }
	}

	// ── Tab title alert while a reply is unread ──────────────────────────────
	var baseTitle = document.title;
	var titleUnread = 0;
	function refreshTitle() {
		document.title = titleUnread > 0 ? "(" + titleUnread + ") New message — " + baseTitle : baseTitle;
	}
	function clearTitleAlert() { titleUnread = 0; refreshTitle(); }
	document.addEventListener("visibilitychange", function () {
		if (!document.hidden && panelOpen) clearTitleAlert();
	});
	window.addEventListener("focus", function () { if (panelOpen) clearTitleAlert(); });

	function updateHeaderTitle() {
		var t = header && header.querySelector("span");
		if (t) t.textContent = visitorName ? "Hi " + visitorName.split(" ")[0] + " — chat with us" : "Chat with us";
	}

	// Restores a returning visitor's prior messages on a fresh tab/reload — the widget already
	// reconnects to the same session_uuid, but previously started from an empty panel every time.
	function loadHistory() {
		fetch(config.serverUrl + "/api/chat/history?session_uuid=" + encodeURIComponent(sessionUuid))
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data) return;
				if (Array.isArray(data.messages)) {
					leadLineShown = false;
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

	// ── End Chat / transcript prompt ─────────────────────────────────────────
	// Built lazily (once) on first "End Chat" click rather than at widget init, since most visitors
	// never end their chat this way (tab close/navigation is far more common) — no point paying for
	// the extra DOM on every page load.
	var endOverlay = null;
	var endEmailInput, endPhoneInput, endEmailError, endPhoneError, endRating = "";

	function buildEndOverlay() {
		endOverlay = document.createElement("div");
		endOverlay.id = "ajcore-chat-end-overlay";
		endOverlay.innerHTML =
			'<div class="aj-end-modal">' +
				'<p class="aj-end-title">End this chat?</p>' +
				'<p class="aj-end-sub">Want a copy of this conversation? Update your info below if it needs a fix.</p>' +
				'<div id="aj-end-email-row"><input type="email" id="aj-end-email" placeholder="you@example.com"><div class="aj-field-error" id="aj-end-email-error"></div></div>' +
				'<div id="aj-end-phone-row"><input type="tel" id="aj-end-phone" placeholder="(704) 555-0123"><div class="aj-field-error" id="aj-end-phone-error"></div></div>' +
				'<label class="aj-end-option"><input type="radio" name="aj-transcript-channel" value="email" checked><span>Email me a copy</span></label>' +
				'<label class="aj-end-option"><input type="radio" name="aj-transcript-channel" value="text"><span>Text me a copy</span></label>' +
				'<label class="aj-end-option"><input type="radio" name="aj-transcript-channel" value="none"><span>No thanks, just end the chat</span></label>' +
				'<div class="aj-rate"><span>How was this chat?</span><button type="button" data-rate="up" aria-label="Helpful">👍</button><button type="button" data-rate="down" aria-label="Not helpful">👎</button></div>' +
				'<div class="aj-end-actions"><button type="button" id="aj-end-cancel">Cancel</button><button type="button" id="aj-end-confirm">End Chat</button></div>' +
			'</div>';
		panel.appendChild(endOverlay);

		endRating = "";
		Array.prototype.forEach.call(endOverlay.querySelectorAll(".aj-rate button"), function (b) {
			b.addEventListener("click", function () {
				endRating = endRating === b.getAttribute("data-rate") ? "" : b.getAttribute("data-rate");
				Array.prototype.forEach.call(endOverlay.querySelectorAll(".aj-rate button"), function (x) {
					x.classList.toggle("on", x.getAttribute("data-rate") === endRating);
				});
			});
		});
		endEmailInput = endOverlay.querySelector("#aj-end-email");
		endPhoneInput = endOverlay.querySelector("#aj-end-phone");
		bindPhoneFormat(endPhoneInput);
		endEmailError = endOverlay.querySelector("#aj-end-email-error");
		endPhoneError = endOverlay.querySelector("#aj-end-phone-error");

		endOverlay.querySelector("#aj-end-cancel").addEventListener("click", hideEndOverlay);
		endOverlay.querySelector("#aj-end-confirm").addEventListener("click", confirmEndChat);
	}

	function showEndOverlay() {
		if (!endOverlay) buildEndOverlay();
		endEmailInput.value = visitorEmail;
		endPhoneInput.value = formatPhone(visitorPhone);
		endEmailInput.classList.remove("aj-invalid");
		endPhoneInput.classList.remove("aj-invalid");
		endEmailError.classList.remove("show");
		endPhoneError.classList.remove("show");
		endOverlay.querySelector('input[value="email"]').checked = true;
		endOverlay.classList.add("show");
	}
	function hideEndOverlay() {
		if (endOverlay) endOverlay.classList.remove("show");
	}

	// Both fields are always shown and always validated/persisted together — regardless of which
	// single channel is chosen to actually receive the transcript — since this is also the
	// visitor's chance to correct either one before the session record is closed out.
	function confirmEndChat() {
		var checked = endOverlay.querySelector('input[name="aj-transcript-channel"]:checked');
		var channel = checked ? checked.value : "none";
		var email = endEmailInput.value.trim();
		var phone = endPhoneInput.value.trim();

		var emailOk = isValidEmail(email);
		var phoneOk = isValidPhone(phone);
		endEmailInput.classList.toggle("aj-invalid", !emailOk);
		endEmailError.textContent = emailOk ? "" : emailProblem(email);
		endEmailError.classList.toggle("show", !emailOk);
		endPhoneInput.classList.toggle("aj-invalid", !phoneOk);
		endPhoneError.textContent = phoneOk ? "" : "Please enter a valid phone number, like (704) 555-0123.";
		endPhoneError.classList.toggle("show", !phoneOk);
		if (!emailOk || !phoneOk) return;

		hideEndOverlay();
		performEndChat(channel, email, phone);
	}

	function performEndChat(transcriptChannel, transcriptEmail, transcriptPhone) {
		var payload = {
			session_uuid: sessionUuid,
			transcript_channel: transcriptChannel,
			transcript_email: transcriptEmail,
			transcript_phone: transcriptPhone,
			rating: endRating || undefined,
		};

		fetch(config.serverUrl + "/api/chat/end", {
			method: "POST",
			headers: { "Content-Type": "application/json" },
			body: JSON.stringify(payload),
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
					updateHeaderTitle();
					renderPreChatForm();
				}, 1200);
			});
	}
	endChatBtn.addEventListener("click", showEndOverlay);

	function renderChatUI() {
		body.innerHTML = "";
		sessionOpen = true; // optimistic default — loadHistory() below corrects it if stale
		updateEndChatVisibility();
		updateHeaderTitle();
		if (config.businessHoursEnabled && config.businessHours) {
			var st = document.createElement("div");
			var online = isWithinBusinessHours();
			st.className = "aj-status" + (online ? " online" : "");
			st.textContent = online ? "We're online now" : "We're offline — leave a message and we'll reply as soon as we're back.";
			body.appendChild(st);
		}
		loadHistory();
		requestDesktopNotifyPermission();
		var row = document.createElement("div");
		row.id = "ajcore-chat-inputrow";
		row.innerHTML =
			'<div class="aj-inwrap"><textarea id="ajcore-chat-input" rows="1" maxlength="1000" placeholder="Type a message…"></textarea><span id="ajcore-chat-count"></span></div>' +
			'<button id="ajcore-chat-send" disabled>Send</button>';
		row.appendChild(endChatBtn); // End Chat sits right of Send
		panel.appendChild(row);
		updateEndChatVisibility();

		var input = row.querySelector("#ajcore-chat-input");
		var sendBtn = row.querySelector("#ajcore-chat-send");

		var countEl = row.querySelector("#ajcore-chat-count");
		var lastSendAt = 0;
		function syncInput() {
			var len = input.value.length;
			sendBtn.disabled = !input.value.trim();
			input.style.height = "auto";
			input.style.height = Math.min(input.scrollHeight, 110) + "px";
			countEl.style.display = len >= 800 ? "block" : "none";
			countEl.textContent = len + "/1000";
		}
		input.addEventListener("input", syncInput);
		function doSend() {
			var text = (input.value || "").trim();
			if (!text) return;
			if (Date.now() - lastSendAt < 600) return; // accidental double-tap / key repeat
			lastSendAt = Date.now();
			sendMessage(text);
			input.value = "";
			syncInput();
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
			'<input type="text" id="ajcore-chat-name" placeholder="Your name" autocomplete="name" maxlength="80" required>' +
			'<div class="aj-field-error" id="ajcore-chat-name-error"></div>' +
			'<input type="email" id="ajcore-chat-email" placeholder="you@example.com" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" maxlength="254" required>' +
			'<div class="aj-field-error" id="ajcore-chat-email-error"></div>' +
			'<input type="tel" id="ajcore-chat-phone" placeholder="(704) 555-0123" autocomplete="tel" inputmode="tel" maxlength="20" required>' +
			'<div class="aj-field-error" id="ajcore-chat-phone-error"></div>' +
			'<div class="aj-multi" id="ajcore-chat-services" style="display:none;"></div>' +
			'<div class="aj-chips" id="ajcore-chat-starters" style="margin:0;"></div>' +
			'<textarea id="ajcore-chat-first-message" rows="2" maxlength="1000" placeholder="How can we help?" required></textarea>' +
			'<input type="text" name="website" class="aj-hp" id="ajcore-chat-hp" tabindex="-1" autocomplete="off" aria-hidden="true">' +
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
				errorEl.textContent = ok ? "" : (typeof message === "function" ? message(input.value.trim()) : message);
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
		var starterWrap = form.querySelector("#ajcore-chat-starters");
		var starterWrap0 = starterWrap;
		updateHeaderTitle();
		// Per-site starter chips (config.quickReplies overrides); unknown sites show none.
		var host = siteHost().replace(/^www\./, "");
		var starters = Array.isArray(config.quickReplies) ? config.quickReplies
			: host === "ncllcagents.com" ? ["Registered agent service", "Start a new LLC", "Get an EIN", "Virtual office address"]
			: host === "universityofficesuites.com" ? ["Office space pricing", "Schedule a tour", "Availability"]
			: [];
		// Multi-select "What do you need?" — picks one or more services and writes them into the
		// message box (replaced on each change, left alone once the visitor edits it).
		var services = Array.isArray(config.services) ? config.services
			: host === "ncllcagents.com" ? [
				"Registered agent service", "Start a new LLC", "Nonprofit incorporator & formation",
				"Get an EIN", "Foreign qualification in NC", "Reinstatement",
				"Virtual office address", "Corporate records custody & inspection access",
				"Other / custom service"
			] : [];
		if (services.length) {
			starters = []; // the dropdown replaces the single-tap chips on this site
			var multi = form.querySelector("#ajcore-chat-services");
			multi.style.display = "";
			multi.className = "aj-chips aj-pick";
			var picked = [];
			var autoText = "";
			function refreshPicks() {
				var next = picked.length ? "I'm interested in: " + picked.join(", ") + "." : "";
				if (!messageInput.value.trim() || messageInput.value === autoText) {
					messageInput.value = next;
					autoText = next;
				}
			}
			var hint = document.createElement("div");
			hint.className = "aj-pick-hint";
			hint.textContent = "What do you need? Tap all that apply:";
			multi.parentNode.insertBefore(hint, multi);
			services.forEach(function (label) {
				var b = document.createElement("button");
				b.type = "button";
				b.className = "aj-chip";
				b.setAttribute("aria-pressed", "false");
				b.textContent = String(label);
				b.addEventListener("click", function () {
					var i = picked.indexOf(String(label));
					if (i === -1) picked.push(String(label)); else picked.splice(i, 1);
					var on = i === -1;
					b.classList.toggle("on", on);
					b.setAttribute("aria-pressed", on ? "true" : "false");
					b.textContent = (on ? "✓ " : "") + String(label);
					refreshPicks();
				});
				multi.appendChild(b);
			});
		}
		if (!starters.length) starterWrap0.style.display = "none";
		starters.slice(0, 4).forEach(function (label) {
			var b = document.createElement("button");
			b.type = "button";
			b.className = "aj-chip";
			b.textContent = String(label);
			b.addEventListener("click", function () {
				messageInput.value = String(label);
				messageInput.focus();
				var e = document.createEvent("Event"); e.initEvent("input", true, true); messageInput.dispatchEvent(e);
			});
			starterWrap.appendChild(b);
		});

		bindPhoneFormat(phoneInput);
		nameInput.addEventListener("blur", function () { nameInput.value = tidyName(nameInput.value); });
		emailInput.addEventListener("blur", function () { emailInput.value = normalizeEmail(emailInput.value); });
		var validateName = fieldValidator(nameInput, form.querySelector("#ajcore-chat-name-error"),
			isValidName, "Please enter your real name.");
		var validateEmail = fieldValidator(emailInput, form.querySelector("#ajcore-chat-email-error"),
			isValidEmail, emailProblem);
		var validatePhone = fieldValidator(phoneInput, form.querySelector("#ajcore-chat-phone-error"),
			isValidPhone, "Please enter a valid phone number, like (704) 555-0123.");
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

			// Spam guard: a filled hidden field means a bot — look like it worked, send nothing. Also
			// caps how often one browser can open new chats (5 per hour).
			if (form.querySelector("#ajcore-chat-hp").value) { body.innerHTML = '<div class="aj-status">Thanks!</div>'; return; }
			var startsKey = "ajcore_chat_starts", nowMs = Date.now(), starts = [];
			try { starts = JSON.parse(getStored(startsKey) || "[]").filter(function (t) { return nowMs - t < 3600000; }); } catch (e) { starts = []; }
			if (starts.length >= 5) {
				var lim = form.querySelector("#ajcore-chat-message-error");
				lim.textContent = "Too many chats started — please try again later or text us.";
				lim.classList.add("show");
				return;
			}
			starts.push(nowMs);
			setStored(startsKey, JSON.stringify(starts));

			var name = tidyName(nameInput.value);
			var email = normalizeEmail(emailInput.value);
			var phone = formatPhone(phoneInput.value);
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

	// A staff "prompt chat" push pops the panel open with a normal chat session ready to go —
	// declining to interrupt outside business hours (matches the bubble's own click behavior) rather
	// than popping a panel a visitor can't get a live reply from anyway.
	startPresence(function () {
		if (isWithinBusinessHours()) openPanel();
	});
	maybeShowEngagementPopup(function () { return textUsHref(defaultTextUsMessage()); });
	maybeShowIdentifyPopup();

	// Warns before closing the tab only while the visitor has the chat panel actually OPEN on an
	// open session (not merely because hasVisitorInfo/sessionOpen persist in localStorage from some
	// past visit — hasVisitorInfo never clears itself, and sessionOpen defaults optimistically to
	// true the instant renderChatUI() runs at page load, before loadHistory() has had a chance to
	// correct it; without the panelOpen check this fires on every navigation for any returning
	// visitor whose last session is still open server-side, even with the bubble collapsed).
	// Browsers ignore custom beforeunload text and always show their own generic prompt — there's
	// no way to display "you have an active chat" wording itself, only to trigger that prompt. Nor
	// can this distinguish an actual tab/browser close from navigating to another page — both fire
	// the identical beforeunload event; the browser gives no way to tell them apart.
	//
	// Also actually ends the session server-side here (explicit product decision: visitors read
	// that generic browser prompt as "closing this will end my chat," so the code now matches that
	// expectation) — accepting the trade-off that a plain page REFRESH fires this exact same event
	// and can't be told apart from a real tab close, so a refresh now also ends the chat instead of
	// resuming it. navigator.sendBeacon is used instead of fetch() because a fetch() started here
	// is routinely aborted mid-flight once the browser tears the page down; sendBeacon is built
	// specifically to survive that. Sent as a "text/plain" Blob (not "application/json") purely to
	// keep this a CORS "simple request" — a JSON content-type triggers a preflight OPTIONS with no
	// guarantee of finishing before unload completes, which would silently drop the beacon on this
	// cross-origin (widget's site → AJOps) call; /api/chat/end parses the body as JSON regardless
	// of what Content-Type says. transcript_channel is "none" since there's no UI at this point to
	// ask a transcript preference — the End Chat button remains how a visitor requests one.
	window.addEventListener("beforeunload", function (e) {
		if (!hasVisitorInfo || !sessionOpen || !panelOpen) return;
		if (typeof navigator.sendBeacon === "function") {
			try {
				var payload = JSON.stringify({ session_uuid: sessionUuid, transcript_channel: "none" });
				navigator.sendBeacon(config.serverUrl + "/api/chat/end", new Blob([payload], { type: "text/plain" }));
			} catch (err) { /* best-effort only — the tab is already closing either way */ }
		}
		e.preventDefault();
		e.returnValue = "";
	});
})();
