<?php

declare(strict_types=1);

/**
 * Visitors & RSVPs: the matcher the workspace shares with the sign-up and RSVP
 * modules, and VisitorService's rules (promotion, review, attendance, access
 * codes, authorization). No database: repositories are in-memory fakes.
 */

// The service logs a failed write; keep the test output to the checks.
ini_set('error_log', '/dev/null');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use App\Contracts\VisitorMemberRepository;
use App\Contracts\VisitorPersonCreator;
use App\Contracts\VisitorRepository;
use App\Core\ActorContext;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Services\VisitorService;
use App\Services\Visitors\VisitorMatcher;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : " ({$detail})");
}
/** @return ?Throwable */
function thrown(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

// ---- Matcher ----------------------------------------------------------------
echo "Matcher\n";

$parent = VisitorMatcher::person('Maria', 'Santos', 4, 1980, ['santos.family@example.com'], ['416-555-0100']);
$level = static fn (array $p): string => VisitorMatcher::level(VisitorMatcher::components($p, $parent));
check('same person by name and email is exact', $level(VisitorMatcher::person('Maria', 'Santos', 0, 0, ['Santos.Family@example.com '], [])) === 'exact');
check('same person by name and phone, however written, is exact', $level(VisitorMatcher::person('maria', 'SANTOS', 0, 0, [], ['(416) 555-0100'])) === 'exact');
check('same person by name and birth month/year is exact', $level(VisitorMatcher::person('Maria', 'Santos', 4, 1980, [], [])) === 'exact');
check('a child on the family email is only possible', $level(VisitorMatcher::person('Lito', 'Santos', 9, 2015, ['santos.family@example.com'], [])) === 'possible');
check('a child on the family phone is only possible', $level(VisitorMatcher::person('Lito', 'Santos', 0, 0, [], ['416-555-0100'])) === 'possible');
check('a relative born the same month and year is only possible', $level(VisitorMatcher::person('Nena', 'Santos', 4, 1980, [], [])) === 'possible');
check('a stranger on a shared email (different surname) is only possible', $level(VisitorMatcher::person('Ana', 'Cruz', 0, 0, ['santos.family@example.com'], [])) === 'possible');
check('an unrelated person is no match', $level(VisitorMatcher::person('Ana', 'Cruz', 0, 0, ['ana@example.com'], [])) === 'none');
check('placeholders never match each other',
    VisitorMatcher::components(VisitorMatcher::person('A', 'B', 0, 0, ['n/a'], ['000']), VisitorMatcher::person('C', 'D', 0, 0, ['N/A'], ['000'])) === []);
check('a first name matches its longer form', VisitorMatcher::firstNamesAgree('vince', 'vince cedric'));
check('a phone key keeps the last ten digits', VisitorMatcher::phoneKey('+1 (416) 555-0100') === '4165550100');
check('a short number is not a phone', VisitorMatcher::phoneKey('555-01') === '');

// The module's own classification, so the two cannot drift apart.
require_once __DIR__ . '/../../people_signup/includes/helpers.php';
$cases = [
    [['Maria', 'Santos', 0, 0, ['santos.family@example.com'], []]],
    [['Lito', 'Santos', 9, 2015, ['santos.family@example.com'], []]],
    [['Nena', 'Santos', 4, 1980, [], []]],
    [['Maria', 'Santos', 0, 0, [], ['(416) 555-0100']]],
    [['Vince Cedric', 'Santos', 0, 0, ['x@example.com'], ['416-555-0100']]],
    [['Ana', 'Cruz', 0, 0, ['ana@example.com'], []]],
];
$agree = true;
$moduleParent = sg_person('Maria', 'Santos', 4, 1980, ['santos.family@example.com'], ['416-555-0100']);
foreach ($cases as [$args]) {
    $mine = VisitorMatcher::level(VisitorMatcher::components(VisitorMatcher::person(...$args), $parent));
    $theirs = sg_match_level(sg_components(sg_person(...$args), $moduleParent));
    $agree = $agree && $mine === $theirs;
}
check('the workspace and the sign-up module classify alike', $agree);

$classified = VisitorMatcher::classify(
    VisitorMatcher::person('Lito', 'Santos', 9, 2015, ['santos.family@example.com'], []),
    [
        ['id' => 1, 'first_name' => 'Maria', 'last_name' => 'Santos', 'birth_month' => 4, 'birth_year' => 1980, 'emails' => ['santos.family@example.com'], 'phones' => []],
        ['id' => 2, 'first_name' => 'Lito', 'last_name' => 'Santos', 'birth_month' => 9, 'birth_year' => 2015, 'emails' => [''], 'phones' => []],
        ['id' => 3, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'birth_month' => 0, 'birth_year' => 0, 'emails' => [], 'phones' => []],
    ],
);
check('classify: the child himself is exact, the parent on the family email is possible, a stranger is left out',
    array_column($classified['exact'], 'id') === [2] && array_column($classified['possible'], 'id') === [1]);

// ---- Fakes ------------------------------------------------------------------

final class FakeVisitors implements VisitorRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $registrations = [];
    /** @var list<array<string,mixed>> */
    public array $promotions = [];
    /** @var array<int,array<string,mixed>> */
    public array $rsvps = [];
    /** @var list<array<string,mixed>> */
    public array $codes = [];
    public bool $failPromotion = false;

    public function countRegistrationsByStatus(): array
    {
        $out = array_fill_keys(VisitorService::STATUSES, 0);
        foreach ($this->registrations as $r) {
            $out[$r['status']]++;
        }
        return $out;
    }
    public function listRegistrations(?string $status, string $search, int $limit, int $offset): array
    {
        return array_slice(array_values(array_filter($this->registrations, static fn ($r) => $status === null || $r['status'] === $status)), $offset, $limit);
    }
    public function countRegistrations(?string $status, string $search): int
    {
        return count($this->listRegistrations($status, $search, PHP_INT_MAX, 0));
    }
    public function findRegistration(int $registrationId): ?array { return $this->registrations[$registrationId] ?? null; }
    public function registrationCandidates(string $lastName, string $email, int $excludeId): array { return []; }
    public function setRegistrationStatus(int $registrationId, string $status, string $now): void
    {
        $this->registrations[$registrationId]['status'] = $status;
        if ($status !== 'new') {
            $this->registrations[$registrationId]['reviewed_at'] = $now;
        }
    }
    public function setReviewerNotes(int $registrationId, ?string $notes, string $now): void { $this->registrations[$registrationId]['reviewer_notes'] = $notes; }
    public function recordPromotion(int $registrationId, int $personId, string $outcome, ?int $accountId, ?string $notes, string $now): void
    {
        if ($this->failPromotion) {
            throw new RuntimeException('disk I/O error');
        }
        $this->registrations[$registrationId]['status'] = 'promoted';
        $this->registrations[$registrationId]['matched_person_id'] = $personId;
        $this->promotions[] = compact('registrationId', 'personId', 'outcome', 'accountId', 'notes', 'now');
    }
    public function promotionsFor(int $registrationId): array { return array_values(array_filter($this->promotions, static fn ($p) => $p['registrationId'] === $registrationId)); }
    public function rsvpsForRegistration(int $registrationId): array { return []; }
    public function rsvpOccasions(): array { return [['event_id' => 10, 'occurrence_id' => 242, 'responses' => count($this->rsvps), 'last_response_at' => '2026-09-16 10:00:00']]; }
    public function rsvpsFor(int $eventId, ?int $occurrenceId): array { return array_values($this->rsvps); }
    public function findRsvp(int $rsvpId): ?array { return $this->rsvps[$rsvpId] ?? null; }
    public function setRsvpAttendance(int $rsvpId, string $attendance, string $now): void
    {
        $this->rsvps[$rsvpId]['attendance'] = $attendance;
        $this->rsvps[$rsvpId]['updated_at'] = $now;
    }
    public function latestAccessCode(string $module): ?array
    {
        $mine = array_values(array_filter($this->codes, static fn ($c) => $c['module'] === $module));
        return $mine === [] ? null : end($mine);
    }
    public function insertAccessCode(string $module, string $code, string $issuedAt, string $expiresAt, ?string $note): void
    {
        $this->codes[] = ['module' => $module, 'code' => $code, 'issued_at' => $issuedAt, 'expires_at' => $expiresAt, 'note' => $note];
    }
}

