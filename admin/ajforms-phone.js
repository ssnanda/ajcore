/* Auto-formats US phone inputs to +1 NXX NXX-XXXX as the user types. */
(function () {
	function formatPhone(raw) {
		var stripped = raw.replace(/^\+1\s?/, '');
		var d = stripped.replace(/\D/g, '').slice(0, 10);
		if (!d) return '';
		if (d.length <= 3) return '+1 ' + d;
		if (d.length <= 6) return '+1 ' + d.slice(0, 3) + ' ' + d.slice(3);
		return '+1 ' + d.slice(0, 3) + ' ' + d.slice(3, 6) + '-' + d.slice(6);
	}

	function attach(el) {
		if (el._ajPhoneAttached) return;
		el._ajPhoneAttached = true;
		el.addEventListener('input', function () {
			var pos   = el.selectionStart;
			var prev  = el.value;
			el.value  = formatPhone(prev);
			var delta = el.value.length - prev.length;
			var next  = Math.max(0, pos + delta);
			el.setSelectionRange(next, next);
		});
		// Format on load if already has a value
		if (el.value) el.value = formatPhone(el.value);
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('input[type="tel"], #lead_phone').forEach(attach);
	});
})();
