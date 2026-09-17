<?php

declare(strict_types=1);

/**
 * /ministries/{id}[/members|/roles|/schedule] — one ministry's workspace.
 *
 * Tabs (App\Core\Navigation\Workspaces::ministryTabs()):
 *   Overview           leaders, size, positions and serving roles at a glance,
 *                      upcoming serving dates, and the ways into the tools.
 *   Members & leaders  the membership editor that used to be the admin page
 *                      /ministries/members-and-leaders, for this ministry.
 *   Serving roles      the roles the serving grid schedules people into.
 *   Schedule           who serves on each coming date (read-only; the serving
 *                      grid stays the editor).
 *
 * The route reads everything through MinistryService / ScheduleService and
 * hands this template plain arrays; writes go to the existing /api/ministry*
 * endpoints, which remain the authorization boundary. The access flags only
 * decide what is offered.
 *
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var array<string,mixed> $campusSelector
 * @var string $tab
 * @var int $ministryId
 * @var ?array<string,mixed> $overview
 * @var list<array<string,mixed>> $members
 * @var list<array<string,mixed>> $roles
 * @var ?list<array<string,mixed>> $servingDates
 * @var string $scheduleError
 */

require_once __DIR__ . '/_portal-shell.php';

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$base = $e($basePath);
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$campusNames = [];
foreach ($campuses as $c) {
    $campusNames[(int) ($c['id'] ?? 0)] = (string) ($c['name'] ?? '');
}

$access = is_array($overview['access'] ?? null) ? $overview['access'] : [
    'canViewPeople' => false, 'canManageMembers' => false, 'canManageRoles' => false,
    'canEditSchedule' => false, 'canPrintSchedule' => false, 'canManageMinistries' => false,
];
$home = $base . '/ministries/' . $ministryId;

// Tabs this actor is offered; a tab they may not use is not a page they can be on.
$tabs = [];
foreach (\App\Core\Navigation\Workspaces::ministryTabs() as $t) {
    if ($t['requires'] === null || !empty($access[$t['requires']])) {
        $tabs[$t['id']] = $t;
    }
}
$tabAllowed = isset($tabs[$tab]);
$tabLabel = 'Overview';
foreach (\App\Core\Navigation\Workspaces::ministryTabs() as $t) {
    if ($t['id'] === $tab) {
        $tabLabel = $t['label'];
    }
}
if ($overview !== null && $actor !== null && !$tabAllowed) {
    http_response_code(403);
}
$name = (string) ($overview['name'] ?? '');

$pageTitle = $overview === null ? 'Ministry' : $name . ($tab !== 'overview' ? ' · ' . $tabLabel : '');

$when = static fn (string|DateTimeImmutable $d, string $format = 'D j M Y'): string => $d instanceof DateTimeImmutable
    ? $d->format($format)
    : (($t = strtotime($d)) !== false ? date($format, $t) : '');

$link = static fn (string $href, string $label, string $icon = '', string $class = 'ek-btn'): string =>
    '<a class="' . $class . '" href="' . $href . '">' . ($icon !== '' ? portal_icon($icon) : '') . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';

$empty = static fn (string $title, string $body, string $action = ''): string =>
    '<div class="ek-empty"><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong>'
    . '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>' . $action . '</div>';