final class FakeMembers implements VisitorMemberRepository
{
    /** @var list<array<string,mixed>> */
    public array $people = [];
    /** @var list<array<string,mixed>> */
    public array $completed = [];
    public int $transactions = 0;

    public function personCandidates(string $lastName, string $email): array
    {
        return array_values(array_filter($this->people, static fn ($p) =>
            ($lastName !== '' && strtolower($p['last_name']) === $lastName) || ($email !== '' && in_array($email, $p['emails'], true))));
    }
    public function peopleByIds(array $personIds): array
    {
        $out = [];
        foreach ($this->people as $p) {
            if (in_array($p['id'], $personIds, true)) {
                $out[$p['id']] = ['id' => $p['id'], 'name' => $p['first_name'] . ' ' . $p['last_name'], 'city' => ''];
            }
        }
        return $out;
    }
    public function membershipStatuses(): array { return [['id' => 1, 'name' => 'Member'], ['id' => 4, 'name' => 'Guest']]; }
    public function campuses(): array { return [['id' => 2, 'name' => 'North York']]; }
    public function memberTypeIdByName(string $name): ?int { return $name === 'Trailblazer' ? 2 : null; }
    public function eventsByIds(array $eventIds): array
    {
        return in_array(10, $eventIds, true) ? [10 => ['id' => 10, 'title' => 'Welcome lunch', 'starts_on' => '2026-01-01', 'start_time' => null, 'is_active' => true]] : [];
    }
    public function occurrencesByIds(array $occurrenceIds): array
    {
        return in_array(242, $occurrenceIds, true) ? [242 => ['id' => 242, 'event_id' => 10, 'starts_at' => '2026-09-20 12:30:00', 'status' => 'scheduled', 'title' => null]] : [];
    }
    public function upcomingEvents(string $fromDate, string $toDate): array { return []; }
    public function transaction(callable $work): mixed
    {
        $this->transactions++;
        return $work();
    }
    public function completePromotedPerson(int $personId, ?int $birthMonth, ?int $birthDay, ?float $latitude, ?float $longitude): void
    {
        $this->completed[] = compact('personId', 'birthMonth', 'birthDay', 'latitude', 'longitude');
    }
}

