<?php

declare(strict_types=1);

/**
 * Web route map. Closures may return either:
 *   - a string (rendered as text/html as-is), or
 *   - an array (JSON-encoded — same shape as API routes).
 *
 * For now we serve a tiny login page so the API can be exercised from a
 * browser, and a stub schedules page placeholder.
 */

/**
 * Render a /admin/{section} view that needs only the standard request
 * scaffolding (basePath, actor, campusSelector). Theme/header/footer/hero
 * routes have their own closures because they preload extra config.
 */
/**
 * The campus chosen in the header, or null for "All campuses".
 *
 * The topbar selector persists its choice in the portal_campus_id cookie — the
 * shell sets it in JavaScript and reads it back for its own control — and
 * nothing injects that into $req. A page reading only $req therefore sees no
 * campus at all, and the selector appears to do nothing on it. That is what
 * happened on the events page once, and this exists so it does not have to be
 * rediscovered a third time.
 *
 * An explicit ?campus= wins, so existing links and bookmarks keep working.
 *
 * @param array<string,mixed> $req
 */
function _campusContext(array $req, string $queryKey = 'campus'): ?int
{
    $explicit = isset($req[$queryKey]) ? (int) $req[$queryKey] : 0;
    if ($explicit > 0) {
        return $explicit;
    }
    if (isset($req['current_campus_id']) && (int) $req['current_campus_id'] > 0) {
        return (int) $req['current_campus_id'];
    }
    if (isset($_COOKIE['portal_campus_id'])) {
        $cookie = filter_var($_COOKIE['portal_campus_id'], FILTER_VALIDATE_INT);

        return $cookie !== false && $cookie > 0 ? $cookie : null;
    }

    return null;
}

function _adminSectionRender(array $req, string $viewFile, callable $resolvePortalActor, callable $resolveCampusSelector): string
{
    $basePath = (string) ($req['_base_path'] ?? '');
    $actor = $resolvePortalActor($req);
    $campusSelector = $resolveCampusSelector($req);
    ob_start();
    require __DIR__ . '/../resources/views/' . $viewFile;
    return (string) ob_get_clean();
}

$parseCampusFilter = static function (mixed $rawCampus): array {
    if (!is_string($rawCampus)) {
        return [];
    }

    $rawCampus = trim($rawCampus);
    if ($rawCampus === '') {
        return [];
    }

    $parts = preg_split('/\s*,\s*/', $rawCampus, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $campusSlugs = [];
    foreach ($parts as $part) {
        $normalized = strtolower(trim((string) $part));
        if ($normalized === '' || $normalized === 'all_campus' || $normalized === 'all-campus' || $normalized === 'all_campuses' || $normalized === 'all-campuses') {
            return [];
        }
        $campusSlugs[] = $normalized;
    }

    return array_values(array_unique($campusSlugs));
};

$resolvePortalActor = static function (array $req): ?array {
    try {
        $reqctx = \App\Providers\PortalServiceProvider::makeRequestContext();
        $resolved = $reqctx->fromArray($req);
        return [
            'actorId' => $resolved->actorId,
            'personId' => $resolved->personId,
            'displayName' => $resolved->displayName,
            'isPortalWideAdmin' => $resolved->isPortalWideAdmin,
            'permissions' => array_map(fn ($p): string => $p->value, $resolved->permissions),
            'ministryScopeIds' => $resolved->ministryScopeIds,
            'currentCampusId' => $resolved->currentCampusId,
            'currentCampusIds' => $resolved->currentCampusIds,
        ];
    } catch (\App\Exceptions\PermissionDenied) {
        return null;
    }
};

$resolveCampusSelector = static function (array $req): array {
    try {
        $reqctx = \App\Providers\PortalServiceProvider::makeRequestContext();
        $context = $reqctx->fromArray($req);
        return \App\Providers\PortalServiceProvider::makeMinistryService()->getCampusSelector($context);
    } catch (\Throwable) {
        try {
            return \App\Providers\PortalServiceProvider::makeMinistryService()->getCampusSelector(new \App\Core\ActorContext(
                actorId: 0,
                personId: null,
                displayName: 'Public directory',
                permissions: [\App\Core\PortalPermission::ViewMinistrySchedule],
                ministryScopeIds: [],
                isPortalWideAdmin: true,
                campusScopeIds: [],
            ));
        } catch (\Throwable) {
            return ['campuses' => [], 'defaultCampusId' => null];
        }
    }
};

$resolveAvailableMinistries = static function (array $req): array {
    try {
        $reqctx = \App\Providers\PortalServiceProvider::makeRequestContext();
        $context = $reqctx->fromArray($req);
        return \App\Providers\PortalServiceProvider::makeMinistryService()->listAccessibleMinistries($context);
    } catch (\Throwable) {
        return [];
    }
};

$resolveAllMinistries = static function (array $req): array {
    try {
        $reqctx = \App\Providers\PortalServiceProvider::makeRequestContext();
        $context = $reqctx->fromArray($req);
        return \App\Providers\PortalServiceProvider::makeMinistryService()->listAllMinistries($context);
    } catch (\Throwable) {
        try {
            return \App\Providers\PortalServiceProvider::makeMinistryService()->listAllMinistries(new \App\Core\ActorContext(
                actorId: 0,
                personId: null,
                displayName: 'Public directory',
                permissions: [\App\Core\PortalPermission::ViewMinistrySchedule],
                ministryScopeIds: [],
                isPortalWideAdmin: true,
                campusScopeIds: [],
            ));
        } catch (\Throwable) {
            return [];
        }
    }
};

$redirectBackWithNotice = static function (array $req, string $messageKey): string {
    $basePath = (string) ($req['_base_path'] ?? '');
    $fallback = $basePath . '/ministries';
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $target = $fallback;

    if ($referer !== '') {
        $parts = parse_url($referer);
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $query = isset($parts['query']) ? (string) $parts['query'] : '';
        $sameHost = !isset($parts['host']) || strcasecmp((string) $parts['host'], (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0;
        $insidePortal = $basePath === '' ? str_starts_with($path, '/') : ($path === $basePath || str_starts_with($path, $basePath . '/'));

        if ($sameHost && $insidePortal && !str_ends_with($path, '/schedules')) {
            $target = $path . ($query !== '' ? '?' . $query : '');
        }
    }

    $separator = str_contains($target, '?') ? '&' : '?';
    header('Location: ' . $target . $separator . http_build_query(['portal_notice' => $messageKey]), true, 302);
    return '';
};

$renderScheduleEditor = function (array $req) use ($resolveCampusSelector, $resolveAvailableMinistries, $redirectBackWithNotice): string {
    /** @var string $basePath used by template */
    $basePath = (string) ($req['_base_path'] ?? '');
    /** @var ?array<string, mixed> $actor used by template */
    $actor = null;
    $resolved = null;
    try {
        $reqctx = \App\Providers\PortalServiceProvider::makeRequestContext();
        $resolved = $reqctx->fromArray($req);
    } catch (\App\Exceptions\PermissionDenied $e) {
        return $redirectBackWithNotice($req, 'schedule_editor_denied');
    }

    $ministryId = (int) ($req['ministry_id'] ?? ($resolved->ministryScopeIds[0] ?? 4));
    if ($ministryId <= 0) {
        throw new \App\Exceptions\ValidationFailed('A ministry is required to open the schedule editor.');
    }

    if (!$resolved->hasPermission(\App\Core\PortalPermission::ManageSchedules) || !$resolved->canAccessMinistry($ministryId)) {
        return $redirectBackWithNotice($req, 'schedule_editor_denied');
    }

    try {
        $actor = [
            'actorId'          => $resolved->actorId,
            'personId'         => $resolved->personId,
            'displayName'      => $resolved->displayName,
            'permissions'      => array_map(fn ($p): string => $p->value, $resolved->permissions),
            'ministryScopeIds' => $resolved->ministryScopeIds,
            'currentCampusId'  => $resolved->currentCampusId,
            'currentCampusIds' => $resolved->currentCampusIds,
            'isPortalWideAdmin'=> (bool) ($resolved->isPortalWideAdmin ?? false),
        ];
    } catch (\App\Exceptions\PermissionDenied $e) {
        throw $e;
    }
    /** @var array<string, mixed> $campusSelector used by template */
    $campusSelector = $resolveCampusSelector($req);
    /** @var array<int, array<string, mixed>> $availableMinistries used by template */
    $availableMinistries = $resolveAvailableMinistries($req);

    $currentCampusIds = [];
    if (array_key_exists('current_campus_ids', $req)) {
        $rawCampusIds = $req['current_campus_ids'];
        if (is_string($rawCampusIds)) {
            $rawCampusIds = $rawCampusIds === ''
                ? []
                : preg_split('/\s*,\s*/', $rawCampusIds, -1, PREG_SPLIT_NO_EMPTY);
        }
        $currentCampusIds = array_values(array_unique(array_map('intval', is_array($rawCampusIds) ? $rawCampusIds : [])));
        $currentCampusIds = array_values(array_filter($currentCampusIds, static fn (int $campusId): bool => $campusId > 0));
    } elseif (isset($req['current_campus_id']) && $req['current_campus_id'] !== '') {
        $currentCampusIds = [(int) $req['current_campus_id']];
    } elseif (isset($actor['currentCampusIds']) && is_array($actor['currentCampusIds'])) {
        $currentCampusIds = array_values(array_map('intval', $actor['currentCampusIds']));
    } elseif (isset($actor['currentCampusId']) && $actor['currentCampusId'] !== null) {
        $currentCampusIds = [(int) $actor['currentCampusId']];
    }
    $currentCampusId = count($currentCampusIds) === 1 ? $currentCampusIds[0] : null;
    $currentCampusNames = array_values(array_filter(
        array_map('strval', $req['current_campus_names'] ?? []),
        static fn (string $campusName): bool => trim($campusName) !== '',
    ));
    $campusScopeNote = $currentCampusIds === []
        ? 'Ministry members follow the full ministry roster.'
        : (count($currentCampusIds) === 1
            ? 'Ministry members are filtered to the selected campus affiliation.'
            : 'Ministry members are filtered to the selected campus affiliations.');
    if ($currentCampusNames !== []) {
        $campusScopeNote .= ' Filter: ' . implode(', ', $currentCampusNames) . '.';
    }
    $ministryName = 'Ministry #' . $ministryId;
    try {
        $ministryRepo = new \App\Repositories\DefaultMinistryRepository(
            new \App\Adapters\Sql\SqlMinistryAdapter(\App\Core\Database\MembersConnection::get())
        );
        $ministry = $ministryRepo->findMinistry($ministryId);
        if ($ministry !== null && trim((string) ($ministry['name'] ?? '')) !== '') {
            $ministryName = (string) $ministry['name'];
        }
    } catch (\Throwable) {
        $ministryName = 'Ministry #' . $ministryId;
    }
    $start = (string) ($req['start'] ?? date('Y-m-d', strtotime('-7 days')));
    $end   = (string) ($req['end']   ?? date('Y-m-d', strtotime('+28 days')));
    ob_start();
    require __DIR__ . '/../resources/views/schedule-editor.php';
    return (string) ob_get_clean();
};

/**
 * Visitors & RSVPs pages: resolve the actor, ask VisitorService for the page's
 * data, render. Signed-out visitors are sent to sign in; anyone else the
 * service refuses gets a 403 that says so.
 *
 * $load returns the view's variables, or null for a record that is not there.
 */
$visitorsPage = static function (array $req, string $view, callable $load) use ($resolvePortalActor, $resolveCampusSelector): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $basePath = (string) ($req['_base_path'] ?? '');
    $actor = $resolvePortalActor($req);
    if ($actor === null) {
        // REQUEST_URI already carries the base path.
        $next = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: $basePath . '/visitors');
        header('Location: ' . $basePath . '/login?' . http_build_query(['next' => $next]), true, 302);
        return '';
    }
    $campusSelector = $resolveCampusSelector($req);
    $vars = [];
    $refused = false;
    $missing = false;
    $unavailable = false;
    try {
        $context = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
        $loaded = $load(\App\Providers\PortalServiceProvider::makeVisitorService(), $context);
        if ($loaded === null) {
            $missing = true;
            http_response_code(404);
        } else {
            $vars = $loaded;
        }
    } catch (\App\Exceptions\PermissionDenied) {
        $refused = true;
        http_response_code(403);
    } catch (\RuntimeException $e) {
        // The visitors database is missing or unreadable.
        error_log('[visitors] ' . $e->getMessage());
        $unavailable = true;
        http_response_code(503);
    }
    $flash = $_SESSION['visitors_flash'] ?? null;
    unset($_SESSION['visitors_flash']);
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../resources/views/' . $view;
    return (string) ob_get_clean();
};

/**
 * Visitors & RSVPs form posts: run $act, keep its message (or the refusal) for
 * the next page, and go back to $back (a path below the base path).
 */
$visitorsAction = static function (array $req, string $back, callable $act): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $basePath = (string) ($req['_base_path'] ?? '');
    try {
        $context = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
        $message = $act(\App\Providers\PortalServiceProvider::makeVisitorService(), $context);
        $_SESSION['visitors_flash'] = ['kind' => 'ok', 'text' => $message];
    } catch (\App\Exceptions\PermissionDenied) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        return 'Visitors & RSVPs is for church administrators.';
    } catch (\App\Exceptions\ValidationFailed $e) {
        $_SESSION['visitors_flash'] = ['kind' => 'error', 'text' => $e->getMessage()];
    } catch (\Throwable $e) {
        error_log('[visitors] ' . $e->getMessage());
        $_SESSION['visitors_flash'] = ['kind' => 'error', 'text' => 'That could not be saved. Please try again.'];
    }
    header('Location: ' . $basePath . $back, true, 303);
    return '';
};

/**
 * The campus a Ministries page counts people for: ?campus=<id> (or "all"),
 * otherwise the header selector. A campus outside the actor's scope is ignored
 * rather than refused — ministries are not campus-scoped reading.
 *
 * @param array<string,mixed> $req
 */
$ministriesCampus = static function (array $req): ?int {
    if (($req['campus'] ?? '') === 'all') {
        return null;
    }

    return _campusContext($req);
};

/**
 * One ministry's workspace: Overview, Members & leaders, Serving roles or
 * Schedule. Signed-in only; the service decides what each tab may show.
 */
$renderMinistryWorkspace = static function (array $req, string $tab) use ($resolvePortalActor, $resolveCampusSelector, $ministriesCampus): string {
    $basePath = (string) ($req['_base_path'] ?? '');
    $rawId = (string) ($req['id'] ?? '');
    $service = \App\Providers\PortalServiceProvider::makeMinistryService();

    // /ministries/victuals/members and the like: an old name-based address.
    if (!ctype_digit($rawId)) {
        $found = $service->findMinistryIdBySlug($rawId);
        header('Location: ' . $basePath . '/ministries' . ($found !== null ? '/' . $found : ''), true, 302);

        return '';
    }

    $actor = $resolvePortalActor($req);
    $campusSelector = $resolveCampusSelector($req);
    $ministryId = (int) $rawId;
    $overview = null;
    $members = [];
    $roles = [];
    $servingDates = null;
    $scheduleError = '';
    $campusId = $ministriesCampus($req);

    if ($actor !== null) {
        $context = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
        try {
            $overview = $service->getWorkspaceOverview($context, $ministryId, $campusId, new DateTimeImmutable('today'));
        } catch (\App\Exceptions\PermissionDenied) {
            $campusId = null;
            $overview = $service->getWorkspaceOverview($context, $ministryId, null, new DateTimeImmutable('today'));
        }
        if ($overview === null) {
            http_response_code(404);
        } else {
            $access = $overview['access'];
            $countCampus = $overview['countCampusId'];
            if ($tab === 'members' && $access['canViewPeople']) {
                $members = $service->getMinistryMembers($context, $ministryId, $countCampus);
            }
            if ($tab === 'roles' && $access['canViewPeople']) {
                $roles = array_map(static fn ($r): array => $r->toArray(), $service->getMinistryRoles($context, $ministryId));
            }
            if ($tab === 'schedule') {
                try {
                    $start = new DateTimeImmutable('today');
                    $grid = \App\Providers\PortalServiceProvider::makeScheduleService()
                        ->getScheduleGrid($context, $ministryId, $start, $start->modify('+56 days'));
                    $servingDates = \App\Services\Ministry\ServingDates::fromGrid($grid->toArray());
                } catch (\App\Exceptions\PermissionDenied) {
                    $scheduleError = 'Your account cannot read this ministry\'s schedule.';
                } catch (\Throwable) {
                    $scheduleError = 'The schedule could not be loaded just now.';
                }
            }
        }
    }

    ob_start();
    require __DIR__ . '/../resources/views/ministry-workspace.php';
    return (string) ob_get_clean();
};

