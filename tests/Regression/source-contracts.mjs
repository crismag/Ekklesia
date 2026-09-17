#!/usr/bin/env node
/**
 * Source-contract checks that do not require PHP.
 * Complements tests/Regression/run.php (service-layer assertions).
 *
 * Usage: node tests/Regression/source-contracts.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
let failed = 0;
let passed = 0;

function read(rel) {
  return fs.readFileSync(path.join(root, rel), 'utf8');
}

function ok(cond, message) {
  if (cond) {
    passed += 1;
    console.log(`  ok  ${message}`);
    return;
  }
  failed += 1;
  console.log(`  FAIL ${message}`);
}

console.log('People privacy');
const people = read('app/Http/Controllers/Api/PeopleController.php');
ok(people.includes('function maskedName'), 'maskedName() exists');
ok(people.includes('strtoupper(substr($lastName, 0, 1))'), 'last-name initial masking');
ok(people.includes('if ($privileged)'), 'privileged gate for contact/address');
ok(people.includes("'contact'") && people.includes("'address'"), 'privileged keys contact/address');
ok(people.includes("$base['lastName'] = $lastName;"), 'full last name is privileged-only');

console.log('Campus cookie / compact chrome');
const shell = read('resources/views/_portal-shell.php');
ok(shell.includes('portal_campus_id'), 'cookie portal_campus_id');
ok(shell.includes('id="campusSelect"'), 'select#campusSelect');
ok(shell.includes('820px') && shell.includes('.topbar-campus'), '820px hides .topbar-campus');
ok(/display\s*:\s*none/.test(shell) && shell.includes('.topbar-campus'), 'display:none on campus chrome');

console.log('Destructive confirmations');
// What matters is that a destructive action is confirmed and that the prompt
// names the act. Pinning the exact button label made this fail the moment a
// prompt improved: admin-users now says "Delete the account for <email>",
// which is better than "Delete user" and did not contain it.
const confirms = [
  ['resources/views/admin-users.php', /delete/i],
  ['resources/views/admin-person-view.php', /delete/i],
  ['resources/views/admin-family-edit.php', /delete/i],
  ['resources/views/admin-campuses.php', /delete/i],
  ['resources/views/admin-family-duplicates.php', /merge/i],
  ['resources/views/admin-options.php', /delete/i],
  ['resources/views/admin-event-types.php', /delete/i],
];
for (const [file, verb] of confirms) {
  const src = read(file);
  ok(src.includes('confirm('), `${file} confirm()`);
  const prompts = [...src.matchAll(/confirm\((['"`])([\s\S]{0,200}?)\1/g)].map(m => m[2]);
  ok(prompts.some(t => verb.test(t)),
     `${file} confirmation names the act (${verb})`);
  ok(!prompts.some(t => /^\s*(are you sure\??|confirm\??)\s*$/i.test(t)),
     `${file} confirmation says more than "are you sure"`);
}

console.log('Auth / schedule deny / HTML vs API');
const web = read('routes/web.php');
ok(web.includes("'GET /'"), 'GET / HTML route');
ok(web.includes("'GET /admin'"), 'GET /admin HTML route');
ok(web.includes('Home is the portal entry for everyone'), 'home route treats missing auth as a guest, not a 500');
ok(web.includes('isPortalWideAdmin'), 'admin mutations check isPortalWideAdmin');
ok(web.includes('schedule_editor_denied'), 'schedule_editor_denied notice');
const auth = read('app/Http/Controllers/Api/AuthController.php');
ok(auth.includes('mustChangePassword'), 'mustChangePassword on login');
const front = read('public/index.php');
ok(front.includes('PermissionDenied'), 'front controller catches PermissionDenied');

console.log('Permission maps');
const perms = read('app/Core/PortalPermission.php');
ok(perms.includes('function forRole'), 'PortalPermission::forRole');
// Leader-audience events are hidden by a SQL predicate, and these strings are
// the data contract that predicate compares against — a typo would quietly
// make an elders' meeting visible to the whole congregation.
ok(perms.includes("ViewLeaderEvents = 'view_leader_events'"), 'ViewLeaderEvents permission exists');
{
  const roleRow = (role) => new RegExp(`'${role}'\\s*=>\\s*\\[([^\\]]*)\\]`, 's').exec(perms)?.[1] ?? '';
  ok(!roleRow('member').includes('ViewLeaderEvents'), 'member role is not granted ViewLeaderEvents');
  ok(!roleRow('scheduler').includes('ViewLeaderEvents'), 'scheduler role is not granted ViewLeaderEvents');
  ok(roleRow('leader').includes('ViewLeaderEvents'), 'leader role is granted ViewLeaderEvents');
}
// The calendar's layer plumbing. The whitelist below is the one that would
// have shipped this feature dark: a saved v1 filter set listed seven fixed
// sources, so every per-type layer added later was filtered out for anyone who
// had already loaded the page.
const cal = read('resources/views/calendar.php');
ok(cal.includes('church_portal_calendar_sources_v2'), 'calendar uses the v2 filter key');
ok(!cal.includes('allowed.includes(item.source)'), 'calendar no longer whitelists known sources');
ok(cal.includes('/api/calendar/layers'), 'calendar fetches its chips from the server');
ok(read('resources/views/calendar-settings.php').includes('church_portal_calendar_sources_v2'),
   'calendar settings shares the v2 key with the calendar');
ok(read('app/Adapters/Sql/SqlCalendarAdapter.php').includes("'events:' . $slug"),
   'calendar items namespace their source per event type');
// The picker and the INSERT are what make the type column real; a regression in
// either silently returns every new event to a dangling event_type_id.
ok(read('app/DTO/Events/EventCreateCommand.php').includes('eventTypeId'), 'create command carries the event type');
ok(read('app/Adapters/Sql/SqlEventAdapter.php').includes(':event_type_id'),
   'the events INSERT/UPDATE writes event_type_id');
const evEditor = read('resources/views/_event-editor.php');
ok(evEditor.includes('id="eeType"'), 'the editor offers a type');

const audience = read('app/Core/EventAudience.php');
ok(audience.includes("case Leaders = 'leaders'"), 'EventAudience leaders case matches the seeded value');
ok(audience.includes('?? self::Members'), 'EventAudience falls back to members, never leaders');
for (const f of ['app/Adapters/Sql/SqlEventAdapter.php', 'app/Adapters/Sql/SqlCalendarAdapter.php']) {
  const src = read(f);
  ok(src.includes('et.audience'), `${f} filters on event_types.audience`);
  ok(src.includes('LEFT JOIN event_types'), `${f} LEFT JOINs event_types so dangling ids stay visible`);
}
ok(perms.includes('ViewOwnAssignments'), 'ViewOwnAssignments exists');
ok(perms.includes('ManageEvents'), 'ManageEvents exists');
ok(perms.includes("'member'") && perms.includes('ViewOwnAssignments'), 'member role mapping present');

console.log('Home is the portal dashboard, not the church website');
const home = read('resources/views/index.php');
const homeSvc = read('app/Services/HomePageService.php');
for (const [needle, what] of [
  ['class="hero', 'a promotional hero'], ['heroBoot', 'the banner rotator'], ['api/hero', 'banner settings'],
  ['ministry-tile', 'ministry marketing tiles'], ['Visit and contact', 'a visit/contact card'],
  ['tel:', 'a phone link'], ['mailto:', 'an email link'], ['addressLine', 'the church address'],
]) {
  ok(!home.includes(needle), `home has no ${what}`);
}
ok(!homeSvc.includes('makeChurchInfoService') && !homeSvc.includes('makeHeroSettingsService'),
  'home composition reads no church presentation settings');
ok(homeSvc.includes('Workspaces::visible('), 'home workspace shortcuts come from the workspace map');
ok(homeSvc.includes('->getMySchedule(') && homeSvc.includes('->agenda($ctx'),
  'home reads serving and events through the services that apply their rules');
ok(homeSvc.includes('makeAnnouncementSettingsService') && homeSvc.includes('function portalNotices'),
  'portal notices are read in one place, ready to move to a notices service');
ok(!/\b(SELECT|INSERT|UPDATE|DELETE)\s/.test(homeSvc), 'HomePageService holds no SQL');
ok(home.includes('<?= portal_footer() ?>'), 'home uses the shared portal footer');
ok(!home.includes('Continue Working') && !home.includes('Shout Out') && !home.includes('messageList'),
  'home ships no placeholder panels');

console.log('Account profile');
const acct = read('resources/views/account.php');
const acctRoutes = read('routes/web.php');
const personSvc = read('app/Services/PersonAdminService.php');
// The endpoint takes the person from the session and has no id parameter, so
// there is nothing to tamper with. If an id ever appears in this handler, the
// page has become a way to edit other people.
ok(acctRoutes.includes("'POST /account/profile'"), 'account has a self-service profile save');
{
  // Slice from this route key to the next one at the same indent, so the
  // assertions below look at this handler and nothing else.
  const start = acctRoutes.indexOf("'POST /account/profile'");
  const rest = acctRoutes.slice(start + 10);
  const next = rest.search(/\n    '(GET|POST|PUT|DELETE) /);
  const handler = next === -1 ? rest : rest.slice(0, next);
  ok(handler.includes("(int) ($actor['personId'] ?? 0)"),
    'the profile save takes its person from the session actor');
  ok(!/\$req\[.(id|person_id).\]/.test(handler),
    'the profile save never reads a person id out of the request');
}
// Field scope. These columns are the church's record of a person's standing and
// household, and a household carries a shared address with it -- self-service
// must not be a way to join one.
ok(personSvc.includes('OWN_STR_FIELDS'), 'self-service writes go through their own field whitelist');
{
  const list = /OWN_STR_FIELDS = \[([\s\S]*?)\];/.exec(personSvc)?.[1] ?? '';
  for (const forbidden of ['membership_status_id', 'household_id', 'household_role_id', 'member_type_id', 'member_since', 'campus_id']) {
    ok(!list.includes(forbidden), `self-service cannot write ${forbidden}`);
  }
}
ok(personSvc.includes('function saveOwnProfile'), 'saveOwnProfile exists');
ok(!/function saveOwnProfile[\s\S]*?\n    \}/.exec(personSvc)?.[0].includes('$this->save('),
  'saveOwnProfile does not delegate to the full-record save, which blanks omitted columns');
// A class with a display rule beats [hidden]; this page hides its edit form and
// its tab panels that way, and the bug has shipped here before.
ok(acct.includes('[hidden] { display:none !important; }'),
  'account defeats the class-beats-[hidden] trap its panels rely on');
ok(acct.includes('role="tablist"') && acct.includes('aria-controls="panel-household"'),
  'account sections are a real tablist');
ok(acct.includes('id="photoFile"') && acct.includes('/people/photo'),
  'the owner can change their own photo through the existing photo endpoint');
ok(acct.includes('/ministries/<?= (int) $m[\'ministry_id\'] ?>'),
  'ministries on the profile link to the ministry, not just name it');

console.log('Hub ministry catalog');
const cat = read('config/ministry-catalog.json');
ok(cat.includes('"name": "Gifts and Arrows"'), 'catalog uses Gifts and Arrows');
ok(cat.includes('"name": "Guest Services"'), 'catalog keeps Guest Services as one ministry');
ok(!cat.includes('"name": "GS: Usher"'), 'catalog does not treat GS: Usher as a ministry');
ok(cat.includes('"Usher"') && cat.includes('"Emcee"'), 'Guest Services roles include Usher and Emcee');
ok(read('tools/sync-ministry-catalog.php').includes('--apply'), 'sync tool has --apply');

// --- Events: the defects that made the section unusable ---------------------
console.log('Events');
const evList = read('resources/views/events-list.php');
const evNew = read('resources/views/events-new.php');
const evCmd = read('app/DTO/Events/EventCreateCommand.php');
const evAdapter = read('app/Adapters/Sql/SqlEventAdapter.php');
const evService = read('app/Services/EventService.php');
const evRoutes = read('routes/web.php');

// Creation used prompt('Event title') and posted a bare title to an endpoint
// that could not succeed, because an event's start is NOT NULL.
ok(!evList.includes("prompt('Event title')"), 'events list does not create events through a prompt()');
ok(evList.includes('/events/new'), 'events list links to a real create form');
ok(evRoutes.includes("'GET /events/new'"), 'a create-event route exists');
ok(evEditor.includes('id="eeForm"'), 'the shared editor form exists');
// One editor, three callers. Three separate implementations of the same form
// is how they drifted into three different interaction models.
ok(evNew.includes("_event-editor.php") && evNew.includes('ee_html('),
   'the create page uses the shared editor rather than its own form');
ok(read('resources/views/events-detail.php').includes('ee_html('),
  'the event page uses the same editor');
ok(read('resources/views/events-detail.php').includes('This activity'),
  'event detail groups the activity itself');
ok(read('resources/views/events-detail.php').includes('aria-label="This date"'),
  'event detail groups one-date tools separately from the activity');
ok(!read('resources/views/events-detail.php').includes("portal_shell_mods('wide')"),
  'event detail does not stretch the workspace to a marketing layout');
ok(read('resources/views/calendar.php').includes("/events/new?"),
   'the calendar creates through the same page, prefilled');

// The create path must carry a date, or every save fails on the NOT NULL column.
ok(evCmd.includes('$startDate'), 'create command carries a start date');
ok(!evAdapter.includes('VALUES (:title, :summary, NULL'), 'adapter never inserts NULL into starts_on');
ok(evAdapter.includes(':starts_on') && evAdapter.includes(':end_time'), 'adapter binds a real start and end');

// Scheduling shapes a church calendar actually needs. The editor renders its
// repeat options from RecurrenceRule::PRESETS, so that is where they live.
const evRule = read('app/Services/Events/RecurrenceRule.php');
for (const pattern of ['one_off', 'weekly', 'biweekly', 'monthly', 'selected']) {
  ok(evRule.includes(`'${pattern}'`), `the editor offers the ${pattern} pattern`);
}
ok(evEditor.includes('RecurrenceRule::PRESETS'),
   'the editor renders its repeat options from the rule, not from its own list');
// A preset the column cannot store must not be offered: it would silently do
// something other than what it says.
ok(!/First |Second |Third |Last /.test(evRule.slice(evRule.indexOf('PRESETS'), evRule.indexOf('STEPPED'))),
   'no week-of-month preset is offered in the fixed-step presets');
ok(evService.includes("'selected'"), 'service resolves an explicit list of selected dates');
ok(evService.includes('resolveScheduleDates'), 'service turns the schedule choice into concrete dates');
ok(evService.includes('insertOccurrences'), 'creating an event also creates its occurrences for the calendar');

// Campus is not location, and the topbar campus context must reach the list.
ok(evAdapter.includes(':location_name'), 'adapter persists a location distinct from campus');
ok(evAdapter.includes('$hostCampusId'), 'adapter records which campus hosts the event');
ok(evRoutes.includes('portal_campus_id'), 'events list honours the topbar campus cookie');

// The list is a management surface, not a stack of cards.
ok(evList.includes('ev-table'), 'events list renders a scannable table');
ok(evList.includes('ev-periods'), 'events list offers period browsing');
ok(evList.includes('range=') || evRoutes.includes("\$req['range']"), 'events list supports a range filter');

// --- Campus selector -------------------------------------------------------
// It defaulted to Scarborough on the dashboard, docs and every admin page, and
// "All campuses" snapped back to a single campus.
console.log('Campus selector');
const shellSrc = read('resources/views/_portal-shell.php');
const ministrySrc = read('app/Services/MinistryService.php');

ok(!ministrySrc.includes("'Scarborough'"),
  'no campus is hardcoded by name as the default');
ok(ministrySrc.includes("!empty($campus['is_main'])"),
  'the default campus comes from the configured main campus');
ok(ministrySrc.includes('!$context->isPortalWideAdmin'),
  'a portal-wide admin is not silently scoped to their own campus');
ok(shellSrc.includes('$cookieDecided'),
  'the campus cookie outranks whatever a page passed in');
ok(shellSrc.includes("$raw === ''"),
  'an empty cookie means All campuses rather than no preference');
ok(shellSrc.includes('isset($available[$cookieVal])'),
  'a cookie naming an inaccessible campus is ignored, not shown as All');

// The selector sends "All campuses" as 0, not as an absent parameter. Treating
// that as a real campus id made every campus-aware read filter on campus 0,
// which matches nothing — the calendar showed no events and no birthdays.
const ctxSrc = read('app/Http/Requests/PortalRequestContext.php');
ok(ctxSrc.includes("(int) $request['current_campus_id'] > 0"),
  'campus 0 means All campuses, not a campus to filter on');

console.log('Fluid application shell');
ok(shellSrc.includes('data-layout='), 'portal_shell_mods emits data-layout');
ok(shellSrc.includes("function portal_shell_mods"), 'declarative shell API exists');
ok(shellSrc.includes('.portal-body'), 'three-region portal-body grid exists');
ok(shellSrc.includes('--portal-left-collapsed'), 'collapsed left rail token exists');
ok(shellSrc.includes('--container-readable'), 'readable content width token exists');
ok(!/^\.shell\{width:min\(/m.test(shellSrc.replace(/\s+/g, '')), 'global .shell is not a centered max-width container');
ok(shellSrc.includes('width:100%') && shellSrc.includes('max-width:none'), 'global .shell is fluid');
ok(shellSrc.includes('@media print'), 'screen shell print isolation exists');
ok(read('resources/views/calendar.php').includes("portal_shell_mods('workspace', 'open'"),
  'calendar opts into the workspace shell with a left rail');
ok(read('resources/views/calendar.php').includes('portal_left_toggle'),
  'calendar uses the shared left-rail collapse control');
ok(shellSrc.includes("function portal_shell_mods(string \$layout = 'workspace'"),
  'portal_shell_mods defaults to workspace, not wide');
ok(shellSrc.includes('Default: the working surface fills the shell'),
  'workspace fill is the CSS default for #portal-main');
ok(!/shell>#portal-main[\s\S]{0,80}width:min\(100%,var\(--container-wide/.test(shellSrc),
  'workspace #portal-main is not a centered --container-wide column');
ok(read('resources/views/events-new.php').includes("portal_shell_mods('workspace')"),
  'event create fills the workspace; the editor, not the page, stays compact');
ok(read('resources/views/docs.php').includes("portal_shell_mods('workspace')"),
  'docs grid fills the workspace; the article keeps its own readable max-width');
ok(read('resources/views/index.php').includes("portal_shell_mods('workspace')"),
  'home fills the workspace like the rest of the portal');
ok(!read('resources/views/index.php').includes("portal_shell_mods('wide')"),
  'home is not a centered wide column');
ok(read('resources/views/login.php').includes("portal_shell_mods('wide')"),
  'login opts into wide');
ok(read('resources/views/account.php').includes("portal_shell_mods('wide')"),
  'account opts into wide');
ok(read('resources/views/availability.php').includes("portal_shell_mods('readable')"),
  'availability stays on a readable surface');
ok(!read('resources/views/events-occurrence-new.php').includes('class="container"'),
  'occurrence generator is not a leftover Bootstrap .container');
ok(read('resources/views/_admin-shell.php').includes("portal_shell_mods('workspace')"),
  'admin shell is a workspace, not a centered 1320px page');
ok(!read('resources/views/calendar.php').includes('width:min(1240px'),
  'calendar no longer escapes a page-local max-width');
ok(read('resources/views/print/_shell.php').includes('@page'),
  'printable calendars keep independent paper @page rules');

// ---------------------------------------------------------------------------
// The print setup offers what a printout can actually carry.
console.log('Printable calendar options');
const printRoute = read('routes/web.php');
const printView = read('resources/views/calendar-print.php');

// Layers that can only ever print nothing are not offered. Anniversaries have
// no wedding dates in the register; "Others" are custom calendars kept in the
// reader's own browser and never reach the server at all.
ok(printRoute.includes("$notPrintable = ['anniversaries', 'custom', 'events:sunday-school']"),
   'unprintable layers are excluded by name, in one place');
ok(printRoute.includes('/admin/event-types'),
   'and the durable route for the one that is a setting is written down beside it');

// Two things a church prints; everything else is a one-off.
ok(printRoute.includes("'Birthdays & holidays'") && printRoute.includes("'Ministry & leadership'"),
   'both common selections are offered as presets');
ok(printRoute.includes('array_intersect($preset[\'sources\'], $available)'),
   'a preset only ticks layers this portal actually has');

// Nothing is ticked from the markup: the default comes from a preset, so the
// two stay in step.
ok(!/name="source"[^>]*checked/.test(printView),
   'no source is checked in the markup — the default is a preset');
// The heading became an accordion section when the sidebar was reorganised, so
// the group carries its own accessible name rather than borrowing a nearby <h2>.
ok(/<div class="pc-chips" role="group" aria-label="[^"]+"/.test(printView),
   'the group of calendars has an accessible name of its own');
ok(printView.includes('aria-label="Calendars to include"'), 'and it says what it is for');
ok(!printView.includes('What should appear'), 'and not what it used to say');

// A preset sets the boxes and then gets out of the way: someone who picks one
// and then ticks an extra layer should not have it undone.
ok(printView.includes("aria-pressed"), 'preset state is exposed, not just coloured');
ok(printView.includes('Nothing selected'),
   'an empty selection says so rather than printing a blank sheet silently');

console.log('Phase 2 navigation — workspace map, sidebar and drawer');
const chrome = JSON.parse(read('config/chrome.json'));
const workspacesSrc = read('app/Core/Navigation/Workspaces.php');
ok(chrome.header.brandTitle === 'Ekklesia', 'the product is named Ekklesia by default');
ok(!shellSrc.includes("chrome['header']['primaryNav']"),
  'chrome.json primaryNav no longer drives navigation');
ok(!shellSrc.includes('class="portal-nav"') && !shellSrc.includes('nav-tab'),
  'the top-bar tab row is gone');
ok(!shellSrc.includes('id="moreBtn"'), 'the overflow menu is gone — every destination is in the workspace map');
ok(shellSrc.includes('ek_workspace_nav($basePath, $actor, $location)')
  && shellSrc.includes("ek_workspace_nav($basePath, $actor, $location, 'Workspaces (menu)')"),
  'the sidebar and the drawer render the same workspace navigation');
for (const label of ['Home', 'People & Records', 'Ministries', 'Events & Calendar', 'Serving & Scheduling', 'Visitors & RSVPs', 'Admin']) {
  ok(workspacesSrc.includes(`'label' => '${label}'`), `workspace exists: ${label}`);
}
ok(workspacesSrc.includes("'label' => 'Printables', 'href' => '/printables'"), 'Printables lives in Serving & Scheduling');
ok(workspacesSrc.includes("'label' => 'Portal appearance & notices'"), 'Admin groups portal appearance & notices');
ok(!workspacesSrc.includes('Website & appearance'), 'the portal is not labelled as the church website');
ok(shellSrc.includes("'/docs'") && shellSrc.includes('User guide'), 'the user guide stays reachable from the sidebar and drawer');
ok(/\.ek-sidebar\{display:none\}/.test(shellSrc) && shellSrc.includes('@media screen and (min-width:1024px)'),
  'the sidebar shows only on desktop screens, never on paper');
ok(/@media print\{[^}]*\.ek-sidebar/.test(shellSrc), 'the sidebar never prints');
ok(shellSrc.includes('@media(max-width:1023px){.ham-btn{display:grid!important}}'),
  'below 1024px the menu button opens the drawer');
ok(shellSrc.includes('ekklesia_sidebar_rail_v1') && !/localStorage[^\n]*(aria-current|is-current|location)/.test(shellSrc),
  'storage remembers only the collapsed sidebar, never which page is current');
ok(shellSrc.includes('background:#fff center/cover no-repeat;box-shadow'),
  'the brand mark background declaration is terminated');
ok(shellSrc.includes("id=\"campusSelect\""), 'campus selector is kept');
ok(shellSrc.includes("id=\"searchBtn\""), 'search is kept');
ok(shellSrc.includes("basePath+'/events/'+id"),
  'search opens an event at /events/{id} when the API supplies an id');
ok(!/href:basePath\+'\/events',type:'event'/.test(shellSrc.replace(/\s+/g, '')),
  'search event results are not all the events list');

const adminEventsSrc = read('resources/views/admin-events.php');
ok(adminEventsSrc.includes('/events/new'), 'admin Events New event uses /events/new');
ok(!adminEventsSrc.includes('/events/occurrence/new'),
  'admin Events does not send New event to the leftover occurrence generator');
ok(read('resources/views/admin-me.php').includes('/account'),
  'admin Me sends people to Account');
ok(!read('resources/views/admin.php').includes('PORTAL_BASE_PATH'),
  'admin Overview does not dump PORTAL_* env');
ok(!read('resources/views/admin.php').includes('/admin/me'),
  'admin Overview does not tile My Pages');

console.log('Wave 4 — staff from the day inspector');
const calStaff = read('resources/views/calendar.php');
const schedCtl = read('app/Http/Controllers/Api/ScheduleController.php');
const apiRoutes = read('routes/api.php');
ok(apiRoutes.includes("'GET /api/schedules/staffing'"),
  'staffing ids are routed on the existing schedule controller');
ok(apiRoutes.includes("'POST /api/schedules/assignments'"),
  'assignment writes stay on POST /api/schedules/assignments');
ok(schedCtl.includes('function staffing'),
  'ScheduleController exposes staffing as a read over the existing grid');
ok(schedCtl.includes('restrictToSchedulingEvents: false'),
  'staffing uses the full day window, not only default scheduling events');
ok(calStaff.includes('/api/schedules/staffing'),
  'the inspector loads staffing ids from GET /api/schedules/staffing');
ok(calStaff.includes('/api/schedules/assignments'),
  'the inspector writes through POST /api/schedules/assignments');
ok(calStaff.includes('No start/end'),
  'the inspector omits start/end so a one-role save cannot diff-delete the week');
ok(!calStaff.includes('/api/calendar/assign'),
  'the inspector does not invent a calendar assignment POST');
ok(!calStaff.includes("fetch(basePath + '/api/rosters'"),
  'the inspector does not write posted-list rosters');
ok(calStaff.includes('/schedules?') && calStaff.includes('ministry_id'),
  'a staffed role still deep-links to the serving grid');
ok(calStaff.includes('Busy at the same time'),
  'conflict labels from the grid are shown, not swallowed');

console.log('Wave 5–7 — screen views, right rail, print this view, unfilled');
const printCfg = read('app/Services/Calendar/PrintConfig.php');
ok(printCfg.includes("'screen' => ['view' => '', 'left' => '']"),
  'PrintConfig defaults screen.view and screen.left to empty');
ok(printCfg.includes("screenView") && printCfg.includes("screenLeft"),
  'screen state uses screenView/screenLeft so it cannot collide with print-setup view=');
ok(read('app/Services/Calendar/SavedViewService.php').includes('function screenOf'),
  'SavedViewService exposes screenOf on the same row as print');
ok(read('app/Http/Controllers/Api/SavedViewController.php').includes("'screen' =>"),
  'GET /api/calendar/views/{id} returns screen alongside config');

const calViews = read('resources/views/calendar.php');
ok(calViews.includes('Open view'), 'calendar offers Open view');
ok(calViews.includes('Save view'), 'calendar offers Save view');
ok(calViews.includes('/api/calendar/views'), 'calendar talks to the existing views API');
ok(calViews.includes("visibility: 'private'"), 'calendar save creates a private view rather than overwriting a shared one');
ok(calViews.includes("source !== 'custom'") || calViews.includes("l.source === 'custom'"),
  'custom calendars stay local and are not treated as shared view sources');
ok(calViews.includes('Print this view'), 'calendar offers Print this view');
ok(calViews.includes("/calendar/print-setup?'"), 'Print this view opens print-setup with a query');
ok(calViews.includes("dateMode', 'custom'"), 'Print this view sends the visible date range');
ok(calViews.includes('id="portalRight"') && calViews.includes('portal_right_toggle'),
  'the day inspector lives in the shell right rail');
ok(calViews.includes("portal_shell_mods('workspace', 'open', 'collapsed')"),
  'calendar starts with the right rail collapsed until a day is opened');
ok(calViews.includes('id="dayUnfilledCount"'), 'the inspector shows how many roles are unfilled');
ok(!calViews.includes('/api/calendar/assign'),
  'the inspector does not invent a calendar assignment POST');

ok(shellSrc.includes('function portal_right_toggle'), 'portal_right_toggle exists');
ok(shellSrc.includes('.portal-right{') || shellSrc.includes('.portal-right{') || shellSrc.includes('.portal-right'),
  'right rail CSS exists');
ok(shellSrc.includes('[data-portal-right-toggle]'),
  'church_portal_shell_v1 persists the right rail like the left');
ok(shellSrc.includes('@media(max-width:760px)') && shellSrc.includes('.portal-right-toggle{display:none}'),
  'the right-rail toggle is hidden below 760px with the left');

const printStudio = read('resources/views/calendar-print.php');
ok(printStudio.includes('fromCalendar'),
  'print-setup overlays Calendar query sources/dates when no saved view is open');
ok(printStudio.includes("qs.get('sources')"),
  'print-setup reads sources from the Print this view query');

const servingGrid = read('resources/views/schedule-editor.php');
// The count survived the workspace redesign; the element and the function that
// produce it did not. These assert the property, not the old implementation.
ok(servingGrid.includes('id="wsUnfilled"'), 'the serving grid shows an unfilled role count');
ok(servingGrid.includes('function scheduleTotals') && servingGrid.includes('function updateProgress'),
  'the count is derived from the same cells map the slots render, so the two cannot disagree');
ok(servingGrid.includes('id="wsProgressLine"') && servingGrid.includes('role="progressbar"'),
  'and progress through the range is stated, not only what is missing');
ok(servingGrid.includes('data-filter="unfilled"'),
  'the unfilled count is reachable as a filter rather than being a dead number');
ok(!servingGrid.includes('id="snapshot"') && !servingGrid.includes('renderSnapshot'),
  'one surface: the read-only snapshot that duplicated the editable grid is gone');
ok(servingGrid.includes("className = 'slot-assign'") && servingGrid.includes("className = 'slot-panel'"),
  'an unfilled slot offers one control, and the member/external inputs are disclosed on request');
ok(servingGrid.includes('sp-more') && servingGrid.includes('Someone outside the ministry'),
  'the external assignee is a second step rather than a permanent second form');
ok(!servingGrid.includes('mailto:'), 'the unfilled hint does not send email');
ok(servingGrid.includes('Posted lists'), 'the serving grid names posted lists the same way the ministry page does');
ok(servingGrid.includes('No activities happen on this campus in these dates'),
  'an empty picker blames the date window, not the assignment tick');
ok(!servingGrid.includes('No events on this campus are set up for assignments yet'),
  'the empty picker does not tell people to tick Allow ministry assignments');

// Two layouts of one schedule. The stacked view fills a single date; the grid
// puts dates in columns so a person can be followed across weeks, which is the
// comparison the stacked view cannot make. Both render from the same cells map.
ok(servingGrid.includes("data-view=\"stacked\"") && servingGrid.includes("data-view=\"grid\""),
  'the serving grid offers both a stacked and a side-by-side layout');
ok(servingGrid.includes('function renderStackedLayout') && servingGrid.includes('function renderGridLayout'),
  'the two layouts are two renderers over one state, not two sources of truth');
ok(servingGrid.includes("scope = 'col'") && servingGrid.includes("scope = 'row'"),
  'the side-by-side view is a real table with date columns and role rows');
ok(servingGrid.includes('.ws-grid-wrap { overflow-x: auto'),
  'a wide grid scrolls inside its own box rather than scrolling the page sideways');
// A <tr> is display:table-row from the UA stylesheet, which beats [hidden].
ok(servingGrid.includes('.ws-grid tr[hidden] { display: none; }'),
  'grid rows can actually be hidden despite the table display rule');
ok(servingGrid.includes('VIEW_KEY') && servingGrid.includes('localStorage'),
  'the chosen layout is remembered per browser');

// Copying a date creates assignments; it never moves the source's rows.
ok(servingGrid.includes('function applyCopy'), 'a date can be copied on to other dates');
{
  const body = /function applyCopy\(\)[\s\S]*?\n    \}/.exec(servingGrid)?.[0] ?? '';
  ok(body.includes('id: null'),
    'copied assignments are new rows, so the date copied from keeps its own');
  ok(body.includes('markDirty()'),
    'a copy lands unsaved so it is reviewed against the clash markers before saving');
  ok(body.includes("mode === 'fill'"),
    'copying can fill only the empty roles instead of overwriting people');
}
ok(servingGrid.includes('new Date(o.startsOn) >= today'),
  'only dates still to come are offered to copy on to');
// Copying starts from a date, not from a control in the toolbar: the click
// says which schedule is being copied, so the dialog asks only where it goes.
ok(servingGrid.includes('function makeCopyButton') && servingGrid.includes('data-copy-for'),
  'each date carries its own Copy control');
ok(servingGrid.includes("makeCopyButton(occ, 'occ-copy')") && servingGrid.includes("makeCopyButton(occ, 'gdate-copy')"),
  'both layouts put that control on the date itself');
ok(!servingGrid.includes('id="copyOpen"') && !servingGrid.includes('id="copyFrom"'),
  'there is no toolbar Copy button and no separate pick-a-source list');
ok(servingGrid.includes('<dialog class="copy-dialog"') && servingGrid.includes('showModal'),
  'the copy question is asked in a dialog titled with the date it came from');
ok(servingGrid.includes('function refreshCopyButtons'),
  'a date whose people are removed stops offering to be copied');
ok(read('resources/views/_event-editor.php').includes('nominates a default'),
  'the event editor says the assignment tick nominates a campus default');
ok(read('printable/index.php').includes('/calendar/print-setup'),
  'Printables hub points wall calendars at print-setup rather than becoming a second composer');
ok(read('printable/index.php').includes('Print this view'),
  'and it uses the same name Calendar uses');

console.log('In-app guide — calendar week, views, print, unfilled');
const calGuide = read('resources/views/docs/sections/14-calendar.md');
ok(calGuide.includes('Open view') && calGuide.includes('Save view'),
  'the guide teaches Open view and Save view');
ok(calGuide.includes('Print this view'), 'the guide teaches Print this view');
ok(calGuide.includes('Custom calendars'), 'the guide says custom calendars stay local');
ok(calGuide.includes('does not email') || calGuide.includes('Neither sends mail'),
  'the guide does not promise serving-request email');
ok(read('resources/views/docs.php').includes('14-calendar.md'),
  'the calendar guide is in the docs catalog');
ok(read('resources/views/docs/sections/01-viewing-schedules.md').includes('does not email anyone'),
  'the serving-grid guide mentions the unfilled count without email');
ok(read('resources/views/docs/sections/03-site-navigation.md').includes('Print this view'),
  'navigation destinations include Print this view');
ok(read('resources/views/docs/sections/09-faq.md').includes('Print this view'),
  'FAQ answers how to print the month on screen');

console.log('In-app guide — serving-grid picker follows the date window');
const viewingSchedules = read('resources/views/docs/sections/01-viewing-schedules.md');
ok(viewingSchedules.includes('occurrence in that window'),
  'the serving-grid guide says the picker follows the date window');
ok(!viewingSchedules.includes('never reaches this page'),
  'the serving-grid guide does not claim the assignment tick gates the picker');
const creatingEvents = read('resources/views/docs/sections/04-creating-events.md');
ok(creatingEvents.includes('Default assignment event'),
  'creating-events still teaches the campus default');
ok(!creatingEvents.includes('Schedules page will not offer it'),
  'creating-events does not say the tick hides the event from the picker');
ok(creatingEvents.includes("Doesn't repeat") && creatingEvents.includes('Every 2 weeks'),
  'creating-events names the recurrence presets the editor actually offers');
ok(read('resources/views/docs/sections/02-schedule-editor.md').includes('does not occur in the dates you loaded'),
  'the editor guide blames a missing date on selection or the loaded window');
ok(read('resources/views/docs/sections/09-faq.md').includes('campus default, not because it is the only thing allowed'),
  'FAQ says Sunday Service is the default, not the only allowed activity');

console.log('In-app guide — serving grid is in-place cells, not a sliding form');
ok(viewingSchedules.includes('Save changes'),
  'the serving-grid guide teaches Save changes, not instant save');
ok(!viewingSchedules.includes('editor sliding open') && !viewingSchedules.includes('Click the row'),
  'the serving-grid guide does not describe a sliding row editor');
ok(!viewingSchedules.includes('Notes column'),
  'the serving-grid guide does not invent a notes column');
const fillingGrid = read('resources/views/docs/sections/02-schedule-editor.md');
ok(fillingGrid.includes('Ministry member') && fillingGrid.includes('Add ★'),
  'the editor guide describes the in-cell add rows');
ok(!fillingGrid.includes('+ New Slot') && !fillingGrid.includes('Duplicate'),
  'the editor guide does not invent New Slot or Duplicate');
ok(!fillingGrid.includes('email/SMS') && !fillingGrid.includes('48 hours'),
  'the editor guide does not promise assignment reminder mail');
ok(read('resources/views/docs.php').includes('Filling the serving grid'),
  'the docs catalog names the editor page for the grid people actually use');
ok(read('resources/views/docs/sections/09-faq.md').includes('Do assignment changes save as I type?'),
  'FAQ says assignment edits wait for Save changes');
ok(!read('resources/views/docs/sections/08-help-support.md').includes('Reminders go out 48 hours'),
  'help does not claim serving-request or reminder email');

console.log('People vs Member records, Members & leaders vs Ministry list');
ok(read('resources/views/docs/sections/05-people-management.md').includes('look-up only'),
  'the people guide sends add/edit to Member records, not the People tab');
ok(!read('resources/views/docs/sections/05-people-management.md').includes('People dashboard'),
  'the people guide no longer names a People dashboard');
ok(read('resources/views/docs/sections/07-adding-leaders.md').includes('Members & leaders'),
  'the leaders guide names Members & leaders');
ok(!read('resources/views/docs/sections/07-adding-leaders.md').includes('Groups & Ministries'),
  'the leaders guide no longer says Groups & Ministries');
ok(read('resources/views/docs/sections/11-ministries.md').includes('Ministry list'),
  'the ministries guide names Ministry list');
ok(!read('resources/views/docs/sections/11-ministries.md').includes('Groups & Ministries'),
  'the ministries guide no longer says Groups & Ministries');
ok(read('resources/views/docs.php').includes('Member records — Add, Edit & Manage'),
  'the docs catalog titles the people section Member records');

console.log('Phase 7c is brief only');
const sevenC = read('docs/design/33-execution-phases/phase-07c-later-integrations.md');
ok(sevenC.includes('nothing in the original 7c table is ready to code'),
  '7c brief exists and says the later integrations are not ready to code');
ok(sevenC.includes('Do not implement any 7c item'),
  '7c handoff still forbids coding those items');
ok(read('docs/design/33-execution-phases/README.md').includes('phase-07c-later-integrations.md'),
  'the phase index lists the 7c brief');
ok(read('docs/design/33-execution-phases/README.md').includes('7c-later-integrations'),
  'the phase index still names 7c as the open planning track');
ok(!fs.existsSync(path.join(root, 'resources/views/docs/leader.php')),
  'the unrouted Leader Guide PHP stub is gone');
ok(!fs.existsSync(path.join(root, 'resources/views/docs/member.php')),
  'the unrouted Member Guide PHP stub is gone');
ok(!fs.existsSync(path.join(root, 'resources/views/docs/shortcuts.php')),
  'the unrouted Shortcuts PHP stub is gone');
ok(read('resources/views/docs.php').includes("'leader'    => 'schedule-editor'"),
  '/docs/leader still aliases to the serving-grid guide');

console.log(`\nPassed: ${passed}; failed: ${failed}`);
process.exit(failed === 0 ? 0 : 1);