final class FakeCreator implements VisitorPersonCreator
{
    /** @var list<array{fields:array<string,mixed>,account:int}> */
    public array $created = [];
    public int $nextId = 5000;
    public function create(array $fields, int $accountId): int
    {
        $this->created[] = ['fields' => $fields, 'account' => $accountId];
        return $this->nextId++;
    }
}

function registration(int $id, array $overrides = []): array
{
    return $overrides + [
        'id' => $id, 'first_name' => 'Lito', 'middle_name' => null, 'last_name' => 'Santos', 'preferred_name' => '',
        'gender' => 'male', 'email' => 'santos.family@example.com', 'phone' => '', 'birth_year' => 2015, 'birth_month' => 9, 'birth_day' => null,
        'address_line1' => null, 'address_line2' => null, 'city' => 'Toronto', 'region' => null, 'postal_code' => null, 'country' => null,
        'latitude' => null, 'longitude' => null, 'member_type_name' => null, 'source' => 'rsvp', 'source_event_id' => 10,
        'status' => 'new', 'matched_person_id' => null, 'reviewer_notes' => null, 'reviewed_at' => null, 'created_at' => '2026-09-16 10:00:00',
    ];
}

/** @return array{0:VisitorService,1:FakeVisitors,2:FakeMembers,3:FakeCreator} */
function world(string $now = '2026-09-17 12:00:00'): array
{
    $visitors = new FakeVisitors();
    $members = new FakeMembers();
    $members->people = [
        ['id' => 800, 'first_name' => 'Maria', 'last_name' => 'Santos', 'birth_month' => 4, 'birth_year' => 1980, 'city' => '', 'emails' => ['santos.family@example.com'], 'phones' => []],
        ['id' => 801, 'first_name' => 'Doreen', 'last_name' => 'Galicia', 'birth_month' => 10, 'birth_year' => 1992, 'city' => '', 'emails' => ['doreen@example.com'], 'phones' => []],
    ];
    $creator = new FakeCreator();
    $clock = static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('America/Toronto'));
    $service = new VisitorService($visitors, $members, $creator, ['signup' => 'ChristLikeness', 'rsvp' => 'ChristLikeness'], $clock);

    return [$service, $visitors, $members, $creator];
}

