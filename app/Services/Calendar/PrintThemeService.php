<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Adapters\Sql\SqlPrintThemeAdapter;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;

/**
 * PowerPoint calendar themes: who may add, see, publish and retire one.
 *
 * Uses the permissions Ekklesia already has rather than new roles:
 *
 *   select a theme            anyone signed in, for themes they may see
 *   upload / replace / retire  holders of "manage events", or portal admins;
 *                              only the theme's owner or an admin may change it
 *   publish church-wide        portal administrators, as with shared views
 *
 * A new theme is private to whoever uploaded it until it is published.
 * Replacing adds a version; a saved design keeps the version it was made with.
 * Retiring (archive) hides a theme; nothing is deleted while designs may pin it.
 *
 * Files: storage/private/print-assets/themes/{id}/v{n}/ — the original .pptx,
 * its re-encoded pictures and a thumbnail. Only PrintThemeController serves them.
 */
final class PrintThemeService
{
    public function __construct(
        private readonly SqlPrintThemeAdapter $records,
        private readonly string $dir,
    ) {
    }

    public function mayManage(ActorContext $actor): bool
    {
        return $actor->actorId > 0 && ($actor->isPortalWideAdmin
            || in_array(PortalPermission::ManageEvents, $actor->permissions, true));
    }

    /** @return list<array<string,mixed>> themes this actor may choose */
    public function listFor(ActorContext $actor): array
    {
        if ($actor->actorId <= 0) {
            return [];
        }
        $out = [];
        foreach ($this->records->listActive() as $t) {
            if ($this->maySee($actor, $t)) {
                $out[] = $t + ['canEdit' => $this->mayEdit($actor, $t), 'canPublish' => $actor->isPortalWideAdmin];
            }
        }

        return $out;
    }

    /**
     * @return array{theme:array<string,mixed>,warnings:list<string>}
     */
    public function upload(ActorContext $actor, string $tmp, string $originalName, int $bytes, string $name): array
    {
        if (!$this->mayManage($actor)) {
            throw new PermissionDenied('Only calendar administrators can add PowerPoint themes.');
        }
        $read = PptxThemeReader::read($tmp, $originalName, $bytes);
        if (!$read['ok']) {
            throw new ValidationFailed(implode(' ', $read['errors']));
        }
        $name = mb_substr(trim($name) !== '' ? trim($name) : ($read['title'] !== '' ? $read['title']
            : (string) preg_replace('/\.pptx$/i', '', basename($originalName))), 0, 120);
        $id = $this->records->createTheme($actor->actorId, $name !== '' ? $name : 'PowerPoint theme');
        try {
            $this->storeVersion($actor, $id, 1, $tmp, $originalName, $bytes, $read);
        } catch (\Throwable $e) {
            $this->records->deleteTheme($id);
            throw $e;
        }

        return ['theme' => (array) $this->records->find($id), 'warnings' => $read['warnings']];
    }

    /** A new version of an existing theme. @return array{theme:array<string,mixed>,warnings:list<string>} */
    public function replace(ActorContext $actor, int $id, string $tmp, string $originalName, int $bytes): array
    {
        $theme = $this->records->find($id);
        if ($theme === null || !$this->mayEdit($actor, $theme)) {
            throw new ValidationFailed('That theme is not available.');
        }
        $read = PptxThemeReader::read($tmp, $originalName, $bytes);
        if (!$read['ok']) {
            throw new ValidationFailed(implode(' ', $read['errors']));
        }
        $version = $this->records->latestVersion($id) + 1;
        $this->storeVersion($actor, $id, $version, $tmp, $originalName, $bytes, $read);

        return ['theme' => (array) $this->records->find($id), 'warnings' => $read['warnings']];
    }

