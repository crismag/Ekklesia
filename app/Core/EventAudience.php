<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Who an event type is for.
 *
 * Stored on event_types.portal_audience (migration 009) and compared in SQL on
 * every event read path, so these string values are a data contract — renaming
 * a case without a migration would silently widen or narrow who sees what.
 *
 * The ladder is cumulative: an actor allowed 'leaders' is also allowed
 * 'members' and 'public'.
 */
enum EventAudience: string
{
    /**
     * Visible without a session.
     *
     * Used by the public home feed and GET /api/public/events. Signed-in list
     * and agenda paths still go through allowedFor($ctx) so members and leaders
     * see their extra types.
     */
    case Public = 'public';

    /** Any signed-in portal account. The default for an unclassified event. */
    case Members = 'members';

    /**
     * Ministry leaders only. Both the Leadership and Ministry event types sit
     * here — they are one access level split into two categories for filtering.
     */
    case Leaders = 'leaders';

    /**
     * The audiences this actor may read, for passing to a repository.
     *
     * Adapters receive this plain list rather than the ActorContext itself, so
     * that app/Adapters stays free of the actor model.
     *
     * @return list<string>
     */
    public static function allowedFor(?ActorContext $ctx): array
    {
        if ($ctx === null) {
            return [self::Public->value];
        }

        $allowed = [self::Public->value, self::Members->value];

        if ($ctx->isPortalWideAdmin || $ctx->hasPermission(PortalPermission::ViewLeaderEvents)) {
            $allowed[] = self::Leaders->value;
        }

        return $allowed;
    }

    /**
     * The audience an event type row resolves to.
     *
     * A type row can be missing entirely (events_event.event_type has carried
     * dangling ids, and ChurchCRM's own EditEventTypes.php can delete a row the
     * portal created), so the fallback matters: it is Members, never Leaders.
     * An unclassified event is visible to signed-in users and hidden from
     * anonymous ones; it can never become restricted by accident.
     */
    public static function fromStorage(?string $stored): self
    {
        return self::tryFrom((string) $stored) ?? self::Members;
    }
}