$admin = new ActorContext(actorId: 16, personId: null, displayName: 'Admin', permissions: [], ministryScopeIds: [], isPortalWideAdmin: true);
$leader = new ActorContext(actorId: 18, personId: 5, displayName: 'Leader', permissions: [], ministryScopeIds: [4], isPortalWideAdmin: false);

// ---- Promotion ----------------------------------------------------------------
echo "\nPromotion\n";

[$svc, $visitors, $members, $creator] = world();
$visitors->registrations[1] = registration(1);   // a child on the family email: possible match to Maria only
$result = $svc->promote($admin, 1, ['mode' => 'create', 'membership_status_id' => 4, 'campus_id' => 2]);
$fields = $creator->created[0]['fields'] ?? [];
check('promote new: a person is created through the member editor', $result === ['person_id' => 5000, 'outcome' => 'created'] && count($creator->created) === 1);
check('with the chosen membership status and campus', ($fields['membership_status_id'] ?? null) === 4 && ($fields['campus_id'] ?? null) === 2);
check('and the registration as the editor expects it (phone → mobile, blank → null)',
    $fields['first_name'] === 'Lito' && $fields['mobile_phone'] === null && $fields['preferred_name'] === null && $fields['gender'] === 'male');
check('a birth month without a day is kept, outside the editor, in the same transaction',
    $fields['birth_month'] === null && $members->completed === [['personId' => 5000, 'birthMonth' => 9, 'birthDay' => null, 'latitude' => null, 'longitude' => null]]
    && $members->transactions === 1);
check('the promotion is recorded with the signed-in account', ($visitors->promotions[0] ?? [])['accountId'] === 16
    && $visitors->promotions[0]['outcome'] === 'created' && $visitors->promotions[0]['notes'] === null
    && $visitors->registrations[1]['status'] === 'promoted' && $visitors->registrations[1]['matched_person_id'] === 5000);

$e = thrown(fn () => $svc->promote($admin, 1, ['mode' => 'create']));
check('refuse promoted twice', $e instanceof ValidationFailed && str_contains($e->getMessage(), 'Already promoted') && count($creator->created) === 1);
$e = thrown(fn () => $svc->promote($admin, 1, ['mode' => 'link', 'person_id' => 800]));
check('refuse linking an already promoted registration too', $e instanceof ValidationFailed && count($visitors->promotions) === 1);
$e = thrown(fn () => $svc->setStatus($admin, 1, 'new'));
check('a promoted registration keeps its status', $e instanceof ValidationFailed && $visitors->registrations[1]['status'] === 'promoted');

[$svc, $visitors, $members, $creator] = world();
$visitors->registrations[2] = registration(2);
$e = thrown(fn () => $svc->promote($admin, 2, ['mode' => 'link', 'person_id' => 801]));
check('link existing: refused to someone it does not match', $e instanceof ValidationFailed && $visitors->promotions === []);
$result = $svc->promote($admin, 2, ['mode' => 'link', 'person_id' => 800]);
check('link existing: allowed to a possible match, and creates no one',
    $result === ['person_id' => 800, 'outcome' => 'matched_existing'] && $creator->created === [] && $visitors->registrations[2]['matched_person_id'] === 800);

[$svc, $visitors, $members, $creator] = world();
$visitors->registrations[3] = registration(3, ['first_name' => 'Doreen', 'last_name' => 'Galicia', 'email' => 'doreen@example.com', 'birth_month' => 10, 'birth_year' => 1992, 'birth_day' => 3, 'latitude' => 43.7, 'longitude' => -79.4, 'member_type_name' => 'Trailblazer', 'phone' => 'not-an-email', 'email' => 'doreen@example.com']);
$e = thrown(fn () => $svc->promote($admin, 3, ['mode' => 'create']));
check('create is refused while it exactly matches a member', $e instanceof ValidationFailed && str_contains($e->getMessage(), '#801') && $creator->created === []);
$result = $svc->promote($admin, 3, ['mode' => 'create', 'force' => true, 'membership_status_id' => 99, 'campus_id' => 99]);
$fields = $creator->created[0]['fields'];
check('unless confirmed, and then the promotion notes the match', $result['outcome'] === 'created' && $visitors->promotions[0]['notes'] === 'Created although it matched member #801');
check('an unknown status falls back to the first; an unknown campus to none', $fields['membership_status_id'] === 1 && $fields['campus_id'] === 0);
check('member type is resolved by name; a full birthday goes through the editor; coordinates are completed',
    $fields['member_type_id'] === 2 && $fields['birth_month'] === 10 && $fields['birth_day'] === 3
    && ($members->completed[0]['latitude'] ?? null) === 43.7);