    /** Rename, publish/unpublish, or retire. */
    public function update(ActorContext $actor, int $id, array $input): array
    {
        $theme = $this->records->find($id);
        if ($theme === null || !$this->mayEdit($actor, $theme)) {
            throw new ValidationFailed('That theme is not available.');
        }
        $fields = [];
        if (isset($input['name']) && trim((string) $input['name']) !== '') {
            $fields['name'] = mb_substr(trim((string) $input['name']), 0, 120);
        }
        if (isset($input['scope'])) {
            $scope = (string) $input['scope'] === 'church' ? 'church' : 'private';
            if ($scope !== $theme['scope'] && !$actor->isPortalWideAdmin) {
                throw new PermissionDenied('Only a portal administrator can publish a theme to the whole church.');
            }
            $fields['scope'] = $scope;
        }
        if (isset($input['status'])) {
            $fields['status'] = (string) $input['status'] === 'archived' ? 'archived' : 'active';
        }
        $this->records->update($id, $fields);

        return (array) $this->records->find($id);
    }

    /**
     * A theme version to render, if this actor may see the theme and it is
     * not retired. Null means: print with a built-in theme and say so.
     *
     * @return array<string,mixed>|null
     */
    public function openVersion(ActorContext $actor, int $id, int $version): ?array
    {
        $theme = $this->records->find($id);
        if ($theme === null || $theme['status'] !== 'active' || !$this->maySee($actor, $theme)) {
            return null;
        }
        $v = $this->records->version($id, $version > 0 ? $version : $theme['version']);
        if ($v === null || $v['model'] === null) {
            return null;
        }

        return $v + ['name' => $theme['name'], 'current' => $theme['version']];
    }

    /** Path of a stored file of a version, if this actor may see it. */
    public function filePath(ActorContext $actor, int $id, int $version, string $name): ?string
    {
        if (preg_match('/^([a-f0-9]{16}\.(png|jpg)|thumb\.png)$/', $name) !== 1) {
            return null;
        }
        $theme = $this->records->find($id);
        if ($theme === null || !$this->maySee($actor, $theme)) {
            return null;
        }
        $path = $this->versionDir($id, $version) . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /** @param array<string,mixed> $read the PptxThemeReader result */
    private function storeVersion(ActorContext $actor, int $id, int $version, string $tmp, string $originalName, int $bytes, array $read): void
    {
        $dir = $this->versionDir($id, $version);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('The theme store could not be created.');
        }
        if (!@copy($tmp, $dir . '/original.pptx')) {
            throw new \RuntimeException('The presentation could not be saved.');
        }
        foreach ($read['assets'] as $key => $asset) {
            file_put_contents($dir . '/' . $key . '.' . $asset['ext'], $asset['data'], LOCK_EX);
        }
        $model = $read['model'];
        foreach ($model['elements'] as &$el) {
            foreach (['asset'] as $k) {
                if (isset($el[$k])) {
                    $el[$k] .= '.' . $read['assets'][$el[$k]]['ext'];
                }
            }
            if (isset($el['fill']['asset'])) {
                $el['fill']['asset'] .= '.' . $read['assets'][$el['fill']['asset']]['ext'];
            }
        }
        unset($el);
        if (isset($model['background']['asset'])) {
            $model['background']['asset'] .= '.' . $read['assets'][$model['background']['asset']]['ext'];
        }
        PptxThemeRenderer::thumbnail($model, $dir, $dir . '/thumb.png');
        $this->records->addVersion($id, $version, $actor->actorId, mb_substr(basename($originalName), 0, 190), $bytes,
            (string) hash_file('sha256', $tmp), (string) $model['page']['paper'], (string) $model['page']['orientation'],
            $model, $read['warnings']);
    }

    private function versionDir(int $id, int $version): string
    {
        return rtrim($this->dir, '/') . '/' . $id . '/v' . $version;
    }

    /** @param array<string,mixed> $theme */
    private function maySee(ActorContext $actor, array $theme): bool
    {
        return $actor->actorId > 0 && ($theme['scope'] === 'church' || $theme['accountId'] === $actor->actorId
            || $actor->isPortalWideAdmin);
    }

    /** @param array<string,mixed> $theme */
    private function mayEdit(ActorContext $actor, array $theme): bool
    {
        return $this->mayManage($actor) && ($theme['accountId'] === $actor->actorId || $actor->isPortalWideAdmin);
    }
}
