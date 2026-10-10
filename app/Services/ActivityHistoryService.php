<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ActivityHistoryRepository;
use App\Core\ActorContext;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use DateTimeImmutable;

/**
 * Activity history for the administration area: the whole audit log, read-only.
 *
 * Portal-wide administrators only. The log names who changed which record and
 * from where (IP address, browser), which is more than any other role sees.
 *
 * This reads every target type. People & Records keeps its own record history
 * for people and households; the two are deliberately separate classes so they
 * can be built side by side and consolidated later.
 */
final class ActivityHistoryService
{
    public const PER_PAGE = 50;

    public function __construct(private readonly ActivityHistoryRepository $repository)
    {
    }

    /**
     * One page of history.
     *
     * Query keys (as they arrive from the page's filter form): account, person,
     * action, target_type, target_id, from, to, page.
     *
     * @param array<string,mixed> $query
     * @return array{
     *   entries:list<array<string,mixed>>,
     *   total:int, page:int, pages:int, perPage:int,
     *   filters:array{account:int,person:int,action:string,target_type:string,target_id:string,from:string,to:string},
     *   facets:array{actions:list<string>,targetTypes:list<string>,accounts:list<array{id:int,label:string}>}
     * }
     */
    public function page(ActorContext $actor, array $query): array
    {
        if (!$actor->isPortalWideAdmin) {
            throw new PermissionDenied('Activity history is limited to portal-wide administrators.');
        }

        $filters = self::normalise($query);
        $repoFilters = array_filter([
            'accountId' => $filters['account'],
            'personId' => $filters['person'],
            'action' => $filters['action'],
            'targetType' => $filters['target_type'],
            'targetId' => $filters['target_type'] !== '' ? $filters['target_id'] : '',
            'from' => $filters['from'],
            'to' => $filters['to'],
        ], static fn (mixed $v): bool => $v !== '' && $v !== 0);

        $total = $this->repository->count($repoFilters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($query['page'] ?? 1)), $pages);

        return [
            'entries' => $this->repository->search($repoFilters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'filters' => $filters,
            'facets' => $this->repository->facets(),
        ];
    }

    /**
     * Validate the filter form. A date that is not a real day, or a range that
     * ends before it starts, is refused rather than silently widened: a history
     * that quietly shows "everything" when asked for a week misleads.
     *
     * @param array<string,mixed> $query
     * @return array{account:int,person:int,action:string,target_type:string,target_id:string,from:string,to:string}
     */
    public static function normalise(array $query): array
    {
        $date = static function (mixed $raw, string $label): string {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return '';
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
                throw new ValidationFailed($label . ' must be a date (YYYY-MM-DD).');
            }

            return $raw;
        };
        $token = static function (mixed $raw): string {
            $raw = trim((string) $raw);

            return preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $raw) === 1 ? $raw : '';
        };

        $from = $date($query['from'] ?? '', 'The start date');
        $to = $date($query['to'] ?? '', 'The end date');
        if ($from !== '' && $to !== '' && $to < $from) {
            throw new ValidationFailed('The end date is before the start date.');
        }

        return [
            'account' => max(0, (int) ($query['account'] ?? 0)),
            'person' => max(0, (int) ($query['person'] ?? 0)),
            'action' => $token($query['action'] ?? ''),
            'target_type' => $token($query['target_type'] ?? ''),
            'target_id' => $token($query['target_id'] ?? ''),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * A plain-language reading of an action name, for rows without a summary.
     * Unknown actions fall back to the stored name, which is still accurate.
     */
    public static function describeAction(string $action): string
    {
        return match ($action) {
            'auth.profile.update' => 'Changed their display name',
            'auth.password.change' => 'Changed their password',
            'portal_access.create' => 'Created a login',
            'portal_access.update' => 'Changed a login’s access',
            'user_account.create' => 'Created a login',
            'user_account.update' => 'Changed a login',
            'user_account.password' => 'Set a login’s password',
            'user_account.role.add' => 'Added a role',
            'user_account.role.remove' => 'Removed a role',
            'user_account.link' => 'Linked a login to a person',
            'user_account.delete' => 'Deleted a login',
            'person.created' => 'Added a person',
            'person.updated' => 'Edited a person',
            'person.profile_updated' => 'Edited a person’s profile',
            'person.campus_changed' => 'Changed a person’s campus',
            'household.created' => 'Added a household',
            'household.updated' => 'Edited a household',
            'household.merged' => 'Merged households',
            'campus.created' => 'Added a campus',
            'campus.updated' => 'Edited a campus',
            'legacy.edit' => 'Edited a record (earlier portal)',
            'legacy.group' => 'Changed ministry membership (earlier portal)',
            'legacy.photo' => 'Changed a photo (earlier portal)',
            'legacy.user' => 'Changed a login (earlier portal)',
            default => $action,
        };
    }
}
