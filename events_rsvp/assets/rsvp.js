/* Events RSVP — progressive enhancement only. The form works without JS. */
(function () {
  'use strict';
  var form = document.getElementById('rsvpForm');
  if (!form) return;
  var btn = document.getElementById('submitBtn');

  // Party-count stepper.
  var party = document.getElementById('party_size');
  Array.prototype.forEach.call(document.querySelectorAll('.stepper button'), function (b) {
    b.addEventListener('click', function () {
      if (!party) return;
      var min = parseInt(party.min || '1', 10);
      var max = parseInt(party.max || '50', 10);
      var v = parseInt(party.value || '1', 10) + parseInt(b.getAttribute('data-step'), 10);
      if (isNaN(v) || v < min) v = min;
      if (v > max) v = max;
      party.value = v;
    });
  });

  form.addEventListener('submit', function () {
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
    if (btn) { btn.disabled = true; btn.textContent = 'Saving your RSVP…'; }
  });

  window.addEventListener('pageshow', function () {
    if (btn) { btn.disabled = false; btn.textContent = 'Confirm my RSVP'; }
  });
})();