$editorHref = $base . '/schedules?ministry_id=' . $ministryId;
$countCampus = $overview['countCampusId'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $e($pageTitle) ?> · Ministries · Church Portal</title>
    <style>
        body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
        .mw-meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
        .mw-grid{display:grid;gap:var(--sp-4,16px);grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));align-items:start}
        .mw-list{list-style:none;margin:0;padding:0;display:grid}
        .mw-list li{display:flex;align-items:baseline;gap:8px;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line,#d9e4dd);min-width:0}
        .mw-list li:last-child{border-bottom:0}
        .mw-list a{color:var(--teal-ink,#117b6d);text-decoration:none;font-weight:600}
        .mw-list a:hover{text-decoration:underline}
        .mw-sub{color:var(--muted);font-size:12px;white-space:nowrap}
        .mw-names{color:var(--muted);font-size:12px}
        .mw-chips{display:flex;flex-wrap:wrap;gap:6px}
        .mw-chips .ek-chip{cursor:default}
        .mw-chips .ek-chip b{font-variant-numeric:tabular-nums;color:var(--muted);font-weight:650}
        .mw-h3{margin:0 0 8px;font-size:13px;font-weight:700;color:var(--muted)}
        .mw-h3:not(:first-child){margin-top:14px}
        .mw-actions{display:grid;gap:8px}
        .mw-actions .ek-btn{justify-content:flex-start}
        .mw-note{margin:0;font-size:13px;color:var(--muted)}
        .mw-table td .ek-chip{min-height:22px;font-size:12px;padding:0 8px}
        .mw-table td.mw-do{white-space:nowrap;text-align:right}
        .mw-table .ek-btn{min-height:32px;padding:4px 10px;font-size:13px}
        .mw-inline{display:flex;flex-wrap:wrap;gap:8px;align-items:end}
        .mw-inline .ek-field{flex:1 1 200px}
        .mw-add{display:grid;gap:var(--sp-3,12px);grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto;align-items:end}
        .mw-typeahead{position:relative}
        .mw-results{position:absolute;top:100%;left:0;right:0;z-index:20;margin:4px 0 0;padding:4px;list-style:none;max-height:260px;overflow:auto;
            background:var(--paper,#fff);border:1px solid var(--line,#d9e4dd);border-radius:var(--radius,8px);box-shadow:0 12px 30px rgba(27,50,40,.16)}
        .mw-results li{padding:8px 10px;border-radius:6px;cursor:pointer;font-size:14px}
        .mw-results li[aria-selected="true"],.mw-results li:hover{background:var(--soft,#eef4f0)}
        .mw-results .mw-none{cursor:default;color:var(--muted)}
        .mw-date{display:grid;gap:10px;padding:var(--sp-3,12px) var(--sp-5,20px);border-bottom:1px solid var(--line,#d9e4dd)}
        .mw-date:last-child{border-bottom:0}
        .mw-date-head{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:baseline}
        .mw-date-head strong{font-size:15px}
        .mw-roles{display:grid;gap:6px;grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr))}
        .mw-role{display:grid;gap:2px;min-width:0}
        .mw-role-name{font-size:12px;font-weight:700;color:var(--muted)}
        .mw-role-people{font-size:14px;overflow-wrap:anywhere}
        .mw-filter{max-width:280px}
        [hidden]{display:none!important}
        @media (max-width:640px){
            .mw-add{grid-template-columns:1fr}
            .mw-table td.mw-do{white-space:normal;text-align:left}
            .mw-table thead{display:none}
            .mw-table tr{display:grid;gap:4px;padding:10px 12px;border-bottom:1px solid var(--line,#d9e4dd)}
            .mw-table td{padding:0;border:0}
        }
        @media print{
            .ek-tabs,.ek-crumbs,.ek-page-actions,.mw-noprint{display:none!important}
            .mw-date{break-inside:avoid}
        }
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
    <?= portal_header($basePath, '', '', $campuses, $campusSelector['defaultCampusId'] ?? null, $actor, [], [], [], 'Sign in', $basePath . '/login') ?>

    <main id="portal-main" tabindex="-1" class="ek-page">
        <p class="ek-crumbs"><a href="<?= $base ?>/ministries">Ministries</a><?= $overview !== null ? ' &rsaquo; ' . $e($name) : '' ?></p>

<?php if ($actor === null): ?>
        <?= ek_page_header('Ministry') ?>
        <?= pc_signed_out($basePath, 'this ministry') ?>

<?php elseif ($overview === null): ?>
        <?= ek_page_header('Ministry not found') ?>
        <div class="ek-card"><?= $empty('There is no ministry at this address', 'It may have been removed or made inactive. The directory lists every ministry you can open.', $link($base . '/ministries', 'Back to Ministries', '', 'ek-btn ek-btn-primary')) ?></div>

<?php else: ?>
        <?php
        $campusLabel = $overview['campusId'] === null ? 'All campuses' : ($campusNames[(int) $overview['campusId']] ?? 'Campus');
        $headerActions = '';
        if ($access['canEditSchedule']) {
            $headerActions .= $link($editorHref, 'Open schedule editor', 'calendar', 'ek-btn ek-btn-primary');
        }
        if ($access['canManageMinistries']) {
            $headerActions .= $link($base . '/admin/ministries#ministry-' . $ministryId, 'Rename or deactivate', 'settings');
        }
        $member = (int) $overview['memberCount'];
        ?>
        <header class="ek-page-header">
            <div class="ek-page-headings">
                <h1 class="ek-page-title"><?= $e($name) ?></h1>
                <div class="mw-meta">
                    <span class="ek-badge"><?= $e($campusLabel) ?></span>
                    <span class="ek-badge"><?= $member ?> <?= $member === 1 ? 'member' : 'members' ?><?= $countCampus !== null ? ' at ' . $e($campusNames[(int) $countCampus] ?? 'this campus') : '' ?></span>
                    <?php if ($overview['leadsIt']): ?><span class="ek-badge is-ok">You lead this ministry</span>
                    <?php elseif ($overview['isMine']): ?><span class="ek-badge is-ok">You are a member</span><?php endif; ?>
                    <?php if (!$overview['active']): ?><span class="ek-badge is-warn">Inactive</span><?php endif; ?>
                </div>
            </div>
            <?php if ($headerActions !== ''): ?><div class="ek-page-actions"><?= $headerActions ?></div><?php endif; ?>
        </header>

        <nav class="ek-tabs" aria-label="<?= $e($name) ?>">
            <?php foreach ($tabs as $t): ?>
                <a class="ek-tab" href="<?= $home . $e($t['suffix']) ?>"<?= $t['id'] === $tab ? ' aria-current="page"' : '' ?>><?= $e($t['label']) ?></a>
            <?php endforeach; ?>
        </nav>

        <div id="mwFlash" role="status" aria-live="polite"></div>

    <?php if (!$tabAllowed): ?>
        <div class="ek-card"><?= $empty(
            $tabLabel . ' is for this ministry\'s leaders',
            'Your account cannot see this ministry\'s people. Its overview and schedule are open to you.',
            $link($home, 'Go to the overview', '', 'ek-btn ek-btn-primary')
        ) ?></div>

    <?php elseif ($tab === 'overview'): ?>
        <?php
        $upcoming = $overview['upcoming'];
        $stats = [
            ['Members', (string) $overview['memberCount'], $access['canViewPeople'] ? $home . '/members' : ''],
            ['Leaders', (string) $overview['leaderCount'], $access['canViewPeople'] ? $home . '/members' : ''],
            ['Serving roles', (string) $overview['servingRoleCount'], $access['canViewPeople'] ? $home . '/roles' : ''],
            ['Dates with people scheduled (60 days)', (string) count($upcoming), $home . '/schedule'],
        ];
        ?>
        <div class="ek-stats">
            <?php foreach ($stats as [$label, $value, $href]): ?>
                <?php if ($href !== ''): ?>
                    <a class="ek-stat" href="<?= $href ?>"><span class="ek-stat-label"><?= $e($label) ?></span><span class="ek-stat-value"><?= $e($value) ?></span></a>
                <?php else: ?>
                    <div class="ek-stat"><span class="ek-stat-label"><?= $e($label) ?></span><span class="ek-stat-value"><?= $e($value) ?></span></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="mw-grid">
            <section class="ek-card" aria-labelledby="mwUpcoming">
                <div class="ek-card-head"><div><h2 id="mwUpcoming">Coming up</h2><p>Dates this ministry serves in the next 60 days.</p></div></div>
                <div class="ek-card-body">
                <?php if ($upcoming === []): ?>
                    <p class="mw-note">Nobody from <?= $e($name) ?> is scheduled in the next 60 days.</p>
                    <?php if ($access['canEditSchedule']): ?><p style="margin:10px 0 0"><?= $link($editorHref, 'Schedule people in the editor') ?></p><?php endif; ?>
                <?php else: ?>
                    <ul class="mw-list">
                    <?php foreach (array_slice($upcoming, 0, 8) as $u): ?>
                        <li><span><?= $e($u['title'] !== '' ? $u['title'] : 'Service') ?> <span class="mw-sub">· <?= $e($when($u['startsOn'], 'D j M, g:i a')) ?></span></span>
                            <span class="mw-sub"><?= (int) $u['assignmentCount'] ?> serving</span></li>
                    <?php endforeach; ?>
                    </ul>
                    <p style="margin:10px 0 0"><?= $link($home . '/schedule', 'See who serves on each date', '', 'ek-btn ek-btn-quiet') ?></p>
                <?php endif; ?>
                </div>
            </section>

            <?php if ($overview['leaders'] !== null): ?>
            <section class="ek-card" aria-labelledby="mwLeaders">
                <div class="ek-card-head"><div><h2 id="mwLeaders">Leaders</h2><p>Members marked as leaders of this ministry.</p></div></div>
                <div class="ek-card-body">
                <?php if ($overview['leaders'] === []): ?>
                    <p class="mw-note">No leader is named yet.</p>
                    <?php if ($access['canManageMembers']): ?><p style="margin:10px 0 0"><?= $link($home . '/members', 'Name a leader') ?></p><?php endif; ?>
                <?php else: ?>
                    <ul class="mw-list">
                    <?php foreach ($overview['leaders'] as $l): ?>
                        <li><a href="<?= $base ?>/people/<?= (int) $l['personId'] ?>"><?= $e($l['name']) ?></a></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                </div>
            </section>

            <section class="ek-card" aria-labelledby="mwPositions">
                <div class="ek-card-head"><div><h2 id="mwPositions">Positions and serving roles</h2><p>What members do here, and what the schedule fills.</p></div></div>
                <div class="ek-card-body">
                    <h3 class="mw-h3">Positions held by members</h3>
                    <?php if ($overview['positions'] === []): ?>
                        <p class="mw-note">No member has a position recorded.</p>
                    <?php else: ?>
                        <div class="mw-chips"><?php foreach ($overview['positions'] as $p => $n): ?><span class="ek-chip"><?= $e($p) ?> <b><?= (int) $n ?></b></span><?php endforeach; ?></div>
                    <?php endif; ?>
                    <h3 class="mw-h3">Serving roles</h3>
                    <?php $activeRoles = array_values(array_filter($overview['roles'], static fn (array $r): bool => $r['active'])); ?>
                    <?php if ($activeRoles === []): ?>
                        <p class="mw-note">This ministry has no serving roles, so the schedule has nothing to fill.</p>
                    <?php else: ?>
                        <div class="mw-chips"><?php foreach ($activeRoles as $r): ?><span class="ek-chip"><?= $e($r['name']) ?> <b><?= (int) $r['assignedCount'] ?></b></span><?php endforeach; ?></div>
                        <p class="ek-hint" style="margin:6px 0 0">Numbers are people who have served in each role.</p>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="ek-card" aria-labelledby="mwTools">
                <div class="ek-card-head"><div><h2 id="mwTools">Work with this ministry</h2></div></div>
                <div class="ek-card-body mw-actions">
                    <?php if ($access['canEditSchedule']): ?><?= $link($editorHref, 'Open the schedule editor', 'calendar') ?><?php endif; ?>
                    <?= $link($home . '/schedule', 'See the upcoming schedule', 'availability') ?>
                    <?php if ($access['canPrintSchedule']): ?><?= $link($base . '/printables/schedules', 'Print serving schedules', 'docs') ?><?php endif; ?>
                    <?= $link($base . '/rosters?ministry_id=' . $ministryId, 'Posted lists', 'docs') ?>
                    <?php if ($access['canViewPeople']): ?><?= $link($home . '/members', $access['canManageMembers'] ? 'Manage members and leaders' : 'Members and leaders', 'people') ?><?php endif; ?>
                    <?php if ($access['canManageMembers']): ?><?= $link($home . '/roles', 'Manage serving roles', 'settings') ?><?php endif; ?>
                </div>
            </section>
        </div>

    <?php elseif ($tab === 'members'): ?>
        <?php
        // One row per person, leaders first, then by name.
        usort($members, static fn (array $a, array $b): int => ((int) !empty($b['is_leader']) <=> (int) !empty($a['is_leader'])) ?: strcasecmp((string) $a['display_name'], (string) $b['display_name']));
        $leaderTotal = count(array_filter($members, static fn (array $m): bool => !empty($m['is_leader'])));
        ?>
        <?php if ($countCampus !== null): ?>
            <div class="ek-alert"><div>Showing members from <?= $e($campusNames[(int) $countCampus] ?? 'the selected campus') ?>. <a href="<?= $home ?>/members?campus=all">Show everyone in <?= $e($name) ?></a></div></div>
        <?php endif; ?>

        <?php if ($access['canManageMembers']): ?>
        <section class="ek-card mw-noprint" aria-labelledby="mwAddHead" style="overflow:visible">
            <div class="ek-card-head"><div><h2 id="mwAddHead">Add someone</h2><p>Adding a person who is already a member changes their role instead.</p></div></div>
            <div class="ek-card-body">
                <form class="mw-add" id="mwAddForm" novalidate>
                    <div class="ek-field mw-typeahead">
                        <label for="mwPerson">Person</label>
                        <input class="ek-input" id="mwPerson" type="text" autocomplete="off" placeholder="Type a name…"
                               role="combobox" aria-expanded="false" aria-controls="mwPersonResults" aria-autocomplete="list">
                        <input type="hidden" id="mwPersonId">
                        <ul class="mw-results" id="mwPersonResults" role="listbox" hidden></ul>
                    </div>
                    <div class="ek-field">
                        <label for="mwRole">As</label>
                        <select class="ek-select" id="mwRole"><option value="member">Member</option><option value="leader">Leader</option></select>
                    </div>
                    <button class="ek-btn ek-btn-primary" type="submit">Add to <?= $e($name) ?></button>
                </form>
            </div>
        </section>
        <?php endif; ?>

        <section class="ek-card" aria-labelledby="mwMembersHead">
            <div class="ek-card-head">
                <div><h2 id="mwMembersHead">Members <span class="mw-sub">(<?= count($members) ?>, <?= $leaderTotal ?> <?= $leaderTotal === 1 ? 'leader' : 'leaders' ?>)</span></h2>
                    <p>Positions are what someone does in this ministry (Usher, Emcee). Serving on a date is the schedule's job.</p></div>
                <?php if ($members !== []): ?>
                <div class="ek-field mw-filter mw-noprint"><label class="sr-only" for="mwFilter">Filter members</label>
                    <input class="ek-input" id="mwFilter" type="search" placeholder="Filter by name or position…" autocomplete="off"></div>
                <?php endif; ?>
            </div>
            <?php if ($members === []): ?>
                <?= $empty('No members yet', $access['canManageMembers'] ? 'Add the first person above.' : 'A leader of this ministry adds its members.') ?>
            <?php else: ?>
            <div class="ek-table-wrap">
                <table class="ek-table mw-table" id="mwMembers">
                    <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Positions</th><?php if ($access['canManageMembers']): ?><th scope="col"><span class="sr-only">Actions</span></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($members as $m): ?>
                        <?php
                        $pid = (int) $m['person_id'];
                        $positions = array_values(array_map('strval', (array) ($m['positions'] ?? [])));
                        $isLeader = !empty($m['is_leader']);
                        ?>
                        <tr data-person="<?= $pid ?>" data-name="<?= $e($m['display_name']) ?>" data-search="<?= $e(strtolower($m['display_name'] . ' ' . implode(' ', $positions))) ?>">
                            <td><a href="<?= $base ?>/people/<?= $pid ?>"><?= $e($m['display_name']) ?></a></td>
                            <td><?= $isLeader ? '<span class="ek-badge is-ok">Leader</span>' : 'Member' ?></td>
                            <td>
                                <span class="mw-chips"><?php foreach ($positions as $p): ?><span class="ek-chip"><?= $e($p) ?></span><?php endforeach; ?><?= $positions === [] ? '<span class="mw-sub">None</span>' : '' ?></span>
                                <?php if ($access['canManageMembers']): ?>
                                <form class="mw-inline mw-positions" hidden data-positions-form>
                                    <div class="ek-field"><label for="mwPos<?= $pid ?>">Positions, separated by commas</label>
                                        <input class="ek-input" id="mwPos<?= $pid ?>" type="text" maxlength="600" value="<?= $e(implode(', ', $positions)) ?>"></div>
                                    <button class="ek-btn ek-btn-primary" type="submit">Save</button>
                                    <button class="ek-btn ek-btn-quiet" type="button" data-cancel>Cancel</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <?php if ($access['canManageMembers']): ?>
                            <td class="mw-do">
                                <button class="ek-btn" type="button" data-act="positions" aria-label="Edit positions for <?= $e($m['display_name']) ?>">Positions</button>
                                <button class="ek-btn" type="button" data-act="<?= $isLeader ? 'unlead' : 'lead' ?>" aria-label="<?= $isLeader ? 'Make ' . $e($m['display_name']) . ' a member' : 'Make ' . $e($m['display_name']) . ' a leader' ?>"><?= $isLeader ? 'Make member' : 'Make leader' ?></button>
                                <button class="ek-btn ek-btn-danger" type="button" data-act="remove" aria-label="Remove <?= $e($m['display_name']) ?> from <?= $e($name) ?>">Remove</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="mwNoMatch" hidden><?= $empty('Nobody matches', 'Clear the filter to see every member.') ?></div>
            <?php endif; ?>
        </section>

    <?php elseif ($tab === 'roles'): ?>
        <?php if ($access['canManageRoles']): ?>
        <section class="ek-card mw-noprint" aria-labelledby="mwNewRole">
            <div class="ek-card-head"><div><h2 id="mwNewRole">Add a serving role</h2><p>A role is a slot the schedule editor fills on each date, such as Usher or Sound.</p></div></div>
            <div class="ek-card-body">
                <form class="mw-inline" id="mwRoleForm" novalidate>
                    <div class="ek-field"><label for="mwRoleName">Role name</label><input class="ek-input" id="mwRoleName" type="text" maxlength="128" required></div>
                    <div class="ek-field" style="flex:0 1 110px"><label for="mwRoleOrder">Order</label><input class="ek-input" id="mwRoleOrder" type="number" min="0" step="1" value="<?= count($roles) + 1 ?>"></div>
                    <button class="ek-btn ek-btn-primary" type="submit">Add role</button>
                </form>
            </div>
        </section>
        <?php endif; ?>

        <section class="ek-card" aria-labelledby="mwRolesHead">
            <div class="ek-card-head"><div><h2 id="mwRolesHead">Serving roles <span class="mw-sub">(<?= count($roles) ?>)</span></h2>
                <p>Inactive roles keep their history but are not offered in the schedule editor.</p></div>
                <?php if ($access['canEditSchedule']): ?><?= $link($editorHref, 'Open schedule editor', 'calendar') ?><?php endif; ?>
            </div>
            <?php if ($roles === []): ?>
                <?= $empty('No serving roles yet', $access['canManageRoles'] ? 'Add the first role above, then schedule people into it.' : 'A leader of this ministry sets up its serving roles.') ?>
            <?php else: ?>
            <div class="ek-table-wrap">
                <table class="ek-table mw-table" id="mwRoles">
                    <thead><tr><th scope="col">Order</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">People who have served</th><?php if ($access['canManageRoles']): ?><th scope="col"><span class="sr-only">Actions</span></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($roles as $r): ?>
                        <?php
                        $names = array_map(static fn (array $p): string => (string) ($p['display_name'] ?? $p['displayName'] ?? ''), (array) ($r['assignedMembers'] ?? []));
                        $names = array_values(array_filter($names, static fn (string $n): bool => $n !== ''));
                        ?>
                        <tr data-role="<?= (int) $r['id'] ?>" data-name="<?= $e($r['name']) ?>" data-order="<?= (int) $r['order'] ?>" data-active="<?= $r['active'] ? '1' : '0' ?>" data-count="<?= (int) $r['assignedCount'] ?>">
                            <td class="is-num"><?= (int) $r['order'] ?></td>
                            <td>
                                <strong><?= $e($r['name']) ?></strong>
                                <?php if ($access['canManageRoles']): ?>
                                <form class="mw-inline" hidden data-rename-form>
                                    <div class="ek-field"><label for="mwRn<?= (int) $r['id'] ?>">Name</label><input class="ek-input" id="mwRn<?= (int) $r['id'] ?>" type="text" maxlength="128" value="<?= $e($r['name']) ?>"></div>
                                    <div class="ek-field" style="flex:0 1 90px"><label for="mwRo<?= (int) $r['id'] ?>">Order</label><input class="ek-input" id="mwRo<?= (int) $r['id'] ?>" type="number" min="0" step="1" value="<?= (int) $r['order'] ?>"></div>
                                    <button class="ek-btn ek-btn-primary" type="submit">Save</button>
                                    <button class="ek-btn ek-btn-quiet" type="button" data-cancel>Cancel</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <td><?= $r['active'] ? '<span class="ek-badge is-ok">Active</span>' : '<span class="ek-badge">Inactive</span>' ?></td>
                            <td><?= (int) $r['assignedCount'] ?><?= $names !== [] ? ' <span class="mw-names">· ' . $e(implode(', ', array_slice($names, 0, 6))) . (count($names) > 6 ? ' and ' . (count($names) - 6) . ' more' : '') . '</span>' : '' ?></td>
                            <?php if ($access['canManageRoles']): ?>
                            <td class="mw-do">
                                <button class="ek-btn" type="button" data-act="rename" aria-label="Rename <?= $e($r['name']) ?>">Rename</button>
                                <button class="ek-btn" type="button" data-act="toggle" aria-label="<?= $r['active'] ? 'Deactivate' : 'Activate' ?> <?= $e($r['name']) ?>"><?= $r['active'] ? 'Deactivate' : 'Activate' ?></button>
                                <button class="ek-btn ek-btn-danger" type="button" data-act="delete" aria-label="Delete <?= $e($r['name']) ?>">Delete</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

    <?php elseif ($tab === 'schedule'): ?>
        <div class="ek-toolbar mw-noprint">
            <?php if ($access['canEditSchedule']): ?><?= $link($editorHref, 'Open schedule editor', 'calendar', 'ek-btn ek-btn-primary') ?><?php endif; ?>
            <?= $link($base . '/rosters?ministry_id=' . $ministryId, 'Posted lists', 'docs') ?>
            <?php if ($access['canPrintSchedule']): ?><?= $link($base . '/printables/schedules', 'Printable schedules', 'docs') ?><?php endif; ?>
            <button class="ek-btn" type="button" id="mwPrint"><?= portal_icon('docs') ?>Print this page</button>
        </div>

        <section class="ek-card" aria-labelledby="mwSchedHead">
            <div class="ek-card-head"><div><h2 id="mwSchedHead">The next eight weeks</h2>
                <p>Who serves on each date, by role<?= $countCampus !== null ? ', for ' . $e($campusNames[(int) $countCampus] ?? 'the selected campus') : '' ?>. Changes are made in the schedule editor.</p></div></div>
            <?php if ($scheduleError !== ''): ?>
                <div class="ek-card-body"><div class="ek-alert is-error"><div><strong>Schedule unavailable.</strong> <?= $e($scheduleError) ?></div></div></div>
            <?php elseif ($servingDates === null || $servingDates === []): ?>
                <?= $empty('No dates in the next eight weeks', 'There are no services this ministry is scheduled for yet.', $access['canEditSchedule'] ? $link($editorHref, 'Schedule people in the editor', '', 'ek-btn ek-btn-primary') : '') ?>
            <?php else: ?>
                <?php foreach ($servingDates as $d): ?>
                <article class="mw-date">
                    <div class="mw-date-head">
                        <strong><time datetime="<?= $e(substr($d['startsOn'], 0, 10)) ?>"><?= $e($when($d['startsOn'], 'l j F')) ?></time></strong>
                        <span class="mw-sub"><?= $e($d['title'] !== '' ? $d['title'] : 'Service') ?> · <?= $e($when($d['startsOn'], 'g:i a')) ?></span>
                    </div>
                    <?php if ($d['roles'] === []): ?>
                        <p class="mw-note">Nobody is scheduled yet.</p>
                    <?php else: ?>
                        <div class="mw-roles">
                        <?php foreach ($d['roles'] as $r): ?>
                            <div class="mw-role"><span class="mw-role-name"><?= $e($r['role']) ?></span><span class="mw-role-people"><?= $e(implode(', ', $r['people'])) ?></span></div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($d['roles'] !== [] && $d['unfilled'] !== []): ?>
                        <p class="mw-note">Not filled: <?= $e(implode(', ', $d['unfilled'])) ?></p>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
    </main>

    <?= portal_footer('Church Portal', 'Ministries') ?>
</div>
<?php if ($overview !== null && $actor !== null && $tabAllowed): ?>
<script>
(function () {
  'use strict';
  var BASE = <?= json_encode($basePath) ?> || '';
  var MINISTRY = <?= (int) $ministryId ?>;
  var NAME = <?= json_encode($name) ?>;
  var flash = document.getElementById('mwFlash');

  function say(message, isError) {
    if (!flash) return;
    flash.innerHTML = '';
    var box = document.createElement('div');
    box.className = 'ek-alert ' + (isError ? 'is-error' : 'is-ok');
    var text = document.createElement('div');
    if (isError) { var s = document.createElement('strong'); s.textContent = 'Not saved. '; text.appendChild(s); }
    text.appendChild(document.createTextNode(message));
    box.appendChild(text);
    flash.appendChild(box);
    if (isError) flash.scrollIntoView({ block: 'nearest' });
  }
  // A success survives the reload that shows it; storage is a convenience only.
  try { var kept = sessionStorage.getItem('mw-flash'); if (kept) { sessionStorage.removeItem('mw-flash'); say(kept, false); } } catch (e) {}
  function done(message) {
    try { sessionStorage.setItem('mw-flash', message); } catch (e) {}
    location.reload();
  }
  function api(method, path, body) {
    return fetch(BASE + path, {
      method: method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok || data.success === false) throw new Error(data.error || 'The server refused the change (' + r.status + ').');
        return data;
      });
    });
  }
  function busy(button, on) { if (button) { button.disabled = on; button.setAttribute('aria-busy', on ? 'true' : 'false'); } }

  document.getElementById('mwPrint') && document.getElementById('mwPrint').addEventListener('click', function () { window.print(); });

  // ---- Members & leaders ----
  var filter = document.getElementById('mwFilter');
  if (filter) {
    var rows = [].slice.call(document.querySelectorAll('#mwMembers tbody tr'));
    var none = document.getElementById('mwNoMatch');
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase(), shown = 0;
      rows.forEach(function (tr) { var hit = !q || (tr.getAttribute('data-search') || '').indexOf(q) !== -1; tr.hidden = !hit; if (hit) shown++; });
      if (none) none.hidden = shown !== 0;
    });
  }

  var membersTable = document.getElementById('mwMembers');
  if (membersTable) {
    membersTable.addEventListener('click', function (ev) {
      var btn = ev.target.closest('button[data-act]'); if (!btn) return;
      var tr = btn.closest('tr'); var pid = tr.getAttribute('data-person'); var who = tr.getAttribute('data-name');
      var act = btn.getAttribute('data-act');
      if (act === 'positions') {
        var form = tr.querySelector('[data-positions-form]');
        form.hidden = !form.hidden;
        if (!form.hidden) form.querySelector('input').focus();
        return;
      }
      if (act === 'remove' && !window.confirm('Remove ' + who + ' from ' + NAME + '?\n\nTheir positions here are removed too. Past schedules are not changed.')) return;
      busy(btn, true);
      var call = act === 'lead' ? api('POST', '/api/ministry/' + MINISTRY + '/leaders/' + pid)
        : act === 'unlead' ? api('DELETE', '/api/ministry/' + MINISTRY + '/leaders/' + pid)
        : api('DELETE', '/api/ministry/' + MINISTRY + '/members/' + pid);
      call.then(function () {
        done(act === 'lead' ? who + ' is now a leader of ' + NAME + '.' : act === 'unlead' ? who + ' is now a member of ' + NAME + '.' : who + ' was removed from ' + NAME + '.');
      }).catch(function (err) { busy(btn, false); say(err.message, true); });
    });
    membersTable.addEventListener('submit', function (ev) {
      var form = ev.target.closest('[data-positions-form]'); if (!form) return;
      ev.preventDefault();
      var tr = form.closest('tr');
      var list = form.querySelector('input').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
      var btn = form.querySelector('button[type=submit]'); busy(btn, true);
      api('POST', '/api/ministry/' + MINISTRY + '/members/' + tr.getAttribute('data-person') + '/positions', { positions: list })
        .then(function () { done('Positions saved for ' + tr.getAttribute('data-name') + '.'); })
        .catch(function (err) { busy(btn, false); say(err.message, true); });
    });
    membersTable.addEventListener('click', function (ev) {
      var cancel = ev.target.closest('[data-cancel]'); if (!cancel) return;
      var form = cancel.closest('form'); form.hidden = true;
      var opener = form.closest('tr').querySelector('button[data-act="positions"]'); if (opener) opener.focus();
    });
  }

  var addForm = document.getElementById('mwAddForm');
  if (addForm) {
    var input = document.getElementById('mwPerson'), hidden = document.getElementById('mwPersonId');
    var list = document.getElementById('mwPersonResults');
    var people = null, matches = [], active = -1;
    function loadPeople() {
      if (people !== null) return Promise.resolve(people);
      people = [];
      return fetch(BASE + '/api/people-directory?campus_id=all', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { people = (Array.isArray(d.people) ? d.people : []).map(function (p) { return { id: p.personId, name: p.displayName }; })
          .sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); }); return people; })
        .catch(function () { people = null; say('The people list could not be loaded. Try again.', true); return []; });
    }
    function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; }
    function render() {
      var q = input.value.trim().toLowerCase();
      matches = (people || []).filter(function (p) { return q !== '' && String(p.name).toLowerCase().indexOf(q) !== -1; }).slice(0, 30);
      list.innerHTML = '';
      if (q === '') { close(); return; }
      if (!matches.length) {
        var li = document.createElement('li'); li.className = 'mw-none'; li.textContent = 'Nobody by that name.'; list.appendChild(li);
      }
      matches.forEach(function (p, i) {
        var li = document.createElement('li'); li.id = 'mwOpt' + i; li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', i === active ? 'true' : 'false'); li.textContent = p.name; li.dataset.idx = String(i);
        list.appendChild(li);
      });
      list.hidden = false; input.setAttribute('aria-expanded', 'true');
      if (active >= 0) input.setAttribute('aria-activedescendant', 'mwOpt' + active); else input.removeAttribute('aria-activedescendant');
    }
    function pick(i) { var p = matches[i]; if (!p) return; hidden.value = p.id; input.value = p.name; close(); }
    input.addEventListener('focus', loadPeople);
    input.addEventListener('input', function () { hidden.value = ''; active = -1; loadPeople().then(render); });
    input.addEventListener('keydown', function (ev) {
      if (list.hidden) return;
      if (ev.key === 'ArrowDown') { ev.preventDefault(); active = Math.min(active + 1, matches.length - 1); render(); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); active = Math.max(active - 1, 0); render(); }
      else if (ev.key === 'Enter') { if (active >= 0 || matches.length === 1) { ev.preventDefault(); pick(active >= 0 ? active : 0); } }
      else if (ev.key === 'Escape') { close(); }
    });
    list.addEventListener('mousedown', function (ev) { var li = ev.target.closest('li[data-idx]'); if (!li) return; ev.preventDefault(); pick(parseInt(li.dataset.idx, 10)); });
    document.addEventListener('click', function (ev) { if (!ev.target.closest('.mw-typeahead')) close(); });
    addForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var pid = parseInt(hidden.value, 10);
      if (!pid) { input.setAttribute('aria-invalid', 'true'); say('Choose a person from the list first.', true); input.focus(); return; }
      input.removeAttribute('aria-invalid');
      var role = document.getElementById('mwRole').value;
      var btn = addForm.querySelector('button[type=submit]'); busy(btn, true);
      api('POST', '/api/ministry/' + MINISTRY + '/members/' + pid + '/role', { role: role })
        .then(function () { done(input.value + ' was added to ' + NAME + (role === 'leader' ? ' as a leader.' : '.')); })
        .catch(function (err) { busy(btn, false); say(err.message, true); });
    });
  }

  // ---- Serving roles ----
  var roleForm = document.getElementById('mwRoleForm');
  if (roleForm) {
    roleForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var nameInput = document.getElementById('mwRoleName');
      var roleName = nameInput.value.trim();
      if (!roleName) { nameInput.setAttribute('aria-invalid', 'true'); say('Give the role a name.', true); nameInput.focus(); return; }
      var btn = roleForm.querySelector('button[type=submit]'); busy(btn, true);
      api('POST', '/api/ministry/' + MINISTRY + '/roles', { name: roleName, order: parseInt(document.getElementById('mwRoleOrder').value, 10) || 0, active: true })
        .then(function () { done('Added the ' + roleName + ' role.'); })
        .catch(function (err) { busy(btn, false); say(err.message, true); });
    });
  }
  var rolesTable = document.getElementById('mwRoles');
  if (rolesTable) {
    rolesTable.addEventListener('click', function (ev) {
      var cancel = ev.target.closest('[data-cancel]');
      if (cancel) { var f = cancel.closest('form'); f.hidden = true; var o = f.closest('tr').querySelector('button[data-act="rename"]'); if (o) o.focus(); return; }
      var btn = ev.target.closest('button[data-act]'); if (!btn) return;
      var tr = btn.closest('tr'); var id = tr.getAttribute('data-role'); var roleName = tr.getAttribute('data-name');
      var act = btn.getAttribute('data-act');
      if (act === 'rename') { var form = tr.querySelector('[data-rename-form]'); form.hidden = !form.hidden; if (!form.hidden) form.querySelector('input').focus(); return; }
      if (act === 'toggle') {
        var on = tr.getAttribute('data-active') !== '1';
        busy(btn, true);
        api('PUT', '/api/ministry/roles/' + id, { active: on })
          .then(function () { done(roleName + (on ? ' is active again.' : ' is now inactive.')); })
          .catch(function (err) { busy(btn, false); say(err.message, true); });
        return;
      }
      if (act === 'delete') {
        var count = parseInt(tr.getAttribute('data-count'), 10) || 0;
        var warning = 'Delete the ' + roleName + ' role?\n\n' + (count > 0
          ? 'Every schedule entry in this role is deleted with it, including past dates (' + count + (count === 1 ? ' person has' : ' people have') + ' served in it). To keep that history, deactivate the role instead.'
          : 'Nobody has served in it yet.') + '\n\nThis cannot be undone.';
        if (!window.confirm(warning)) return;
        busy(btn, true);
        api('DELETE', '/api/ministry/roles/' + id)
          .then(function () { done('Deleted the ' + roleName + ' role.'); })
          .catch(function (err) { busy(btn, false); say(err.message, true); });
      }
    });
    rolesTable.addEventListener('submit', function (ev) {
      var form = ev.target.closest('[data-rename-form]'); if (!form) return;
      ev.preventDefault();
      var tr = form.closest('tr'); var inputs = form.querySelectorAll('input');
      var newName = inputs[0].value.trim();
      if (!newName) { inputs[0].setAttribute('aria-invalid', 'true'); say('A role needs a name.', true); inputs[0].focus(); return; }
      var btn = form.querySelector('button[type=submit]'); busy(btn, true);
      api('PUT', '/api/ministry/roles/' + tr.getAttribute('data-role'), { name: newName, order: parseInt(inputs[1].value, 10) || 0 })
        .then(function () { done('Saved the ' + newName + ' role.'); })
        .catch(function (err) { busy(btn, false); say(err.message, true); });
    });
  }
})();
</script>
<?php endif; ?>
</body>
</html>
