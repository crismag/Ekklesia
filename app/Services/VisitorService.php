<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\VisitorMemberRepository;
use App\Contracts\VisitorPersonCreator;
use App\Contracts\VisitorRepository;
use App\Core\ActorContext;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Services\Visitors\VisitorMatcher;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Visitors & RSVPs: reviewing registrations, promoting them to member records,
 * RSVP attendance and the greeters' access codes.
 *
 * Portal-wide administrators only, as the Sign-ups & RSVP pages were. The rules
 * are the standalone modules' (people_signup/migrate.php, admin_save.php,
 * admin_access.php; events_rsvp/admin_attendance.php), so a greeter on a module
 * page and an administrator here make the same decisions.
 */
final class VisitorService
{
    public const STATUSES = ['new', 'reviewed', 'duplicate', 'promoted', 'rejected'];

    /** Decisions a reviewer can make directly; 'promoted' only comes from promote(). */
    public const REVIEW_DECISIONS = ['new', 'reviewed', 'duplicate', 'rejected'];

    public const ATTENDANCE = ['registered', 'checked_in', 'no_show', 'cancelled'];

    public const MODULES = ['signup', 'rsvp'];

    public const PER_PAGE = 50;

    private readonly Closure $clock;

    /**
     * @param array<string,string> $accessPrefixes module => the fixed code prefix
     *        from its config (admin_access.prefix)
     * @param ?Closure():DateTimeImmutable $clock
     */
    public function __construct(
        private readonly VisitorRepository $visitors,
        private readonly VisitorMemberRepository $members,
        private readonly VisitorPersonCreator $creator,
        private readonly array $accessPrefixes = ['signup' => 'ChristLikeness', 'rsvp' => 'ChristLikeness'],
        ?Closure $clock = null,
        // The modules keep visitor timestamps in the church's local time
        // (signup.secure.php / rsvp.secure.php 'time_zone').
        private readonly DateTimeZone $timeZone = new DateTimeZone('America/Toronto'),
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    private function authorize(?ActorContext $actor): ActorContext
    {
        if ($actor === null || !$actor->isPortalWideAdmin) {
            throw new PermissionDenied('Visitors & RSVPs is for church administrators.');
        }

        return $actor;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)()->setTimezone($this->timeZone);
    }

    private function stamp(int $plusSeconds = 0): string
    {
        return $this->now()->modify(($plusSeconds >= 0 ? '+' : '') . $plusSeconds . ' seconds')->format('Y-m-d H:i:s');
    }

    private static function blankToNull(mixed $v): mixed
    {
        return ($v === null || trim((string) $v) === '') ? null : $v;
    }

    // ---- Registrations ------------------------------------------------------

    /**
     * The review queue.
     *
     * @return array{counts:array<string,int>,rows:list<array<string,mixed>>,total:int,page:int,pages:int,status:?string,search:string}
     */
    public function queue(?ActorContext $actor, ?string $status, string $search = '', int $page = 1): array
    {
        $this->authorize($actor);
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            $status = null;
        }
        $search = mb_substr(trim($search), 0, 100);
        $total = $this->visitors->countRegistrations($status, $search);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $rows = $this->visitors->listRegistrations($status, $search, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $people = $this->members->peopleByIds(array_map(static fn (array $r): int => (int) ($r['matched_person_id'] ?? 0), $rows));
        foreach ($rows as &$row) {
            $pid = (int) ($row['matched_person_id'] ?? 0);
            $row['matched_person'] = $people[$pid] ?? null;
        }
        unset($row);

        return [
            'counts' => $this->visitors->countRegistrationsByStatus(),
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'status' => $status,
            'search' => $search,
        ];
    }

    /**
     * How many registrations are in each review state, for dashboards.
     *
     * @return array<string,int>
     */
    public function registrationCounts(?ActorContext $actor): array
    {
        $this->authorize($actor);

        return $this->visitors->countRegistrationsByStatus();
    }

