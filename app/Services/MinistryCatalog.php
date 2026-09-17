<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Canonical serving-ministry names parsed from Hub roster cells.
 *
 * Hub worksheets store comma-separated tags. "GS: Usher" is Guest Services
 * with role Usher — the part after the colon is a role, not a ministry.
 * G&A is Gifts and Arrows.
 */
final class MinistryCatalog
{
    /**
     * @param array{
     *   serving:list<array{name:string,description?:string,aliases?:list<string>,roles?:list<string>}>,
     *   compounds:list<array{phrase:string,names:list<string>}>,
     *   deactivate:list<array{name:string,reason?:string}>
     * } $config
     */
    public function __construct(private readonly array $config) {}

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException('Ministry catalog is missing or invalid: ' . $path);
        }
        return new self($decoded);
    }

    /**
     * @return list<string>
     */
    public function servingNames(): array
    {
        $out = [];
        foreach ($this->serving() as $row) {
            $out[] = $row['name'];
        }
        return $out;
    }

    /**
     * @return list<array{name:string,description:string,aliases:list<string>,roles:list<string>}>
     */
    public function serving(): array
    {
        $out = [];
        foreach ($this->config['serving'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $aliases = [];
            foreach ($row['aliases'] ?? [] as $alias) {
                $alias = trim((string) $alias);
                if ($alias !== '') {
                    $aliases[] = $alias;
                }
            }
            $roles = [];
            foreach ($row['roles'] ?? [] as $role) {
                $role = trim((string) $role);
                if ($role !== '') {
                    $roles[] = $role;
                }
            }
            $out[] = [
                'name' => $name,
                'description' => trim((string) ($row['description'] ?? '')),
                'aliases' => $aliases,
                'roles' => $roles,
            ];
        }
        return $out;
    }

    /**
     * @return list<array{name:string,reason:string}>
     */
    public function deactivate(): array
    {
        $out = [];
        foreach ($this->config['deactivate'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'reason' => trim((string) ($row['reason'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Unique canonical ministry names from a Hub cell.
     *
     * @return list<string>
     */
    public function parseHubCell(string $raw): array
    {
        return $this->parseHubAssignments($raw)['ministries'];
    }

    /**
     * @return array{ministries:list<string>,roles:array<string,list<string>>}
     */
    public function parseHubAssignments(string $raw): array
    {
        $text = trim($raw);
        if ($text === '') {
            return ['ministries' => [], 'roles' => []];
        }

        $ministries = [];
        $roles = [];
        $addMinistry = function (string $name) use (&$ministries): void {
            if ($name !== '' && !in_array($name, $ministries, true)) {
                $ministries[] = $name;
            }
        };
        $addRole = function (string $ministry, string $roleName) use (&$roles, $addMinistry): void {
            $addMinistry($ministry);
            $canonicalRole = $this->canonicalizeRole($ministry, $roleName);
            if ($canonicalRole === null) {
                return;
            }
            $roles[$ministry] ??= [];
            if (!in_array($canonicalRole, $roles[$ministry], true)) {
                $roles[$ministry][] = $canonicalRole;
            }
        };

        $gsName = $this->ministryWithRoles()['Guest Services']['name'] ?? 'Guest Services';

        // "GS: Usher", "GS: Emcee", "GS: Usher and Emcee" — colon introduces roles.
        $text = preg_replace_callback('/\bGS:\s*([^,]+)/i', function (array $m) use ($addRole, $gsName): string {
            foreach ($this->splitRoleList($m[1]) as $role) {
                $addRole($gsName, $role);
            }
            return $gsName;
        }, $text) ?? $text;

        foreach ($this->compounds() as $compound) {
            $text = preg_replace(
                '/' . preg_quote($compound['phrase'], '/') . '/i',
                implode(', ', $compound['names']),
                $text,
            ) ?? $text;
        }

        foreach (preg_split('/\s*,\s*/', $text) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $asRole = $this->roleOnlyToken($token);
            if ($asRole !== null) {
                $addRole($asRole['ministry'], $asRole['role']);
                continue;
            }
            $canonical = $this->canonicalize($token);
            if ($canonical !== null) {
                $addMinistry($canonical);
            }
        }

        return ['ministries' => $ministries, 'roles' => $roles];
    }

    public function canonicalize(string $token): ?string
    {
        $key = $this->key($token);
        if ($key === '') {
            return null;
        }
        return $this->aliasMap()[$key] ?? null;
    }

    /**
     * Match an existing group_grp name to a catalog serving ministry.
     *
     * @return array{name:string,description:string,aliases:list<string>,roles:list<string>}|null
     */
    public function matchServing(string $groupName): ?array
    {
        $want = $this->canonicalize($groupName);
        if ($want === null) {
            return null;
        }
        foreach ($this->serving() as $row) {
            if ($row['name'] === $want) {
                return $row;
            }
        }
        return null;
    }

    public function shouldDeactivate(string $groupName): bool
    {
        $key = $this->key($groupName);
        foreach ($this->deactivate() as $row) {
            if ($this->key($row['name']) === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function splitRoleList(string $raw): array
    {
        $parts = preg_split('/\s+and\s+|\s*&\s*/i', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * Bare "Usher" / "Emcee" (no GS: prefix) still means Guest Services roles.
     *
     * @return array{ministry:string,role:string}|null
     */
    private function roleOnlyToken(string $token): ?array
    {
        foreach ($this->serving() as $row) {
            if ($row['roles'] === []) {
                continue;
            }
            foreach ($row['roles'] as $role) {
                if ($this->key($token) === $this->key($role)) {
                    return ['ministry' => $row['name'], 'role' => $role];
                }
            }
        }
        return null;
    }

    private function canonicalizeRole(string $ministry, string $roleName): ?string
    {
        foreach ($this->serving() as $row) {
            if ($row['name'] !== $ministry) {
                continue;
            }
            foreach ($row['roles'] as $role) {
                if ($this->key($role) === $this->key($roleName)) {
                    return $role;
                }
            }
        }
        return null;
    }

    /**
     * @return array<string, array{name:string,description:string,aliases:list<string>,roles:list<string>}>
     */
    private function ministryWithRoles(): array
    {
        $out = [];
        foreach ($this->serving() as $row) {
            if ($row['roles'] !== []) {
                $out[$row['name']] = $row;
            }
        }
        return $out;
    }

    /**
     * @return list<array{phrase:string,names:list<string>}>
     */
    private function compounds(): array
    {
        $rows = $this->config['compounds'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        usort($rows, static fn ($a, $b): int => mb_strlen((string) ($b['phrase'] ?? '')) <=> mb_strlen((string) ($a['phrase'] ?? '')));
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $phrase = trim((string) ($row['phrase'] ?? ''));
            $names = [];
            foreach ($row['names'] ?? [] as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            if ($phrase !== '' && $names !== []) {
                $out[] = ['phrase' => $phrase, 'names' => $names];
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private function aliasMap(): array
    {
        $map = [];
        foreach ($this->serving() as $row) {
            $map[$this->key($row['name'])] = $row['name'];
            foreach ($row['aliases'] as $alias) {
                $map[$this->key($alias)] = $row['name'];
            }
        }
        return $map;
    }

    public function key(string $value): string
    {
        $s = strtolower(trim($value));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9&]+/', '', $s) ?? $s;
        return $s;
    }
}
