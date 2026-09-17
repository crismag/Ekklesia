<?php
/**
 * Portal home (docs/design/surfaces.md, "Home (portal dashboard)").
 *
 * Ekklesia is the church's records and operations portal, not its website. This
 * page says so and sends people into the workspaces: signed in, it is a
 * dashboard of what concerns this person, limited to what they may access;
 * signed out, it is sign-in plus the portal functions open to everyone.
 *
 * Everything shown comes from HomePageService::build(); this file only renders.
 * A section that is null is not offered to this person and renders nothing.
 *
 * @var string                $basePath
 * @var ?array<string, mixed> $actor
 * @var array<string, mixed>  $home
 * @var array<string, mixed>  $campusSelector
 */
require_once __DIR__ . '/_portal-shell.php';

$actor = is_array($actor ?? null) ? $actor : null;
$home = is_array($home ?? null) ? $home : \App\Services\HomePageService::skeleton($basePath, $actor);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$defaultCampusId = $campusSelector['defaultCampusId'] ?? null;
$brand = trim((string) (portal_chrome()['header']['brandTitle'] ?? '')) ?: 'Ekklesia';
$signedIn = $actor !== null;
$workspaces = is_array($home['workspaces'] ?? null) ? $home['workspaces'] : [];

$parseDate = static function (string $value): ?DateTimeImmutable {
    if ($value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value);
    } catch (\Exception) {
        return null;
    }
};

$dateChip = static function (?DateTimeImmutable $at) use ($h): string {
    if ($at === null) {
        return '<span class="hm-date" aria-hidden="true">—</span>';
    }

    return '<span class="hm-date"><span class="sr-only">' . $h($at->format('l j F')) . ', </span>'
        . '<small aria-hidden="true">' . $h($at->format('D')) . '</small><span aria-hidden="true">' . $h($at->format('j')) . '</span>'
        . '<small aria-hidden="true">' . $h($at->format('M')) . '</small></span>';
};

// The chip carries the date, so the line beside it only needs the time.
$timeOf = static fn (?DateTimeImmutable $at): string => $at === null || $at->format('H:i') === '00:00' ? '' : $at->format('g:i A');

$card = static function (string $id, string $title, string $linkHref, string $linkLabel, string $body) use ($h): string {
    return '<section class="ek-card hm-card" aria-labelledby="' . $h($id) . '">'
        . '<div class="ek-card-head"><h2 id="' . $h($id) . '">' . $h($title) . '</h2>'
        . ($linkHref !== '' ? '<a class="hm-head-link" href="' . $h($linkHref) . '">' . $h($linkLabel) . '</a>' : '')
        . '</div>' . $body . '</section>';
};

$empty = static function (string $message, string $href = '', string $label = '') use ($h): string {
    return '<div class="hm-empty" role="status"><p>' . $h($message) . '</p>'
        . ($href !== '' ? '<a href="' . $h($href) . '">' . $h($label) . '</a>' : '') . '</div>';
};

$unavailable = static fn (string $what): string => '<div class="hm-empty" role="status"><p>'
    . htmlspecialchars($what, ENT_QUOTES, 'UTF-8') . ' could not be loaded right now.</p></div>';