    /**
     * One registration with everything a reviewer decides from.
     *
     * @return ?array<string,mixed>
     */
    public function registration(?ActorContext $actor, int $registrationId): ?array
    {
        $this->authorize($actor);
        $row = $this->visitors->findRegistration($registrationId);
        if ($row === null) {
            return null;
        }

        $matches = $this->memberMatches($row);
        $person = VisitorMatcher::fromRegistration($row);
        $duplicates = ['exact' => [], 'possible' => []];
        if (VisitorMatcher::searchable($person)) {
            $candidates = array_map(static fn (array $r): array => $r + [
                'emails' => [(string) ($r['email'] ?? '')],
                'phones' => [(string) ($r['phone'] ?? '')],
            ], $this->visitors->registrationCandidates($person['last'], $person['emails'][0] ?? '', $registrationId));
            $duplicates = VisitorMatcher::classify($person, $candidates);
        }

        $promotions = $this->visitors->promotionsFor($registrationId);
        $rsvps = $this->withOccasionLabels($this->visitors->rsvpsForRegistration($registrationId));

        $personIds = array_merge(
            [(int) ($row['matched_person_id'] ?? 0)],
            array_map(static fn (array $p): int => (int) $p['person_id'], $promotions),
        );
        $events = $this->members->eventsByIds([(int) ($row['source_event_id'] ?? 0)]);

        return [
            'registration' => $row,
            'matches' => $matches,
            'duplicates' => $duplicates,
            'promotions' => $promotions,
            'promoters' => $this->members->accountNames(array_map(static fn (array $p): int => (int) ($p['promoted_by_account_id'] ?? 0), $promotions)),
            'rsvps' => $rsvps,
            'people' => $this->members->peopleByIds($personIds),
            'source_event' => $events[(int) ($row['source_event_id'] ?? 0)] ?? null,
            'membership_statuses' => $this->members->membershipStatuses(),
            'campuses' => $this->members->campuses(),
            'promotion_block' => self::promotionBlock((string) $row['status']),
        ];
    }

