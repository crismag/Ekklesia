/* People Sign-Up — progressive enhancement only. The form works without JS. */
(function () {
  'use strict';
  var form = document.getElementById('signupForm');
  if (!form) return;
  var btn = document.getElementById('submitBtn');

  form.addEventListener('submit', function (ev) {
    // Let native validation surface first.
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
      return; // browser shows the message; do not lock the button
    }
    if (btn) {
      btn.dataset.label = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Please wait…';
    }
  });

  // Guard against a stale disabled button when returning via back/forward cache.
  window.addEventListener('pageshow', function () {
    if (btn && btn.dataset.locked !== '1') {
      btn.disabled = false;
    }
  });

  // ---- Page 3 (extra.php) niceties ---------------------------------------
  // Married toggle nudges Member Type toward Trailblazer (unless a child),
  // but never overrides a manual choice the guest just made.
  var married = document.getElementById('is_married');
  var mtSel = document.getElementById('member_type');
  var mtHint = document.getElementById('mtHint');
  var byAttr = form.getAttribute('data-birth-year');
  var birthYear = byAttr ? parseInt(byAttr, 10) : null;
  var mtUserTouched = false;
  if (mtSel) mtSel.addEventListener('change', function () { mtUserTouched = true; if (mtHint) mtHint.textContent = '(adjust if needed)'; });
  if (married && mtSel) {
    married.addEventListener('change', function () {
      if (mtUserTouched) return;
      var age = birthYear ? (new Date().getFullYear() - birthYear) : null;
      var isChild = age !== null && age <= 10;
      if (married.checked && !isChild) {
        mtSel.value = '2'; // Trailblazer
        if (mtHint) mtHint.textContent = '(set to Trailblazer — adjust if needed)';
      }
    });
  }

  // Address geo lookup via geocode.php.
  var geoBtn = document.getElementById('geoBtn');
  if (geoBtn) {
    var status = document.getElementById('geoStatus');
    var csrf = form.querySelector('input[name="csrf"]');
    geoBtn.addEventListener('click', function () {
      var body = new URLSearchParams();
      body.set('csrf', csrf ? csrf.value : '');
      ['address', 'address2', 'city', 'state', 'zip', 'country'].forEach(function (f) {
        var el = document.getElementById(f);
        if (el) body.set(f, el.value);
      });
      geoBtn.disabled = true;
      if (status) { status.style.color = ''; status.textContent = 'Looking up address…'; }
      fetch('geocode.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      }).then(function (r) { return r.json(); }).then(function (j) {
        geoBtn.disabled = false;
        if (!j.ok) { if (status) { status.style.color = '#b3261e'; status.textContent = j.error || 'No match.'; } return; }
        var a = j.address || {};
        function setIf(id, v) { var el = document.getElementById(id); if (el && v) el.value = v; }
        setIf('address', a.street);
        setIf('city', a.city);
        setIf('state', a.state);
        setIf('zip', a.postcode);
        setIf('country', a.country);
        var lat = document.getElementById('latitude'); if (lat && j.lat != null) lat.value = j.lat;
        var lon = document.getElementById('longitude'); if (lon && j.lon != null) lon.value = j.lon;
        if (status) { status.style.color = '#137a5f'; status.textContent = '✓ ' + (j.display_name || 'Address found.'); }
      }).catch(function () {
        geoBtn.disabled = false;
        if (status) { status.style.color = '#b3261e'; status.textContent = 'Lookup failed. Enter address manually.'; }
      });
    });
  }
})();
