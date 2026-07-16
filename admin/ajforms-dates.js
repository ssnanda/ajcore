(function () {
  'use strict';
  document.addEventListener('click', function (event) {
    var input = event.target.closest && event.target.closest('input[type="date"]');
    if (!input || input.disabled || input.readOnly || typeof input.showPicker !== 'function') return;
    try { input.showPicker(); } catch (error) { /* Native picker may already be open. */ }
  });
})();
