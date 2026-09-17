<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationFailed;
use DateTimeImmutable;
use RuntimeException;

/**
 * Church-wide announcements shown on the portal home page.
 *
 * Persisted as JSON at config/announcements.json (same pattern as hero.json).
 * Leaders/admins author posts in /admin/announcements; the dashboard only
 * reads published, in-window items. There is no member shout-out or message
 * board — those need moderation and a different product, so they stay off
 * the home page until they exist.
 *
 * Schema:
 *   items[]  id, title, body, tag, published, startsOn (Y-m-d|""), endsOn (Y-m-d|"")
 */
final class AnnouncementSettingsService
{
    private const MAX_ITEMS = 24;
    private const TAGS = ['Church', 'Campus', 'Ministry', 'Prayer', 'Serve'];

    public function __construct(private readonly string $configPath) {}

    /**
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,published:bool,startsOn:string,endsOn:string}>}
     */
    public function load(): array
    {
        if (!is_file($this->configPath)) {
            return $this->defaults();
        }
        $raw = file_get_contents($this->configPath);
        if ($raw === false || $raw === '') {
            return $this->defaults();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->defaults();
        }
        return $this->normalize($decoded);
    }

    /**
     * Published items whose date window includes $today (America/Toronto by default).
     *
     * @return list<array{id:string,title:string,body:string,tag:string,published:bool,startsOn:string,endsOn:string}>
     */
    public function publishedNow(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $day = $today->format('Y-m-d');
        $out = [];
        foreach ($this->load()['items'] as $item) {
            if (!$item['published']) {
                continue;
            }
            if ($item['startsOn'] !== '' && $item['startsOn'] > $day) {
                continue;
            }
            if ($item['endsOn'] !== '' && $item['endsOn'] < $day) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,published:bool,startsOn:string,endsOn:string}>}
     */
    public function save(array $payload): array
    {
        $clean = $this->validate($payload);
        $encoded = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode announcements as JSON.');
        }
        $dir = dirname($this->configPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create config directory: $dir");
        }
        $tmp = tempnam($dir, 'ann-');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create temporary file for announcements.');
        }
        if (file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write announcements tempfile.');
        }
        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to atomically replace announcements.');
        }
        @chmod($this->configPath, 0664);
        return $clean;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,published:bool,startsOn:string,endsOn:string}>}
     */
    private function validate(array $payload): array
    {
        $itemsIn = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (count($itemsIn) > self::MAX_ITEMS) {
            throw new ValidationFailed(sprintf('At most %d announcements are allowed.', self::MAX_ITEMS));
        }

        $clean = [];
        $seen = [];
        foreach ($itemsIn as $i => $item) {
            if (!is_array($item)) {
                throw new ValidationFailed('Announcement #' . ($i + 1) . ' must be an object.');
            }
            $title = trim((string) ($item['title'] ?? ''));
            $body = trim((string) ($item['body'] ?? ''));
            if ($title === '') {
                throw new ValidationFailed('Announcement #' . ($i + 1) . ' needs a title.');
            }
            if ($body === '') {
                throw new ValidationFailed('Announcement #' . ($i + 1) . ' needs a body.');
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $id)) {
                $id = 'a' . bin2hex(random_bytes(6));
            }
            if (isset($seen[$id])) {
                $id = 'a' . bin2hex(random_bytes(6));
            }
            $seen[$id] = true;

            $tag = trim((string) ($item['tag'] ?? 'Church'));
            if (!in_array($tag, self::TAGS, true)) {
                $tag = 'Church';
            }

            $startsOn = $this->coerceDate($item['startsOn'] ?? '');
            $endsOn = $this->coerceDate($item['endsOn'] ?? '');
            if ($startsOn !== '' && $endsOn !== '' && $endsOn < $startsOn) {
                throw new ValidationFailed('Announcement #' . ($i + 1) . ' ends before it starts.');
            }

            $clean[] = [
                'id' => $id,
                'title' => mb_substr($title, 0, 140),
                'body' => mb_substr($body, 0, 800),
                'tag' => $tag,
                'published' => $this->coerceBool($item['published'] ?? true),
                'startsOn' => $startsOn,
                'endsOn' => $endsOn,
            ];
        }

        return ['items' => $clean];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,published:bool,startsOn:string,endsOn:string}>}
     */
    private function normalize(array $decoded): array
    {
        try {
            return $this->validate($decoded);
        } catch (ValidationFailed) {
            return $this->defaults();
        }
    }

    /**
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,published:bool,startsOn:string,endsOn:string}>}
     */
    private function defaults(): array
    {
        return ['items' => []];
    }

    private function coerceDate(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new ValidationFailed('Announcement dates must use YYYY-MM-DD.');
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            throw new ValidationFailed('Announcement date is not a real calendar day.');
        }
        return $raw;
    }

    private function coerceBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }
}