/** Old member-editor addresses → the ministry's Members & leaders tab. */
$redirectToMembersTab = static function (array $req): string {
    $basePath = (string) ($req['_base_path'] ?? '');
    $slug = (string) ($req['slug'] ?? '');
    $found = $slug !== '' ? \App\Providers\PortalServiceProvider::makeMinistryService()->findMinistryIdBySlug($slug) : null;
    header('Location: ' . $basePath . '/ministries' . ($found !== null ? '/' . $found . '/members' : ''), true, 302);

    return '';
};

$webRoutes = [
    'GET /login' => function (array $req): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var string $error    used by the template */
        $error    = isset($req['error']) ? (string) $req['error'] : '';

        // Default landing page after a successful sign-in. Must be a UI page,
        // not a JSON endpoint, and must be inside the portal mount.
        $defaultNext = $basePath . '/my-schedule';

        // Sanitize the caller-supplied next= to a same-app, same-origin path.
        // We only allow values that begin with $basePath . '/' and contain no
        // protocol/host. This blocks open-redirect phishing of the form
        // ?next=https://evil.example/phish.
        $rawNext = isset($req['next']) ? (string) $req['next'] : '';
        $next    = $defaultNext;
        if ($rawNext !== ''
            && str_starts_with($rawNext, $basePath . '/')
            && !str_contains($rawNext, "\n")
            && !str_contains($rawNext, "\r")
            && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $rawNext)
            && !str_starts_with($rawNext, '//')
        ) {
            $next = $rawNext;
        }
        /** @var string $next used by the template */

        // Which other ways in this installation offers. Configuration only —
        // no database, no network: the screen must not draw a button that
        // cannot work, and must not be slow to say so.
        \App\Core\Config\EnvLoader::loadOnce(__DIR__ . '/../.env');
        $appUrlSet = \App\Core\Config\AppUrl::isConfigured();
        /** @var bool $googleAvailable used by the template */
        $googleAvailable = $appUrlSet
            && trim((string) \App\Core\Config\EnvLoader::get('GOOGLE_CLIENT_ID', '')) !== ''
            && trim((string) \App\Core\Config\EnvLoader::get('GOOGLE_CLIENT_SECRET', '')) !== '';
        /** @var bool $magicLinkAvailable used by the template */
        $magicLinkAvailable = $appUrlSet
            && \App\Services\Mail\MailerFactory::isConfigured(dirname(__DIR__));

        ob_start();
        require __DIR__ . '/../resources/views/login.php';
        return (string) ob_get_clean();
    },

    // Signing in with Google, and following a sign-in link from an email.
    // Both are browser round trips that end in a session cookie; both refuse
    // in the same words, and neither creates an account.
    'GET /auth/google/start' => fn (array $req): string
        => (new \App\Http\Controllers\Web\SignInMethodController())->googleStart($req),

    'GET /auth/google/callback' => fn (array $req): string
        => (new \App\Http\Controllers\Web\SignInMethodController())->googleCallback($req),

    'GET /login/link/{token}' => fn (array $req): string
        => (new \App\Http\Controllers\Web\SignInMethodController())->magicLink($req),

    'GET /schedules' => $renderScheduleEditor,

    // Roster Schedules — leader/admin posted schedules (non-event assignments
    // with multi-person slots, optional ministry anchor, optional roles).
    // /rosters lists; /rosters/{id} edits; /rosters/new creates.
    'GET /rosters' => function (array $req) use ($resolvePortalActor, $resolveAvailableMinistries, $resolveCampusSelector, $redirectBackWithNotice): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null) {
            return $redirectBackWithNotice($req, 'schedule_editor_denied');
        }
        $availableMinistries = $resolveAvailableMinistries($req);
        $campusSelector = $resolveCampusSelector($req);
        $ministryFilterId = isset($req['ministry_id']) && $req['ministry_id'] !== ''
            ? (int) $req['ministry_id']
            : null;
        $rosterMode = 'list';
        ob_start();
        require __DIR__ . '/../resources/views/roster-editor.php';
        return (string) ob_get_clean();
    },

    'GET /rosters/new' => function (array $req) use ($resolvePortalActor, $resolveAvailableMinistries, $resolveCampusSelector, $redirectBackWithNotice): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null
            || (!in_array('manage_schedules', $actor['permissions'] ?? [], true) && empty($actor['isPortalWideAdmin']))) {
            return $redirectBackWithNotice($req, 'schedule_editor_denied');
        }
        $availableMinistries = $resolveAvailableMinistries($req);
        $campusSelector = $resolveCampusSelector($req);
        $rosterMode = 'new';
        $rosterId = 0;
        $prefilledMinistryId = isset($req['ministry_id']) && $req['ministry_id'] !== ''
            ? (int) $req['ministry_id']
            : null;
        ob_start();
        require __DIR__ . '/../resources/views/roster-editor.php';
        return (string) ob_get_clean();
    },

    'GET /rosters/{id}' => function (array $req) use ($resolvePortalActor, $resolveAvailableMinistries, $resolveCampusSelector, $redirectBackWithNotice): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null) {
            return $redirectBackWithNotice($req, 'schedule_editor_denied');
        }
        $availableMinistries = $resolveAvailableMinistries($req);
        $campusSelector = $resolveCampusSelector($req);
        $rosterMode = 'edit';
        $rosterId = (int) ($req['id'] ?? 0);
        if ($rosterId <= 0) {
            throw new \App\Exceptions\ValidationFailed('Roster id is required.');
        }
        ob_start();
        require __DIR__ . '/../resources/views/roster-editor.php';
        return (string) ob_get_clean();
    },

    'GET /scheduler/{ministry_name}' => function (array $req) use ($renderScheduleEditor, $parseCampusFilter): string {
        $ministryRepo = new \App\Repositories\DefaultMinistryRepository(
            new \App\Adapters\Sql\SqlMinistryAdapter(\App\Core\Database\MembersConnection::get())
        );
        $campusSlugs = $parseCampusFilter($req['campus'] ?? null);
        $campuses = $ministryRepo->resolveCampuses($campusSlugs);

        if (count($campuses) !== count($campusSlugs)) {
            throw new \App\Exceptions\ValidationFailed('Unknown campus filter in scheduler path.');
        }

        $resolved = $ministryRepo->resolveScheduleRouteByMinistry(
            (string) ($req['ministry_name'] ?? ''),
            array_map(static fn (array $campus): int => (int) $campus['campus_id'], $campuses),
        );

        if ($resolved === null) {
            throw new \App\Exceptions\ValidationFailed('Unknown or ambiguous ministry schedule path. Add a campus filter to disambiguate it.');
        }

        $req['ministry_id'] = $resolved['ministry_id'];
        $req['current_campus_ids'] = array_map(static fn (array $campus): int => (int) $campus['campus_id'], $campuses);
        $req['current_campus_names'] = array_map(static fn (array $campus): string => (string) $campus['campus_name'], $campuses);
        $req['current_campus_id'] = count($req['current_campus_ids']) === 1 ? $req['current_campus_ids'][0] : null;

        return $renderScheduleEditor($req);
    },

    'GET /scheduler/{campus}/{ministry_name}' => function (array $req) use ($renderScheduleEditor): string {
        $ministryRepo = new \App\Repositories\DefaultMinistryRepository(
            new \App\Adapters\Sql\SqlMinistryAdapter(\App\Core\Database\MembersConnection::get())
        );
        $resolved = $ministryRepo->resolveScheduleRoute(
            (string) ($req['campus'] ?? ''),
            (string) ($req['ministry_name'] ?? ''),
        );

        if ($resolved === null) {
            throw new \App\Exceptions\ValidationFailed('Unknown campus/ministry schedule path.');
        }

        $req['ministry_id'] = $resolved['ministry_id'];
        $req['current_campus_id'] = $resolved['campus_id'];
        $req['current_campus_ids'] = [$resolved['campus_id']];
        $req['current_campus_names'] = [$resolved['campus_name']];

        return $renderScheduleEditor($req);
    },

    // Printables — printable / PDF-exportable listings (events, birthdays, …)
    'GET /printables' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        ob_start();
        require __DIR__ . '/../printable/index.php';
        return (string) ob_get_clean();
    },
    'GET /printables/events' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        ob_start();
        require __DIR__ . '/../printable/events.php';
        return (string) ob_get_clean();
    },
    'GET /printables/birthdays' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        ob_start();
        require __DIR__ . '/../printable/birthdays.php';
        return (string) ob_get_clean();
    },
    'GET /printables/schedules' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        ob_start();
        require __DIR__ . '/../printable/schedules.php';
        return (string) ob_get_clean();
    },

    // A real create form. The Events list previously asked for a title through
    // prompt() and posted it to an endpoint that could not succeed.
    'GET /events/new' => function (array $req) use ($resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        $campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
        $actor = null;
        $canManageEvents = false;
        try {
            $resolved = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
            $actor = [
                'actorId' => $resolved->actorId,
                'personId' => $resolved->personId,
                'displayName' => $resolved->displayName,
                'permissions' => array_map(static fn ($p) => $p->value, $resolved->permissions),
                'ministryScopeIds' => $resolved->ministryScopeIds,
                'isPortalWideAdmin' => $resolved->isPortalWideAdmin,
            ];
            // Same test the events list uses, so the button and the form agree.
            $canManageEvents = (bool) $resolved->isPortalWideAdmin
                || in_array('manage_events', $actor['permissions'], true);
        } catch (\App\Exceptions\PermissionDenied) {
            $actor = null;
        }
        $ministries = [];
        try {
            $ministries = \App\Providers\PortalServiceProvider::makeMinistryService()->listMinistriesPublic(null);
        } catch (\Throwable) {
            $ministries = [];
        }
        // Audience-filtered: an actor is never offered a type they would
        // immediately lose the ability to read.
        $eventTypes = [];
        try {
            $resolvedForTypes = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
            $eventTypes = \App\Providers\PortalServiceProvider::makeEventTypeService()->listForPicker($resolvedForTypes);
        } catch (\Throwable) {
            $eventTypes = [];
        }
        /** @var list<array<string,mixed>> $allTags used by the template */
        $allTags = [];
        try {
            $allTags = \App\Providers\PortalServiceProvider::makeEventService()->listTags();
        } catch (\Throwable) {
            $allTags = [];
        }

        ob_start();
        require __DIR__ . '/../resources/views/events-new.php';

        return (string) ob_get_clean();
    },

    'GET /events' => function (array $req) use ($resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = null;
        $resolved = null;
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        try {
            $reqctx = \App\Providers\PortalServiceProvider::makeRequestContext();
            $resolved = $reqctx->fromArray($req);
            $actor = [
                'actorId' => $resolved->actorId,
                'personId' => $resolved->personId,
                'displayName' => $resolved->displayName,
                'permissions' => array_map(fn($p) => $p->value, $resolved->permissions),
                'ministryScopeIds' => $resolved->ministryScopeIds,
            ];
        } catch (\App\Exceptions\PermissionDenied) {
            $actor = null;
        }

        $service = \App\Providers\PortalServiceProvider::makeEventService();
        $events = [];
        $agenda = [];
        try {
            // Honour the topbar campus context and the period browser.
            //
            // The topbar selector persists the choice in the portal_campus_id
            // cookie (the shell sets it in JS and reads it back for its own
            // control), but nothing injects that into $req — so an events page
            // reading only $req saw no campus at all and the selector appeared
            // to do nothing here. Explicit query wins, cookie is the fallback.
            $campusFilter = _campusContext($req);
            $range = (string) ($req['range'] ?? 'upcoming');
            $anchor = isset($req['on']) && $req['on'] !== '' ? (string) $req['on'] : null;
            $viewer = $resolved ?? null;
            $events = array_map(
                fn ($e) => $e->toArray(),
                $viewer !== null
                    ? $service->listUpcoming($viewer, 200, $campusFilter, $range, $anchor)
                    : $service->listUpcomingPublic(200, $campusFilter, $range, $anchor)
            );
            // The page groups by date, so it needs occurrences: one weekly event
            // is many rows across a month, and a Sunday holds several events.
            $agenda = $service->agenda($viewer, $campusFilter, $range, $anchor);
        } catch (\Throwable) {
            $events = [];
            $agenda = [];
        }

        $agenda = $agenda ?? [];
        $eventRange = (string) ($req['range'] ?? 'upcoming');
        $eventAnchor = isset($req['on']) && $req['on'] !== '' ? (string) $req['on'] : null;
        ob_start();
        require __DIR__ . '/../resources/views/events-list.php';
        return (string) ob_get_clean();
    },

    'GET /events/{id}' => function (array $req) use ($resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = null;
        $resolved = null;
        try {
            $reqctx = \App\Providers\PortalServiceProvider::makeRequestContext();
            $resolved = $reqctx->fromArray($req);
            $actor = [
                'actorId' => $resolved->actorId,
                'personId' => $resolved->personId,
                'displayName' => $resolved->displayName,
                'permissions' => array_map(fn($p) => $p->value, $resolved->permissions),
                'ministryScopeIds' => $resolved->ministryScopeIds,
            ];
        } catch (\App\Exceptions\PermissionDenied) {
            $actor = null;
        }

        $id = (int) ($req['id'] ?? 0);
        $start = isset($req['start']) ? new DateTimeImmutable((string) $req['start']) : new DateTimeImmutable('-7 days');
        $end = isset($req['end']) ? new DateTimeImmutable((string) $req['end']) : new DateTimeImmutable('+90 days');
        $service = \App\Providers\PortalServiceProvider::makeEventService();
        $event = null;
        try {
            $viewer = $resolved;
            $ev = $service->getEvent($viewer, $id, $start, $end);
            $event = $ev?->toArray();
        } catch (\Throwable) {
            $event = null;
        }

        // The schedule as a rule, so the page can say "Every Sunday until
        // October" rather than only listing the rows that produced.
        /** @var array{rule:?array,summary:string} $schedule used by the template */
        $schedule = ['rule' => null, 'summary' => ''];
        try {
            $schedule = $service->scheduleFor($resolved, $id);
        } catch (\Throwable) {
            $schedule = ['rule' => null, 'summary' => ''];
        }

        // The editor is shared with /events/new, so the page needs the same
        // pickers that page does.
        /** @var array<string,mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        $ministries = [];
        try {
            $ministries = \App\Providers\PortalServiceProvider::makeMinistryService()->listMinistriesPublic(null);
        } catch (\Throwable) {
            $ministries = [];
        }
        $eventTypes = [];
        try {
            $eventTypes = \App\Providers\PortalServiceProvider::makeEventTypeService()->listForPicker($resolved);
        } catch (\Throwable) {
            $eventTypes = [];
        }
        /** @var list<array<string,mixed>> $allTags used by the template */
        $allTags = [];
        /** @var list<array<string,mixed>> $eventTags used by the template */
        $eventTags = [];
        try {
            $allTags = $service->listTags();
            $eventTags = $service->tagsForEvent($resolved, $id);
        } catch (\Throwable) {
            $allTags = [];
            $eventTags = [];
        }

        ob_start();
        require __DIR__ . '/../resources/views/events-detail.php';
        return (string) ob_get_clean();
    },

    'GET /events/{id}/occurrences/new' => function (array $req): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = null;
        $event = null;
        try {
            $reqctx = \App\Providers\PortalServiceProvider::makeRequestContext();
            $resolved = $reqctx->fromArray($req);
            $actor = [
                'actorId' => $resolved->actorId,
                'personId' => $resolved->personId,
                'displayName' => $resolved->displayName,
                'permissions' => array_map(fn($p) => $p->value, $resolved->permissions),
                'ministryScopeIds' => $resolved->ministryScopeIds,
            ];
        } catch (\App\Exceptions\PermissionDenied) {
            $actor = null;
        }

        $eventId = (int) ($req['id'] ?? 0);
        $start = isset($req['start']) ? new DateTimeImmutable((string) $req['start']) : new DateTimeImmutable('-7 days');
        $end = isset($req['end']) ? new DateTimeImmutable((string) $req['end']) : new DateTimeImmutable('+90 days');
        $service = \App\Providers\PortalServiceProvider::makeEventService();
        try {
            $ev = $service->getEvent($reqctx->fromArray($req), $eventId, $start, $end);
            $event = $ev?->toArray();
        } catch (\Throwable) {
            $event = null;
        }
        ob_start();
        require __DIR__ . '/../resources/views/events-occurrence-new.php';
        return (string) ob_get_clean();
    },

    'GET /my-schedule' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = $resolvePortalActor($req);
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        ob_start();
        require __DIR__ . '/../resources/views/my-schedule.php';
        return (string) ob_get_clean();
    },

    // The ministries directory: every ministry for a signed-in member, their
    // own first, with campus, size, leaders (where they may see people),
    // whether it schedules and its next serving date. The assignment board that
    // used to live at this address is unchanged at /schedule-board.
    'GET /ministries' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector, $ministriesCampus): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $campusId = $ministriesCampus($req);
        $directory = [];
        if ($actor !== null) {
            $context = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
            $service = \App\Providers\PortalServiceProvider::makeMinistryService();
            try {
                $directory = $service->listDirectory($context, $campusId, new DateTimeImmutable('today'));
            } catch (\App\Exceptions\PermissionDenied) {
                $campusId = null;
                $directory = $service->listDirectory($context, null, new DateTimeImmutable('today'));
            }
        }
        ob_start();
        require __DIR__ . '/../resources/views/ministries-directory.php';
        return (string) ob_get_clean();
    },

    // The existing schedule/assignment board, moved verbatim. No behaviour or
    // scheduling logic changed — only the path it is reached at.
    'GET /schedule-board' => function (array $req) use ($resolvePortalActor, $resolveAvailableMinistries, $resolveCampusSelector): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = $resolvePortalActor($req);
        /** @var array<int, array<string, mixed>> $availableMinistries used by the template */
        $availableMinistries = $resolveAvailableMinistries($req);
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        ob_start();
        require __DIR__ . '/../resources/views/ministries.php';
        return (string) ob_get_clean();
    },

    'GET /ministries/{id}' => fn (array $req): string => $renderMinistryWorkspace($req, 'overview'),
    'GET /ministries/{id}/members' => fn (array $req): string => $renderMinistryWorkspace($req, 'members'),
    'GET /ministries/{id}/roles' => fn (array $req): string => $renderMinistryWorkspace($req, 'roles'),
    'GET /ministries/{id}/schedule' => fn (array $req): string => $renderMinistryWorkspace($req, 'schedule'),
    // Friendly name alias: /ministry/victuals → /ministries/4
    'GET /ministry/{slug}' => function (array $req): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $found = \App\Providers\PortalServiceProvider::makeMinistryService()->findMinistryIdBySlug((string) ($req['slug'] ?? ''));
        header('Location: ' . $basePath . '/ministries' . ($found !== null ? '/' . $found : ''), true, 302);

        return '';
    },

    'GET /people' => function (array $req) use ($resolvePortalActor, $resolveAllMinistries, $resolveCampusSelector): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = $resolvePortalActor($req);
        /** @var array<int, array<string, mixed>> $availableMinistries used by the template */
        $availableMinistries = $resolveAllMinistries($req);
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        /** @var int $ministryId used by the template */
        $ministryId = (int) ($req['ministry_id'] ?? 0);
        ob_start();
        require __DIR__ . '/../resources/views/people.php';
        return (string) ob_get_clean();
    },

    // Record history — who changed which person or household record, and when.
    // Read-only, portal administrators only; the service refuses anyone else.
    'GET /people/history' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = \App\Services\RecordHistoryService::mayRead($actor);
        $history = null;
        $options = ['actions' => [], 'people' => []];
        $householdName = null;
        $personName = null;
        $loadError = '';
        if ($isAdmin) {
            try {
                $svc = \App\Providers\PortalServiceProvider::makeRecordHistoryService();
                $history = $svc->page($actor, $req);
                $options = $svc->filterOptions($actor);
                if ($history['criteria']['householdId'] !== null) {
                    $householdName = $svc->recordName($actor, 'household', (int) $history['criteria']['householdId']);
                }
                if ($history['criteria']['personId'] !== null) {
                    $personName = $svc->recordName($actor, 'person', (int) $history['criteria']['personId']);
                }
            } catch (\Throwable $e) {
                error_log('[people/history] ' . $e->getMessage());
                $loadError = 'The record history could not be read just now.';
            }
        } else {
            http_response_code($actor === null ? 401 : 403);
        }
        ob_start();
        require __DIR__ . '/../resources/views/people-history.php';
        return (string) ob_get_clean();
    },

    'GET /about' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        try {
            $churchName = trim((string) (\App\Providers\PortalServiceProvider::makeChurchInfoService()->load()['name'] ?? ''));
        } catch (\Throwable) {
            $churchName = '';
        }
        ob_start();
        require __DIR__ . '/../resources/views/about.php';
        return (string) ob_get_clean();
    },

    'GET /docs' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $section = null;
        ob_start();
        require __DIR__ . '/../resources/views/docs.php';
        return (string) ob_get_clean();
    },

    'GET /docs/{section}' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $section = (string) ($req['section'] ?? '');
        ob_start();
        require __DIR__ . '/../resources/views/docs.php';
        return (string) ob_get_clean();
    },

    'GET /people/{id}' => function (array $req) use ($resolvePortalActor, $resolveAllMinistries, $resolveCampusSelector): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = $resolvePortalActor($req);
        /** @var array<int, array<string, mixed>> $availableMinistries used by the template */
        $availableMinistries = $resolveAllMinistries($req);
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        /** @var int $personId used by the template */
        $personId = (int) ($req['id'] ?? 0);
        /** @var int $ministryId used by the template */
        $ministryId = (int) ($req['ministry_id'] ?? 0);
        // Owner/admin self-service data for the profile toolbar.
        $isOwner = $actor !== null && (int) ($actor['personId'] ?? 0) === $personId && $personId > 0;
        $canManage = $actor !== null && ($isOwner || (bool) ($actor['isPortalWideAdmin'] ?? false));
        $ownerCampuses = [];
        $ownerPrimaryCampus = 0;
        if ($canManage) {
            $psvc = \App\Providers\PortalServiceProvider::makePersonAdminService();
            $ownerCampuses = $psvc->campuses();
            $pd = $psvc->find($personId);
            $ownerPrimaryCampus = (int) ($pd['campus_id'] ?? 0);
        }
        ob_start();
        require __DIR__ . '/../resources/views/person-detail.php';
        return (string) ob_get_clean();
    },

    // Public photo serve (portal-owned Images/Person folder).
    'GET /people/photo' => function (array $req): string {
        $id = (int) ($req['id'] ?? 0);
        foreach ([__DIR__ . '/../Images/Person/'] as $root) {
            foreach (['png', 'jpg', 'jpeg'] as $ext) {
                $file = $root . $id . '.' . $ext;
                if ($id > 0 && is_file($file)) {
                    header('Content-Type: ' . ($ext === 'png' ? 'image/png' : 'image/jpeg'));
                    header('Cache-Control: private, max-age=120');
                    header('Content-Length: ' . (string) filesize($file));
                    readfile($file);
                    return '';
                }
            }
        }
        http_response_code(404);
        header('Content-Type: text/plain');
        return 'No photo';
    },
    // Owner (or admin) self-service: photo upload / remove, primary campus, coords.
    'POST /people/photo' => function (array $req) use ($resolvePortalActor): string {
        header('Content-Type: application/json');
        $actor = $resolvePortalActor($req);
        $target = (int) ($req['id'] ?? 0);
        $canManage = $actor !== null && ((bool) ($actor['isPortalWideAdmin'] ?? false) || (int) ($actor['personId'] ?? 0) === $target);
        if (!$canManage) { http_response_code(403); return json_encode(['success' => false, 'error' => 'Not authorized.']); }
        if (!isset($_FILES['photo']) || ($_FILES['photo']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            http_response_code(422); return json_encode(['success' => false, 'error' => 'No file uploaded.']);
        }
        if (($_FILES['photo']['size'] ?? 0) > 6 * 1024 * 1024) {
            http_response_code(422); return json_encode(['success' => false, 'error' => 'Image is too large (max 6 MB).']);
        }
        $svc = \App\Providers\PortalServiceProvider::makePersonAdminService();
        try {
            $svc->savePhoto($target, (string) $_FILES['photo']['tmp_name']);
            return json_encode(['success' => true]);
        } catch (\Throwable $e) {
            http_response_code(422); return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    },
    'POST /people/photo-delete' => function (array $req) use ($resolvePortalActor): string {
        header('Content-Type: application/json');
        $actor = $resolvePortalActor($req);
        $target = (int) ($req['id'] ?? 0);
        $canManage = $actor !== null && ((bool) ($actor['isPortalWideAdmin'] ?? false) || (int) ($actor['personId'] ?? 0) === $target);
        if (!$canManage) { http_response_code(403); return json_encode(['success' => false]); }
        \App\Providers\PortalServiceProvider::makePersonAdminService()->deletePhoto($target);
        return json_encode(['success' => true]);
    },
    'POST /people/campus' => function (array $req) use ($resolvePortalActor): string {
        header('Content-Type: application/json');
        $actor = $resolvePortalActor($req);
        $target = (int) ($req['id'] ?? 0);
        $canManage = $actor !== null && ((bool) ($actor['isPortalWideAdmin'] ?? false) || (int) ($actor['personId'] ?? 0) === $target);
        if (!$canManage) { http_response_code(403); return json_encode(['success' => false]); }
        $svc = \App\Providers\PortalServiceProvider::makePersonAdminService();
        try {
            $svc->setPrimaryCampus($target, (int) ($req['campus_id'] ?? 0) ?: null, (int) ($actor['actorId'] ?? 0));
            return json_encode(['success' => true]);
        } catch (\Throwable $e) {
            http_response_code(422); return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    },
    'POST /people/geocode' => function (array $req) use ($resolvePortalActor): string {
        header('Content-Type: application/json');
        $actor = $resolvePortalActor($req);
        $target = (int) ($req['id'] ?? 0);
        $canManage = $actor !== null && ((bool) ($actor['isPortalWideAdmin'] ?? false) || (int) ($actor['personId'] ?? 0) === $target);
        if (!$canManage) { http_response_code(403); return json_encode(['success' => false, 'error' => 'Not authorized.']); }
        $svc = \App\Providers\PortalServiceProvider::makePersonAdminService();
        $famId = $svc->personFamilyId($target);
        if ($famId <= 0) { http_response_code(422); return json_encode(['success' => false, 'error' => 'You need a family address on file first.']); }
        $result = $svc->geocodeFamily($famId);
        if (!($result['success'] ?? false)) { http_response_code(422); }
        return json_encode($result);
    },

    'GET /calendar' => function (array $req) use ($resolvePortalActor, $resolveAvailableMinistries, $resolveCampusSelector): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = $resolvePortalActor($req);
        /** @var array<int, array<string, mixed>> $availableMinistries used by the template */
        $availableMinistries = $resolveAvailableMinistries($req);
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        // The calendar offers to create from a clicked date, so it needs the
        // same permission test the events page uses. HTML visibility is not the
        // boundary — the API still refuses — but offering an action that will
        // be refused is its own kind of broken.
        /** @var bool $canManageEvents used by the template */
        $canManageEvents = false;
        try {
            $resolvedForCreate = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
            $canManageEvents = $resolvedForCreate->isPortalWideAdmin
                || in_array(
                    \App\Core\PortalPermission::ManageEvents,
                    $resolvedForCreate->permissions,
                    true,
                );
        } catch (\App\Exceptions\PermissionDenied) {
            $canManageEvents = false;
        }
        ob_start();
        require __DIR__ . '/../resources/views/calendar.php';
        return (string) ob_get_clean();
    },

    'GET /calendar/settings' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = $resolvePortalActor($req);
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        ob_start();
        require __DIR__ . '/../resources/views/calendar-settings.php';
        return (string) ob_get_clean();
    },

    // Central management / control panel. Acts as the hub for every settings
    // surface the portal exposes (calendar sources, account, future system
    // toggles). The page itself filters which sections are actionable based
    // on the actor's permissions, so we don't gate the route here — readers
    // without manage-anything permission still see read-only diagnostics.
    // First-login password change page. Posts to /api/auth/password.
    'GET /password/change' => function (array $req) use ($resolvePortalActor): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = $resolvePortalActor($req);
        ob_start();
        require __DIR__ . '/../resources/views/password-change.php';
        return (string) ob_get_clean();
    },

    // /admin is the single admin landing page and serves the control board.
    // It used to render an Overview whose 13 tiles repeated the sidebar, while
    // /admin/dashboard was a second landing page beside it. The board absorbed
    // both, including Overview's environment snapshot, so there is now one.
    'GET /admin' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = $resolvePortalActor($req);
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);
        $dashboard = [];
        if ($actor !== null && !empty($actor['isPortalWideAdmin'])) {
            $dashboard = (new \App\Services\AdminDashboardService())->build($basePath);
        }
        ob_start();
        require __DIR__ . '/../resources/views/admin-dashboard.php';

        return (string) ob_get_clean();
    },

    // Signing out is a page action, not an API call.
    //
    // The only sign-out in the product was a JSON endpoint driven by script on
    // the account page, so the header menu would have had nothing to point at
    // that works without JavaScript. This revokes the session through the same
    // service and then sends the visitor somewhere sensible, which is what a
    // form submission should do.
    'POST /logout' => function (array $req): string {
        (new \App\Http\Controllers\Api\AuthController(
            \App\Providers\PortalServiceProvider::makeAuthService(),
            \App\Providers\PortalServiceProvider::makeRequestContext(),
        ))->logout($req);

        $base = (string) ($req['_base_path'] ?? '');
        header('Location: ' . ($base === '' ? '/' : $base . '/'), true, 303);

        return '';
    },

    // Troubleshooting information: configuration files and environment. Moved
    // off the control board, which is the page an administrator opens to do
    // their daily work, and given a home under Advanced instead.
    'GET /admin/system' => fn (array $req) => _adminSectionRender($req, 'admin-system.php', $resolvePortalActor, $resolveCampusSelector),

    // Planned capabilities collected in one place, so unbuilt features stay
    // discoverable without each holding a permanent slot in primary navigation.
    'GET /admin/roadmap' => fn (array $req) => _adminSectionRender($req, 'admin-roadmap.php', $resolvePortalActor, $resolveCampusSelector),

    // The home page banner ("hero") became part of Portal notices: short
    // operational messages rather than rotating slides. Its old address opens
    // the notices page, which offers to carry the banner's messages over.
    'GET /admin/hero' => function (array $req): string {
        header('Location: ' . ((string) ($req['_base_path'] ?? '')) . '/admin/announcements', true, 302);

        return '';
    },

    // The 10 admin section pages — all share _admin-shell.php so they
    // present a uniform sidebar + topbar.
    // The control board reads real counts, so it gets its own closure rather
    // than the generic section renderer: the data is assembled only for an
    // actor who is allowed to see it, not fetched and then hidden by the view.
    // Bookmarks and existing links to the old dashboard still resolve.
    'GET /admin/dashboard' => function (array $req): string {
        header('Location: ' . ((string) ($req['_base_path'] ?? '')) . '/admin', true, 302);

        return '';
    },
    'GET /admin/announcements' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $announcementConfig = \App\Providers\PortalServiceProvider::makeAnnouncementSettingsService()->load();
        $bannerSlides = [];
        if ($actor !== null && !empty($actor['isPortalWideAdmin']) && is_file(__DIR__ . '/../config/hero.json')) {
            $bannerSlides = \App\Providers\PortalServiceProvider::makeHeroSettingsService()->load()['slides'];
        }
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['notices_flash'] ?? '');
        unset($_SESSION['notices_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-announcements.php';
        return (string) ob_get_clean();
    },
    // Copy the retired home banner's messages into Portal notices as drafts.
    // Only messages not already copied (same title) are added, so repeating
    // the action adds nothing twice.
    'POST /admin/announcements/banner-drafts' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $target = $basePath . '/admin/announcements';
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['notices_flash'] = 'Only a portal-wide admin can change portal notices.';
            header('Location: ' . $target . '?notice=error', true, 302);
            return '';
        }
        try {
            $notices = \App\Providers\PortalServiceProvider::makeAnnouncementSettingsService();
            $existing = array_map(static fn (array $i): string => mb_strtolower(trim($i['title'])), $notices->load()['items']);
            $drafts = [];
            foreach (\App\Providers\PortalServiceProvider::makeHeroSettingsService()->load()['slides'] as $slide) {
                $title = mb_substr(trim((string) $slide['title']), 0, 140);
                if ($title === '' || in_array(mb_strtolower($title), $existing, true)) {
                    continue;
                }
                $drafts[] = ['title' => $title, 'body' => mb_substr(trim((string) $slide['lead']), 0, 800)];
                $existing[] = mb_strtolower($title);
            }
            if ($drafts !== []) {
                $notices->appendDrafts($drafts);
            }
            $_SESSION['notices_flash'] = $drafts === []
                ? 'Every banner message is already a notice.'
                : (count($drafts) === 1 ? 'Added 1 draft notice.' : 'Added ' . count($drafts) . ' draft notices.') . ' Review and publish the ones still needed.';
            header('Location: ' . $target . '?notice=drafts', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['notices_flash'] = $e->getMessage();
            header('Location: ' . $target . '?notice=error', true, 302);
        }
        return '';
    },
    'GET /admin/maintenance' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['maintenance_flash'] ?? $_SESSION['people_flash'] ?? '');
        unset($_SESSION['maintenance_flash'], $_SESSION['people_flash']);
        $archives = [];
        // The view already hides everything from a non-admin, but it should not
        // need to: archive metadata is only fetched for an actor who is allowed
        // to see it. Defence in depth — if the template is ever restructured,
        // there is nothing in scope to leak.
        $isMaintenanceAdmin = $actor !== null && !empty($actor['isPortalWideAdmin']);
        if ($isMaintenanceAdmin) {
            try {
                $archives = \App\Providers\PortalServiceProvider::makeMaintenanceBackupService()->store()->listRecent();
            } catch (\Throwable) {
                $archives = [];
            }
        }
        ob_start();
        require __DIR__ . '/../resources/views/admin-maintenance.php';
        return (string) ob_get_clean();
    },
    'GET /admin/ministries' => fn (array $req) => _adminSectionRender($req, 'admin-ministries.php', $resolvePortalActor, $resolveCampusSelector),
    // Members & leaders is a tab of each ministry (surfaces.md). The editor's
    // old addresses keep working: with a ministry name they open that
    // ministry's tab, without one the directory to choose from.
    'GET /ministries/members-and-leaders' => $redirectToMembersTab,
    'GET /ministries/members-and-leaders/{slug}' => $redirectToMembersTab,
    'GET /admin/groups-and-ministries' => $redirectToMembersTab,
    'GET /admin/groups-and-ministries/{slug}' => $redirectToMembersTab,
    'GET /admin/calendar'   => fn (array $req) => _adminSectionRender($req, 'admin-calendar.php',   $resolvePortalActor, $resolveCampusSelector),
    'GET /admin/events'     => fn (array $req) => _adminSectionRender($req, 'admin-events.php',     $resolvePortalActor, $resolveCampusSelector),
    // People management — dashboard + searchable list.
    'GET /admin/people' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        // Campus comes from the header selector, not from a second control on
        // the page. Two campus pickers on one screen disagreed with each other.
        $filters = [
            'search' => (string) ($req['q'] ?? ''),
            'classification' => (int) ($req['cls'] ?? 0),
            'campus' => (int) (_campusContext($req) ?? 0),
            'member_type' => (int) ($req['type'] ?? 0),
        ];
        $perPage = 25;
        $page = max(1, (int) ($req['page'] ?? 1));
        // Member records are for portal administrators. Other staff who reach
        // the admin area (schedulers, leaders) used to be shown every name and
        // contact here; nothing is read for them now.
        $stats = ['people' => 0, 'families' => 0, 'activeFamilies' => 0, 'classifications' => [], 'memberTypes' => []];
        $total = 0; $people = []; $classifications = []; $memberTypes = []; $campuses = [];
        if ($isAdmin) {
            $svc = \App\Providers\PortalServiceProvider::makePersonAdminService();
            $stats = $svc->stats();
            $total = $svc->count($filters);
            // A page past the end (a filter narrowed the list) shows the last page.
            $page = min($page, max(1, (int) ceil($total / $perPage)));
            $people = $svc->list($filters, $page, $perPage);
            $classifications = $svc->classifications();
            $memberTypes = $svc->memberTypes();
            $campuses = $svc->campuses();
        }
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['people_flash'] ?? '');
        unset($_SESSION['people_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-people.php';
        return (string) ob_get_clean();
    },
    'GET /admin/people/edit' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $pid = (int) ($req['id'] ?? 0);
        $person = []; $classifications = []; $memberTypes = []; $familyRoles = []; $campuses = []; $families = [];
        $missing = false;
        if ($isAdmin) {
            $svc = \App\Providers\PortalServiceProvider::makePersonAdminService();
            $found = $pid > 0 ? $svc->find($pid) : null;
            $missing = $pid > 0 && $found === null;
            $person = $found ?? $svc->blank();
            // "Add a person to this household" arrives with the household chosen.
            if ($found === null && (int) ($req['household_id'] ?? 0) > 0) {
                $person['household_id'] = (int) $req['household_id'];
            }
            $classifications = $svc->classifications();
            $memberTypes = $svc->memberTypes();
            $familyRoles = $svc->familyRoles();
            $campuses = $svc->campuses();
            $families = $svc->families();
        }
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['people_flash'] ?? '');
        unset($_SESSION['people_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-person-edit.php';
        return (string) ob_get_clean();
    },
    'GET /admin/people/view' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $data = null;
        $ministries = []; $logins = []; $recentHistory = ['entries' => [], 'total' => 0];
        if ($isAdmin) {
            $svc = \App\Providers\PortalServiceProvider::makePersonAdminService();
            $data = $svc->viewData((int) ($req['id'] ?? 0));
        }
        if ($data !== null) {
            $pid = (int) $data['person']['id'];
            // Ministries and logins are shown, not edited, here: each has its
            // own workspace (Ministries; Users & access).
            try { $ministries = $svc->ministriesFor($pid); } catch (\Throwable) { $ministries = []; }
            try {
                $logins = array_values(array_filter(
                    \App\Providers\PortalServiceProvider::makeSystemUserService()->list(),
                    static fn (array $u): bool => $u['person_id'] === $pid,
                ));
            } catch (\Throwable) { $logins = []; }
            try {
                $recentHistory = \App\Providers\PortalServiceProvider::makeRecordHistoryService()->recent($actor, 'person', $pid, 5);
            } catch (\Throwable) { $recentHistory = ['entries' => [], 'total' => 0]; }
        }
        // Household context: related families (map) + shared-residence families (derived).
        $relatedLinks = []; $residenceMates = [];
        if ($data !== null && (int) ($data['person']['household_id'] ?? 0) > 0) {
            $fid = (int) $data['person']['household_id'];
            $fsvc = \App\Providers\PortalServiceProvider::makeFamilyAdminService();
            $rel = \App\Providers\PortalServiceProvider::makeRelatedFamiliesService();
            $raw = $rel->forFamily($fid);
            $names = $fsvc->namesFor(array_map(static fn ($l) => $l['other'], $raw));
            foreach ($raw as $l) { $relatedLinks[] = $l + ['name' => $names[$l['other']] ?? ('Family #' . $l['other'])]; }
            $residenceMates = $fsvc->residenceMates($fid);
        }
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['people_flash'] ?? '');
        unset($_SESSION['people_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-person-view.php';
        return (string) ob_get_clean();
    },
    // Person photo — served from the portal-owned Images/Person folder.
    'GET /admin/people/photo' => function (array $req): string {
        $id = (int) ($req['id'] ?? 0);
        $roots = [
            __DIR__ . '/../Images/Person/',
        ];
        foreach ($roots as $root) {
            foreach (['png', 'jpg', 'jpeg'] as $ext) {
                $file = $root . $id . '.' . $ext;
                if ($id > 0 && is_file($file)) {
                    $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
                    header('Content-Type: ' . $mime);
                    header('Cache-Control: private, max-age=300');
                    header('Content-Length: ' . (string) filesize($file));
                    readfile($file);
                    return '';
                }
            }
        }
        http_response_code(404);
        header('Content-Type: text/plain');
        return 'No photo';
    },
    // Refresh a family's coordinates from its address (OpenStreetMap). JSON.
    'POST /admin/people/geocode-family' => function (array $req) use ($resolvePortalActor): string {
        header('Content-Type: application/json');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            http_response_code(403);
            return json_encode(['success' => false, 'error' => 'Not authorized.']);
        }
        $svc = \App\Providers\PortalServiceProvider::makePersonAdminService();
        $result = $svc->geocodeFamily((int) ($req['family_id'] ?? 0));
        if (!($result['success'] ?? false)) {
            http_response_code(422);
        }
        return json_encode($result);
    },
    'POST /admin/people/delete' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['people_flash'] = 'Only a portal-wide admin can delete people.';
            header('Location: ' . $basePath . '/admin/people?notice=error', true, 302);
            return '';
        }
        $svc = \App\Providers\PortalServiceProvider::makePersonAdminService();
        try {
            $svc->delete((int) ($req['id'] ?? 0), (int) ($actor['actorId'] ?? 0));
            header('Location: ' . $basePath . '/admin/people?notice=deleted', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['people_flash'] = $e->getMessage();
            header('Location: ' . $basePath . '/admin/people?notice=error', true, 302);
        }
        return '';
    },
    'POST /admin/people/save' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['people_flash'] = 'Only a portal-wide admin can edit people.';
            header('Location: ' . $basePath . '/admin/people?notice=error', true, 302);
            return '';
        }
        $svc = \App\Providers\PortalServiceProvider::makePersonAdminService();
        $actorId = (int) ($actor['actorId'] ?? 0);
        try {
            $id = $svc->save($req, $actorId);
            // Saved: back to the record, which is where the change can be seen.
            header('Location: ' . $basePath . '/admin/people/view?id=' . $id . '&notice=saved', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['people_flash'] = $e->getMessage();
            $back = (int) ($req['id'] ?? 0) > 0
                ? '/admin/people/edit?id=' . (int) $req['id'] . '&notice=error'
                : '/admin/people/edit?notice=error';
            header('Location: ' . $basePath . $back, true, 302);
        }
        return '';
    },
    'GET /admin/maintenance/import' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && !empty($actor['isPortalWideAdmin']);
        $campuses = [];
        $campusId = (int) ($req['campus_id'] ?? 0);
        $hubSheet = (string) ($req['hub_sheet'] ?? \App\Services\MemberWorkbookParser::HUB_SHEET);
        $nySheet = (string) ($req['ny_sheet'] ?? '');
        $googleUrl = (string) ($req['google_url'] ?? '');
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['people_flash'] ?? '');
        unset($_SESSION['people_flash']);
        $batches = [];
        $batch = null;
        /** @var list<array<string,mixed>> $duplicateGroups used by the template */
        $duplicateGroups = [];
        /** @var list<array{name:string,kept:string}> $legacyDuplicates used by the template */
        $legacyDuplicates = [];
        /** @var list<string> $matchRuleLabels used by the template */
        $matchRuleLabels = [];
        $rows = [];
        $counts = ['draft' => 0, 'ready' => 0, 'skip' => 0, 'applied' => 0, 'total' => 0];
        $statusFilter = (string) ($req['status'] ?? '');
        /** @var list<array{relative:string,filename:string,bytes:int,mtime:int}> $exports used by the template */
        $exports = [];
        // Staging rows are member records in waiting: nothing is read for anyone
        // but a portal administrator (the view refuses them too).
        if ($isAdmin) {
            try {
                $campuses = \App\Providers\PortalServiceProvider::makePersonAdminService()->campuses();
                $imp = \App\Providers\PortalServiceProvider::makeMemberCampusImportService();
                $batches = $imp->batches();
                $bid = (int) ($req['batch'] ?? 0);
                if ($bid > 0) {
                    $batch = $imp->batch($bid);
                    if ($batch) {
                        $duplicateGroups = $imp->duplicateGroups($bid);
                        $legacyDuplicates = $imp->legacyDuplicates($batch);
                        $matchRuleLabels = $imp->batchRules($batch)->labels();
                        $listed = $imp->rows($bid, $statusFilter);
                        $rows = $listed['rows'];
                        $counts = $listed['counts'];
                        $campusId = (int) $batch['campus_id'];
                    }
                }
            } catch (\Throwable $e) {
                if ($flash === '') {
                    $flash = $e->getMessage();
                    $notice = 'error';
                }
            }
            // What the workbook called each ministry, and whether that landed
            // anywhere. Read here rather than in the view so the page has no idea
            // where the decisions are stored.
            /** @var list<array<string,mixed>> $ministryNameRows used by the template */
            $ministryNameRows = [];
            /** @var list<array{id:int,name:string}> $ministryChoices used by the template */
            $ministryChoices = [];
            /** @var int $ministryNamesPending used by the template */
            $ministryNamesPending = 0;
            try {
                $nameMap = \App\Providers\PortalServiceProvider::makeMinistryNameMap();
                $ministryNameRows = $nameMap->rows();
                $ministryNamesPending = $nameMap->pendingCount();
                foreach (\App\Providers\PortalServiceProvider::makeMinistryService()->listMinistriesPublic(null) as $m) {
                    $ministryChoices[] = [
                        'id' => (int) ($m['ministry_id'] ?? $m['ministryId'] ?? 0),
                        'name' => (string) ($m['name'] ?? ''),
                    ];
                }
                usort($ministryChoices, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            } catch (\Throwable) {
                // A portal without a decisions file simply shows no table.
                $ministryNameRows = [];
            }
            // Workbooks exported before, newest first (backups stay on Backups & maintenance).
            try {
                foreach (\App\Providers\PortalServiceProvider::makeMaintenanceBackupService()->store()->listRecent() as $file) {
                    if (str_ends_with((string) $file['filename'], '.xlsx') && count($exports) < 5) {
                        $exports[] = $file;
                    }
                }
            } catch (\Throwable) {
                $exports = [];
            }
        }

        ob_start();
        require __DIR__ . '/../resources/views/admin-people-import.php';
        return (string) ob_get_clean();
    },

    /**
     * Record what a workbook name means.
     *
     * A ministry id binds the name; 0 means "not a ministry, stop reporting
     * it"; an empty value clears the decision and lets the catalog try again.
     */
    'POST /admin/maintenance/import/ministry-names' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['people_flash'] = 'Only a portal administrator can map ministry names.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
            return '';
        }
        try {
            $map = \App\Providers\PortalServiceProvider::makeMinistryNameMap();
            $decisions = is_array($req['decision'] ?? null) ? $req['decision'] : [];
            $changed = 0;
            foreach ($decisions as $rawName => $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    $map->forget((string) $rawName);
                    $changed++;
                    continue;
                }
                $map->decide((string) $rawName, (int) $value);
                $changed++;
            }
            $map->save();
            $_SESSION['people_flash'] = $changed === 0
                ? 'No ministry name changes to save.'
                : $changed . ' ministry name mapping(s) saved. Re-apply the batch to use them.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=ok', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['people_flash'] = $e->getMessage();
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
        }
        return '';
    },

    'POST /admin/maintenance/import/ingest' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['people_flash'] = 'Only a portal-wide admin can import members.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
            return '';
        }
        $imp = \App\Providers\PortalServiceProvider::makeMemberCampusImportService();
        $campusId = (int) ($req['campus_id'] ?? 0);
        $preset = strtolower(trim((string) ($req['preset'] ?? '')));
        $hubSheet = trim((string) ($req['hub_sheet'] ?? ''));
        $nySheet = trim((string) ($req['ny_sheet'] ?? ''));
        if ($preset !== '' && isset(\App\Services\MemberWorkbookParser::PRESETS[$preset])) {
            $hubSheet = $hubSheet !== '' ? $hubSheet : \App\Services\MemberWorkbookParser::PRESETS[$preset]['primary'];
        }
        if ($hubSheet === '') {
            $hubSheet = \App\Services\MemberWorkbookParser::HUB_SHEET;
        }
        $tmp = null;
        $cleanup = null;
        try {
            $google = trim((string) ($req['google_url'] ?? ''));
            if ($google !== '') {
                $tmp = $imp->downloadGoogleSheet($google);
                $cleanup = $tmp;
            } elseif (isset($_FILES['workbook']) && ($_FILES['workbook']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                if (($_FILES['workbook']['size'] ?? 0) > 12 * 1024 * 1024) {
                    throw new \InvalidArgumentException('That file is larger than 12 MB.');
                }
                $tmp = (string) $_FILES['workbook']['tmp_name'];
                $orig = (string) ($_FILES['workbook']['name'] ?? 'upload.xlsx');
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION)) ?: 'xlsx';
                $named = $tmp . '.' . $ext;
                if (!@move_uploaded_file($tmp, $named) && !@copy($tmp, $named)) {
                    $named = $tmp;
                }
                $tmp = $named;
                if ($named !== (string) $_FILES['workbook']['tmp_name']) {
                    $cleanup = $named;
                }
            } else {
                throw new \InvalidArgumentException('Upload an Excel workbook (.xlsx) or paste a Google Sheets link.');
            }
            $actorId = (int) ($actor['actorId'] ?? 0);
            $rules = new \App\Services\MemberMatchRules(is_array($req['match'] ?? null) ? $req['match'] : []);
            $result = $imp->ingest(
                $tmp, $campusId, $actorId, $hubSheet, $nySheet,
                (string) ($_FILES['workbook']['name'] ?? 'Google Sheet'),
                rules: $rules,
            );
            $batchId = (int) ($result['batch']['id'] ?? 0);
            $dupN = count($imp->duplicateGroups($batchId));
            $_SESSION['people_flash'] = sprintf(
                'Staged %d members (%d ready, %d need review).%s Nothing has been written to member records yet.',
                (int) ($result['counts']['total'] ?? 0),
                (int) ($result['counts']['ready'] ?? 0),
                (int) ($result['counts']['draft'] ?? 0),
                $dupN > 0 ? ' Found ' . $dupN . ' possible ' . ($dupN === 1 ? 'duplicate' : 'duplicates') . ': check the decision on each before applying.' : ''
            );
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=ingested&batch=' . $batchId, true, 302);
        } catch (\Throwable $e) {
            $_SESSION['people_flash'] = $e->getMessage();
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
        } finally {
            if (is_string($cleanup) && $cleanup !== '' && is_file($cleanup)) {
                @unlink($cleanup);
            }
        }
        return '';
    },
    'POST /admin/maintenance/import/row' => function (array $req) use ($resolvePortalActor): string {
        header('Content-Type: application/json');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            http_response_code(403);
            return json_encode(['success' => false, 'error' => 'Not authorized.']);
        }
        try {
            \App\Providers\PortalServiceProvider::makeMemberCampusImportService()
                ->updateRow((int) ($req['id'] ?? 0), (string) ($req['field'] ?? ''), $req['value'] ?? '');
            return json_encode(['success' => true]);
        } catch (\Throwable $e) {
            http_response_code(422);
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    },
    // Fill blank member types on the staged rows from each person's age.
    //
    // Its own action rather than part of ingest, because a suggestion from a
    // birth year is evidence and not a fact — an administrator should be the
    // one who decides to accept it, and should see the result before applying.
    // Writes to staging only.
    'POST /admin/maintenance/import/suggest-types' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $batchId = (int) ($req['batch_id'] ?? 0);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            http_response_code(403);

            return 'Not authorized.';
        }
        try {
            $result = \App\Providers\PortalServiceProvider::makeMemberCampusImportService()
                ->fillMemberTypesFromAge($batchId);
            $parts = [];
            foreach ($result['byType'] as $type => $count) {
                $parts[] = $count . ' × ' . $type;
            }
            $_SESSION['people_flash'] = $result['filled'] === 0
                ? 'No member types could be suggested — the blank rows have no usable birth date.'
                : $result['filled'] . ' member type(s) filled in from age (' . implode(', ', $parts) . ')'
                  . ($result['skipped'] > 0 ? '; ' . $result['skipped'] . ' left blank with no clear match' : '')
                  . '. Check them before applying — these are suggestions, not what the spreadsheet said.';
        } catch (\Throwable $e) {
            $_SESSION['people_flash'] = 'Could not suggest member types: ' . $e->getMessage();
            header('Location: ' . $basePath . '/admin/maintenance/import?batch=' . $batchId . '&notice=error', true, 303);

            return '';
        }
        header('Location: ' . $basePath . '/admin/maintenance/import?batch=' . $batchId . '&notice=typed', true, 303);

        return '';
    },

    'POST /admin/maintenance/import/apply' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['people_flash'] = 'Only a portal-wide admin can import members.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
            return '';
        }
        if ((string) ($req['confirm'] ?? '') !== '1') {
            $_SESSION['people_flash'] = 'Confirm the replacement before applying.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
            return '';
        }
        $batchId = (int) ($req['batch_id'] ?? 0);
        $imp = \App\Providers\PortalServiceProvider::makeMemberCampusImportService();
        $actorId = (int) ($actor['actorId'] ?? 0);
        try {
            $result = $imp->applyBatch($batchId, $actorId);
            $_SESSION['people_flash'] = sprintf(
                'Applied staging: %d new, %d updated, %d removed from campus.',
                $result['created'],
                $result['updated'],
                $result['removed']
            );
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=imported&batch=' . $batchId, true, 302);
        } catch (\Throwable $e) {
            $_SESSION['people_flash'] = $e->getMessage();
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error&batch=' . $batchId, true, 302);
        }
        return '';
    },
    // Decide one duplicate group on a staged batch: keep one row as the
    // person, or keep every row as a different person. Writes staging only.
    'POST /admin/maintenance/import/duplicate' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['people_flash'] = 'Only a portal-wide admin can import members.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
            return '';
        }
        $batchId = (int) ($req['batch_id'] ?? 0);
        // One field carries the choice: a row id to keep, or "separate".
        $choice = (string) ($req['choice'] ?? '');
        try {
            \App\Providers\PortalServiceProvider::makeMemberCampusImportService()->resolveDuplicate(
                $batchId,
                (int) ($req['group'] ?? 0),
                $choice === 'separate' ? 'separate' : 'keep',
                $choice === 'separate' ? 0 : (int) $choice,
            );
            $_SESSION['people_flash'] = $choice === 'separate'
                ? 'Kept every row in that group as a different person.'
                : 'Kept the chosen row; the others in that group are set to Skip.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=ok&batch=' . $batchId . '#mi-dup-' . (int) ($req['group'] ?? 0), true, 303);
        } catch (\Throwable $e) {
            $_SESSION['people_flash'] = $e->getMessage();
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error&batch=' . $batchId, true, 303);
        }
        return '';
    },
    'POST /admin/maintenance/import/discard' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['people_flash'] = 'Only a portal-wide admin can import members.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
            return '';
        }
        try {
            \App\Providers\PortalServiceProvider::makeMemberCampusImportService()
                ->discardBatch((int) ($req['batch_id'] ?? 0));
            $_SESSION['people_flash'] = 'Staging batch discarded.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=discarded', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['people_flash'] = $e->getMessage();
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
        }
        return '';
    },
    'GET /admin/people/export' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['people_flash'] = 'Only a portal-wide admin can export members.';
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
            return '';
        }
        $imp = \App\Providers\PortalServiceProvider::makeMemberCampusImportService();
        try {
            $out = $imp->exportCsv((int) ($req['campus_id'] ?? 0));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $out['filename'] . '"');
            header('Cache-Control: private, no-store');
            return $out['csv'];
        } catch (\Throwable $e) {
            $_SESSION['people_flash'] = $e->getMessage();
            header('Location: ' . $basePath . '/admin/maintenance/import?notice=error', true, 302);
            return '';
        }
    },
    // The Sign-ups & RSVP launcher became the Visitors & RSVPs workspace.
    'GET /admin/outreach' => function (array $req): string {
        header('Location: ' . (string) ($req['_base_path'] ?? '') . '/visitors', true, 301);
        return '';
    },

    // Visitors & RSVPs (docs/design/surfaces.md): registrations, RSVPs and
    // access codes. Portal-wide administrators only; VisitorService refuses
    // everyone else, and these handlers only decide how the refusal looks.
    'GET /visitors' => function (array $req) use ($visitorsPage): string {
        return $visitorsPage($req, 'visitors.php', static fn ($svc, $context) => [
            'queue' => $svc->queue($context, (string) ($req['status'] ?? 'new'), (string) ($req['q'] ?? ''), (int) ($req['page'] ?? 1)),
        ]);
    },
    'GET /visitors/rsvps' => function (array $req) use ($visitorsPage): string {
        return $visitorsPage($req, 'visitors-rsvps.php', static fn ($svc, $context) => [
            'overview' => $svc->rsvps($context, (string) ($req['occasion'] ?? '')),
        ]);
    },
    'POST /visitors/rsvps' => function (array $req) use ($visitorsAction): string {
        $back = '/visitors/rsvps' . (isset($req['occasion']) ? '?' . http_build_query(['occasion' => (string) $req['occasion']]) : '');
        return $visitorsAction($req, $back, static function ($svc, $context) use ($req): string {
            $rsvp = $svc->setAttendance($context, (int) ($req['rsvp_id'] ?? 0), (string) ($req['attendance'] ?? ''));
            return 'Marked ' . trim((string) $rsvp['first_name'] . ' ' . (string) $rsvp['last_name']) . ' as ' . str_replace('_', ' ', (string) $rsvp['attendance']) . '.';
        });
    },
    'GET /visitors/access' => function (array $req) use ($visitorsPage): string {
        return $visitorsPage($req, 'visitors-access.php', static fn ($svc, $context) => [
            'codes' => $svc->accessCodes($context),
            'upcomingEvents' => $svc->upcomingEvents($context),
        ]);
    },
    'POST /visitors/access' => function (array $req) use ($visitorsAction): string {
        return $visitorsAction($req, '/visitors/access', static function ($svc, $context) use ($req): string {
            $module = (string) ($req['module'] ?? '');
            $word = (string) ($req['action'] ?? '') === 'generate' ? '' : (string) ($req['word'] ?? '');
            $code = $svc->issueAccessCode($context, $module, $word, (int) ($req['days'] ?? 7), (string) ($req['note'] ?? ''));
            return 'New ' . ($module === 'rsvp' ? 'RSVP' : 'sign-up') . ' access code saved: ' . $code['word'] . ', valid until ' . substr((string) $code['expires_at'], 0, 16) . '.';
        });
    },
    'GET /visitors/{id}' => function (array $req) use ($visitorsPage): string {
        return $visitorsPage($req, 'visitors-record.php', static function ($svc, $context) use ($req): ?array {
            $id = ctype_digit((string) ($req['id'] ?? '')) ? (int) $req['id'] : 0;
            $record = $id > 0 ? $svc->registration($context, $id) : null;
            return $record === null ? null : ['record' => $record];
        });
    },
    'POST /visitors/{id}' => function (array $req) use ($visitorsAction): string {
        $id = ctype_digit((string) ($req['id'] ?? '')) ? (int) $req['id'] : 0;
        return $visitorsAction($req, '/visitors/' . $id, static function ($svc, $context) use ($req, $id): string {
            $action = (string) ($req['action'] ?? '');
            if ($action === 'notes') {
                $svc->saveNotes($context, $id, (string) ($req['reviewer_notes'] ?? ''));
                return 'Reviewer notes saved.';
            }
            if ($action === 'status') {
                $status = (string) ($req['status'] ?? '');
                $svc->setStatus($context, $id, $status);
                return match ($status) {
                    'reviewed' => 'Marked reviewed.',
                    'duplicate' => 'Marked as a duplicate.',
                    'rejected' => 'Rejected.',
                    default => 'Moved back to new.',
                };
            }
            if ($action === 'promote') {
                $result = $svc->promote($context, $id, [
                    'mode' => (string) ($req['mode'] ?? ''),
                    'person_id' => (int) ($req['person_id'] ?? 0),
                    'membership_status_id' => (int) ($req['membership_status_id'] ?? 0),
                    'campus_id' => (int) ($req['campus_id'] ?? 0),
                    'force' => (string) ($req['force'] ?? '') === '1',
                ]);
                return $result['outcome'] === 'created'
                    ? 'Promoted: member record #' . $result['person_id'] . ' was created.'
                    : 'Promoted: linked to member record #' . $result['person_id'] . '.';
            }
            throw new \App\Exceptions\ValidationFailed('Nothing to do.');
        });
    },

    // Campus locations — self-contained CRUD over campuses.
    'GET /admin/campuses' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $svc = \App\Providers\PortalServiceProvider::makeCampusAdminService();
        $campuses = $svc->all();
        $stats = $svc->stats();
        $editingCampus = $svc->find((int) ($req['campus_id'] ?? 0)) ?? $svc->blank();
        $assignmentEvents = ((int) ($editingCampus['id'] ?? 0) > 0)
            ? $svc->eligibleAssignmentEvents((int) $editingCampus['id'])
            : [];
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['campus_flash'] ?? '');
        unset($_SESSION['campus_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-campuses.php';
        return (string) ob_get_clean();
    },
    'POST /admin/campuses' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $target = $basePath . '/admin/campuses';
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['campus_flash'] = 'Only a portal-wide admin can manage campuses.';
            header('Location: ' . $target . '?notice=error', true, 302);
            return '';
        }
        $svc = \App\Providers\PortalServiceProvider::makeCampusAdminService();
        $actorId = (int) ($actor['actorId'] ?? 0);
        $action = (string) ($req['action'] ?? '');
        try {
            if ($action === 'save') {
                $id = $svc->save($req, $actorId);
                header('Location: ' . $target . '?notice=saved&campus_id=' . $id, true, 302);
                return '';
            }
            if ($action === 'setmain') {
                $svc->setMain((int) ($req['campus_id'] ?? 0));
                header('Location: ' . $target . '?notice=main', true, 302);
                return '';
            }
            if ($action === 'delete') {
                $svc->delete((int) ($req['campus_id'] ?? 0));
                header('Location: ' . $target . '?notice=deleted', true, 302);
                return '';
            }
            header('Location: ' . $target, true, 302);
            return '';
        } catch (\Throwable $e) {
            $_SESSION['campus_flash'] = $e->getMessage();
            $back = $action === 'save' && (int) ($req['id'] ?? 0) > 0 ? '&campus_id=' . (int) $req['id'] : '';
            header('Location: ' . $target . '?notice=error' . $back, true, 302);
            return '';
        }
    },

    // Church info — portal-owned identity/contact/location (config/church-info.json).
    'GET /admin/church-info' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $info = \App\Providers\PortalServiceProvider::makeChurchInfoService()->load();
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['churchinfo_flash'] ?? '');
        unset($_SESSION['churchinfo_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-church-info.php';
        return (string) ob_get_clean();
    },
    'POST /admin/church-info' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $target = $basePath . '/admin/church-info';
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['churchinfo_flash'] = 'Only a portal-wide admin can edit church info.';
            header('Location: ' . $target . '?notice=error', true, 302);
            return '';
        }
        try {
            \App\Providers\PortalServiceProvider::makeChurchInfoService()->save($req);
            header('Location: ' . $target . '?notice=saved', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['churchinfo_flash'] = $e->getMessage();
            header('Location: ' . $target . '?notice=error', true, 302);
        }
        return '';
    },

    // Users & access — logins, the person each belongs to, and their roles.
    // The list is /admin/users; each login has its own record page. The old
    // ?user_id= address (bookmarks, the previous two-pane page) redirects there.
    'GET /admin/users' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector, $resolveAllMinistries): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $legacyId = (int) ($req['user_id'] ?? 0);
        if ($legacyId > 0) {
            $notice = (string) ($req['notice'] ?? '');
            header('Location: ' . $basePath . '/admin/users/' . $legacyId . ($notice !== '' ? '?notice=' . rawurlencode($notice) : ''), true, 302);
            return '';
        }
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $users = [];
        $allUsers = [];
        $ministries = [];
        $filters = [
            'q' => trim((string) ($req['q'] ?? '')),
            'role' => (string) ($req['role'] ?? ''),
            'state' => (string) ($req['state'] ?? ''),
        ];
        if (!in_array($filters['role'], array_merge([''], \App\Services\SystemUserService::ROLES, ['portal-admin']), true)) {
            $filters['role'] = '';
        }
        if (!array_key_exists($filters['state'], \App\Services\LoginDirectory::STATES)) {
            $filters['state'] = '';
        }
        if ($isAdmin) {
            $allUsers = \App\Providers\PortalServiceProvider::makeSystemUserService()->list();
            $users = \App\Services\LoginDirectory::filter($allUsers, $filters['q'], $filters['role'], $filters['state']);
            $ministries = $resolveAllMinistries($req);
        }
        $currentUserId = (int) ($actor['actorId'] ?? 0);
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['users_flash'] ?? '');
        unset($_SESSION['users_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-users.php';
        return (string) ob_get_clean();
    },
    'GET /admin/users/new' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector, $resolveAllMinistries): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $editingUser = null;
        $ministries = $isAdmin ? $resolveAllMinistries($req) : [];
        $currentUserId = (int) ($actor['actorId'] ?? 0);
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['users_flash'] ?? '');
        unset($_SESSION['users_flash']);
        $personQuery = ''; $personResults = []; $recentActivity = [];
        ob_start();
        require __DIR__ . '/../resources/views/admin-user-record.php';
        return (string) ob_get_clean();
    },
    'GET /admin/users/{id}' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector, $resolveAllMinistries): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $editingUser = null; $ministries = []; $personResults = []; $recentActivity = [];
        $personQuery = trim((string) ($req['person_q'] ?? ''));
        // Nothing about the login is fetched for someone who may not see it.
        if ($isAdmin) {
            $editingUser = \App\Providers\PortalServiceProvider::makeSystemUserService()->find((int) ($req['id'] ?? 0));
            if ($editingUser === null) {
                http_response_code(404);
            } else {
                $ministries = $resolveAllMinistries($req);
                if ($personQuery !== '' && empty($editingUser['person_id'])) {
                    try {
                        $personResults = \App\Providers\PortalServiceProvider::makePersonAdminService()
                            ->list(['search' => mb_substr($personQuery, 0, 80)], 1, 10);
                    } catch (\Throwable) {
                        $personResults = [];
                    }
                }
                try {
                    $recentActivity = \App\Providers\PortalServiceProvider::makeActivityHistoryService()->page(
                        \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req),
                        ['target_type' => 'user_account', 'target_id' => (string) $editingUser['id']],
                    );
                } catch (\Throwable) {
                    $recentActivity = [];
                }
            }
        }
        $currentUserId = (int) ($actor['actorId'] ?? 0);
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['users_flash'] ?? '');
        unset($_SESSION['users_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-user-record.php';
        return (string) ob_get_clean();
    },
    'POST /admin/users' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $target = $basePath . '/admin/users';
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['users_flash'] = 'Only a portal-wide admin can manage users.';
            header('Location: ' . $target . '?notice=error', true, 302);
            return '';
        }
        $svc = \App\Providers\PortalServiceProvider::makeSystemUserService();
        $me = (int) ($actor['actorId'] ?? 0);
        $action = (string) ($req['action'] ?? '');
        $uid = (int) ($req['user_id'] ?? 0);
        $record = static fn (int $id, string $notice): string => $target . '/' . $id . '?notice=' . $notice;
        $ci = static fn ($v) => ($v !== null && (int) $v > 0) ? (int) $v : null;
        try {
            if ($action === 'create') {
                $newId = $svc->create(
                    (string) ($req['email'] ?? ''), (string) ($req['display_name'] ?? ''),
                    (string) ($req['password'] ?? ''), (int) ($req['is_active'] ?? 0) === 1,
                    (int) ($req['must_change_password'] ?? 0) === 1, $me,
                );
                $role = (string) ($req['role'] ?? '');
                if ($role !== '') {
                    $svc->addRole($newId, $role, $ci($req['campus_id'] ?? null), $ci($req['ministry_id'] ?? null), $me);
                }
                header('Location: ' . $record($newId, 'created'), true, 302); return '';
            }
            if ($action === 'update') {
                $svc->updateProfile($uid, (string) ($req['display_name'] ?? ''), (int) ($req['is_active'] ?? 0) === 1, (int) ($req['must_change_password'] ?? 0) === 1, $me);
                header('Location: ' . $record($uid, 'saved'), true, 302); return '';
            }
            if ($action === 'setpw') {
                $svc->setPassword($uid, (string) ($req['password'] ?? ''), $me);
                header('Location: ' . $record($uid, 'pw'), true, 302); return '';
            }
            if ($action === 'addrole') {
                $svc->addRole($uid, (string) ($req['role'] ?? ''), $ci($req['campus_id'] ?? null), $ci($req['ministry_id'] ?? null), $me);
                header('Location: ' . $record($uid, 'role'), true, 302); return '';
            }
            if ($action === 'removerole') {
                $svc->removeRole((int) ($req['account_role_id'] ?? 0), $me);
                header('Location: ' . $record($uid, 'role'), true, 302); return '';
            }
            if ($action === 'link') {
                // A login belongs to one person: AuthService refuses a login that
                // already belongs to someone else, and a person who already has one.
                \App\Providers\PortalServiceProvider::makeAuthService()->linkLoginToPerson(
                    \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req),
                    $uid,
                    (int) ($req['person_id'] ?? 0),
                    ['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null],
                );
                header('Location: ' . $record($uid, 'linked'), true, 302); return '';
            }
            if ($action === 'delete') {
                $svc->delete($uid, $me);
                header('Location: ' . $target . '?notice=deleted', true, 302); return '';
            }
            header('Location: ' . $target, true, 302); return '';
        } catch (\Throwable $e) {
            $_SESSION['users_flash'] = $e->getMessage();
            if ($action === 'create') {
                header('Location: ' . $target . '/new?notice=error', true, 302);
            } else {
                header('Location: ' . ($uid > 0 ? $record($uid, 'error') : $target . '?notice=error'), true, 302);
            }
            return '';
        }
    },

    // Activity history — the audit log across every kind of record, read-only.
    'GET /admin/history' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $history = null;
        $historyError = '';
        if ($isAdmin) {
            try {
                $history = \App\Providers\PortalServiceProvider::makeActivityHistoryService()->page(
                    \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req),
                    $req,
                );
            } catch (\App\Exceptions\ValidationFailed $e) {
                $historyError = $e->getMessage();
            } catch (\Throwable) {
                $historyError = 'Activity history could not be read. Try again, or check the database connection under System.';
            }
        }
        ob_start();
        require __DIR__ . '/../resources/views/admin-history.php';
        return (string) ob_get_clean();
    },

    // Family management — self-contained CRUD over households.
    'GET /admin/families' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $search = (string) ($req['q'] ?? '');
        $page = max(1, (int) ($req['page'] ?? 1));
        $perPage = 25;
        // Households carry addresses and contact details: administrators only.
        $stats = ['total' => 0, 'active' => 0];
        $listing = ['total' => 0, 'rows' => []];
        if ($isAdmin) {
            $svc = \App\Providers\PortalServiceProvider::makeFamilyAdminService();
            $stats = $svc->stats();
            $listing = $svc->list($search, $page, $perPage);
            $lastPage = max(1, (int) ceil(((int) $listing['total']) / $perPage));
            if ($page > $lastPage) {
                $page = $lastPage;
                $listing = $svc->list($search, $page, $perPage);
            }
        }
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['family_flash'] ?? '');
        unset($_SESSION['family_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-families.php';
        return (string) ob_get_clean();
    },
    'GET /admin/families/edit' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $fid = (int) ($req['id'] ?? 0);
        $family = []; $members = []; $missing = false;
        $recentHistory = ['entries' => [], 'total' => 0];
        if ($isAdmin) {
            $svc = \App\Providers\PortalServiceProvider::makeFamilyAdminService();
            $found = $fid > 0 ? $svc->find($fid) : null;
            $missing = $fid > 0 && $found === null;
            $family = $found ?? $svc->blank();
            $members = $found !== null ? $svc->members($fid) : [];
            if ($found !== null) {
                try {
                    $recentHistory = \App\Providers\PortalServiceProvider::makeRecordHistoryService()->recent($actor, 'household', $fid, 5);
                } catch (\Throwable) { $recentHistory = ['entries' => [], 'total' => 0]; }
            }
        }
        // Related families (admin-confirmed map) + shared-residence families (derived).
        $relatedLinks = []; $residenceMates = []; $familyPickList = [];
        if ($isAdmin && !$missing && $fid > 0) {
            $rel = \App\Providers\PortalServiceProvider::makeRelatedFamiliesService();
            $raw = $rel->forFamily($fid);
            $names = $svc->namesFor(array_map(static fn ($l) => $l['other'], $raw));
            foreach ($raw as $l) { $relatedLinks[] = $l + ['name' => $names[$l['other']] ?? ('Family #' . $l['other'])]; }
            $residenceMates = $svc->residenceMates($fid);
            $familyPickList = $svc->pickList($fid);
        }
        $relTypes = \App\Services\RelatedFamiliesService::RELS;
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['family_flash'] ?? '');
        unset($_SESSION['family_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-family-edit.php';
        return (string) ob_get_clean();
    },
    'POST /admin/families/link' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $fid = (int) ($req['id'] ?? 0);
        $actor = $resolvePortalActor($req);
        $back = $basePath . '/admin/families/edit?id=' . $fid;
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['family_flash'] = 'Only a portal-wide admin can link families.';
            header('Location: ' . $back . '&notice=error', true, 302); return '';
        }
        $rel = \App\Providers\PortalServiceProvider::makeRelatedFamiliesService();
        try {
            $otherId = (int) ($req['other_id'] ?? 0);
            $actorId = (int) ($actor['actorId'] ?? 0);
            $families = \App\Providers\PortalServiceProvider::makeFamilyAdminService();
            if (($req['action'] ?? '') === 'unlink') {
                $label = '';
                foreach ($rel->forFamily($fid) as $l) {
                    if ((int) $l['other'] === $otherId) { $label = (string) $l['label']; }
                }
                $rel->unlink($fid, $otherId);
                $families->recordLinkChange($actorId, 'household.unlinked', $fid, $otherId, $label);
            } else {
                $rel->link($fid, $otherId, (string) ($req['rel'] ?? 'extended'), (string) ($req['direction'] ?? 'parent'));
                $label = '';
                foreach ($rel->forFamily($fid) as $l) {
                    if ((int) $l['other'] === $otherId) { $label = (string) $l['label']; }
                }
                $families->recordLinkChange($actorId, 'household.linked', $fid, $otherId, $label);
            }
            header('Location: ' . $back . '&notice=saved', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['family_flash'] = $e->getMessage();
            header('Location: ' . $back . '&notice=error', true, 302);
        }
        return '';
    },
    'POST /admin/families/save' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['family_flash'] = 'Only a portal-wide admin can edit families.';
            header('Location: ' . $basePath . '/admin/families?notice=error', true, 302);
            return '';
        }
        $svc = \App\Providers\PortalServiceProvider::makeFamilyAdminService();
        $actorId = (int) ($actor['actorId'] ?? 0);
        try {
            $id = $svc->save($req, $actorId);
            header('Location: ' . $basePath . '/admin/families/edit?id=' . $id . '&notice=saved', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['family_flash'] = $e->getMessage();
            $back = (int) ($req['id'] ?? 0) > 0
                ? '/admin/families/edit?id=' . (int) $req['id'] . '&notice=error'
                : '/admin/families/edit?notice=error';
            header('Location: ' . $basePath . $back, true, 302);
        }
        return '';
    },
    'GET /admin/families/duplicates' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $mode = ($req['by'] ?? 'name') === 'address' ? 'address' : 'name';
        $svc = \App\Providers\PortalServiceProvider::makeFamilyAdminService();
        $groups = []; $nameCount = 0; $addressCount = 0;
        if ($isAdmin) {
            $nameGroups = $svc->duplicateGroups();
            $addressGroups = $svc->addressGroups();
            $nameCount = count($nameGroups);
            $addressCount = count($addressGroups);
            $groups = $mode === 'address' ? $addressGroups : $nameGroups;
        }
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['family_flash'] ?? '');
        unset($_SESSION['family_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-family-duplicates.php';
        return (string) ob_get_clean();
    },
    'POST /admin/families/merge' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $return = (string) ($req['return'] ?? '') === 'edit' && (int) ($req['keep_id'] ?? 0) > 0
            ? $basePath . '/admin/families/edit?id=' . (int) $req['keep_id']
            : $basePath . '/admin/families/duplicates';
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['family_flash'] = 'Only a portal-wide admin can merge families.';
            header('Location: ' . $return . (str_contains($return, '?') ? '&' : '?') . 'notice=error', true, 302);
            return '';
        }
        $svc = \App\Providers\PortalServiceProvider::makeFamilyAdminService();
        $rel = \App\Providers\PortalServiceProvider::makeRelatedFamiliesService();
        $actorId = (int) ($actor['actorId'] ?? 0);
        $keep = (int) ($req['keep_id'] ?? 0);
        $mergeIds = $req['merge_ids'] ?? [];
        if (!is_array($mergeIds)) { $mergeIds = [$mergeIds]; }
        try {
            $moved = 0; $count = 0;
            foreach ($mergeIds as $mid) {
                $mid = (int) $mid;
                if ($mid > 0 && $mid !== $keep) {
                    $moved += $svc->merge($keep, $mid, $actorId);
                    $rel->removeFamily($mid);
                    $count++;
                }
            }
            $_SESSION['family_flash'] = $count === 0 ? 'Nothing to merge.' : "Merged $count family record(s); moved $moved member(s).";
            header('Location: ' . $return . (str_contains($return, '?') ? '&' : '?') . 'notice=saved', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['family_flash'] = $e->getMessage();
            header('Location: ' . $return . (str_contains($return, '?') ? '&' : '?') . 'notice=error', true, 302);
        }
        return '';
    },
    'POST /admin/families/delete' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['family_flash'] = 'Only a portal-wide admin can delete families.';
            header('Location: ' . $basePath . '/admin/families?notice=error', true, 302);
            return '';
        }
        $svc = \App\Providers\PortalServiceProvider::makeFamilyAdminService();
        try {
            $svc->delete((int) ($req['id'] ?? 0));
            header('Location: ' . $basePath . '/admin/families?notice=deleted', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['family_flash'] = $e->getMessage();
            header('Location: ' . $basePath . '/admin/families?notice=error', true, 302);
        }
        return '';
    },

    // Option manager — edit membership statuses, household roles and member types.
    'GET /admin/event-types' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $types = [];
        $orphans = [];
        if ($isAdmin) {
            try {
                $svc = \App\Providers\PortalServiceProvider::makeEventTypeService();
                $resolved = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
                $types = $svc->allForAdmin($resolved);
                $orphans = $svc->orphanedEvents($resolved);
            } catch (\Throwable) {
                $types = [];
                $orphans = [];
            }
        }
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['event_types_flash'] ?? '');
        unset($_SESSION['event_types_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-event-types.php';
        return (string) ob_get_clean();
    },

    // The composer: choose what goes on the page, and watch it change.
    /**
     * The ministry schedule as a printable publication.
     *
     * A different document from the calendar prints, not a mode of them: this
     * consolidates two scheduling systems into one sheet of ministries, roles
     * and names. The consolidation happens in the builder; the template renders
     * what it is handed and queries nothing.
     *
     * Volunteer names are member data. This route reads the signed-in actor and
     * the builder refuses without a schedule-reading permission — the public
     * board endpoint is a deliberate separate thing and this is not it.
     */
    'GET /schedules/ministry-print' => function (array $req) use ($resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $base = $basePath;

        try {
            $actorContext = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
        } catch (\App\Exceptions\PermissionDenied) {
            http_response_code(401);

            return '<!doctype html><meta charset="utf-8"><title>Sign in</title>'
                . '<p style="font:16px system-ui;margin:3rem">Sign in to view ministry schedules. '
                . '<a href="' . htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8') . '">Sign in</a></p>';
        }

        $raw = (string) ($req['date'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1
            ? new DateTimeImmutable($raw)
            : new DateTimeImmutable('today');

        $builder = new \App\Services\Ministry\MinistryScheduleDocumentBuilder(
            \App\Providers\PortalServiceProvider::makeScheduleService(),
            \App\Providers\PortalServiceProvider::makeRosterScheduleService(),
        );

        try {
            /** @var \App\Documents\MinistryScheduleDocument $document used by the template */
            $document = $builder->build($actorContext, $date);
        } catch (\App\Exceptions\PermissionDenied $e) {
            http_response_code(403);

            return '<!doctype html><meta charset="utf-8"><title>Not permitted</title>'
                . '<p style="font:16px system-ui;margin:3rem">'
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        /** @var list<list<array<string,mixed>>> $columns used by the template */
        $columns = \App\Services\Ministry\ColumnBalancer::distribute($document->sections, 3);
        /** @var bool $overflows used by the template */
        $overflows = \App\Services\Ministry\ColumnBalancer::overflows($columns);
        $dateValue = $date->format('Y-m-d');

        ob_start();
        $dateForTemplate = $dateValue;
        $date = $dateValue;
        require __DIR__ . '/../resources/views/print/ministry-schedule/_preview.php';

        return (string) ob_get_clean();
    },

    'GET /calendar/print-setup' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);

        $layers = [];
        try {
            $layers = (new \App\Http\Controllers\Api\CalendarController(
                \App\Providers\PortalServiceProvider::makeCalendarService(),
                \App\Providers\PortalServiceProvider::makeRequestContext(),
                \App\Providers\PortalServiceProvider::makeRosterScheduleService(),
                \App\Providers\PortalServiceProvider::makeEventTypeService(),
            ))->layers($req)['layers'] ?? [];
        } catch (\Throwable) {
            $layers = [];
        }
        // Layers a printed calendar cannot actually carry are not offered.
        //
        //   anniversaries — no family in the register has a wedding date, so
        //                   the layer can only ever print nothing.
        //   custom        — "Others" are custom calendars kept in the reader's
        //                   own browser; they never reach the server, so they
        //                   can never reach a printout.
        //   sunday-school — a configured event type this church does not use.
        //                   Unlike the two above this is a *setting*, not a
        //                   dead end: deleting the type in /admin/event-types
        //                   removes it from the calendar as well, which is the
        //                   durable fix. This list only stops it appearing here.
        $notPrintable = ['anniversaries', 'custom', 'events:sunday-school'];
        $layers = array_values(array_filter(
            $layers,
            static fn (array $l): bool => !in_array($l['source'] ?? '', $notPrintable, true),
        ));

        // The two things a church actually prints. Everything else is a
        // one-off, so it starts unticked and these are one click.
        /** @var array<string,array{label:string,sources:list<string>}> $presets used by the template */
        $presets = [
            'people' => [
                'label' => 'Birthdays & holidays',
                'sources' => ['birthdays', 'holidays:ca-on'],
            ],
            'ministry' => [
                'label' => 'Ministry & leadership',
                'sources' => ['events:ministry', 'events:leadership'],
            ],
        ];
        // Offer only what this portal actually has — a preset that ticks a
        // layer nobody has configured would tick nothing and look broken.
        $available = array_column($layers, 'source');
        foreach ($presets as $key => $preset) {
            $presets[$key]['sources'] = array_values(array_intersect($preset['sources'], $available));
            if ($presets[$key]['sources'] === []) {
                unset($presets[$key]);
            }
        }

        $templates = \App\Services\Calendar\PrintTemplates::all();
        /** @var array<string,array<string,mixed>> $themes used by the template */
        $themes = \App\Services\Calendar\CalendarTheme::all();

        // Saved views this actor may open, and the one they asked for. A view
        // stores the date *mode*, so opening "Scarborough Monthly" in December
        // prints December — the range is resolved now, not when it was saved.
        /** @var list<array<string,mixed>> $savedViews used by the template */
        $savedViews = [];
        /** @var array<string,mixed>|null $activeView used by the template */
        $activeView = null;
        /** @var array<string,mixed> $initialConfig used by the template */
        $initialConfig = \App\Services\Calendar\PrintConfig::fromArray([])->toArray();
        /** @var bool $canShareViews used by the template */
        $canShareViews = false;
        try {
            $resolvedActor = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
            $viewService = \App\Providers\PortalServiceProvider::makeSavedViewService();
            $savedViews = $viewService->listFor($resolvedActor);
            $canShareViews = $resolvedActor->isPortalWideAdmin;
            $wanted = (int) ($req['view'] ?? 0);
            if ($wanted > 0) {
                $row = $viewService->open($resolvedActor, $wanted);
                if ($row !== null) {
                    $activeView = [
                        'id' => $row['id'], 'name' => $row['name'],
                        'visibility' => $row['visibility'], 'mine' => $row['mine'],
                        'canEdit' => $row['canEdit'],
                    ];
                    $initialConfig = $viewService->configOf($row)->toArray();
                }
            }
        } catch (\Throwable) {
            // A portal without saved-view storage still prints calendars.
            $savedViews = [];
        }

        ob_start();
        require __DIR__ . '/../resources/views/calendar-print.php';

        return (string) ob_get_clean();
    },

    // The print composer.
    //
    // A separate document rather than the calendar page with print CSS over it.
    // Screen and paper share the view model and nothing else — which is what
    // lets the printed calendar be set for paper instead of exported from a
    // screen.
    'GET /calendar/print' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);

        // One configuration object, built once. These settings used to be a
        // dozen loose parameters read here and defaulted again in two other
        // files; once a configuration can be saved and reopened next month,
        // "what was the default?" has to have exactly one answer.
        $config = \App\Services\Calendar\PrintConfig::fromQuery($req);
        $template = (string) $config->get('layout');
        $paper = (string) $config->get('page.paper');
        $orientation = (string) $config->get('page.orientation');
        $sources = (array) $config->get('content.sources', []);
        $sources = $sources === [] ? null : $sources;

        $today = new DateTimeImmutable('today');
        [$start, $end] = $config->resolveRange($today);
        // Widen before fetching, not after composing. A template that needs
        // whole months — or twelve of them — must have the feed for them, or it
        // draws the extra months empty.
        [$start, $end] = \App\Services\Calendar\PrintTemplates::rangeFor($template, $start, $end);
        // A year is the most anybody prints in one go, and the ceiling keeps a
        // mistyped date from asking the database for a century.
        if ($end > $start->modify('+1 year')) {
            $end = $start->modify('+1 year');
        }

        $items = [];
        $colors = [];
        try {
            $controller = new \App\Http\Controllers\Api\CalendarController(
                \App\Providers\PortalServiceProvider::makeCalendarService(),
                \App\Providers\PortalServiceProvider::makeRequestContext(),
                \App\Providers\PortalServiceProvider::makeRosterScheduleService(),
                \App\Providers\PortalServiceProvider::makeEventTypeService(),
            );
            // array_merge, not `+`: the union operator keeps the LEFT operand's
            // keys, so $req's own start/end won and the corrected range here was
            // silently thrown away. Harmless until a template widened the window,
            // at which point the extra months printed empty from data nobody had
            // asked the database for.
            $feed = $controller->sources(array_merge($req, [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
            ]));
            $items = $feed['items'] ?? [];
            foreach (($controller->layers($req)['layers'] ?? []) as $layer) {
                $colors[$layer['source']] = $layer['color'];
            }
        } catch (\Throwable) {
            // An empty calendar prints as an empty calendar; it does not 500.
        }

        $church = [];
        try {
            $church = \App\Providers\PortalServiceProvider::makeChurchInfoService()->load();
        } catch (\Throwable) {
            $church = [];
        }

        // Name the campus on the page. A calendar pinned to one campus and a
        // calendar showing all of them look identical once printed.
        $campusName = '';
        $chosen = (int) (_campusContext($req) ?? 0);
        if ($chosen > 0) {
            foreach (($resolveCampusSelector($req)['campuses'] ?? []) as $campus) {
                if ((int) $campus['id'] === $chosen) {
                    $campusName = (string) $campus['name'];
                }
            }
        } else {
            $campusName = 'All campuses';
        }

        $composer = new \App\Services\Calendar\PrintComposer(dirname(__DIR__) . '/resources/views');

        return $composer->render($items, $start, $end, [
            'template' => $template,
            'paper' => $paper,
            'orientation' => $orientation !== '' ? $orientation : null,
            'sources' => $sources,
            'colors' => $colors,
            'church' => (string) ($church['name'] ?? 'Church Portal'),
            'subtitle' => $campusName,
            'website' => (string) ($church['website'] ?? ''),
            'footer' => 'Printed ' . $today->format('j M Y'),
            'accent' => $config->get('appearance.accent') !== '' ? $config->get('appearance.accent') : '#0c5a45',
            'today' => $today->format('Y-m-d'),
            'typeScale' => $config->get('appearance.typeScale'),
            'nameStyle' => $config->get('appearance.names'),
            'font' => $config->get('appearance.font'),
            'density' => $config->get('appearance.density'),
            'titleStyle' => $config->get('appearance.titleStyle'),
            'theme' => $config->get('appearance.theme'),
            'entryDisplay' => $config->get('appearance.entryDisplay'),
            'artwork' => $config->get('appearance.artwork'),
            'decoration' => $config->get('appearance.decoration'),
            'inkFriendly' => $config->get('appearance.inkFriendly'),
            'headerShow' => $config->get('header.show'),
            'headerTitle' => $config->get('header.title'),
            'headerSubtitle' => $config->get('header.subtitle'),
            'footerShow' => $config->get('footer.show'),
            'footerNote' => $config->get('footer.note'),
            // Already sanitised by PrintConfig — the one place that judgement
            // is made — and empty when the region has nothing in it.
            'topInfo' => $config->get('additional.top.enabled') ? $config->get('additional.top.html') : '',
            'bottomInfo' => $config->get('additional.bottom.enabled') ? $config->get('additional.bottom.html') : '',
        ]);
    },

    // Holiday calendars: add, sync, hide, remove.
    //
    // Syncing reaches out to a public holiday service, which is the one place
    // in this application that makes a network call during a request. It is an
    // explicit button rather than a schedule because a church needs it about
    // once a year, and an unattended job for something that rare fails quietly
    // — which is the worst way for a calendar to go stale.
    'POST /admin/calendar/holidays' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $target = $basePath . '/admin/calendar';
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['calendar_flash'] = 'Only a portal administrator can change calendar sources.';
            $_SESSION['calendar_flash_kind'] = 'err';
            header('Location: ' . $target, true, 303);

            return '';
        }

        $sync = new \App\Services\HolidayCalendarSync(dirname(__DIR__) . '/config/holidays.json');
        $action = (string) ($req['action'] ?? '');
        $id = (string) ($req['id'] ?? '');

        try {
            switch ($action) {
                case 'add':
                    $added = $sync->add(
                        (string) ($req['country'] ?? ''),
                        (string) ($req['region'] ?? '') !== '' ? (string) $req['region'] : null,
                        (string) ($req['label'] ?? ''),
                        (string) ($req['color'] ?? '#8a4b12'),
                    );
                    $_SESSION['calendar_flash'] = sprintf(
                        '%s added — %d holidays through %s. It is on the calendar now.',
                        $added['label'], $added['count'], substr((string) $added['through'], 0, 4),
                    );
                    break;

                case 'refresh':
                    $done = $sync->refresh($id);
                    $_SESSION['calendar_flash'] = sprintf(
                        '%s synced — %d holidays through %s.',
                        $done['label'], $done['count'], substr((string) $done['through'], 0, 4),
                    );
                    break;

                case 'refresh-all':
                    $result = $sync->refreshAll();
                    $names = array_map(static fn (array $r): string => $r['label'], $result['refreshed']);
                    $message = $names === []
                        ? 'Nothing was synced.'
                        : count($names) . ' calendar(s) synced: ' . implode(', ', $names) . '.';
                    if ($result['failed'] !== []) {
                        // Partial success is reported as such. Saying "synced"
                        // when one country failed is how a calendar quietly
                        // stops being right.
                        $message .= ' ' . count($result['failed']) . ' could not be reached: '
                            . implode('; ', array_map(static fn (array $f): string => $f['label'], $result['failed']))
                            . '. Their existing holidays are unchanged.';
                        $_SESSION['calendar_flash_kind'] = 'err';
                    }
                    $_SESSION['calendar_flash'] = $message;
                    break;

                case 'enable':
                case 'disable':
                    $sync->setEnabled($id, $action === 'enable');
                    $_SESSION['calendar_flash'] = $action === 'enable'
                        ? 'That calendar is on the church calendar again.'
                        : 'That calendar is hidden. Its dates are kept, so showing it again is instant.';
                    break;

                case 'remove':
                    $sync->remove($id);
                    $_SESSION['calendar_flash'] = 'Removed. Add it again at any time to fetch its holidays afresh.';
                    break;

                default:
                    $_SESSION['calendar_flash'] = 'That action is not something this page does.';
                    $_SESSION['calendar_flash_kind'] = 'err';
            }
        } catch (\Throwable $e) {
            $_SESSION['calendar_flash'] = $e->getMessage();
            $_SESSION['calendar_flash_kind'] = 'err';
        }

        header('Location: ' . $target, true, 303);

        return '';
    },

    'POST /admin/event-types' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $target = $basePath . '/admin/event-types';
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['event_types_flash'] = 'Only a portal-wide admin can manage event types.';
            header('Location: ' . $target . '?notice=error', true, 302);
            return '';
        }
        try {
            $svc = \App\Providers\PortalServiceProvider::makeEventTypeService();
            $resolved = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
            $action = (string) ($req['action'] ?? '');
            $typeId = (int) ($req['event_type_id'] ?? 0);
            $label = (string) ($req['name'] ?? '');
            $audience = (string) ($req['audience'] ?? 'members');
            $color = (string) ($req['color'] ?? '');
            $sort = (int) ($req['sort_order'] ?? 0);
            if ($action === 'add') {
                $svc->add($resolved, $label, $audience, $color, $sort);
            } elseif ($action === 'update') {
                $svc->update($resolved, $typeId, $label, $audience, $color, $sort);
            } elseif ($action === 'set-default') {
                $svc->makeDefault($resolved, $typeId);
            } elseif ($action === 'delete') {
                $svc->delete($resolved, $typeId);
            } else {
                throw new \InvalidArgumentException('Unknown action.');
            }
            header('Location: ' . $target . '?notice=saved', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['event_types_flash'] = $e->getMessage();
            header('Location: ' . $target . '?notice=error', true, 302);
        }
        return '';
    },

    'GET /admin/options' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $lists = $isAdmin ? \App\Providers\PortalServiceProvider::makeOptionAdminService()->all() : [];
        $notice = (string) ($req['notice'] ?? '');
        $flash = (string) ($_SESSION['options_flash'] ?? '');
        unset($_SESSION['options_flash']);
        ob_start();
        require __DIR__ . '/../resources/views/admin-options.php';
        return (string) ob_get_clean();
    },
    'POST /admin/options' => function (array $req) use ($resolvePortalActor): string {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $basePath = (string) ($req['_base_path'] ?? '');
        $target = $basePath . '/admin/options';
        $actor = $resolvePortalActor($req);
        if ($actor === null || empty($actor['isPortalWideAdmin'])) {
            $_SESSION['options_flash'] = 'Only a portal-wide admin can manage options.';
            header('Location: ' . $target . '?notice=error', true, 302);
            return '';
        }
        $svc = \App\Providers\PortalServiceProvider::makeOptionAdminService();
        $action = (string) ($req['action'] ?? '');
        $listId = (string) ($req['list'] ?? '');
        $optId = (int) ($req['option_id'] ?? 0);
        try {
            if ($action === 'add') {
                $svc->add($listId, (string) ($req['name'] ?? ''));
            } elseif ($action === 'rename') {
                $svc->rename($listId, $optId, (string) ($req['name'] ?? ''));
            } elseif ($action === 'move') {
                $svc->move($listId, $optId, (string) ($req['dir'] ?? ''));
            } elseif ($action === 'delete') {
                $svc->delete($listId, $optId);
            }
            header('Location: ' . $target . '?notice=saved', true, 302);
        } catch (\Throwable $e) {
            $_SESSION['options_flash'] = $e->getMessage();
            header('Location: ' . $target . '?notice=error', true, 302);
        }
        return '';
    },

    'GET /admin/me'         => fn (array $req) => _adminSectionRender($req, 'admin-me.php',         $resolvePortalActor, $resolveCampusSelector),
    'GET /admin/links' => function (array $req): string {
        header('Location: ' . ((string) ($req['_base_path'] ?? '')) . '/admin/roadmap', true, 302);

        return '';
    },

    // Theme + chrome (header/footer) get the live config preloaded so the
    // editors can render without an extra round-trip.
    'GET /admin/theme' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $themeConfig = \App\Providers\PortalServiceProvider::makeThemeSettingsService()->load();
        ob_start();
        require __DIR__ . '/../resources/views/admin-theme.php';
        return (string) ob_get_clean();
    },

    'GET /admin/header' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $chromeConfig = \App\Providers\PortalServiceProvider::makeChromeSettingsService()->load();
        ob_start();
        require __DIR__ . '/../resources/views/admin-header.php';
        return (string) ob_get_clean();
    },

    'GET /admin/footer' => function (array $req) use ($resolvePortalActor, $resolveCampusSelector): string {
        $basePath = (string) ($req['_base_path'] ?? '');
        $actor = $resolvePortalActor($req);
        $campusSelector = $resolveCampusSelector($req);
        $chromeConfig = \App\Providers\PortalServiceProvider::makeChromeSettingsService()->load();
        ob_start();
        require __DIR__ . '/../resources/views/admin-footer.php';
        return (string) ob_get_clean();
    },

    'GET /availability' => function (array $req): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = null;
        try {
            $reqctx = \App\Providers\PortalServiceProvider::makeRequestContext();
            $resolved = $reqctx->fromArray($req);
            $actor = [
                'actorId' => $resolved->actorId,
                'personId' => $resolved->personId,
                'displayName' => $resolved->displayName,
            ];
        } catch (\App\Exceptions\PermissionDenied) {
            $actor = null;
        }
        ob_start();
        require __DIR__ . '/../resources/views/availability.php';
        return (string) ob_get_clean();
    },

    'GET /account' => function (array $req) use ($resolvePortalActor, $resolveAllMinistries, $resolveCampusSelector): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = $resolvePortalActor($req);
        /** @var array<int, array<string, mixed>> $availableMinistries used by the template */
        $availableMinistries = $resolveAllMinistries($req);
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = $resolveCampusSelector($req);

        // The church record behind the login. A portal account need not be
        // linked to a person at all (service logins, an admin created before
        // the directory import), so every one of these stays empty rather than
        // failing the page.
        /** @var int $accountPersonId used by the template */
        $accountPersonId = (int) ($actor['personId'] ?? 0);
        /** @var ?array<string, mixed> $profile used by the template */
        $profile = null;
        /** @var list<array<string, mixed>> $profileMinistries used by the template */
        $profileMinistries = [];
        /** @var list<array<string, mixed>> $profileCampuses used by the template */
        $profileCampuses = [];
        if ($accountPersonId > 0) {
            try {
                $psvc = \App\Providers\PortalServiceProvider::makePersonAdminService();
                $profile = $psvc->viewData($accountPersonId);
                $profileMinistries = $psvc->ministriesFor($accountPersonId);
                $profileCampuses = $psvc->campuses();
            } catch (\Throwable) {
                $profile = null;
            }
        }
        ob_start();
        require __DIR__ . '/../resources/views/account.php';
        return (string) ob_get_clean();
    },

    /**
     * Self-service profile save.
     *
     * The person written is taken from the session actor and never from the
     * request: there is no id parameter to tamper with, so this endpoint cannot
     * be pointed at somebody else's record no matter what is posted. Field
     * scope is enforced again in saveOwnProfile().
     */
    'POST /account/profile' => function (array $req) use ($resolvePortalActor): string {
        header('Content-Type: application/json');
        $actor = $resolvePortalActor($req);
        $personId = (int) ($actor['personId'] ?? 0);
        if ($actor === null || $personId <= 0) {
            http_response_code(403);
            return (string) json_encode([
                'success' => false,
                'error' => 'This login is not linked to a person record yet. Ask an administrator to link it.',
            ]);
        }
        try {
            \App\Providers\PortalServiceProvider::makePersonAdminService()
                ->saveOwnProfile($personId, $req, (int) ($actor['actorId'] ?? 0));
            return (string) json_encode(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            return (string) json_encode(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable) {
            http_response_code(500);
            return (string) json_encode(['success' => false, 'error' => 'Your profile could not be saved.']);
        }
    },

    'GET /' => function (array $req) use ($resolveCampusSelector): string {
        /** @var string $basePath used by the template */
        $basePath = (string) ($req['_base_path'] ?? '');
        // Home is the portal entry for everyone: the dashboard when signed in,
        // sign-in and the open portal functions when not. A missing session
        // store or auth fault must not 500 it; the visitor is treated as signed
        // out, which offers only what anyone may use.
        $ctx = null;
        try {
            $ctx = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
        } catch (\Throwable) {
            // A campus parameter outside this person's scope must not make a
            // signed-in person look signed out; drop it and resolve again.
            try {
                $ctx = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray(
                    array_diff_key($req, ['current_campus_id' => true, 'current_campus_ids' => true]),
                );
            } catch (\Throwable) {
                $ctx = null;
            }
        }
        // The campus the top bar shows (cookie or parameter), when this person
        // may see it, so Home's events agree with the selector.
        $homeCampusId = _campusContext($req);
        if ($ctx !== null && $homeCampusId !== null && $ctx->canAccessCampus($homeCampusId)) {
            $ctx = $ctx->withCurrentCampusId($homeCampusId);
        }
        /** @var ?array<string, mixed> $actor used by the template */
        $actor = \App\Services\HomePageService::actorArray($ctx);
        /** @var array<string, mixed> $home used by the template */
        $home = \App\Services\HomePageService::skeleton($basePath, $actor);
        try {
            $home = \App\Providers\PortalServiceProvider::makeHomePageService()->build($ctx, $basePath);
        } catch (\Throwable) {
            // Keep the skeleton: the workspace shortcuts still work.
        }
        /** @var array<string, mixed> $campusSelector used by the template */
        $campusSelector = ['campuses' => [], 'defaultCampusId' => null];
        if ($ctx !== null) {
            try {
                $campusSelector = $resolveCampusSelector($req);
            } catch (\Throwable) {
                $campusSelector = ['campuses' => [], 'defaultCampusId' => null];
            }
        }
        ob_start();
        require __DIR__ . '/../resources/views/index.php';
        return (string) ob_get_clean();
    },
];

$webRoutes['GET /admin/people/import'] = static function (array $req): string {
    $basePath = (string) ($req['_base_path'] ?? '');
    $qs = [];
    foreach (['batch', 'notice', 'status', 'campus_id'] as $k) {
        if (isset($req[$k]) && (string) $req[$k] !== '') {
            $qs[$k] = $req[$k];
        }
    }
    $tail = $qs !== [] ? '?' . http_build_query($qs) : '';
    header('Location: ' . $basePath . '/admin/maintenance/import' . $tail, true, 302);
    return '';
};
foreach ([
    'POST /admin/people/import/ingest' => 'POST /admin/maintenance/import/ingest',
    'POST /admin/people/import/row' => 'POST /admin/maintenance/import/row',
    'POST /admin/people/import/apply' => 'POST /admin/maintenance/import/apply',
    'POST /admin/people/import/discard' => 'POST /admin/maintenance/import/discard',
] as $old => $new) {
    if (isset($webRoutes[$new])) {
        $webRoutes[$old] = $webRoutes[$new];
    }
}

$webRoutes['POST /admin/maintenance/backup'] = function (array $req) use ($resolvePortalActor): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $basePath = (string) ($req['_base_path'] ?? '');
    $actor = $resolvePortalActor($req);
    if ($actor === null || empty($actor['isPortalWideAdmin'])) {
        $_SESSION['maintenance_flash'] = 'Only a portal-wide admin can run backups.';
        header('Location: ' . $basePath . '/admin/maintenance?notice=error', true, 302);
        return '';
    }
    @set_time_limit(180);
    $kind = (string) ($req['kind'] ?? '');
    $target = (string) ($req['target'] ?? '');
    $svc = \App\Providers\PortalServiceProvider::makeMaintenanceBackupService();
    try {
        $members = \App\Core\Database\MembersConnection::get();
        $saved = [];
        if ($kind === 'state') {
            foreach ($svc->backupStates($members) as $file) {
                $saved[] = $file['relative'];
            }
        } else {
            if ($target === 'members' || $target === 'all') {
                $name = \App\Core\Config\EnvLoader::get('MEMBERS_DB_DATABASE', 'members');
                $saved[] = $svc->backupMysql($members, 'members', (string) $name)['relative'];
            }
            if ($target === 'visitors' || $target === 'all') {
                $saved[] = $svc->backupVisitors(\App\Core\Database\VisitorsConnection::get())['relative'];
            }
            if ($saved === []) {
                throw new \InvalidArgumentException('Choose what to back up.');
            }
        }
        $_SESSION['maintenance_flash'] = 'Saved: ' . implode(', ', $saved);
        header('Location: ' . $basePath . '/admin/maintenance?notice=ok', true, 302);
    } catch (\Throwable $e) {
        $_SESSION['maintenance_flash'] = $e->getMessage();
        header('Location: ' . $basePath . '/admin/maintenance?notice=error', true, 302);
    }
    return '';
};

