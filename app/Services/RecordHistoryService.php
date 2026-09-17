<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RecordHistoryRepository;
use App\Exceptions\PermissionDenied;

/**
 * Record history: who changed which person or household record, and when.
 *
 * Read-only, and for portal-wide administrators only. The audit trail names
 * people, households and the accounts that changed them, which is exactly the
 * member information the rest of People & Records keeps to administrators.
 */
final class RecordHistoryService
{
    public const PAGE_SIZE = 50;

    /** What each recorded action means, in the words the pages use. */
    private const ACTION_LABELS = [
        'person.created' => 'Record created',
        'person.updated' => 'Record edited',
        'person.campus_changed' => 'Campus changed',
        'person.profile_updated' => 'Profile updated by the member',
        'household.created' => 'Household created',
        'household.updated' => 'Household edited',
        'household.merged' => 'Households merged',
        'legacy.edit' => 'Edited (earlier system)',
        'legacy.group' => 'Group change (earlier system)',
        'legacy.photo' => 'Photo change (earlier system)',
        'legacy.user' => 'Login change (earlier system)',
    ];

    public function __construct(private readonly RecordHistoryRepository $history) {}

    /** @param ?array<string,mixed> $actor */
    public static function mayRead(?array $actor): bool
    {
        return $actor !== null && !empty($actor['isPortalWideAdmin']);
    }

