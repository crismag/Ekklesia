/* People Sign-Up admin — in-place editing for the review grid.
   Each editable cell carries data-id + data-field; changes auto-save to
   admin_save.php. Requires JS; without it the grid is read-only. */
(function () {
  'use strict';
  var tokenEl = document.getElementById('csrf');
  var CSRF = tokenEl ? tokenEl.value : '';

  function flash(el, cls) {
    var cell = el.closest('td') || el;
    cell.classList.remove('saving', 'saved', 'err');
    if (cls) cell.classList.add(cls);
  }

  function save(el) {
    var id = el.getAttribute('data-id');
    var field = el.getAttribute('data-field');
    var value = el.value;
    if (value === el.getAttribute('data-original')) return;      // no change
    flash(el, 'saving');

    var body = new URLSearchParams();
    body.set('csrf', CSRF);
    body.set('id', id);
    body.set('field', field);
    body.set('value', value);

    fetch('admin_save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok && res.j && res.j.ok) {
          el.setAttribute('data-original', value);
          flash(el, 'saved');
          setTimeout(function () { flash(el, ''); }, 900);
        } else {
          el.value = el.getAttribute('data-original');            // revert
          flash(el, 'err');
          if (res.j && res.j.error) el.title = res.j.error;
        }
      })
      .catch(function () {
        el.value = el.getAttribute('data-original');
        flash(el, 'err');
      });
  }

  var NO_IMPORT = { promoted: 1, rejected: 1, duplicate: 1 };
  function syncRowImport(select) {
    var row = select.closest('tr');
    if (!row) return;
    var btn = row.querySelector('[data-promote-id]');
    var chk = row.querySelector('.rowchk');
    var blocked = !!NO_IMPORT[select.value];
    if (btn && !btn.classList.contains('done')) {
      btn.disabled = blocked;
      btn.title = blocked ? (select.options[select.selectedIndex].text +
        " rows can't be promoted. Change status to enable.") : '';
    }
    if (chk) { chk.disabled = blocked; if (blocked) chk.checked = false; }
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.hasAttribute && t.hasAttribute('data-field')) {
      save(t);
      if (t.getAttribute('data-field') === 'status') syncRowImport(t);
    }
  });
  // Enter commits text inputs (and moves focus out); Escape reverts.
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (!t || !t.hasAttribute || !t.hasAttribute('data-field')) return;
    if (e.key === 'Enter' && t.tagName === 'INPUT') { e.preventDefault(); t.blur(); }
    if (e.key === 'Escape') { t.value = t.getAttribute('data-original'); t.blur(); }
  });
  // Text inputs save on blur too (covers click-away without Enter).
  document.addEventListener('blur', function (e) {
    var t = e.target;
    if (t && t.hasAttribute && t.hasAttribute('data-field') && t.tagName === 'INPUT') save(t);
  }, true);

  // ---- Promotion ----------------------------------------------------------
  function statusValue() {
    var s = document.getElementById('membership_status_id');
    return s ? s.value : '1';
  }
  function statusText() {
    var s = document.getElementById('membership_status_id');
    return s ? s.selectedOptions[0].text : '';
  }

  function promote(ids, force) {
    if (!ids.length) return Promise.resolve();
    var body = new URLSearchParams();
    body.set('csrf', CSRF);
    body.set('ids', ids.join(','));
    body.set('membership_status_id', statusValue());
    if (force) body.set('force', '1');
    return fetch('migrate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function handleResult(res) {
    if (!res || !res.ok) { alert('Promotion failed. Please reload and try again.'); return; }
    var blocked = (res.results || []).filter(function (x) { return !x.ok && x.needs_review; });
    if (blocked.length) {
      var ids = blocked.map(function (x) { return x.id; });
      if (confirm(blocked.length + ' row(s) look like existing members (' +
          blocked.map(function (x) { return '#' + x.id + '→member ' + x.person_id; }).join(', ') +
          ').\n\nOK = promote anyway (creates a new person). Cancel = leave them to link manually.')) {
        promote(ids, true).then(handleErrors).then(function () { location.reload(); });
        return;
      }
    }
    handleErrors(res);
    location.reload();
  }

  // Failures other than a member match (e.g. the person was created but the
  // registration could not be marked promoted) must be seen, not lost to a reload.
  function handleErrors(res) {
    var failed = ((res && res.results) || []).filter(function (x) { return !x.ok && !x.needs_review; });
    if (failed.length) {
      alert(failed.map(function (x) { return '#' + x.id + ': ' + x.error; }).join('\n'));
    }
    return res;
  }

  var single = document.querySelectorAll('[data-promote-id]');
  Array.prototype.forEach.call(single, function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.getAttribute('data-promote-id'), 10);
      if (!confirm('Promote row #' + id + ' to the member records as "' + statusText() + '"?')) return;
      btn.disabled = true; btn.textContent = 'Promoting…';
      promote([id], false).then(handleResult).catch(function () {
        btn.disabled = false; btn.textContent = 'Promote ▸';
      });
    });
  });

  var checkAll = document.getElementById('checkAll');
  if (checkAll) checkAll.addEventListener('change', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.rowchk'), function (c) { c.checked = checkAll.checked; });
  });

  var bulk = document.getElementById('bulkPromote');
  if (bulk) bulk.addEventListener('click', function () {
    var ids = Array.prototype.map.call(document.querySelectorAll('.rowchk:checked'), function (c) { return parseInt(c.value, 10); });
    if (!ids.length) { alert('Tick at least one row to promote.'); return; }
    if (!confirm('Promote ' + ids.length + ' selected row(s) to the member records as "' + statusText() + '"?')) return;
    bulk.disabled = true; bulk.textContent = 'Promoting…';
    promote(ids, false).then(handleResult).catch(function () {
      bulk.disabled = false; bulk.textContent = 'Promote selected ▸';
    });
  });

  // Suggestion chips: fill the member-id field for that row and save it.
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-suggest-id]') : null;
    if (!b) return;
    e.preventDefault();
    var row = b.closest('tr');
    var input = row ? row.querySelector('[data-field="matched_person_id"]') : null;
    if (input) { input.value = b.getAttribute('data-suggest-id'); save(input); }
  });
})();