$section = static fn (string $key): ?array => is_array($home[$key] ?? null) ? $home[$key] : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Home · <?= $h($brand) ?></title>
    <style>
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font:14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color:var(--ink); background:var(--bg); }
        .hm-page { padding-bottom:var(--sp-8,32px); }
        .hm-grid { display:grid; gap:var(--sp-4,16px); grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr)); align-items:start; }
        .hm-card .ek-card-head { align-items:center; }
        .hm-head-link { color:var(--teal-ink,#117b6d); font-size:13px; font-weight:650; text-decoration:none; white-space:nowrap;
            display:inline-flex; align-items:center; min-height:32px; }
        .hm-head-link:hover { text-decoration:underline; }
        .hm-list { list-style:none; margin:0; padding:0; }
        .hm-row { display:grid; grid-template-columns:44px minmax(0,1fr); gap:var(--sp-3,12px); align-items:center;
            padding:var(--sp-2,8px) var(--sp-5,20px); border-bottom:1px solid var(--line,#d9e4dd); color:inherit; text-decoration:none; min-height:56px; }
        .hm-list li:last-child .hm-row { border-bottom:0; }
        a.hm-row:hover { background:color-mix(in srgb,var(--soft,#eef4f0) 60%,transparent); }
        a.hm-row:hover .hm-title { color:var(--teal-ink,#117b6d); }
        .hm-date { display:grid; justify-items:center; line-height:1.05; padding:4px 0; border-radius:var(--radius,8px);
            background:var(--soft,#eef4f0); font-weight:750; font-size:16px; color:var(--ink,#17211b); font-variant-numeric:tabular-nums; }
        .hm-date small { font-size:10.5px; font-weight:650; color:var(--muted,#627169); text-transform:uppercase; letter-spacing:.03em; }
        .hm-title { display:block; font-weight:650; overflow-wrap:anywhere; }
        .hm-meta { color:var(--muted,#627169); font-size:12.5px; font-weight:400; overflow-wrap:anywhere; }
        .hm-desc { display:block; }
        .hm-empty { padding:var(--sp-5,20px); color:var(--muted,#627169); display:grid; gap:var(--sp-1,4px); }
        .hm-empty p { margin:0; }
        .hm-empty a { color:var(--teal-ink,#117b6d); font-weight:650; text-decoration:none; justify-self:start; min-height:32px; display:inline-flex; align-items:center; }
        .hm-empty a:hover { text-decoration:underline; }
        .hm-subhead { margin:0; padding:var(--sp-3,12px) var(--sp-5,20px) var(--sp-1,4px); font-size:12px; font-weight:700;
            color:var(--muted,#627169); text-transform:uppercase; letter-spacing:.04em; }
        .hm-open { display:block; padding:var(--sp-3,12px) var(--sp-5,20px); border-bottom:1px solid var(--line,#d9e4dd); color:inherit; text-decoration:none; }
        a.hm-open:hover .hm-title { color:var(--teal-ink,#117b6d); }
        .hm-list li:last-child .hm-open { border-bottom:0; }
        .hm-notices { display:grid; gap:var(--sp-2,8px); }
        .hm-notice { display:grid; gap:2px; padding:var(--sp-3,12px) var(--sp-4,16px); background:var(--paper,#fff);
            border:1px solid var(--line,#d9e4dd); border-left:4px solid var(--gold,#c48725); border-radius:var(--radius,8px); }
        .hm-notice-top { display:flex; flex-wrap:wrap; gap:var(--sp-2,8px); align-items:center; }
        .hm-notice p { margin:0; color:var(--muted,#627169); overflow-wrap:anywhere; }
        .hm-ministries { display:flex; flex-wrap:wrap; gap:var(--sp-2,8px); padding:var(--sp-4,16px) var(--sp-5,20px); }
        .hm-ministries .ek-chip { min-height:36px; }
        .hm-section-title { margin:var(--sp-2,8px) 0 var(--sp-2,8px); font-size:16px; font-weight:700; }
        .hm-ws { display:grid; gap:var(--sp-3,12px); grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr)); }
        .hm-ws-card { display:grid; gap:var(--sp-2,8px); align-content:start; padding:var(--sp-4,16px); background:var(--paper,#fff);
            border:1px solid var(--line,#d9e4dd); border-radius:var(--radius-lg,12px); min-width:0; }
        .hm-ws-head { display:flex; gap:var(--sp-3,12px); align-items:center; color:var(--ink,#17211b); text-decoration:none; font-weight:700; font-size:15px; }
        .hm-ws-head:hover { color:var(--teal-ink,#117b6d); }
        .hm-ws-icon { width:34px; height:34px; border-radius:10px; display:grid; place-items:center; flex:0 0 auto;
            background:var(--soft,#eef4f0); color:var(--teal-ink,#117b6d); }
        .hm-ws-icon svg { width:18px; height:18px; }
        .hm-ws-card p { margin:0; color:var(--muted,#627169); font-size:13px; }
        .hm-ws-links { display:flex; flex-wrap:wrap; gap:2px 14px; margin:0; padding:0; list-style:none; }
        .hm-ws-links a { color:var(--teal-ink,#117b6d); font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; min-height:32px; }
        .hm-ws-links a:hover { text-decoration:underline; }
        .hm-page a:focus-visible { outline:2px solid var(--teal,#117b6d); outline-offset:2px; border-radius:4px; }
        @media (max-width:820px) {
            .hm-head-link, .hm-ws-links a, .hm-empty a { min-height:44px; }
            .hm-row, .hm-open { padding-left:var(--sp-4,16px); padding-right:var(--sp-4,16px); }
        }
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
    <?= portal_header(
        $basePath,
        '',
        '',
        $campuses,
        $defaultCampusId !== null ? (int) $defaultCampusId : null,
        $actor,
        [],
        [],
        [],
        'Sign in',
        $base . '/login'
    ) ?>
<main id="portal-main" tabindex="-1">
<div class="ek-page hm-page">
<?php if (!$signedIn): ?>
    <?= ek_page_header(
        $brand . ' portal',
        'The church’s portal for its records, ministries, calendar and serving schedules. Sign in to see where you are serving and the workspaces your account can use.',
        '<a class="ek-btn ek-btn-primary" href="' . $base . '/login">Sign in</a>'
    ) ?>

    <section class="ek-card hm-card" aria-labelledby="hm-public">
        <div class="ek-card-head"><div><h2 id="hm-public">Open to everyone</h2><p>These work without an account.</p></div></div>
        <ul class="hm-list">
            <?php foreach ($workspaces as $ws): ?>
                <?php foreach ($ws['pages'] as $page): ?>
                    <?php $purpose = \App\Services\HomePageService::PUBLIC_PAGE_PURPOSES[$page['label']] ?? ''; ?>
                    <li><a class="hm-open" href="<?= $h($page['href']) ?>">
                        <span class="hm-title"><?= $h($page['label']) ?></span>
                        <?php if ($purpose !== ''): ?><span class="hm-meta hm-desc"><?= $h($purpose) ?></span><?php endif; ?>
                    </a></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <li><div class="hm-open"><span class="hm-title">Event RSVP</span>
                <span class="hm-meta hm-desc">Events that take RSVPs each have their own RSVP link, shared by the church.</span></div></li>
        </ul>
    </section>
<?php else: ?>
    <?php
    $first = portal_first_name($actor['displayName'] ?? null);
    echo ek_page_header(
        $first !== '' ? 'Hello, ' . $first : 'Home',
        'What is coming up, where you serve, and the workspaces you can open in the ' . $brand . ' portal.'
    );
    ?>

    <?php $notices = $section('notices'); ?>
    <?php if ($notices !== null && $notices['items'] !== []): ?>
    <section class="hm-notices" aria-label="Portal notices">
        <?php foreach ($notices['items'] as $n): ?>
        <div class="hm-notice">
            <div class="hm-notice-top"><strong><?= $h($n['title']) ?></strong><?php if ($n['tag'] !== ''): ?><span class="ek-badge"><?= $h($n['tag']) ?></span><?php endif; ?></div>
            <?php if ($n['body'] !== ''): ?><p><?= $h($n['body']) ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if (!empty($home['unlinked'])): ?>
    <div class="ek-alert" role="status">
        <div><strong>Your account is not linked to a person record.</strong> Your serving schedule and ministries appear here once an administrator links it.</div>
    </div>
    <?php endif; ?>

    <?php $records = $section('records'); ?>
    <?php if ($records !== null): ?>
    <section aria-labelledby="hm-records">
        <h2 class="hm-section-title" id="hm-records">Records at a glance</h2>
        <?php if (!$records['ok']): ?>
            <?= $unavailable('Record counts') ?>
        <?php else: $r = $records['items']; ?>
        <div class="ek-stats">
            <a class="ek-stat" href="<?= $base ?>/admin/people"><span class="ek-stat-label">People</span><span class="ek-stat-value"><?= number_format((int) $r['people']) ?></span></a>
            <a class="ek-stat" href="<?= $base ?>/admin/families"><span class="ek-stat-label">Households</span><span class="ek-stat-value"><?= number_format((int) $r['households']) ?></span></a>
            <div class="ek-stat"><span class="ek-stat-label">Without a primary campus</span><span class="ek-stat-value"><?= number_format((int) $r['withoutCampus']) ?></span></div>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="hm-grid">
        <?php
        $events = $section('events');
        if ($events !== null) {
            if (!$events['ok']) {
                $body = $unavailable('Upcoming events');
            } elseif ($events['items'] === []) {
                $body = $empty('Nothing on the calendar in the next two weeks.', $basePath . '/calendar', 'Open the calendar');
            } else {
                $body = '<ul class="hm-list">';
                foreach ($events['items'] as $e) {
                    $at = $parseDate($e['startsAt']);
                    $meta = implode(' · ', array_filter([$timeOf($at), $e['location']], static fn (string $x): bool => $x !== ''));
                    $href = $e['eventId'] > 0 ? $basePath . '/events/' . $e['eventId'] : $basePath . '/events';
                    $body .= '<li><a class="hm-row" href="' . $h($href) . '">' . $dateChip($at)
                        . '<span><span class="hm-title">' . $h($e['title'])
                        . ($e['cancelled'] ? ' <span class="ek-badge is-error">Cancelled</span>' : '') . '</span>'
                        . '<span class="hm-meta">' . $h($meta) . '</span></span></a></li>';
                }
                $body .= '</ul>';
            }
            echo $card('hm-events', 'Coming up', $basePath . '/calendar', 'Calendar', $body);
        }

        $serving = $section('serving');
        if ($serving !== null) {
            if (!$serving['ok']) {
                $body = $unavailable('Your serving schedule');
            } elseif ($serving['items'] === []) {
                $body = $empty('Nothing scheduled for you in the next two weeks.', $basePath . '/my-schedule', 'Open My schedule');
            } else {
                $body = '<ul class="hm-list">';
                foreach ($serving['items'] as $a) {
                    $at = $parseDate($a['startsOn']);
                    $meta = implode(' · ', array_filter([$timeOf($at), $a['eventTitle'], $a['ministryName']], static fn (string $s): bool => $s !== ''));
                    $body .= '<li><a class="hm-row" href="' . $h($basePath . '/my-schedule') . '">' . $dateChip($at)
                        . '<span><span class="hm-title">' . $h($a['roleName'] !== '' ? $a['roleName'] : $a['ministryName']) . '</span>'
                        . '<span class="hm-meta">' . $h($meta) . '</span></span></a></li>';
                }
                $body .= '</ul>';
            }
            echo $card('hm-serving', 'My serving', $basePath . '/my-schedule', 'My schedule', $body);
        }

        $openRoles = $section('openRoles');
        if ($openRoles !== null) {
            if (!$openRoles['ok']) {
                $body = $unavailable('Open roles');
            } elseif ($openRoles['items'] === []) {
                $body = $empty('No open roles in your ministries’ schedules for the next three weeks.', $basePath . '/schedules', 'Open schedules');
            } else {
                $body = '';
                foreach ($openRoles['items'] as $m) {
                    $body .= '<h3 class="hm-subhead">' . $h($m['ministryName']) . '</h3><ul class="hm-list">';
                    foreach ($m['occurrences'] as $o) {
                        $at = $parseDate($o['startsOn']);
                        $open = count($o['open']);
                        $names = implode(', ', array_slice($o['open'], 0, 4)) . ($open > 4 ? ' and ' . ($open - 4) . ' more' : '');
                        $body .= '<li><a class="hm-row" href="' . $h($m['href']) . '">' . $dateChip($at)
                            . '<span><span class="hm-title">' . $h($open . ' of ' . $o['roleCount'] . ' roles open')
                            . ' <span class="hm-meta">· ' . $h($o['eventTitle']) . '</span></span>'
                            . '<span class="hm-meta">' . $h($names) . '</span></span></a></li>';
                    }
                    $body .= '</ul>';
                }
            }
            echo $card('hm-open-roles', 'Open roles', '', '', $body);
        }

        $ministries = $section('ministries');
        if ($ministries !== null) {
            if (!$ministries['ok']) {
                $body = $unavailable('Your ministries');
            } elseif ($ministries['items'] === []) {
                $body = $empty('You are not listed in a ministry yet.', $basePath . '/ministries', 'Browse ministries');
            } else {
                $body = '<div class="hm-ministries">';
                foreach ($ministries['items'] as $m) {
                    $label = $m['name'] . ($m['isLeader'] ? ' · Leader' : '');
                    $body .= '<a class="ek-chip" href="' . $h($basePath . '/ministries/' . $m['ministryId']) . '"'
                        . ($m['roles'] !== '' ? ' title="' . $h($m['roles']) . '"' : '') . '>' . $h($label) . '</a>';
                }
                $body .= '</div>';
            }
            echo $card('hm-ministries', 'My ministries', $basePath . '/ministries', 'All ministries', $body);
        }
        ?>
    </div>

    <?php if ($workspaces !== []): ?>
    <section aria-labelledby="hm-workspaces">
        <h2 class="hm-section-title" id="hm-workspaces">Workspaces</h2>
        <div class="hm-ws">
            <?php foreach ($workspaces as $ws): ?>
            <div class="hm-ws-card">
                <a class="hm-ws-head" href="<?= $h($ws['href']) ?>"><span class="hm-ws-icon" aria-hidden="true"><?= portal_icon($ws['icon']) ?></span><span><?= $h($ws['label']) ?></span></a>
                <?php if ($ws['purpose'] !== ''): ?><p><?= $h($ws['purpose']) ?></p><?php endif; ?>
                <ul class="hm-ws-links" aria-label="<?= $h($ws['label']) ?> pages">
                    <?php foreach ($ws['pages'] as $page): ?>
                    <li><a href="<?= $h($page['href']) ?>"><?= $h($page['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
<?php endif; ?>
</div>
</main>
<?= portal_footer() ?>
</div>
</body>
</html>
