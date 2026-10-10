<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Http\Requests\PortalRequestContext;
use App\Services\Calendar\PrintThemeService;

/**
 * PowerPoint calendar themes over HTTP. Rules live in PrintThemeService;
 * reading the presentation lives in PptxThemeReader.
 */
final class PrintThemeController
{
    /** The official starters, by the name used in their download URL. */
    public const STARTERS = [
        'letter-portrait' => 'Letter portrait',
        'a4-portrait' => 'A4 portrait',
        'letter-landscape' => 'Letter landscape',
        'tabloid-portrait' => 'Tabloid portrait (11 × 17)',
    ];

    public function __construct(
        private readonly PrintThemeService $themes,
        private readonly PortalRequestContext $requestContext,
        private readonly string $startersDir,
    ) {
    }

    public function index(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);

        return [
            'themes' => array_map(self::public(...), $this->themes->listFor($actor)),
            'canUpload' => $this->themes->mayManage($actor),
            'starters' => self::STARTERS,
        ];
    }

    public function store(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $file = $this->file();
        $result = $this->themes->upload($actor, (string) $file['tmp_name'], (string) ($file['name'] ?? 'theme.pptx'),
            (int) ($file['size'] ?? 0), (string) ($request['name'] ?? ''));

        return ['theme' => self::public($result['theme'] + ['canEdit' => true, 'canPublish' => $actor->isPortalWideAdmin]),
            'warnings' => $result['warnings']];
    }

    public function replace(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $file = $this->file();
        $result = $this->themes->replace($actor, (int) ($request['id'] ?? 0), (string) $file['tmp_name'],
            (string) ($file['name'] ?? 'theme.pptx'), (int) ($file['size'] ?? 0));

        return ['theme' => self::public($result['theme'] + ['canEdit' => true, 'canPublish' => $actor->isPortalWideAdmin]),
            'warnings' => $result['warnings']];
    }

    public function update(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $theme = $this->themes->update($actor, (int) ($request['id'] ?? 0), $request);

        return ['theme' => self::public($theme + ['canEdit' => true, 'canPublish' => $actor->isPortalWideAdmin])];
    }

    /** A theme's picture or thumbnail. Written straight out; missing is a 404. */
    public function serve(array $request): string
    {
        $path = $this->themes->filePath($this->requestContext->fromArray($request), (int) ($request['id'] ?? 0),
            (int) ($request['version'] ?? 0), (string) ($request['file'] ?? ''));
        if ($path === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return '';
        }
        header('Content-Type: ' . (str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg'));
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($path);

        return '';
    }

    /** An official starter .pptx, for anyone signed in. */
    public function starter(array $request): string
    {
        $actor = $this->requestContext->fromArray($request);
        if ($actor->actorId <= 0) {
            throw new PermissionDenied('Sign in to download a theme starter.');
        }
        $variant = (string) ($request['variant'] ?? '');
        $path = rtrim($this->startersDir, '/') . '/ekklesia-calendar-theme-' . $variant . '.pptx';
        if (!isset(self::STARTERS[$variant]) || !is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return '';
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
        header('Content-Disposition: attachment; filename="ekklesia-calendar-theme-' . $variant . '.pptx"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);

        return '';
    }

    /** @return array<string,mixed> */
    private function file(): array
    {
        $file = $_FILES['pptx'] ?? null;
        if (!is_array($file)) {
            throw new ValidationFailed('Choose a PowerPoint (.pptx) file to upload.');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationFailed('That presentation is larger than this server accepts ('
                . ini_get('upload_max_filesize') . '). Use smaller pictures and save again.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new ValidationFailed('The upload did not arrive. Try again.');
        }

        return $file;
    }

    /** @param array<string,mixed> $t @return array<string,mixed> */
    private static function public(array $t): array
    {
        return [
            'id' => $t['id'], 'name' => $t['name'], 'scope' => $t['scope'], 'version' => $t['version'],
            'paper' => $t['paper'], 'orientation' => $t['orientation'], 'creator' => $t['creator'],
            'updatedAt' => $t['updatedAt'], 'warnings' => $t['warnings'],
            'canEdit' => (bool) ($t['canEdit'] ?? false), 'canPublish' => (bool) ($t['canPublish'] ?? false),
        ];
    }
}