[$svc, $visitors, $members, $creator] = world();
$visitors->registrations[4] = registration(4, ['status' => 'duplicate']);
$visitors->registrations[5] = registration(5, ['status' => 'rejected']);
$visitors->registrations[6] = registration(6, ['last_name' => '  ', 'email' => 'someone@example.com']);
check('refuse promoting a duplicate', thrown(fn () => $svc->promote($admin, 4, ['mode' => 'link', 'person_id' => 800])) instanceof ValidationFailed);
check('refuse promoting a rejected registration', thrown(fn () => $svc->promote($admin, 5, ['mode' => 'create'])) instanceof ValidationFailed);
check('refuse creating a person without a last name', thrown(fn () => $svc->promote($admin, 6, ['mode' => 'create'])) instanceof ValidationFailed && $creator->created === []);
$svc->setStatus($admin, 4, 'reviewed');
check('a duplicate marked reviewed can then be linked',
    $svc->promote($admin, 4, ['mode' => 'link', 'person_id' => 800])['outcome'] === 'matched_existing');

[$svc, $visitors, $members, $creator] = world();
$visitors->registrations[7] = registration(7, ['first_name' => 'Joy', 'last_name' => 'Reyes', 'email' => 'joy@example.com']);
$visitors->failPromotion = true;
$e = thrown(fn () => $svc->promote($admin, 7, ['mode' => 'create']));
check('when the visitors write fails after the person was created, the error names that person',
    $e instanceof ValidationFailed && str_contains($e->getMessage(), 'Person #5000 was added') && ($e->getErrors()['person_id'] ?? null) === 5000
    && count($creator->created) === 1);

// ---- Review -----------------------------------------------------------------
echo "\nReview\n";
[$svc, $visitors] = world();
$visitors->registrations[1] = registration(1);
$svc->setStatus($admin, 1, 'rejected');
check('reject stamps the review time', $visitors->registrations[1]['status'] === 'rejected' && $visitors->registrations[1]['reviewed_at'] === '2026-09-17 12:00:00');
check('promoted is not a review decision', thrown(fn () => $svc->setStatus($admin, 1, 'promoted')) instanceof ValidationFailed);
$svc->saveNotes($admin, 1, "  Called Tuesday.  ");
check('reviewer notes are trimmed', $visitors->registrations[1]['reviewer_notes'] === 'Called Tuesday.');
$svc->saveNotes($admin, 1, '   ');
check('blank notes are cleared', $visitors->registrations[1]['reviewer_notes'] === null);
$q = $svc->queue($admin, 'rejected');
check('the queue filters by status and counts every status', count($q['rows']) === 1 && $q['counts']['rejected'] === 1 && $q['counts']['new'] === 0);
check('an unknown status filter shows everything', $svc->queue($admin, 'bogus')['status'] === null);

// ---- RSVPs ------------------------------------------------------------------
echo "\nRSVPs\n";
[$svc, $visitors, $members] = world();
$visitors->rsvps = [
    1 => ['id' => 1, 'event_id' => 10, 'occurrence_id' => 242, 'person_id' => 800, 'visitor_registration_id' => null, 'first_name' => 'Maria', 'last_name' => 'Santos', 'response' => 'yes', 'party_size' => 3, 'attendance' => 'registered'],
    2 => ['id' => 2, 'event_id' => 10, 'occurrence_id' => 242, 'person_id' => null, 'visitor_registration_id' => 7, 'first_name' => 'Joy', 'last_name' => 'Reyes', 'response' => 'maybe', 'party_size' => 2, 'attendance' => 'registered'],
    3 => ['id' => 3, 'event_id' => 10, 'occurrence_id' => 242, 'person_id' => null, 'visitor_registration_id' => 8, 'first_name' => 'Ben', 'last_name' => 'Ong', 'response' => 'no', 'party_size' => 1, 'attendance' => 'registered'],
];
$updated = $svc->setAttendance($admin, 1, 'checked_in');
check('RSVP attendance update is saved and returned', $updated['attendance'] === 'checked_in' && $visitors->rsvps[1]['attendance'] === 'checked_in' && $visitors->rsvps[1]['updated_at'] === '2026-09-17 12:00:00');
check('an unknown attendance mark is refused', thrown(fn () => $svc->setAttendance($admin, 1, 'maybe')) instanceof ValidationFailed && $visitors->rsvps[1]['attendance'] === 'checked_in');
check('a missing RSVP is refused', thrown(fn () => $svc->setAttendance($admin, 99, 'no_show')) instanceof ValidationFailed);
$overview = $svc->rsvps($admin, '10:242');
check('the event date is labelled from its occurrence and counted as upcoming',
    $overview['selected']['title'] === 'Welcome lunch' && $overview['selected']['date'] === '2026-09-20' && $overview['selected']['upcoming'] === true);
