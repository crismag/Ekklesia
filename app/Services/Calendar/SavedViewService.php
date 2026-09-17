<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Contracts\SavedViewRepository;
use App\Core\ActorContext;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;

/**
 * Saved calendar views: who may open one, who may change it.
 *
 * The value of a saved view is that one administrator configures
 * "Sunday Ministry Wall Calendar" once and everybody else selects it and
 * prints. That only works if the canonical configuration is safe from the
 * people reusing it — so a consumer of a shared view can always take a copy,
 * and can never overwrite the original.
 *
 * Two scopes, not five. `private` is the owner's; `shared` is everyone who can
 * reach the print screen at all. A richer model would need its own permission
 * vocabulary, and this portal already has one that these rules ride on.
 */
final class SavedViewService
{
    public const VISIBILITIES = ['private', 'shared'];

    public function __construct(private readonly SavedViewRepository $views)
    {
    }

    /**
     * Views this actor may open.
     *
     * @return list<array<string,mixed>> each with `canEdit` already decided, so
     *         a caller never has to re-derive it and get it wrong
     */
    public function listFor(ActorContext $actor): array
    {
        $rows = $this->views->listFor($actor->actorId);

        return array_map(
            fn (array $row): array => $row + [
                'mine' => $row['ownerId'] === $actor->actorId,
                'canEdit' => $this->mayEdit($actor, $row),
            ],
            $rows,
        );
    }

    /**
     * One view, if this actor may see it.
     *
     * A private view belonging to somebody else reports as missing rather than
     * forbidden: "that view exists but is not yours" is an answer nobody needs
     * and a way to enumerate ids.
     *
     * @return array<string,mixed>|null
     */
    public function open(ActorContext $actor, int $viewId): ?array
    {
        $row = $this->views->find($viewId);
        if ($row === null) {
            return null;
        }
        if ($row['visibility'] !== 'shared' && $row['ownerId'] !== $actor->actorId) {
            return null;
        }

        return $row + [
            'mine' => $row['ownerId'] === $actor->actorId,
            'canEdit' => $this->mayEdit($actor, $row),
        ];
    }

    /**
     * Screen-calendar fields from a stored configuration.
     *
     * Print and screen share one row. Paper settings are ignored on the
     * calendar; these two are ignored on paper. Empty means the view never
     * claimed a screen opinion, so the calendar keeps whatever the operator
     * is already looking at (aside from layers, which live under content).
     *
     * @return array{view:string,left:string}
     */
    public function screenOf(PrintConfig $config): array
    {
        $view = (string) $config->get('screen.view', '');
        $left = (string) $config->get('screen.left', '');

        return [
            'view' => in_array($view, ['month', 'week', 'day', 'agenda'], true) ? $view : '',
            'left' => in_array($left, ['open', 'collapsed'], true) ? $left : '',
        ];
    }

    /** The configuration a view holds, defaulted through the current model. */
    public function configOf(array $row): PrintConfig
    {
        return PrintConfig::fromArray(is_array($row['config'] ?? null) ? $row['config'] : []);
    }

    /**
     * Save a new view owned by this actor.
     *
     * @return int the new id
     */
    public function create(ActorContext $actor, string $name, string $visibility, PrintConfig $config): int
    {
        $this->ensureSignedIn($actor);
        $name = $this->cleanName($name);
        $visibility = $this->cleanVisibility($actor, $visibility);

        foreach ($this->views->listFor($actor->actorId) as $existing) {
            if ($existing['ownerId'] === $actor->actorId && mb_strtolower($existing['name']) === mb_strtolower($name)) {
                throw new ValidationFailed('You already have a view called “' . $name . '”. Pick another name.');
            }
        }

        return $this->views->create($name, $actor->actorId, $visibility, PrintConfig::VERSION, $config->toArray());
    }

    /**
     * Overwrite an existing view.
     *
     * The check is deliberately not "is it shared" but "may this actor edit
     * it": somebody reusing a colleague's shared configuration must not be able
     * to change what everyone else gets by pressing Save.
     */
    public function update(ActorContext $actor, int $viewId, string $name, string $visibility, PrintConfig $config): void
    {
        $this->ensureSignedIn($actor);
        $row = $this->views->find($viewId);
        if ($row === null) {
            throw new ValidationFailed('That view no longer exists.');
        }
        if (!$this->mayEdit($actor, $row)) {
            throw new PermissionDenied('That view belongs to somebody else. Save it as your own instead.');
        }

        $this->views->update(
            $viewId,
            $this->cleanName($name),
            $this->cleanVisibility($actor, $visibility),
            PrintConfig::VERSION,
            $config->toArray(),
        );
    }

    public function delete(ActorContext $actor, int $viewId): void
    {
        $this->ensureSignedIn($actor);
        $row = $this->views->find($viewId);
        if ($row === null) {
            return;
        }
        if (!$this->mayEdit($actor, $row)) {
            throw new PermissionDenied('That view belongs to somebody else.');
        }
        $this->views->delete($viewId);
    }

    /**
     * Who may change a view.
     *
     * The owner, and a portal-wide administrator — who is already trusted with
     * every other shared setting in this portal, so excluding them here would
     * mean a shared view outliving the person who made it with nobody able to
     * correct it.
     *
     * @param array<string,mixed> $row
     */
    public function mayEdit(ActorContext $actor, array $row): bool
    {
        if ($actor->actorId <= 0) {
            return false;
        }

        return $row['ownerId'] === $actor->actorId || $actor->isPortalWideAdmin;
    }

    private function ensureSignedIn(ActorContext $actor): void
    {
        if ($actor->actorId <= 0) {
            throw new PermissionDenied('Sign in to save calendar views.');
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            throw new ValidationFailed('Give the view a name.');
        }

        return mb_substr($name, 0, 120);
    }

    /**
     * Sharing is a deliberate act, not a default.
     *
     * Only a portal-wide administrator may publish a view to everyone. Anyone
     * can keep their own.
     */
    private function cleanVisibility(ActorContext $actor, string $visibility): string
    {
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            return 'private';
        }
        if ($visibility === 'shared' && !$actor->isPortalWideAdmin) {
            throw new PermissionDenied('Only a portal administrator can share a view with everyone.');
        }

        return $visibility;
    }
}
