<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Read access to audit_log for the administration area's Activity history.
 *
 * Read-only by design: entries are written by the services that make the
 * change (AuthRepository::recordAudit and the admin services), never here.
 *
 * Filters (all optional, already validated by the service):
 *   accountId   int     who acted (audit_log.account_id)
 *   personId    int     entries by this person or about their record
 *   action      string  exact action ('portal_access.update')
 *   targetType  string  exact target type ('person', 'user_account', …)
 *   targetId    string  exact target id, with targetType
 *   from        string  Y-m-d, inclusive
 *   to          string  Y-m-d, inclusive
 */
interface ActivityHistoryRepository
{
    /**
     * Newest first.
     *
     * @param array{accountId?:int,personId?:int,action?:string,targetType?:string,targetId?:string,from?:string,to?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function search(array $filters, int $limit, int $offset): array;

    /** @param array{accountId?:int,personId?:int,action?:string,targetType?:string,targetId?:string,from?:string,to?:string} $filters */
    public function count(array $filters): int;

    /**
     * What the filter menus can offer: only values that occur in the log.
     *
     * @return array{actions:list<string>,targetTypes:list<string>,accounts:list<array{id:int,label:string}>}
     */
    public function facets(): array;
}
