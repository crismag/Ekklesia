<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Adapters\Sql\SqlPrintBackgroundAdapter;
use App\Core\ActorContext;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;

/**
 * Background pictures for printed calendars: who may add, see and remove one.
 *
 *  - Any signed-in account may upload (the same accounts that can save a
 *    design), within a quota, because a background is part of a design.
 *  - A picture is visible to its owner, to portal administrators, and to
 *    anyone who can open a *shared* saved view that uses it. A private view's
 *    picture stays private, and a picture nobody may see reports as missing.
 *  - Only the owner or a portal administrator may delete one, and never while
 *    a saved view still uses it: that view's design would silently change.
 *
 * Files live in storage/private/print-assets/backgrounds/{id}.jpg, outside the
 * web root, and are served only through PrintBackgroundController.
 */
final class PrintBackgroundService
{
    public const MAX_PER_ACCOUNT = 20;
    public const MAX_BYTES_PER_ACCOUNT = 100 * 1024 * 1024;

    public function __construct(
        private readonly SqlPrintBackgroundAdapter $records,
        private readonly string $dir,
    ) {
    }

    /** @return list<array<string,mixed>> what this actor may choose from */
    public function listFor(ActorContext $actor): array
    {
        $this->requireSignedIn($actor);
        $rows = $actor->isPortalWideAdmin ? $this->records->listAll() : $this->records->listOwnedBy($actor->actorId);

        return array_map(fn (array $r): array => $r + [
            'mine' => $r['accountId'] === $actor->actorId,
            'canDelete' => $r['accountId'] === $actor->actorId || $actor->isPortalWideAdmin,
        ], $rows);
    }

    /**
     * @return array<string,mixed> the stored record
     */
    public function upload(ActorContext $actor, string $tmpPath, string $originalName, int $bytes, string $label = ''): array
    {
        $this->requireSignedIn($actor);
        $usage = $this->records->usageOf($actor->actorId);
        if ($usage['count'] >= self::MAX_PER_ACCOUNT) {
            throw new ValidationFailed('You already have ' . self::MAX_PER_ACCOUNT
                . ' background pictures. Delete one you no longer use, then upload again.');
        }
        $image = ImageIntake::process($tmpPath, $bytes);
        $size = strlen($image['jpeg']);
        if ($usage['bytes'] + $size > self::MAX_BYTES_PER_ACCOUNT) {
            throw new ValidationFailed('Your background pictures would use more than 100 MB. Delete one you no longer use.');
        }
        $name = mb_substr(trim(basename($originalName)), 0, 190);
        $label = mb_substr(trim($label) !== '' ? trim($label) : (string) preg_replace('/\.[a-z0-9]+$/i', '', $name), 0, 120);
        $id = $this->records->insert(
            $actor->actorId, $label !== '' ? $label : 'Background', $name,
            $image['width'], $image['height'], $size, hash('sha256', $image['jpeg']),
        );
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            $this->records->delete($id);
            throw new \RuntimeException('The picture store could not be created.');
        }
        if (file_put_contents($this->path($id), $image['jpeg'], LOCK_EX) !== $size) {
            $this->records->delete($id);
            throw new \RuntimeException('The picture could not be saved.');
        }

        return (array) $this->records->find($id);
    }

    /**
     * The picture's record and file path, if this actor may see it; null
     * otherwise, including when the file has gone missing.
     *
     * @return array{record:array<string,mixed>,path:string}|null
     */
    public function open(ActorContext $actor, int $id): ?array
    {
        $record = $this->records->find($id);
        if ($record === null || !is_file($this->path($id))) {
            return null;
        }
        $may = $actor->actorId > 0 && ($record['accountId'] === $actor->actorId || $actor->isPortalWideAdmin);
        if (!$may) {
            foreach ($this->records->viewsUsing($id) as $view) {
                if ($view['visibility'] === 'shared' && $actor->actorId > 0) {
                    $may = true;
                    break;
                }
            }
        }

        return $may ? ['record' => $record, 'path' => $this->path($id)] : null;
    }

    public function delete(ActorContext $actor, int $id): void
    {
        $this->requireSignedIn($actor);
        $record = $this->records->find($id);
        if ($record === null || ($record['accountId'] !== $actor->actorId && !$actor->isPortalWideAdmin)) {
            throw new ValidationFailed('That picture no longer exists.');
        }
        $using = $this->records->viewsUsing($id);
        if ($using !== []) {
            $names = array_map(static fn (array $v): string => '“' . $v['name'] . '”', $using);
            throw new ValidationFailed('That picture is used by ' . implode(', ', $names)
                . '. Choose another background there first, then delete it.');
        }
        $this->records->delete($id);
        if (is_file($this->path($id))) {
            @unlink($this->path($id));
        }
    }

    private function path(int $id): string
    {
        return rtrim($this->dir, '/') . '/' . $id . '.jpg';
    }

    private function requireSignedIn(ActorContext $actor): void
    {
        if ($actor->actorId <= 0) {
            throw new PermissionDenied('Sign in to use background pictures.');
        }
    }
}
