<?php

declare(strict_types=1);

/** @var string $basePath */
/** @var ?array<string,mixed> $actor */
/** @var int $eventId */
/** @var array<string,mixed>|null $event */

?>
<!-- Focused occurrence tool: readable width, not a Bootstrap .container. -->
<div class="layout-readable" style="max-width:42rem;margin:0;padding:1.5rem 1rem">
    <a href="<?= $basePath ?>/events/<?= (int)$eventId ?>">← Back</a>
    <h1>Generate occurrences for event <?= (int)$eventId ?></h1>

    <form id="generate-form">
        <fieldset style="margin: 12px 0;">
            <legend>Campus scope</legend>
            <label style="display:block;margin:4px 0;">
                <input type="checkbox" id="allCampusesToggle" <?= empty($event['campus_ids'] ?? []) ? 'checked' : '' ?>>
                All campuses / no campus filter
            </label>
            <div style="font-size:12px;color:#57606a;margin:6px 0 10px;">Disable this and select a campus set when the schedule should be limited to specific campuses only.</div>
            <div id="campusList" style="<?= empty($event['campus_ids'] ?? []) ? 'display:none;' : '' ?>">
                <?php foreach (($event['available_campuses'] ?? []) as $campus): ?>
                    <label style="display:block;margin:4px 0;">
                        <input type="checkbox" name="campusIds[]" value="<?= (int) $campus['id'] ?>"
                            <?= in_array((int) $campus['id'], array_map('intval', $event['campus_ids'] ?? []), true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars((string) $campus['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <label>Starts at <input id="startsAt" type="datetime-local"></label>
        <label>Duration (min) <input id="durationMin" type="number" value="90"></label>
        <label>Pattern
            <select id="pattern"><option value="one_off">One-off</option><option value="weekly">Weekly</option><option value="biweekly">Biweekly</option></select>
        </label>
        <label>Count <input id="count" type="number"></label>
        <button type="submit">Generate</button>
    </form>
</div>

<script>
function selectedCampusIds() {
    if (document.getElementById('allCampusesToggle')?.checked) {
        return [];
    }
    return Array.from(document.querySelectorAll('input[name="campusIds[]"]:checked')).map((el) => parseInt(el.value, 10));
}

document.getElementById('allCampusesToggle')?.addEventListener('change', function () {
    const list = document.getElementById('campusList');
    if (!list) return;
    list.style.display = this.checked ? 'none' : 'block';
});

document.getElementById('generate-form')?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const id = <?= (int)$eventId ?>;
    const patchRes = await fetch('<?= $basePath ?>/api/events/' + id, {
        method: 'PATCH',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ campusIds: selectedCampusIds() }),
    });
    if (!patchRes.ok) {
        alert('Failed to save event campuses');
        return;
    }
    const pattern = document.getElementById('pattern').value;
    const startsAt = document.getElementById('startsAt').value;
    const durationMin = parseInt(document.getElementById('durationMin').value || '90', 10);
    const count = document.getElementById('count').value ? parseInt(document.getElementById('count').value, 10) : undefined;
    const body = { pattern, startsAt, durationMin };
    if (count) body.count = count;
    const res = await fetch('<?= $basePath ?>/api/events/' + id + '/occurrences', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
    // Say what happened on arrival, the same way creating an event does — the
    // event page is otherwise indistinguishable from having opened it to edit.
    if (res.ok) location.href = '<?= $basePath ?>/events/' + id + '?added=1';
    else alert('Failed to generate');
});
</script>
