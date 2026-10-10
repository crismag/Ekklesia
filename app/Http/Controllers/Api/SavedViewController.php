<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\PortalRequestContext;
use App\Exceptions\ValidationFailed;
use App\Services\Calendar\PrintConfig;
use App\Services\Calendar\SavedViewService;

/**
 * Saved calendar views over HTTP.
 *
 * Thin on purpose: every rule about who may open, edit or share a view lives in
 * SavedViewService, so there is one place to read and one place to get right.
 * This translates requests into calls and back.
 */
final class SavedViewController
{
    public function __construct(
        private readonly SavedViewService $views,
        private readonly PortalRequestContext $requestContext,
    ) {
    }

    /** Views this actor may open, with whether they may edit each. */
    public function index(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);

        return ['views' => array_map(
            static fn (array $v): array => [
                'id' => $v['id'],
                'name' => $v['name'],
                'visibility' => $v['visibility'],
                'mine' => $v['mine'],
                'canEdit' => $v['canEdit'],
                'updatedAt' => $v['updatedAt'],
            ],
            $this->views->listFor($actor),
        )];
    }

    /** One view's configuration, ready to load into the studio. */
    public function show(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $row = $this->views->open($actor, (int) ($request['id'] ?? 0));
        if ($row === null) {
            // A view somebody else keeps private reports as missing rather than
            // forbidden: the other answer is a way to enumerate ids.
            throw new ValidationFailed('That view is not available.');
        }

        $config = $this->views->configOf($row);

        return [
            'view' => [
                'id' => $row['id'],
                'name' => $row['name'],
                'visibility' => $row['visibility'],
                'mine' => $row['mine'],
                'canEdit' => $row['canEdit'],
                'config' => $config->toArray(),
                'screen' => $this->views->screenOf($config),
            ],
        ];
    }

    public function store(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = $this->views->create(
            $actor,
            (string) ($request['name'] ?? ''),
            (string) ($request['visibility'] ?? 'private'),
            $this->configFrom($request),
        );

        return ['ok' => true, 'id' => $id];
    }

    public function update(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        $this->views->update(
            $actor,
            $id,
            (string) ($request['name'] ?? ''),
            (string) ($request['visibility'] ?? 'private'),
            $this->configFrom($request),
        );

        return ['ok' => true, 'id' => $id];
    }

    public function destroy(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        $this->views->delete($actor, $id);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * The configuration from the request body.
     *
     * Always through PrintConfig, never stored raw: that is where every value
     * is bounded, every colour checked and every editorial note sanitised. A
     * path that skipped it would put unchecked settings into a row that other
     * people later open.
     *
     * @param array<string,mixed> $request
     */
    private function configFrom(array $request): PrintConfig
    {
        $raw = $request['config'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            throw new ValidationFailed('A view needs a configuration.');
        }

        return PrintConfig::fromArray($raw);
    }
}
