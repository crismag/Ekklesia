<?php

declare(strict_types=1);

namespace App\Services;

use App\Providers\PortalServiceProvider;

/**
 * Assembles the admin control board.
 *
 * Composes existing services and reads the settings files; it holds no SQL of
 * its own, so the service layer stays source-agnostic. Every metric is
 * individually guarded: an admin landing page must degrade to "unavailable" for
 * one figure rather than fail whole because a single query threw.
 */
final class AdminDashboardService
{
    /** Configuration files that are edited through the portal. */
    private const CONFIG_FILES = [
        'theme.json' => ['label' => 'Theme presets', 'route' => '/admin/theme'],
        'hero.json' => ['label' => 'Dashboard hero', 'route' => '/admin/hero'],
        'chrome.json' => ['label' => 'Header & footer', 'route' => '/admin/header'],
        'church-info.json' => ['label' => 'Church information', 'route' => '/admin/church-info'],
        'ministries.json' => ['label' => 'Ministry catalog', 'route' => '/admin/ministries'],
    ];

    /** @param callable():mixed $fn */
    private function attempt(callable $fn, mixed $fallback = null): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function build(string $basePath): array
    {
        $person = $this->attempt(static fn () => PortalServiceProvider::makePersonAdminService()->stats(), []);
        $family = $this->attempt(static fn () => PortalServiceProvider::makeFamilyAdminService()->stats(), []);
        $campus = $this->attempt(static fn () => PortalServiceProvider::makeCampusAdminService()->stats(), []);
        $users = $this->attempt(static fn () => PortalServiceProvider::makeSystemUserService()->list(), []);
        // duplicateGroupCount() counts families sharing an identical fam_Name,
        // which for ChurchCRM is essentially the surname — so it flags surname
        // collision, not duplication. Investigated against this data: all 37
        // flagged groups had disjoint member sets, no shared address and no
        // shared email. Reporting that as "37 possible duplicates" sends an
        // administrator to a 12,000px review page to find nothing wrong.
        //
        // The dashboard therefore counts only groups with corroborating
        // evidence, and keeps the raw figure as context rather than as an alarm.
        $dupSignal = $this->attempt(fn () => $this->duplicateSignal(), ['candidates' => null, 'sameName' => null]);
        $addressGroups = $this->attempt(static fn () => PortalServiceProvider::makeFamilyAdminService()->addressGroupCount(), null);
        $ministries = $this->attempt(static fn () => count(PortalServiceProvider::makeMinistryService()->listMinistriesPublic(null)), null);
        $store = $this->attempt(static fn () => PortalServiceProvider::makeMaintenanceBackupService()->store(), null);
        $archives = $store !== null ? $this->attempt(static fn () => $store->listRecent(5), []) : [];
        $archivesReadable = $store !== null ? $this->attempt(static fn () => $store->isReadable(), false) : false;
        $batches = $this->attempt(static fn () => PortalServiceProvider::makeMemberCampusImportService()->batches(10), []);
        $theme = $this->attempt(static fn () => PortalServiceProvider::makeThemeSettingsService()->loadActivePreset(), []);
        $hero = $this->attempt(static fn () => PortalServiceProvider::makeHeroSettingsService()->load(), []);

        $users = is_array($users) ? $users : [];

        return [
            'metrics' => $this->metrics($basePath, $person, $family, $campus, $users, $ministries),
            'attention' => $this->attention($basePath, $person, $campus, $users, $dupSignal, $archives, $batches, (bool) $archivesReadable),
            'breakdown' => $this->breakdown($person),
            'configFiles' => $this->configFiles($basePath),
            'settings' => [
                'theme' => $theme['name'] ?? ($theme['id'] ?? 'unknown'),
                'heroSlides' => is_array($hero['slides'] ?? null) ? count($hero['slides']) : null,
                'addressGroups' => $addressGroups,
                'sameSurnameGroups' => $dupSignal['sameName'] ?? null,
            ],
            'archives' => is_array($archives) ? $archives : [],
            'environment' => $this->environment(),
        ];
    }

