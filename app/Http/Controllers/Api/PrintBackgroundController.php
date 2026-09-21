<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ValidationFailed;
use App\Http\Requests\PortalRequestContext;
use App\Services\Calendar\PrintBackgroundService;

/**
 * Background pictures for the print studio, over HTTP. Every rule about who
 * may add, see or remove one lives in PrintBackgroundService.
 */
final class PrintBackgroundController
{
    public function __construct(
        private readonly PrintBackgroundService $backgrounds,
        private readonly PortalRequestContext $requestContext,
    ) {
    }

    public function index(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);

        return ['backgrounds' => array_map(self::public(...), $this->backgrounds->listFor($actor))];
    }

    /** Multipart upload, field "picture". */
    public function store(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $file = $_FILES['picture'] ?? null;
        if (!is_array($file)) {
            throw new ValidationFailed('Choose a picture to upload.');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationFailed('That picture is larger than this server accepts ('
                . ini_get('upload_max_filesize') . '). Save a smaller copy and try again.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new ValidationFailed('The upload did not arrive. Try again.');
        }
        $row = $this->backgrounds->upload(
            $actor, (string) $file['tmp_name'], (string) ($file['name'] ?? 'picture'),
            (int) ($file['size'] ?? 0), (string) ($request['label'] ?? ''),
        );

        return ['background' => self::public($row + ['mine' => true, 'canDelete' => true])];
    }

    public function destroy(array $request): array
    {
        $this->backgrounds->delete($this->requestContext->fromArray($request), (int) ($request['id'] ?? 0));

        return ['deleted' => true];
    }

    /**
     * The picture itself. Written straight out, so the router's HTML content
     * type never applies; a picture this viewer may not see is a plain 404.
     */
    public function serve(array $request): string
    {
        $opened = $this->backgrounds->open($this->requestContext->fromArray($request), (int) ($request['id'] ?? 0));
        if ($opened === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return '';
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($opened['path']));
        // Private: a background may belong to a private design.
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($opened['path']);

        return '';
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function public(array $r): array
    {
        return [
            'id' => $r['id'], 'label' => $r['label'], 'width' => $r['width'], 'height' => $r['height'],
            'mine' => (bool) ($r['mine'] ?? false), 'canDelete' => (bool) ($r['canDelete'] ?? false),
        ];
    }
}
