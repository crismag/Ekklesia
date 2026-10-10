<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationFailed;
use DateTimeImmutable;
use RuntimeException;

/**
 * Portal notices: short operational messages for the people who use the portal
 * ("The directory is read-only on Saturday while we import", "New sign-in
 * address from 1 October"). Not church news or promotion — that belongs to the
 * church website.
 *
 * Persisted as JSON at config/announcements.json (the file keeps its old name so
 * existing installations carry their notices over). Administrators edit them in
 * Admin › Portal appearance & notices (/admin/announcements).
 *
 * Schema:
 *   items[]  id, title, body, tag, audience, published,
 *            startsOn (Y-m-d|""), endsOn (Y-m-d|"")
 *
 * audience, who a notice is for:
 *   'public'     anyone who opens the portal, signed in or not (the portal entry)
 *   'signed-in'  anyone signed in
 *   'admins'     portal-wide administrators
 * A notice written before audiences existed has none and is read as 'public',
 * because that is who saw it then. tag is kept for those older notices and is
 * no longer offered in the editor.
 *
 * Reading: activeNotices($actor) is the one method pages should use. It applies
 * published, the date window and the audience together, so a caller cannot show
 * an administrators' notice to a member by forgetting one of the three.
 */
final class AnnouncementSettingsService
{
    private const MAX_ITEMS = 24;
    private const TAGS = ['Church', 'Campus', 'Ministry', 'Prayer', 'Serve'];
    public const AUDIENCES = ['signed-in', 'admins', 'public'];

    public function __construct(private readonly string $configPath) {}

    /**
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,audience:string,published:bool,startsOn:string,endsOn:string}>}
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
     * Notices this reader may see today: published, inside their date window,
     * and meant for them. In the order the administrator arranged them.
     *
     * $actor is who is reading: null for someone not signed in, otherwise an
     * ActorContext or the portal's actor array (the 'isPortalWideAdmin' key is
     * the only one read).
     *
     * @param \App\Core\ActorContext|array<string,mixed>|null $actor
     * @return list<array{id:string,title:string,body:string,tag:string,audience:string,published:bool,startsOn:string,endsOn:string}>
     */
    public function activeNotices(\App\Core\ActorContext|array|null $actor = null, ?DateTimeImmutable $today = null): array
    {
        $signedIn = $actor !== null;
        $isAdmin = $actor instanceof \App\Core\ActorContext
            ? $actor->isPortalWideAdmin
            : (is_array($actor) && !empty($actor['isPortalWideAdmin']));

        return array_values(array_filter(
            $this->inWindow($today),
            static fn (array $item): bool => match ($item['audience']) {
                'public' => true,
                'signed-in' => $signedIn,
                'admins' => $isAdmin,
                default => false,
            },
        ));
    }

    /**
     * Notices for someone who is not signed in: the public portal entry.
     * Kept for existing callers; equivalent to activeNotices(null).
     *
     * @return list<array{id:string,title:string,body:string,tag:string,audience:string,published:bool,startsOn:string,endsOn:string}>
     */
    public function publishedNow(?DateTimeImmutable $today = null): array
    {
        return $this->activeNotices(null, $today);
    }

    /**
     * Add drafts (unpublished, for signed-in users) after the existing notices
     * and save. Used to carry the retired home banner's messages over.
     *
     * @param list<array{title:string,body:string}> $drafts
     * @return array{items:list<array<string,mixed>>}
     */
    public function appendDrafts(array $drafts): array
    {
        $items = $this->load()['items'];
        foreach ($drafts as $draft) {
            $items[] = [
                'id' => '',
                'title' => (string) $draft['title'],
                'body' => (string) $draft['body'],
                'tag' => 'Church',
                'audience' => 'signed-in',
                'published' => false,
                'startsOn' => '',
                'endsOn' => '',
            ];
        }

        return $this->save(['items' => $items]);
    }

    /** @return list<array{id:string,title:string,body:string,tag:string,audience:string,published:bool,startsOn:string,endsOn:string}> */
    private function inWindow(?DateTimeImmutable $today): array
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
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,audience:string,published:bool,startsOn:string,endsOn:string}>}
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
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,audience:string,published:bool,startsOn:string,endsOn:string}>}
     */
    private function validate(array $payload): array
    {
        $itemsIn = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (count($itemsIn) > self::MAX_ITEMS) {
            throw new ValidationFailed(sprintf('At most %d notices are allowed.', self::MAX_ITEMS));
        }

        $clean = [];
        $seen = [];
        foreach ($itemsIn as $i => $item) {
            if (!is_array($item)) {
                throw new ValidationFailed('Notice #' . ($i + 1) . ' must be an object.');
            }
            $title = trim((string) ($item['title'] ?? ''));
            $body = trim((string) ($item['body'] ?? ''));
            if ($title === '') {
                throw new ValidationFailed('Notice #' . ($i + 1) . ' needs a title.');
            }
            if ($body === '') {
                throw new ValidationFailed('Notice #' . ($i + 1) . ' needs a body.');
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

            // No audience means a notice from before audiences existed, which
            // everyone saw on the home page. Anything unrecognised is narrowed
            // to administrators rather than widened to the public.
            $audience = array_key_exists('audience', $item) ? trim((string) $item['audience']) : 'public';
            if (!in_array($audience, self::AUDIENCES, true)) {
                $audience = 'admins';
            }

            $startsOn = $this->coerceDate($item['startsOn'] ?? '');
            $endsOn = $this->coerceDate($item['endsOn'] ?? '');
            if ($startsOn !== '' && $endsOn !== '' && $endsOn < $startsOn) {
                throw new ValidationFailed('Notice #' . ($i + 1) . ' ends before it starts.');
            }

            $clean[] = [
                'id' => $id,
                'title' => mb_substr($title, 0, 140),
                'body' => mb_substr($body, 0, 800),
                'tag' => $tag,
                'audience' => $audience,
                'published' => $this->coerceBool($item['published'] ?? true),
                'startsOn' => $startsOn,
                'endsOn' => $endsOn,
            ];
        }

        return ['items' => $clean];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,audience:string,published:bool,startsOn:string,endsOn:string}>}
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
     * @return array{items:list<array{id:string,title:string,body:string,tag:string,audience:string,published:bool,startsOn:string,endsOn:string}>}
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
            throw new ValidationFailed('Notice dates must use YYYY-MM-DD.');
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            throw new ValidationFailed('A notice date is not a real calendar day.');
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
