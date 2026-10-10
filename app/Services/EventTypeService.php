<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventTypeRepository;
use App\Core\ActorContext;
use App\Core\EventAudience;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;

/**
 * Event types: the categories that drive calendar layers and decide who may
 * read an event.
 *
 * Note for future edits: this file must stay free of SQL.
 * tests/Architecture/BoundaryTest.php enforces that with a whole-file regex
 * that is deliberately blunt — it matches the two halves of a query verb pair
 * even when they sit paragraphs apart in prose. Ordinary English can trip it,
 * so the wording here says "choose", "picker" and "read" rather than the
 * query words themselves. Persistence belongs in EventTypeRepository.
 */
final readonly class EventTypeService
{
    /** Colours are rendered into a generated CSS rule, so the format is strict. */
    private const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(private EventTypeRepository $types) {}

    /**
     * Every type, for the admin screen. Portal-managed rows first.
     *
     * @return list<array<string,mixed>>
     */
    public function allForAdmin(ActorContext $ctx): array
    {
        $this->requireAdmin($ctx);

        return $this->types->listAll();
    }

    /**
     * Events pointing at a type row that no longer exists.
     *
     * A type row removed behind the application's back leaves its events
     * pointing at nothing. Those events fall back to the 'members' audience, so a leaders-only event
     * becomes visible to every signed-in user — silently. Surfacing the list is
     * what turns that from a hidden regression into a visible chore.
     *
     * @return list<array{event_id:int,title:string,event_type_id:int}>
     */
    public function orphanedEvents(ActorContext $ctx): array
    {
        $this->requireAdmin($ctx);

        return $this->types->listOrphanedEvents();
    }

    /**
     * The calendar layers this actor may see.
     *
     * Audience-filtered here rather than in the browser: rendering a
     * "Leadership" chip to a member would confirm that leadership events exist
     * and were withheld, which is most of what we are trying to hide.
     *
     * @return list<array{source:string,label:string,color:string,kind:string,group:string}>
     */
    public function listLayers(?ActorContext $ctx): array
    {
        $allowed = EventAudience::allowedFor($ctx);
        $layers = [];
        foreach ($this->portalTypes() as $type) {
            if (!in_array((string) $type['audience'], $allowed, true)) {
                continue;
            }
            $layers[] = [
                'source' => 'events:' . $type['slug'],
                'label' => (string) $type['name'],
                'color' => (string) ($type['color'] ?? '#2c6ea5'),
                'kind' => 'event',
                'group' => 'events',
            ];
        }

        return $layers;
    }

    /**
     * Types offered in the event create/edit picker.
     *
     * Filtered by the same audience rule, so nobody can file an event under a
     * type they would immediately lose the ability to read.
     *
     * @return list<array{typeId:int,label:string,audience:string,color:string,isDefault:bool}>
     */
    public function listForPicker(?ActorContext $ctx): array
    {
        $allowed = EventAudience::allowedFor($ctx);
        $out = [];
        foreach ($this->portalTypes() as $type) {
            if (!in_array((string) $type['audience'], $allowed, true)) {
                continue;
            }
            $out[] = [
                'typeId' => (int) $type['id'],
                'label' => (string) $type['name'],
                'audience' => (string) $type['audience'],
                'color' => (string) ($type['color'] ?? '#2c6ea5'),
                'isDefault' => (bool) $type['is_default'],
            ];
        }

        return $out;
    }

    /** The type a new event gets when none was chosen. 0 when none is configured. */
    public function defaultTypeId(): int
    {
        foreach ($this->portalTypes() as $type) {
            if ($type['is_default']) {
                return (int) $type['id'];
            }
        }

        return 0;
    }

    /** True when the actor is allowed to read events of this type. */
    public function actorMayUseType(?ActorContext $ctx, int $typeId): bool
    {
        $type = $this->types->find($typeId);
        if ($type === null) {
            return false;
        }

        return in_array(
            EventAudience::fromStorage($type['audience'] ?? null)->value,
            EventAudience::allowedFor($ctx),
            true,
        );
    }

    public function add(ActorContext $ctx, string $label, string $audience, string $color, int $sort): int
    {
        $this->requireAdmin($ctx);
        $label = $this->cleanLabel($label);
        $slug = $this->slugify($label);
        if ($slug === '') {
            throw new ValidationFailed('That name has no letters or numbers to build a layer key from.');
        }
        if ($this->types->slugExists($slug)) {
            throw new ValidationFailed('An event type with a similar name already exists.');
        }

        return $this->types->insert([
            'name' => $label,
            'slug' => $slug,
            'audience' => $this->cleanAudience($audience),
            'color' => $this->cleanColor($color),
            'sort_order' => $sort,
        ]);
    }

    public function update(ActorContext $ctx, int $typeId, string $label, string $audience, string $color, int $sort): void
    {
        $this->requireAdmin($ctx);
        $existing = $this->requireType($typeId);
        $label = $this->cleanLabel($label);

        $this->types->update($typeId, [
            'name' => $label,
            'audience' => $this->cleanAudience($audience),
            'color' => $this->cleanColor($color),
            'sort_order' => $sort,
            // The slug is deliberately not editable: it is the calendar's
            // localStorage key and a CSS class name, so changing it would reset
            // every user's layer preferences without telling them.
            'slug' => $existing['slug'],
        ]);
    }

    public function makeDefault(ActorContext $ctx, int $typeId): void
    {
        $this->requireAdmin($ctx);
        $type = $this->requireType($typeId);
        if (EventAudience::fromStorage($type['audience'] ?? null) === EventAudience::Leaders) {
            // The default lands on every event created without an explicit
            // choice, and on every event the backfill could not classify. A
            // leaders-only default would hide those from the congregation by
            // accident, which is the one direction that loses information.
            throw new ValidationFailed('A leaders-only type cannot be the default — new events would be hidden from members.');
        }
        $this->types->setDefault($typeId);
    }

    public function delete(ActorContext $ctx, int $typeId): void
    {
        $this->requireAdmin($ctx);
        $type = $this->requireType($typeId);
        if ($type['is_default']) {
            throw new ValidationFailed('The default type cannot be deleted. Make another type the default first.');
        }
        $used = $this->types->countEventsOfType($typeId);
        if ($used > 0) {
            throw new ValidationFailed(
                'This type is used by ' . $used . ' event' . ($used === 1 ? '' : 's') . '. Reassign them first.'
            );
        }
        $this->types->delete($typeId);
    }

    /** @return list<array<string,mixed>> active types */
    private function portalTypes(): array
    {
        return array_values(array_filter(
            $this->types->listAll(),
            static fn (array $t): bool => $t['is_active'] !== false,
        ));
    }

    /** @return array<string,mixed> */
    private function requireType(int $typeId): array
    {
        $type = $this->types->find($typeId);
        if ($type === null) {
            throw new ValidationFailed('That event type no longer exists.');
        }

        return $type;
    }

    private function requireAdmin(ActorContext $ctx): void
    {
        if (!$ctx->isPortalWideAdmin) {
            throw new PermissionDenied('Only a portal-wide admin can manage event types.');
        }
    }

    private function cleanLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            throw new ValidationFailed('An event type needs a name.');
        }

        return mb_substr($label, 0, 64);
    }

    private function cleanAudience(string $audience): string
    {
        $case = EventAudience::tryFrom(trim($audience));
        if ($case === null) {
            throw new ValidationFailed('Pick a valid audience for this event type.');
        }

        return $case->value;
    }

    /** Rejected rather than sanitised: a silently corrected colour is a lie. */
    private function cleanColor(string $color): string
    {
        $color = trim($color);
        if (preg_match(self::COLOR_PATTERN, $color) !== 1) {
            throw new ValidationFailed('Colour must be a six-digit hex value such as #117b6d.');
        }

        return strtolower($color);
    }

    /** Constrained to [a-z0-9-]: the result becomes a CSS class name. */
    private function slugify(string $label): string
    {
        $slug = strtolower(trim($label));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
