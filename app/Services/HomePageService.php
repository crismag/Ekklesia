<?php

declare(strict_types=1);

namespace App\Services;

use App\Providers\PortalServiceProvider;

/**
 * Composes the public church home page.
 *
 * Home is the main visiting page for everyone — not a signed-in dashboard
 * that happens to have a guest fallback. The composition has two layers:
 *
 *   1. Church life (always): identity, hero, announcements, upcoming events,
 *      active ministries. These are what a visitor, member, or leader should
 *      all be able to see without signing in.
 *   2. Member overlay (the view, not this service): personal assignments and
 *      leader jump-ins. Those stay on Home so a signed-in person is not sent
 *      hunting, but they never replace layer 1.
 *
 * This service holds no SQL. Each feed is independently guarded so a down
 * events database still leaves announcements and church info on the page.
 */
final class HomePageService
{
    public const EVENT_LIMIT = 8;
    public const MINISTRY_LIMIT = 12;

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
     * @return array{
     *   church:array<string,string>,
     *   addressLine:string,
     *   hero:array<string,mixed>,
     *   announcements:list<array<string,mixed>>,
     *   events:list<array<string,mixed>>,
     *   ministries:list<array<string,mixed>>
     * }
     */
    public function build(?int $campusId = null): array
    {
        $church = $this->attempt(static fn () => PortalServiceProvider::makeChurchInfoService()->load(), []);
        $hero = $this->attempt(static fn () => PortalServiceProvider::makeHeroSettingsService()->load(), []);
        $announcements = $this->attempt(static fn () => PortalServiceProvider::makeAnnouncementSettingsService()->publishedNow(), []);
        $events = $this->attempt(static function () {
            return array_map(
                static fn ($e): array => $e->toArray(),
                PortalServiceProvider::makeEventService()->listUpcomingPublic(self::EVENT_LIMIT),
            );
        }, []);
        $ministries = $this->attempt(static function () use ($campusId) {
            return PortalServiceProvider::makeMinistryService()->listMinistriesPublic($campusId);
        }, []);

        return self::compose([
            'church' => is_array($church) ? $church : [],
            'hero' => is_array($hero) ? $hero : [],
            'announcements' => is_array($announcements) ? $announcements : [],
            'events' => is_array($events) ? $events : [],
            'ministries' => is_array($ministries) ? $ministries : [],
        ]);
    }

    /**
     * @param array<string,mixed> $parts
     * @return array{
     *   church:array<string,string>,
     *   addressLine:string,
     *   hero:array<string,mixed>,
     *   announcements:list<array<string,mixed>>,
     *   events:list<array<string,mixed>>,
     *   ministries:list<array<string,mixed>>
     * }
     */
    public static function compose(array $parts): array
    {
        $churchIn = is_array($parts['church'] ?? null) ? $parts['church'] : [];
        $church = [];
        foreach (['name', 'website', 'phone', 'email', 'address', 'city', 'state', 'zip', 'country'] as $field) {
            $church[$field] = trim((string) ($churchIn[$field] ?? ''));
        }
        if ($church['name'] === '') {
            $church['name'] = 'Church Portal';
        }

        $hero = is_array($parts['hero'] ?? null) ? $parts['hero'] : [];
        $slides = is_array($hero['slides'] ?? null) ? $hero['slides'] : [];
        $behavior = is_array($hero['behavior'] ?? null) ? $hero['behavior'] : [];

        $announcements = [];
        foreach (is_array($parts['announcements'] ?? null) ? $parts['announcements'] : [] as $item) {
            if (!is_array($item) || trim((string) ($item['title'] ?? '')) === '') {
                continue;
            }
            $announcements[] = $item;
        }

        $events = [];
        foreach (is_array($parts['events'] ?? null) ? $parts['events'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $events[] = $item;
            if (count($events) >= self::EVENT_LIMIT) {
                break;
            }
        }

        $ministries = [];
        foreach (is_array($parts['ministries'] ?? null) ? $parts['ministries'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['ministry_id'] ?? $item['ministryId'] ?? 0);
            $name = trim((string) ($item['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $campus = $item['campus_id'] ?? $item['campusId'] ?? null;
            $ministries[] = [
                'ministry_id' => $id,
                'name' => $name,
                'campus_id' => $campus === null || $campus === '' ? null : (int) $campus,
            ];
        }
        usort($ministries, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $ministries = array_slice($ministries, 0, self::MINISTRY_LIMIT);

        return [
            'church' => $church,
            'addressLine' => self::formatAddress($church),
            'hero' => [
                'behavior' => $behavior,
                'slides' => $slides,
            ],
            'announcements' => $announcements,
            'events' => $events,
            'ministries' => $ministries,
        ];
    }

    /**
     * @return array{
     *   church:array<string,string>,
     *   addressLine:string,
     *   hero:array<string,mixed>,
     *   announcements:list<array<string,mixed>>,
     *   events:list<array<string,mixed>>,
     *   ministries:list<array<string,mixed>>
     * }
     */
    public static function empty(): array
    {
        return self::compose([]);
    }

    /** @param array<string,string> $church */
    private static function formatAddress(array $church): string
    {
        $line1 = trim($church['address'] ?? '');
        $cityBits = array_values(array_filter([
            trim($church['city'] ?? ''),
            trim($church['state'] ?? ''),
            trim($church['zip'] ?? ''),
        ], static fn (string $p): bool => $p !== ''));
        $line2 = implode(', ', $cityBits);
        if ($line1 !== '' && $line2 !== '') {
            return $line1 . ', ' . $line2;
        }
        return $line1 !== '' ? $line1 : $line2;
    }
}
