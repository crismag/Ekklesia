<?php

declare(strict_types=1);

namespace App\Services\Ministry;

use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Documents\MinistryScheduleDocument;
use App\Exceptions\PermissionDenied;
use App\Services\RosterScheduleService;
use App\Services\ScheduleService;
use DateTimeImmutable;

/**
 * Turns whatever the church actually recorded into one printable schedule.
 *
 * Two sources exist, and they are genuinely different systems:
 *
 *   assignments → serving_roles → ministries — someone rostered onto a role
 *                                     of a ministry, for a dated occurrence.
 *                                     This is the shape the printed schedule
 *                                     has always had: ministry, role, names.
 *
 *   rosters → roster_slots           — a container with dated or
 *                                     weekday slots, each carrying a label, a
 *                                     location and one or more assignees.
 *
 * Neither was built with printing in mind, and neither is going to be reshaped
 * for it. Consolidating them is this class's whole job, and it happens here
 * rather than in a template because a template that queries cannot be tested
 * without a database and grows a second copy of these rules the moment a
 * second template exists.
 *
 * Nothing is invented. Ministries come from ministries, roles from the
 * serving_roles table, names from people or from the label somebody typed. A ministry
 * that has no assignments on the day does not appear — a card reading
 * "Facilities: nobody" is not what a noticeboard is for, though the builder
 * does say so in its warnings.
 */
final class MinistryScheduleDocumentBuilder
{
    public const SOURCE_ASSIGNMENTS = 'assignments';
    public const SOURCE_ROSTERS = 'rosters';

