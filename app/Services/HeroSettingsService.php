<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationFailed;
use RuntimeException;

/**
 * Read/write the dashboard hero rotator config.
 *
 * Persisted as JSON at config/hero.json so admins can edit it in three places:
 *   - the /admin/hero settings UI (recommended)
 *   - direct edits to config/hero.json
 *   - any deployment automation that wants to ship pre-baked content
 *
 * Schema:
 *   behavior.autoRotate    bool   — auto-advance the rotator
 *   behavior.rotationMs    int    — ms between auto-advances (1500..60000)
 *   behavior.showControls  bool   — show prev/next + dot pager
 *   behavior.displayMode   string — "rotator" | "static"
 *   behavior.titleScale    string — "sm" | "md" | "lg" | "xl"
 *   slides                 list   — each slide is { kicker, title, lead }
 *                                   (kicker optional; title + lead required)
 */
final class HeroSettingsService
{
    private const ALLOWED_DISPLAY_MODES = ['rotator', 'static'];
    private const ALLOWED_TITLE_SCALES  = ['sm', 'md', 'lg', 'xl'];
    private const ROTATION_MIN = 1500;
    private const ROTATION_MAX = 60000;
    private const MAX_SLIDES   = 12;

    public function __construct(private readonly string $configPath) {}

    /**
     * @return array{
     *   behavior:array{autoRotate:bool,rotationMs:int,showControls:bool,displayMode:string,titleScale:string},
     *   slides:list<array{kicker:string,title:string,lead:string}>
     * }
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
            // Bad file — return defaults rather than throwing; admins can fix
            // via the UI. The diagnostic shows under "System" on /admin.
            return $this->defaults();
        }
        return $this->normalize($decoded);
    }

    /**
     * Persist a new config. Throws ValidationFailed for malformed input.
     *
     * @param array<string,mixed> $payload
     * @return array{
     *   behavior:array{autoRotate:bool,rotationMs:int,showControls:bool,displayMode:string,titleScale:string},
     *   slides:list<array{kicker:string,title:string,lead:string}>
     * }
     */
    public function save(array $payload): array
    {
        $clean = $this->validate($payload);
        $encoded = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode hero config as JSON.');
        }
        // Atomic write: rename(tmp, target) avoids half-written files for
        // concurrent reads.
        $dir = dirname($this->configPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create config directory: $dir");
        }
        $tmp = tempnam($dir, 'hero-');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create temporary file for hero config.');
        }
        if (file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write hero config tempfile.');
        }
        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to atomically replace hero config.');
        }
        @chmod($this->configPath, 0664);
        return $clean;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   behavior:array{autoRotate:bool,rotationMs:int,showControls:bool,displayMode:string,titleScale:string},
     *   slides:list<array{kicker:string,title:string,lead:string}>
     * }
     */
    private function validate(array $payload): array
    {
        $behaviorIn = is_array($payload['behavior'] ?? null) ? $payload['behavior'] : [];
        $slidesIn   = is_array($payload['slides']   ?? null) ? $payload['slides']   : [];

        $defaults = $this->defaults()['behavior'];
        $rotationMs = $this->coerceInt($behaviorIn['rotationMs'] ?? null, $defaults['rotationMs']);
        if ($rotationMs < self::ROTATION_MIN || $rotationMs > self::ROTATION_MAX) {
            throw new ValidationFailed(sprintf(
                'rotationMs must be between %d and %d (got %d).',
                self::ROTATION_MIN, self::ROTATION_MAX, $rotationMs,
            ));
        }
        $displayMode = (string) ($behaviorIn['displayMode'] ?? $defaults['displayMode']);
        if (!in_array($displayMode, self::ALLOWED_DISPLAY_MODES, true)) {
            throw new ValidationFailed('displayMode must be one of: ' . implode(', ', self::ALLOWED_DISPLAY_MODES));
        }
        $titleScale = (string) ($behaviorIn['titleScale'] ?? $defaults['titleScale']);
        if (!in_array($titleScale, self::ALLOWED_TITLE_SCALES, true)) {
            throw new ValidationFailed('titleScale must be one of: ' . implode(', ', self::ALLOWED_TITLE_SCALES));
        }

        $cleanBehavior = [
            'autoRotate'   => $this->coerceBool($behaviorIn['autoRotate']   ?? $defaults['autoRotate']),
            'rotationMs'   => $rotationMs,
            'showControls' => $this->coerceBool($behaviorIn['showControls'] ?? $defaults['showControls']),
            'displayMode'  => $displayMode,
            'titleScale'   => $titleScale,
        ];

        if ($slidesIn === []) {
            throw new ValidationFailed('At least one slide is required.');
        }
        if (count($slidesIn) > self::MAX_SLIDES) {
            throw new ValidationFailed(sprintf('At most %d slides are allowed.', self::MAX_SLIDES));
        }

        $cleanSlides = [];
        foreach ($slidesIn as $i => $slide) {
            if (!is_array($slide)) {
                throw new ValidationFailed("Slide #" . ($i + 1) . ' must be an object.');
            }
            $title = trim((string) ($slide['title'] ?? ''));
            $lead  = trim((string) ($slide['lead']  ?? ''));
            $kicker = trim((string) ($slide['kicker'] ?? ''));
            if ($title === '') {
                throw new ValidationFailed("Slide #" . ($i + 1) . ' is missing a title.');
            }
            if ($lead === '') {
                throw new ValidationFailed("Slide #" . ($i + 1) . ' is missing a lead/body.');
            }
            // Cap lengths so a runaway paste can't blow out the rotator UI.
            $cleanSlides[] = [
                'kicker' => mb_substr($kicker, 0, 80),
                'title'  => mb_substr($title,  0, 240),
                'lead'   => mb_substr($lead,   0, 600),
            ];
        }

        return ['behavior' => $cleanBehavior, 'slides' => $cleanSlides];
    }

    /**
     * Best-effort normalization of an already-parsed file. Keeps the public
     * shape consistent even when the on-disk file is older or partially
     * authored by hand.
     *
     * @param array<string,mixed> $decoded
     * @return array{
     *   behavior:array{autoRotate:bool,rotationMs:int,showControls:bool,displayMode:string,titleScale:string},
     *   slides:list<array{kicker:string,title:string,lead:string}>
     * }
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
     * @return array{
     *   behavior:array{autoRotate:bool,rotationMs:int,showControls:bool,displayMode:string,titleScale:string},
     *   slides:list<array{kicker:string,title:string,lead:string}>
     * }
     */
    private function defaults(): array
    {
        return [
            'behavior' => [
                'autoRotate'   => true,
                'rotationMs'   => 7000,
                'showControls' => true,
                'displayMode'  => 'rotator',
                'titleScale'   => 'lg',
            ],
            'slides' => [
                [
                    'kicker' => 'Ministry command center',
                    'title'  => 'Choose the ministry first. Then lead the work.',
                    'lead'   => 'Leaders manage people, roles, events, and schedules only after selecting an available ministry. Members still get their own schedule, availability, calendar, and upcoming events.',
                ],
            ],
        ];
    }

    private function coerceBool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value))  return $value !== 0;
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private function coerceInt(mixed $value, int $fallback): int
    {
        if (is_int($value)) return $value;
        if (is_numeric($value)) return (int) $value;
        return $fallback;
    }
}