$webRoutes['POST /admin/maintenance/export-xlsx'] = function (array $req) use ($resolvePortalActor): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $basePath = (string) ($req['_base_path'] ?? '');
    $actor = $resolvePortalActor($req);
    // The workbook export lives on People & Records › Import & export; a failure
    // goes back to whichever page asked for it.
    $fromImport = (string) ($req['return'] ?? '') === 'import';
    $failTo = $basePath . ($fromImport ? '/admin/maintenance/import' : '/admin/maintenance') . '?notice=error';
    $flashKey = $fromImport ? 'people_flash' : 'maintenance_flash';
    if ($actor === null || empty($actor['isPortalWideAdmin'])) {
        $_SESSION[$flashKey] = 'Only a portal-wide admin can export members.';
        header('Location: ' . $failTo, true, 302);
        return '';
    }
    try {
        $people = \App\Providers\PortalServiceProvider::makePersonAdminService();
        $import = \App\Providers\PortalServiceProvider::makeMemberCampusImportService();
        $campusId = (int) ($req['campus_id'] ?? 0);
        $sheets = [];
        foreach ($people->campuses() as $c) {
            if ($campusId > 0 && (int) $c['id'] !== $campusId) {
                continue;
            }
            // The Ministry column in the form the import reads back:
            // "Guest Services (Usher, Emcee), Psalmists".
            $cells = $import->ministryCellsForCampus((int) $c['id']);
            $rows = $people->exportCampus((int) $c['id']);
            foreach ($rows as &$row) {
                $row['ministry'] = $cells[(int) $row['id']] ?? '';
            }
            unset($row);
            $sheets[] = [
                'campus' => (string) $c['name'],
                'rows' => $rows,
            ];
        }
        if ($sheets === []) {
            throw new \InvalidArgumentException('No campus members were found to export.');
        }
        $slot = \App\Providers\PortalServiceProvider::makeMaintenanceBackupService()->exportMemberWorkbook($sheets);
        $_SESSION['maintenance_flash'] = 'Saved styled workbook ' . $slot['relative'] . '.';
        header('Location: ' . $basePath . '/admin/maintenance/file?path=' . rawurlencode($slot['relative']), true, 302);
    } catch (\Throwable $e) {
        $_SESSION[$flashKey] = $e->getMessage();
        header('Location: ' . $failTo, true, 302);
    }
    return '';
};

$webRoutes['GET /admin/maintenance/file'] = function (array $req) use ($resolvePortalActor): string {
    $actor = $resolvePortalActor($req);
    if ($actor === null || empty($actor['isPortalWideAdmin'])) {
        http_response_code(403);
        return 'Not authorized.';
    }
    try {
        $path = \App\Providers\PortalServiceProvider::makeMaintenanceBackupService()
            ->store()
            ->absolute((string) ($req['path'] ?? ''));
        $name = basename($path);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('Cache-Control: private, no-store');
        readfile($path);
        return '';
    } catch (\Throwable $e) {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $_SESSION['maintenance_flash'] = $e->getMessage();
        header('Location: ' . ($req['_base_path'] ?? '') . '/admin/maintenance?notice=error', true, 302);
        return '';
    }
};

return $webRoutes;