    /**
     * A same-surname group only counts as a duplicate candidate when something
     * else agrees: the same normalised street address, or the same email.
     * Member-name overlap is a stronger signal still, but needs a query per
     * family and this runs on a landing page.
     *
     * @return array{candidates:int,sameName:int}
     */
    private function duplicateSignal(): array
    {
        $groups = PortalServiceProvider::makeFamilyAdminService()->duplicateGroups();
        $normalise = static fn (mixed $v): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $v));

        $candidates = 0;
        foreach ($groups as $group) {
            $families = $group['families'] ?? [];
            $addresses = array_filter(array_map(static fn (array $f): string => $normalise($f['addr'] ?? ''), $families));
            $emails = array_filter(array_map(static fn (array $f): string => $normalise($f['email'] ?? ''), $families));

            $addressAgrees = count($addresses) === count($families) && count(array_unique($addresses)) === 1;
            $emailAgrees = count($emails) === count($families) && count(array_unique($emails)) === 1;

            if ($addressAgrees || $emailAgrees) {
                $candidates++;
            }
        }

        return ['candidates' => $candidates, 'sameName' => count($groups)];
    }

    /** @return list<array<string,mixed>> */
    private function metrics(string $b, array $person, array $family, array $campus, array $users, ?int $ministries): array
    {
        $active = count(array_filter($users, static fn (array $u): bool => !empty($u['is_active'])));

        return array_values(array_filter([
            ['label' => 'People', 'value' => $person['people'] ?? null, 'href' => $b . '/admin/people'],
            ['label' => 'Families', 'value' => $family['total'] ?? ($person['families'] ?? null), 'href' => $b . '/admin/families'],
            ['label' => 'Ministries', 'value' => $ministries, 'href' => $b . '/admin/ministries'],
            ['label' => 'Campuses', 'value' => $campus['count'] ?? null, 'href' => $b . '/admin/campuses'],
            ['label' => 'Portal accounts', 'value' => $users === [] ? null : count($users), 'href' => $b . '/admin/users'],
            ['label' => 'Active accounts', 'value' => $users === [] ? null : $active, 'href' => $b . '/admin/users'],
        ], static fn (array $m): bool => $m['value'] !== null));
    }

    /**
     * Signals an administrator can act on, worst first.
     *
     * This is the part that makes a dashboard worth opening: not "here are your
     * sections" but "here is what is wrong today".
     *
     * @return list<array<string,mixed>>
     */
    private function attention(string $b, array $person, array $campus, array $users, array $dupSignal, array $archives, array $batches, bool $archivesReadable): array
    {
        $out = [];

        $unclassified = 0;
        foreach (($person['classifications'] ?? []) as $c) {
            if (strtolower((string) ($c['name'] ?? '')) === 'unclassified') {
                $unclassified = (int) ($c['count'] ?? 0);
            }
        }
        $total = (int) ($person['people'] ?? 0);
        if ($unclassified > 0 && $total > 0) {
            $pct = (int) round($unclassified / $total * 100);
            $out[] = [
                'level' => $pct >= 50 ? 'high' : 'medium',
                'title' => "{$unclassified} of {$total} people are unclassified ({$pct}%)",
                'body' => 'Classification drives member type reporting and several filters. Bulk-classify from the people list.',
                'href' => $b . '/admin/people',
                'action' => 'Open People',
            ];
        }

        $dupCandidates = $dupSignal['candidates'] ?? null;
        if ($dupCandidates !== null && $dupCandidates > 0) {
            $out[] = [
                'level' => $dupCandidates >= 20 ? 'high' : 'medium',
                'title' => $dupCandidates === 1
                    ? '1 family group looks genuinely duplicated'
                    : "{$dupCandidates} family groups look genuinely duplicated",
                'body' => 'These share a surname AND a corroborating address or email, so they are worth merging.',
                'href' => $b . '/admin/families/duplicates',
                'action' => 'Review duplicates',
            ];
        }

        if (($campus['count'] ?? 0) > 1 && empty($campus['main'])) {
            $out[] = [
                'level' => 'medium',
                'title' => 'No main campus has been chosen',
                // Checked before writing this: is_main only drives list ordering
                // and a label — nothing fails without it. So this is a decision
                // waiting to be made, not a fault, and it is worded as one.
                'body' => 'Nothing breaks without one: it only orders campus lists today. '
                    . 'Choosing one is an administrator decision, not something the portal should guess.',
                'href' => $b . '/admin/campuses',
                'action' => 'Choose one',
            ];
        }

        $pendingPw = count(array_filter($users, static fn (array $u): bool => (int) ($u['must_change_password'] ?? 0) === 1));
        if ($pendingPw > 0) {
            $out[] = [
                'level' => 'low',
                'title' => $pendingPw === 1 ? '1 account must change its password' : "{$pendingPw} accounts must change their password",
                'body' => 'These accounts cannot use the portal until the password is changed.',
                'href' => $b . '/admin/users',
                'action' => 'Open accounts',
            ];
        }

        $neverIn = count(array_filter($users, static fn (array $u): bool => empty($u['last_login_at'])));
        if ($neverIn > 0) {
            $out[] = [
                'level' => 'low',
                'title' => $neverIn === 1 ? '1 account has never signed in' : "{$neverIn} accounts have never signed in",
                'body' => 'An account created but never used may mean the invitation never arrived.',
                'href' => $b . '/admin/users',
                'action' => 'Open accounts',
            ];
        }

        $staged = count(array_filter($batches, static fn (array $x): bool => (string) ($x['status'] ?? '') !== 'applied'));
        if ($staged > 0) {
            $out[] = [
                'level' => 'medium',
                'title' => $staged === 1 ? '1 member import waiting to be applied' : "{$staged} member imports waiting to be applied",
                'body' => 'Staged rows do not reach the directory until the batch is applied or discarded.',
                'href' => $b . '/admin/maintenance/import',
                'action' => 'Open import',
            ];
        }

        if (!$archivesReadable) {
            $out[] = [
                'level' => 'high',
                'title' => 'The backup archive cannot be read',
                'body' => 'storage/private is missing or not readable by the web process, so whether any backup '
                    . 'exists cannot be determined from here. Check ownership and permissions before trusting this page.',
                'href' => $b . '/admin/maintenance',
                'action' => 'Open maintenance',
            ];
        } elseif ($archives === []) {
            $out[] = [
                'level' => 'high',
                'title' => 'No backup has ever been taken',
                'body' => 'There is no archive in the private store. A backup is the only way back from a bad import or a bad merge.',
                'href' => $b . '/admin/maintenance',
                'action' => 'Run a backup',
            ];
        } else {
            $newest = 0;
            foreach ($archives as $a) {
                $newest = max($newest, (int) ($a['mtime'] ?? 0));
            }
            $days = $newest > 0 ? (int) floor((time() - $newest) / 86400) : null;
            if ($days !== null && $days >= 30) {
                $out[] = [
                    'level' => $days >= 90 ? 'high' : 'medium',
                    'title' => "The most recent backup is {$days} days old",
                    'body' => 'Backups are taken manually; nothing schedules them.',
                    'href' => $b . '/admin/maintenance',
                    'action' => 'Run a backup',
                ];
            }
        }

        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($out, static fn (array $x, array $y): int => ($rank[$x['level']] ?? 3) <=> ($rank[$y['level']] ?? 3));

        return $out;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function breakdown(array $person): array
    {
        $shape = static function (array $rows): array {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['count'] ?? 0) > 0));
            $max = 0;
            foreach ($rows as $r) {
                $max = max($max, (int) $r['count']);
            }
            foreach ($rows as $i => $r) {
                $rows[$i]['pct'] = $max > 0 ? (int) round((int) $r['count'] / $max * 100) : 0;
            }
            usort($rows, static fn (array $a, array $b): int => (int) $b['count'] <=> (int) $a['count']);

            return $rows;
        };

        return [
            'classifications' => $shape($person['classifications'] ?? []),
            'memberTypes' => $shape($person['memberTypes'] ?? []),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function configFiles(string $basePath): array
    {
        $root = dirname(__DIR__, 2) . '/config';
        $out = [];
        foreach (self::CONFIG_FILES as $file => $meta) {
            $path = $root . '/' . $file;
            $exists = is_file($path);
            $out[] = [
                'file' => $file,
                'label' => $meta['label'],
                'href' => $meta['route'] !== null ? $basePath . $meta['route'] : null,
                'exists' => $exists,
                'bytes' => $exists ? (int) filesize($path) : 0,
                'modified' => $exists ? (int) filemtime($path) : null,
                'writable' => $exists && is_writable($path),
            ];
        }

        return $out;
    }

    /** @return array<string,string> */
    private function environment(): array
    {
        $get = static fn (string $k): string => (string) ($_ENV[$k] ?? getenv($k) ?: '(unset)');

        return [
            'APP_ENV' => $get('APP_ENV'),
            'APP_DEBUG' => $get('APP_DEBUG'),
            'PORTAL_BASE_PATH' => $get('PORTAL_BASE_PATH'),
            'PORTAL_SOURCE_OF_TRUTH' => $get('PORTAL_SOURCE_OF_TRUTH'),
            'CHURCHCRM_DB' => $get('CHURCHCRM_DB_DATABASE'),
            'PHP' => PHP_VERSION,
        ];
    }
}