    /**
     * Roughly how many lines a card costs, used only to balance columns.
     *
     * A guess, deliberately: CSS does the real text layout, and a typesetting
     * engine here would be a second layout system disagreeing with the first.
     */
    private const WEIGHT_HEADER = 2;
    private const WEIGHT_ROLE = 1;
    private const WEIGHT_PERSON = 1;

    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly ?RosterScheduleService $rosters = null,
    ) {
    }

    /**
     * Build the schedule for one day.
     *
     * @param list<string> $only limit to these sources; empty means all
     */
    public function build(ActorContext $actor, DateTimeImmutable $date, array $only = []): MinistryScheduleDocument
    {
        // Volunteer names, and who is serving when, across every ministry at
        // once. That is member data, and the public board endpoint is a
        // deliberately separate thing — this is not it.
        //
        // ViewMinistryDashboard is deliberately NOT accepted, though the
        // per-ministry schedule grid accepts it. Every member holds it, and a
        // dashboard summary is not the same as the whole church's duty roster
        // with names. The two explicit schedule permissions are what scheduler,
        // leader and admin hold; a member who serves sees their own duties on
        // /my-schedule.
        if (!$actor->isPortalWideAdmin
            && !$actor->hasPermission(PortalPermission::ViewMinistrySchedule)
            && !$actor->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor lacks permission to read ministry schedules.');
        }

        $wanted = $only === [] ? [self::SOURCE_ASSIGNMENTS, self::SOURCE_ROSTERS] : $only;
        $dayStart = $date->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $sections = [];
        $serviceTitle = '';
        $used = [];

        if (in_array(self::SOURCE_ASSIGNMENTS, $wanted, true)) {
            [$fromAssignments, $serviceTitle] = $this->fromAssignments($actor, $dayStart, $dayEnd);
            if ($fromAssignments !== []) {
                $used[] = self::SOURCE_ASSIGNMENTS;
            }
            $sections = array_merge($sections, $fromAssignments);
        }

        if (in_array(self::SOURCE_ROSTERS, $wanted, true) && $this->rosters !== null) {
            $fromRosters = $this->fromRosters($dayStart);
            if ($fromRosters !== []) {
                $used[] = self::SOURCE_ROSTERS;
            }
            $sections = $this->merge($sections, $fromRosters);
        }

        // Ministries in alphabetical order. Any order is arbitrary, but an
        // arbitrary order that changes between printings is worse: somebody
        // learns where their ministry sits on the sheet.
        usort($sections, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return new MinistryScheduleDocument(
            title: 'MINISTRY SCHEDULE',
            serviceDate: $dayStart->format('Y-m-d'),
            serviceTitle: $serviceTitle,
            displayDate: strtoupper($dayStart->format('F j, Y')),
            sections: $sections,
            warnings: $this->inspect($sections),
            stats: $this->count($sections),
            sources: array_values(array_unique($used)),
        );
    }

    /**
     * Assignments made against the day's occurrences.
     *
     * @return array{0:list<array<string,mixed>>,1:string}
     */
    private function fromAssignments(ActorContext $actor, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        try {
            $rows = $this->schedules->getScheduleBoard($actor, $start, $end);
        } catch (\Throwable) {
            // A schedule that cannot be read prints as an empty schedule rather
            // than a 500. The warnings below will say it is empty.
            return [[], ''];
        }

        $byMinistry = [];
        $serviceTitle = '';
        foreach ($rows as $row) {
            $ministry = trim((string) ($row['ministry_name'] ?? ''));
            if ($ministry === '') {
                // A role whose ministry group has been deleted still has people
                // on it. Losing them silently is worse than a plain heading.
                $ministry = 'Unassigned ministry';
            }
            if ($serviceTitle === '' && ($row['event_title'] ?? '') !== '') {
                $serviceTitle = (string) $row['event_title'];
            }

            $key = mb_strtolower($ministry);
            $byMinistry[$key] ??= [
                'title' => $ministry,
                'ministryId' => isset($row['ministry_id']) ? (int) $row['ministry_id'] : null,
                'source' => self::SOURCE_ASSIGNMENTS,
                'roles' => [],
            ];

            $role = trim((string) ($row['role_name'] ?? ''));
            $roleKey = mb_strtolower($role);
            $byMinistry[$key]['roles'][$roleKey] ??= ['role' => $role === '' ? null : $role, 'people' => [], 'note' => null];

            $name = trim((string) ($row['person_name'] ?? ''));
            if ($name === '') {
                // An open slot. Kept, because an unfilled role is the single
                // most useful thing a schedule can tell somebody before Sunday.
                $byMinistry[$key]['roles'][$roleKey]['note'] = 'Unfilled';
                continue;
            }
            $byMinistry[$key]['roles'][$roleKey]['people'][] = [
                'name' => $name,
                'personId' => isset($row['person_id']) ? (int) $row['person_id'] : null,
            ];
        }

        return [array_values(array_map($this->finishSection(...), $byMinistry)), $serviceTitle];
    }

    /**
     * Roster slots falling on the day.
     *
     * A roster's slot label is its role — "Pick-up", "Kitchen" — and its
     * roster title is the ministry when it has no ministry of its own.
     *
     * @return list<array<string,mixed>>
     */
    private function fromRosters(DateTimeImmutable $day): array
    {
        try {
            $items = $this->rosters?->expandRostersInWindow($day->format('Y-m-d'), $day->format('Y-m-d')) ?? [];
        } catch (\Throwable) {
            return [];
        }

        $byTitle = [];
        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $key = mb_strtolower($title);
            $byTitle[$key] ??= [
                'title' => $title,
                'ministryId' => isset($item['ministryId']) ? (int) $item['ministryId'] : null,
                'source' => self::SOURCE_ROSTERS,
                'roles' => [],
            ];

            $role = trim((string) ($item['label'] ?? ''));
            $roleKey = mb_strtolower($role);
            $byTitle[$key]['roles'][$roleKey] ??= [
                'role' => $role === '' ? null : $role,
                'people' => [],
                // Where, when a slot says where. The assignment source has no
                // equivalent, so this is not promoted to its own field.
                'note' => trim((string) ($item['location'] ?? '')) ?: null,
            ];

            foreach ((array) ($item['assignees'] ?? []) as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $byTitle[$key]['roles'][$roleKey]['people'][] = ['name' => $name, 'personId' => null];
                }
            }
            if ($byTitle[$key]['roles'][$roleKey]['people'] === []) {
                $byTitle[$key]['roles'][$roleKey]['note'] = 'Unfilled';
            }
        }

        return array_values(array_map($this->finishSection(...), $byTitle));
    }

    /**
     * Fold roster sections into assignment sections of the same name.
     *
     * A ministry can appear in both systems, and printing it twice would look
     * like a mistake in the schedule rather than in the software.
     *
     * @param list<array<string,mixed>> $primary
     * @param list<array<string,mixed>> $extra
     * @return list<array<string,mixed>>
     */
    private function merge(array $primary, array $extra): array
    {
        $index = [];
        foreach ($primary as $i => $section) {
            $index[mb_strtolower($section['title'])] = $i;
        }

        foreach ($extra as $section) {
            $key = mb_strtolower($section['title']);
            if (!isset($index[$key])) {
                $primary[] = $section;
                continue;
            }
            $target = $index[$key];
            $existingRoles = [];
            foreach ($primary[$target]['assignments'] as $a) {
                $existingRoles[mb_strtolower((string) $a['role'])] = true;
            }
            foreach ($section['assignments'] as $a) {
                // Same ministry and same role from both systems is one thing
                // recorded twice, not two duties.
                if (isset($existingRoles[mb_strtolower((string) $a['role'])])) {
                    continue;
                }
                $primary[$target]['assignments'][] = $a;
            }
            $primary[$target]['weight'] = $this->weigh($primary[$target]['assignments']);
        }

        return $primary;
    }

    /** Roles keyed for grouping become an ordered list with a weight. */
    private function finishSection(array $section): array
    {
        $assignments = array_values($section['roles']);
        // People within a role in the order they were assigned; roles in the
        // order the source gave them, which for assignments is the serving role's sort_order.
        foreach ($assignments as $i => $a) {
            $seen = [];
            $unique = [];
            foreach ($a['people'] as $p) {
                $k = mb_strtolower($p['name']);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $unique[] = $p;
            }
            $assignments[$i]['people'] = $unique;
        }
        unset($section['roles']);

        return $section + [
            'assignments' => $assignments,
            'weight' => $this->weigh($assignments),
        ];
    }

    /** @param list<array<string,mixed>> $assignments */
    private function weigh(array $assignments): int
    {
        $w = self::WEIGHT_HEADER;
        foreach ($assignments as $a) {
            if (($a['role'] ?? null) !== null) {
                $w += self::WEIGHT_ROLE;
            }
            $w += max(1, count($a['people'])) * self::WEIGHT_PERSON;
        }

        return $w;
    }

    /**
     * What is worth saying before somebody prints this.
     *
     * Only things the data can actually support. "Required role" is one of
     * them — roles.is_blocking exists — but it is not read here, because the
     * board query does not return roles nobody was assigned to, and inventing
     * the absence would mean a second query per ministry for a warning.
     * Unfilled slots that *were* recorded are reported.
     *
     * Serving twice on one Sunday is normal and is reported as information,
     * never as an error: a church runs on people who do two things.
     *
     * @param list<array<string,mixed>> $sections
     * @return list<array{level:string,kind:string,message:string}>
     */
    private function inspect(array $sections): array
    {
        $out = [];
        $appearances = [];

        foreach ($sections as $section) {
            if ($section['assignments'] === []) {
                $out[] = [
                    'level' => MinistryScheduleDocument::LEVEL_WARN,
                    'kind' => 'empty_ministry',
                    'message' => $section['title'] . ' has no assignments.',
                ];
                continue;
            }
            foreach ($section['assignments'] as $a) {
                if ($a['people'] === []) {
                    $out[] = [
                        'level' => MinistryScheduleDocument::LEVEL_WARN,
                        'kind' => 'unfilled_role',
                        'message' => $section['title']
                            . (($a['role'] ?? null) !== null ? ' — ' . $a['role'] : '')
                            . ' has nobody assigned.',
                    ];
                }
                foreach ($a['people'] as $p) {
                    $appearances[$p['name']] ??= 0;
                    $appearances[$p['name']]++;
                }
            }
        }

        $twice = array_keys(array_filter($appearances, static fn (int $n): bool => $n > 1));
        if ($twice !== []) {
            sort($twice);
            $out[] = [
                'level' => MinistryScheduleDocument::LEVEL_INFO,
                'kind' => 'multiple_assignments',
                'message' => count($twice) . ' '
                    . (count($twice) === 1 ? 'person is' : 'people are')
                    . ' serving more than once: ' . implode(', ', array_slice($twice, 0, 8))
                    . (count($twice) > 8 ? ', and others' : '') . '.',
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $sections */
    private function count(array $sections): array
    {
        $assignments = 0;
        $people = 0;
        $distinct = [];
        foreach ($sections as $section) {
            foreach ($section['assignments'] as $a) {
                $assignments++;
                foreach ($a['people'] as $p) {
                    $people++;
                    $distinct[mb_strtolower($p['name'])] = true;
                }
            }
        }

        return [
            'ministries' => count($sections),
            'assignments' => $assignments,
            'volunteers' => count($distinct),
            'people' => $people,
        ];
    }
}