check('totals count parties, members and visitors',
    $overview['totals']['yes'] === 1 && $overview['totals']['maybe'] === 1 && $overview['totals']['no'] === 1
    && $overview['totals']['expected'] === 5 && $overview['totals']['checked_in'] === 3
    && $overview['totals']['members'] === 1 && $overview['totals']['visitors'] === 2);

// ---- Access codes -----------------------------------------------------------
echo "\nAccess codes\n";
[$svc, $visitors] = world();
check('no code yet reads as none', $svc->accessCodes($admin) === ['signup' => null, 'rsvp' => null]);
$code = $svc->issueAccessCode($admin, 'signup', 'Grace 2026!', 400, '  weekend team ');
check('the word keeps letters and numbers; days are capped at 365',
    $code['word'] === 'Grace2026' && $code['code'] === 'ChristLikenessGrace2026' && $code['expires_at'] === '2027-09-17 12:00:00'
    && $code['active'] === true && $code['remaining_days'] === 365 && $code['note'] === 'weekend team');
$code = $svc->issueAccessCode($admin, 'rsvp', '!!!', 0);
check('an empty word is generated, and at least one day is given',
    preg_match('/^[A-Z][a-z]+\d{3}$/', $code['word']) === 1 && $code['expires_at'] === '2026-09-18 12:00:00' && $visitors->codes[1]['note'] === null);
check('an unknown module is refused', thrown(fn () => $svc->issueAccessCode($admin, 'members', 'x', 7)) instanceof ValidationFailed);
$later = new VisitorService($visitors, new FakeMembers(), new FakeCreator(), ['signup' => 'ChristLikeness', 'rsvp' => 'ChristLikeness'],
    static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-19 08:00:00', new DateTimeZone('America/Toronto')));
check('a code past its expiry reads as expired', $later->accessCodes($admin)['rsvp']['active'] === false && $later->accessCodes($admin)['signup']['active'] === true);

// ---- Authorization ----------------------------------------------------------
echo "\nAuthorization\n";
[$svc, $visitors] = world();
$visitors->registrations[1] = registration(1);
$visitors->rsvps[1] = ['id' => 1, 'attendance' => 'registered'];
$calls = [
    'queue' => fn (?ActorContext $a) => $svc->queue($a, null),
    'registration' => fn (?ActorContext $a) => $svc->registration($a, 1),
    'setStatus' => fn (?ActorContext $a) => $svc->setStatus($a, 1, 'rejected'),
    'saveNotes' => fn (?ActorContext $a) => $svc->saveNotes($a, 1, 'x'),
    'promote' => fn (?ActorContext $a) => $svc->promote($a, 1, ['mode' => 'link', 'person_id' => 800]),
    'rsvps' => fn (?ActorContext $a) => $svc->rsvps($a),
    'setAttendance' => fn (?ActorContext $a) => $svc->setAttendance($a, 1, 'no_show'),
    'upcomingEvents' => fn (?ActorContext $a) => $svc->upcomingEvents($a),
    'accessCodes' => fn (?ActorContext $a) => $svc->accessCodes($a),
    'issueAccessCode' => fn (?ActorContext $a) => $svc->issueAccessCode($a, 'signup', 'Hack', 7),
];
foreach ($calls as $name => $call) {
    check("{$name} refuses a ministry leader and a signed-out caller",
        thrown(fn () => $call($leader)) instanceof PermissionDenied && thrown(fn () => $call(null)) instanceof PermissionDenied);
}
check('and nothing changed', $visitors->registrations[1]['status'] === 'new' && $visitors->promotions === []
    && $visitors->rsvps[1]['attendance'] === 'registered' && $visitors->codes === []);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
