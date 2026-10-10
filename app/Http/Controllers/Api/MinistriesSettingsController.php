<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\PermissionDenied;
use App\Http\Requests\PortalRequestContext;
use App\Services\MinistriesSettingsService;

final readonly class MinistriesSettingsController
{
    public function __construct(private MinistriesSettingsService $svc, private PortalRequestContext $requestContext) {}

    /** @param array<string,mixed> $request */
    public function show(array $request): array
    {
        return $this->svc->load();
    }

    /** @param array<string,mixed> $request */
    public function update(array $request): array
    {
        $this->requireAdmin($request);
        $errors = [];

        // pages
        $pages = $request['pages'] ?? [];
        if (!is_array($pages)) {
            $errors['pages'] = 'Pages must be an object with boolean flags.';
        } else {
            foreach (['board', 'directory'] as $k) {
                if (isset($pages[$k]) && !is_bool($pages[$k])) {
                    $errors["pages.$k"] = 'Must be a boolean.';
                }
            }
        }

        // Excludes: allow list of strings
        foreach (['excluded_ministries', 'excluded_groups'] as $field) {
            if (isset($request[$field]) && !is_array($request[$field])) {
                $errors[$field] = 'Must be a comma-separated list or array of names/IDs.';
            } else {
                $values = $request[$field] ?? [];
                foreach ($values as $i => $v) {
                    if (!is_scalar($v) && $v !== null) {
                        $errors["$field.$i"] = 'Invalid value.';
                    } elseif (is_string($v) && mb_strlen($v) > 200) {
                        $errors["$field.$i"] = 'Value too long (max 200 chars).';
                    }
                }
            }
        }

        // Title/subtitle/hero lengths
        if (isset($request['title']) && !is_string($request['title'])) {
            $errors['title'] = 'Must be a string.';
        } elseif (isset($request['title']) && mb_strlen($request['title']) > 255) {
            $errors['title'] = 'Title must be 255 characters or less.';
        }
        if (isset($request['subtitle']) && !is_string($request['subtitle'])) {
            $errors['subtitle'] = 'Must be a string.';
        } elseif (isset($request['subtitle']) && mb_strlen($request['subtitle']) > 255) {
            $errors['subtitle'] = 'Subtitle must be 255 characters or less.';
        }
        if (isset($request['hero_lead']) && !is_string($request['hero_lead'])) {
            $errors['hero_lead'] = 'Must be a string.';
        } elseif (isset($request['hero_lead']) && mb_strlen($request['hero_lead']) > 2000) {
            $errors['hero_lead'] = 'Hero lead must be 2000 characters or less.';
        }

        if ($errors !== []) {
            throw new \App\Exceptions\ValidationFailed('Invalid ministries settings', $errors);
        }

        return $this->svc->save($request);
    }

    private function requireAdmin(array $request): void
    {
        $actor = $this->requestContext->fromArray($request);
        if (!$actor->isPortalWideAdmin) {
            throw new PermissionDenied('Ministries settings can only be edited by a portal-wide admin.');
        }
    }
}