    /** Why a registration in this status cannot be promoted, or null when it can. */
    public static function promotionBlock(string $status): ?string
    {
        return match ($status) {
            'promoted' => 'Already promoted to a member record.',
            'rejected' => 'Rejected registrations cannot be promoted. Mark it reviewed first if that was a mistake.',
            'duplicate' => 'Duplicate registrations cannot be promoted. Mark it reviewed first if it is someone new.',
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array{exact:list<array<string,mixed>>,possible:list<array<string,mixed>>}
     */
    private function memberMatches(array $row): array
    {
        $person = VisitorMatcher::fromRegistration($row);
        if (!VisitorMatcher::searchable($person)) {
            return ['exact' => [], 'possible' => []];
        }

        return VisitorMatcher::classify($person, $this->members->personCandidates($person['last'], $person['emails'][0] ?? ''));
    }

    /** Mark reviewed, duplicate or rejected (or back to new). A promoted registration stays promoted. */
    public function setStatus(?ActorContext $actor, int $registrationId, string $status): void
    {
        $this->authorize($actor);
        if (!in_array($status, self::REVIEW_DECISIONS, true)) {
            throw new ValidationFailed('That is not a review decision.');
        }
        $row = $this->visitors->findRegistration($registrationId);
        if ($row === null) {
            throw new ValidationFailed('That registration no longer exists.');
        }
        if ($row['status'] === 'promoted') {
            throw new ValidationFailed('This registration is already a member record; its status cannot change.');
        }
        $this->visitors->setRegistrationStatus($registrationId, $status, $this->stamp());
    }

    public function saveNotes(?ActorContext $actor, int $registrationId, string $notes): void
    {
        $this->authorize($actor);
        if ($this->visitors->findRegistration($registrationId) === null) {
            throw new ValidationFailed('That registration no longer exists.');
        }
        $notes = trim($notes);
        if (mb_strlen($notes) > 65535) {
            throw new ValidationFailed('Reviewer notes are too long.');
        }
        $this->visitors->setReviewerNotes($registrationId, $notes === '' ? null : $notes, $this->stamp());
    }

    /**
     * Promote a registration to a member record: create a new person, or link
     * it to a person it matches.
     *
     * Rules (people_signup/migrate.php):
     *  - promoted, rejected and duplicate registrations are refused;
     *  - linking is only to a person the registration matches (exact or possible);
     *  - creating is refused while it exactly matches someone, unless forced,
     *    and a forced creation notes which members it matched;
     *  - a last name is required;
     *  - the member row is written first, then the visitors rows. They cannot
     *    share a transaction; if the second write fails the person still
     *    exists, and the error says which person to link it to.
     *
     * @param array{mode?:string,person_id?:int|string,membership_status_id?:int|string,campus_id?:int|string,force?:bool|string} $input
     * @return array{person_id:int,outcome:string}
     */
    public function promote(?ActorContext $actor, int $registrationId, array $input): array
    {
        $actor = $this->authorize($actor);
        $row = $this->visitors->findRegistration($registrationId);
        if ($row === null) {
            throw new ValidationFailed('That registration no longer exists.');
        }
        $block = self::promotionBlock((string) $row['status']);
        if ($block !== null) {
            throw new ValidationFailed($block);
        }

        $mode = (string) ($input['mode'] ?? 'create');
        $force = in_array($input['force'] ?? false, [true, '1', 1, 'on'], true);
        $matches = $this->memberMatches($row);
        $exactIds = array_map(static fn (array $c): int => (int) $c['id'], $matches['exact']);
        $matchIds = array_merge($exactIds, array_map(static fn (array $c): int => (int) $c['id'], $matches['possible']));
        $notes = null;

        if ($mode === 'link') {
            $personId = (int) ($input['person_id'] ?? 0);
            if ($personId <= 0 || !in_array($personId, $matchIds, true)) {
                throw new ValidationFailed('A registration can only be linked to a member it matches.');
            }
            $outcome = 'matched_existing';
        } elseif ($mode === 'create') {
            if ($exactIds !== [] && !$force) {
                throw new ValidationFailed(
                    'This matches existing member #' . $exactIds[0] . '. Link it to that person, or confirm that this is someone new.',
                    ['person_id' => $exactIds[0]],
                );
            }
            if ($exactIds !== []) {
                $notes = 'Created although it matched member #' . implode(', #', $exactIds);
            }
            $personId = $this->createPerson($row, $input, $actor->actorId);
            $outcome = 'created';
        } else {
            throw new ValidationFailed('Choose whether to create a new person or link an existing one.');
        }

        try {
            $this->visitors->recordPromotion($registrationId, $personId, $outcome, $actor->actorId > 0 ? $actor->actorId : null, $notes, $this->stamp());
        } catch (Throwable $e) {
            error_log('[visitors promote] registration ' . $registrationId . ' person ' . $personId . ': ' . $e->getMessage());
            throw new ValidationFailed(
                $outcome === 'created'
                    ? 'Person #' . $personId . ' was added to the member records, but this registration could not be marked promoted. Link it to #' . $personId . ' to record it.'
                    : 'This registration could not be marked promoted. Please try again.',
                ['person_id' => $personId],
            );
        }

        return ['person_id' => $personId, 'outcome' => $outcome];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $input
     */
    private function createPerson(array $row, array $input, int $accountId): int
    {
        if (trim((string) $row['last_name']) === '') {
            throw new ValidationFailed('This registration has no last name, which a member record needs.');
        }

        // Membership status from the member database's own list; an unknown
        // choice falls back to the first in its order, as migrate.php does.
        $statusIds = array_map(static fn (array $s): int => $s['id'], $this->members->membershipStatuses());
        $statusId = (int) ($input['membership_status_id'] ?? 0);
        if (!in_array($statusId, $statusIds, true)) {
            $statusId = $statusIds[0] ?? 0;
        }
        $campusIds = array_map(static fn (array $c): int => $c['id'], $this->members->campuses());
        $campusId = (int) ($input['campus_id'] ?? 0);
        if (!in_array($campusId, $campusIds, true)) {
            $campusId = 0;
        }

        $typeId = null;
        if (self::blankToNull($row['member_type_name'] ?? null) !== null) {
            $typeId = $this->members->memberTypeIdByName((string) $row['member_type_name']);
        }

        $email = self::blankToNull($row['email'] ?? null);
        $email = $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? (string) $email : null;
        $bm = (int) ($row['birth_month'] ?? 0);
        $bd = (int) ($row['birth_day'] ?? 0);
        $by = (int) ($row['birth_year'] ?? 0);
        $birthMonth = $bm >= 1 && $bm <= 12 ? $bm : null;
        $birthDay = $bd >= 1 && $bd <= 31 ? $bd : null;
        $bothParts = $birthMonth !== null && $birthDay !== null;

        $fields = [
            'first_name' => (string) $row['first_name'],
            'middle_name' => self::blankToNull($row['middle_name'] ?? null),
            'last_name' => (string) $row['last_name'],
            'preferred_name' => self::blankToNull($row['preferred_name'] ?? null),
            'address_line1' => self::blankToNull($row['address_line1'] ?? null),
            'address_line2' => self::blankToNull($row['address_line2'] ?? null),
            'city' => self::blankToNull($row['city'] ?? null),
            'region' => self::blankToNull($row['region'] ?? null),
            'postal_code' => self::blankToNull($row['postal_code'] ?? null),
            'country' => self::blankToNull($row['country'] ?? null),
            'mobile_phone' => self::blankToNull($row['phone'] ?? null),
            'email' => $email,
            // The editor keeps a birthday only as month and day together; a
            // sign-up often gives month and year alone, which is written below.
            'birth_month' => $bothParts ? $birthMonth : null,
            'birth_day' => $bothParts ? $birthDay : null,
            'birth_year' => $by > 0 ? $by : null,
            'gender' => in_array($row['gender'] ?? null, ['male', 'female'], true) ? $row['gender'] : null,
            'member_type_id' => $typeId,
            'membership_status_id' => $statusId,
            'campus_id' => $campusId,
        ];
        $latitude = ($row['latitude'] ?? null) !== null ? (float) $row['latitude'] : null;
        $longitude = ($row['longitude'] ?? null) !== null ? (float) $row['longitude'] : null;

        try {
            return (int) $this->members->transaction(function () use ($fields, $accountId, $bothParts, $birthMonth, $birthDay, $latitude, $longitude): int {
                $personId = $this->creator->create($fields, $accountId);
                if (!$bothParts && ($birthMonth !== null || $birthDay !== null) || $latitude !== null || $longitude !== null) {
                    $this->members->completePromotedPerson($personId, $birthMonth, $birthDay, $latitude, $longitude);
                }

                return $personId;
            });
        } catch (InvalidArgumentException $e) {
            throw new ValidationFailed('The member record could not be created: ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('[visitors promote] create person: ' . $e->getMessage());
            throw new ValidationFailed('The member record could not be created. Nothing was changed.');
        }
    }

    // ---- RSVPs --------------------------------------------------------------

    /**
     * Event dates with RSVPs, the chosen one's responses and totals.
     *
     * @return array<string,mixed>
     */
    public function rsvps(?ActorContext $actor, string $occasionKey = ''): array
    {
        $this->authorize($actor);
        $occasions = $this->withOccasionLabels($this->visitors->rsvpOccasions());
        $today = $this->now()->format('Y-m-d');
        foreach ($occasions as &$o) {
            $o['key'] = (int) $o['event_id'] . ':' . ($o['occurrence_id'] !== null ? (int) $o['occurrence_id'] : '');
            $o['upcoming'] = $o['date'] !== null && $o['date'] >= $today;
        }
        unset($o);
        // Upcoming soonest first, then past most recent first.
        usort($occasions, static function (array $a, array $b): int {
            if ($a['upcoming'] !== $b['upcoming']) {
                return $a['upcoming'] ? -1 : 1;
            }
            $cmp = strcmp((string) $a['date'], (string) $b['date']);

            return $a['upcoming'] ? $cmp : -$cmp;
        });

        $selected = null;
        foreach ($occasions as $o) {
            if ($o['key'] === $occasionKey) {
                $selected = $o;
            }
        }
        $selected ??= $occasions[0] ?? null;

        $rows = [];
        $totals = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'expected' => 0, 'checked_in' => 0, 'no_show' => 0, 'cancelled' => 0, 'members' => 0, 'visitors' => 0];
        if ($selected !== null) {
            $rows = $this->visitors->rsvpsFor((int) $selected['event_id'], $selected['occurrence_id'] !== null ? (int) $selected['occurrence_id'] : null);
            $people = $this->members->peopleByIds(array_map(static fn (array $r): int => (int) ($r['person_id'] ?? 0), $rows));
            foreach ($rows as &$r) {
                $r['person'] = $people[(int) ($r['person_id'] ?? 0)] ?? null;
                $totals[$r['response']] = ($totals[$r['response']] ?? 0) + 1;
                $party = max(1, (int) $r['party_size']);
                if ($r['response'] !== 'no' && $r['attendance'] !== 'cancelled') {
                    $totals['expected'] += $party;
                }
                if ($r['attendance'] === 'checked_in') {
                    $totals['checked_in'] += $party;
                } elseif (isset($totals[$r['attendance']])) {
                    $totals[$r['attendance']]++;
                }
                $totals[(int) ($r['person_id'] ?? 0) > 0 ? 'members' : 'visitors']++;
            }
            unset($r);
        }

        return ['occasions' => $occasions, 'selected' => $selected, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * Label RSVP rows (or occasion rows) with their event title and date.
     *
     * @param list<array<string,mixed>> $rows each with event_id and occurrence_id
     * @return list<array<string,mixed>>
     */
    private function withOccasionLabels(array $rows): array
    {
        $events = $this->members->eventsByIds(array_map(static fn (array $r): int => (int) $r['event_id'], $rows));
        $occurrences = $this->members->occurrencesByIds(array_map(static fn (array $r): int => (int) ($r['occurrence_id'] ?? 0), $rows));
        foreach ($rows as &$r) {
            $event = $events[(int) $r['event_id']] ?? null;
            $occ = $occurrences[(int) ($r['occurrence_id'] ?? 0)] ?? null;
            $r['event_exists'] = $event !== null;
            $r['title'] = $occ['title'] ?? ($event['title'] ?? ('Event #' . (int) $r['event_id']));
            if ($occ !== null) {
                $r['date'] = substr($occ['starts_at'], 0, 10);
                $r['time'] = substr($occ['starts_at'], 11, 5);
            } else {
                $r['date'] = $event['starts_on'] ?? null;
                $r['time'] = $event['start_time'] ?? null;
            }
        }
        unset($r);

        return $rows;
    }

    /** @return array<string,mixed> the RSVP as it now is */
    public function setAttendance(?ActorContext $actor, int $rsvpId, string $attendance): array
    {
        $this->authorize($actor);
        if (!in_array($attendance, self::ATTENDANCE, true)) {
            throw new ValidationFailed('That is not an attendance mark.');
        }
        $rsvp = $this->visitors->findRsvp($rsvpId);
        if ($rsvp === null) {
            throw new ValidationFailed('That RSVP no longer exists.');
        }
        $this->visitors->setRsvpAttendance($rsvpId, $attendance, $this->stamp());
        $rsvp['attendance'] = $attendance;

        return $rsvp;
    }

    /**
     * Active events with a date in the next $days days, for building RSVP links.
     *
     * @return list<array<string,mixed>>
     */
    public function upcomingEvents(?ActorContext $actor, int $days = 60): array
    {
        $this->authorize($actor);
        $from = $this->now()->format('Y-m-d');

        return $this->members->upcomingEvents($from, $this->now()->modify('+' . max(1, $days) . ' days')->format('Y-m-d'));
    }

    // ---- Access codes -------------------------------------------------------

    /**
     * The current code for each module, computed as the modules do.
     *
     * @return array<string,?array<string,mixed>>
     */
    public function accessCodes(?ActorContext $actor): array
    {
        $this->authorize($actor);
        $out = [];
        foreach (self::MODULES as $module) {
            $out[$module] = $this->accessStatus($module);
        }

        return $out;
    }

    /** @return ?array<string,mixed> */
    private function accessStatus(string $module): ?array
    {
        $row = $this->visitors->latestAccessCode($module);
        if ($row === null) {
            return null;
        }
        try {
            $expires = (new DateTimeImmutable((string) $row['expires_at'], $this->timeZone))->getTimestamp();
        } catch (Throwable) {
            $expires = false;
        }
        $now = $this->now()->getTimestamp();
        $active = $expires !== false && $expires >= $now;

        return [
            'word' => (string) $row['code'],
            'code' => $this->prefix($module) . $row['code'],
            'prefix' => $this->prefix($module),
            'issued_at' => (string) $row['issued_at'],
            'expires_at' => (string) $row['expires_at'],
            'active' => $active,
            'remaining_days' => $active ? (int) ceil(($expires - $now) / 86400) : 0,
            'note' => (string) ($row['note'] ?? ''),
        ];
    }

    public function prefix(string $module): string
    {
        return (string) ($this->accessPrefixes[$module] ?? 'ChristLikeness');
    }

    /**
     * Issue a new code, replacing the current one at once (sg_admin_set_word):
     * letters and numbers only, a generated word when none is left, valid for
     * 1 to 365 days.
     *
     * @return array<string,mixed> the new status
     */
    public function issueAccessCode(?ActorContext $actor, string $module, string $word, int $days, string $note = ''): array
    {
        $this->authorize($actor);
        if (!in_array($module, self::MODULES, true)) {
            throw new ValidationFailed('Unknown access code.');
        }
        $word = preg_replace('/[^A-Za-z0-9]/', '', mb_substr($word, 0, 64)) ?? '';
        if ($word === '') {
            $word = self::generateWord();
        }
        $days = max(1, min(365, $days));
        $note = mb_substr(trim($note), 0, 120);
        $this->visitors->insertAccessCode($module, $word, $this->stamp(), $this->stamp($days * 86400), $note === '' ? null : $note);

        return (array) $this->accessStatus($module);
    }

    /** A memorable word, e.g. "Harvest482" (sg_admin_gen_word). */
    public static function generateWord(): string
    {
        $words = ['Sunrise', 'Grace', 'Harvest', 'Cedar', 'River', 'Beacon', 'Anchor',
            'Summit', 'Haven', 'Journey', 'Radiant', 'Kindred', 'Shepherd', 'Cornerstone'];

        return $words[random_int(0, count($words) - 1)] . random_int(100, 999);
    }
}