    /**
     * Turn request parameters into criteria. Anything malformed is dropped
     * rather than guessed at, so a bad link shows everything, not nothing.
     *
     * @param array<string,mixed> $query
     * @return array{types:list<string>,personId:?int,householdId:?int,action:?string,from:?string,to:?string,page:int}
     */
    public static function criteria(array $query): array
    {
        $type = (string) ($query['type'] ?? '');
        $types = in_array($type, ['person', 'household'], true) ? [$type] : ['person', 'household'];
        $id = static fn (mixed $v): ?int => is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0 ? (int) $v : null;
        $date = static function (mixed $v): ?string {
            if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

            return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
        };
        $action = trim((string) ($query['action'] ?? ''));
        $from = $date($query['from'] ?? null);
        $to = $date($query['to'] ?? null);
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'types' => $types,
            'personId' => $id($query['person'] ?? null),
            'householdId' => $id($query['household'] ?? null),
            'action' => $action !== '' && preg_match('/^[a-z0-9_.-]{1,80}$/i', $action) === 1 ? $action : null,
            'from' => $from,
            'to' => $to,
            'page' => max(1, (int) ($id($query['page'] ?? null) ?? 1)),
        ];
    }

    /**
     * One page of history.
     *
     * @param ?array<string,mixed> $actor
     * @param array<string,mixed> $query request parameters
     * @return array{entries:list<array<string,mixed>>,total:int,page:int,pages:int,perPage:int,criteria:array<string,mixed>}
     */
    public function page(?array $actor, array $query): array
    {
        $this->authorize($actor);
        $criteria = self::criteria($query);
        $total = $this->history->count($criteria);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($criteria['page'], $pages);
        $criteria['page'] = $page;
        $rows = $total === 0 ? [] : $this->history->find($criteria, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return [
            'entries' => array_map($this->describe(...), $rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PAGE_SIZE,
            'criteria' => $criteria,
        ];
    }

    /**
     * The latest entries for one record, for its record page.
     *
     * @param ?array<string,mixed> $actor
     * @param 'person'|'household' $type
     * @return array{entries:list<array<string,mixed>>,total:int}
     */
    public function recent(?array $actor, string $type, int $id, int $limit = 5): array
    {
        $this->authorize($actor);
        $criteria = self::criteria([$type => (string) $id, 'type' => $type]);
        if ($criteria[$type === 'person' ? 'personId' : 'householdId'] === null) {
            return ['entries' => [], 'total' => 0];
        }
        $total = $this->history->count($criteria);

        return [
            'entries' => $total === 0 ? [] : array_map($this->describe(...), $this->history->find($criteria, max(1, $limit), 0)),
            'total' => $total,
        ];
    }

    /**
     * Filter choices: the actions on record, and the people with history.
     *
     * @param ?array<string,mixed> $actor
     * @return array{actions:list<array{value:string,label:string}>,people:list<array{id:int,name:string}>}
     */
    public function filterOptions(?array $actor): array
    {
        $this->authorize($actor);
        $actions = array_map(
            static fn (string $a): array => ['value' => $a, 'label' => self::actionLabel($a)],
            $this->history->actions(),
        );
        usort($actions, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        $people = array_map(static function (array $p): array {
            $last = trim($p['last_name']);
            $first = trim($p['first_name']);

            return ['id' => $p['id'], 'name' => $last !== '' && $first !== '' ? $last . ', ' . $first : ($last . $first ?: 'Person #' . $p['id'])];
        }, $this->history->peopleWithHistory());

        return ['actions' => $actions, 'people' => $people];
    }

    /**
     * The name of a record a filter points at, or null when it no longer exists.
     *
     * @param ?array<string,mixed> $actor
     * @param 'person'|'household' $type
     */
    public function recordName(?array $actor, string $type, int $id): ?string
    {
        $this->authorize($actor);

        return $type === 'person' ? $this->history->personName($id) : $this->history->householdName($id);
    }

    public static function actionLabel(string $action): string
    {
        if (isset(self::ACTION_LABELS[$action])) {
            return self::ACTION_LABELS[$action];
        }
        $tail = str_contains($action, '.') ? substr($action, strrpos($action, '.') + 1) : $action;
        $words = trim(str_replace(['_', '-'], ' ', $tail));

        return $words === '' ? 'Change' : ucfirst($words);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,occurredAt:string,action:string,actionLabel:string,who:string,whoPersonId:?int,recordType:string,recordId:int,recordName:?string,summary:string}
     */
    public function describe(array $row): array
    {
        $type = (string) ($row['target_type'] ?? '');
        $recordName = null;
        if ($type === 'person' && ($row['target_first_name'] !== null || $row['target_last_name'] !== null)) {
            $recordName = trim((string) $row['target_first_name'] . ' ' . (string) $row['target_last_name']);
        } elseif ($type === 'household' && $row['target_household_name'] !== null) {
            $recordName = (string) $row['target_household_name'];
        }

        $actorPerson = trim((string) ($row['actor_first_name'] ?? '') . ' ' . (string) ($row['actor_last_name'] ?? ''));
        $who = match (true) {
            $actorPerson !== '' => $actorPerson,
            trim((string) ($row['account_name'] ?? '')) !== '' => trim((string) $row['account_name']),
            trim((string) ($row['account_email'] ?? '')) !== '' => trim((string) $row['account_email']),
            default => 'Not recorded',
        };

        return [
            'id' => (int) $row['id'],
            'occurredAt' => (string) $row['occurred_at'],
            'action' => (string) $row['action'],
            'actionLabel' => self::actionLabel((string) $row['action']),
            'who' => $who,
            'whoPersonId' => $actorPerson !== '' && $row['actor_person_id'] !== null ? (int) $row['actor_person_id'] : null,
            'recordType' => $type,
            'recordId' => (int) $row['target_id'],
            'recordName' => $recordName,
            'summary' => $this->summary($row),
        ];
    }

    /** @param array<string,mixed> $row */
    private function summary(array $row): string
    {
        $summary = trim((string) ($row['summary'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }
        $details = is_string($row['details'] ?? null) ? json_decode((string) $row['details'], true) : null;
        if ((string) $row['action'] === 'household.merged' && is_array($details)) {
            $moved = (int) ($details['people_moved'] ?? 0);

            return sprintf(
                'Household #%d merged into this one; %d %s moved.',
                (int) ($details['merged_household_id'] ?? 0),
                $moved,
                $moved === 1 ? 'person' : 'people',
            );
        }

        return '';
    }

    /** @param ?array<string,mixed> $actor */
    private function authorize(?array $actor): void
    {
        if (!self::mayRead($actor)) {
            throw new PermissionDenied('Record history is available to portal administrators.');
        }
    }
}
