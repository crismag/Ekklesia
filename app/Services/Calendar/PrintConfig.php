<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use DateTimeImmutable;

/**
 * Everything that decides what a printed calendar looks like, in one place.
 *
 * The settings used to live as a dozen loose query parameters read in three
 * different files, each with its own idea of what the default was. That is
 * survivable while there are six of them; it is not survivable once a
 * configuration can be saved, reopened next month and shared with somebody
 * else — at that point "what was the default?" has to have exactly one answer.
 *
 * Two properties matter more than the shape:
 *
 * 1. **Defaults are the current sheet.** A reader who saves nothing and
 *    changes nothing gets precisely the calendar the church already prints.
 *    Every new capability here is off until asked for.
 *
 * 2. **Missing keys are filled, never fatal.** A view saved today must still
 *    open after a setting is added next year, so `fromArray()` takes whatever
 *    it is given and fills the rest. The version is recorded so a genuine
 *    breaking change can be migrated rather than guessed at.
 */
final class PrintConfig
{
    /** Bump only for a change old configurations cannot be defaulted through. */
    public const VERSION = 1;

    /** Date modes that resolve against "now" rather than a stored range. */
    public const DATE_MODES = ['this-month', 'next-month', 'quarter', 'year', 'custom'];

    public const DENSITIES = ['standard', 'compact', 'extra-compact', 'auto'];

    /** How much room the writing inside a calendar cell may take. */
    public const ENTRY_DISPLAYS = ['compact', 'readable', 'showcase', 'auto'];
    public const TITLE_STYLES = ['classic', 'editorial', 'banner'];
    public const FONTS = ['serif', 'sans', 'display', 'mono'];
    /**
     * How a celebrant is named on paper: the name they go by (preferred, else
     * first), optionally with a last initial to tell two of them apart. Never
     * the full legal name. The old values still load: "full" meant "do not
     * shorten" and "short" meant "first name and initial".
     */
    public const NAME_STYLES = ['first', 'initial'];
    private const LEGACY_NAME_STYLES = ['full' => 'first', 'short' => 'initial'];

    /** Paper sizes, portrait, in inches. Tabloid is the common large office sheet. */
    public const PAPERS = [
        'letter' => [8.5, 11.0], 'legal' => [8.5, 14.0], 'tabloid' => [11.0, 17.0],
        'a4' => [8.27, 11.69], 'a3' => [11.69, 16.54],
    ];

    /**
     * Whether a month may run past one sheet. "fit" (the default) is one page,
     * with "+n more" for what does not fit; "grow" lets a busy week take the
     * height its entries need and hides nothing.
     */
    public const PAGE_HEIGHTS = ['fit', 'grow'];

    /** @param array<string,mixed> $raw */
    private function __construct(private readonly array $raw)
    {
    }

    /**
     * Build from whatever was supplied, filling everything absent.
     *
     * @param array<string,mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $d = self::defaults();

        $date = self::section($input, 'date', $d['date']);
        $date['mode'] = in_array($date['mode'], self::DATE_MODES, true) ? $date['mode'] : $d['date']['mode'];
        $date['from'] = self::ymdOrNull($date['from']);
        $date['to'] = self::ymdOrNull($date['to']);

        $content = self::section($input, 'content', $d['content']);
        $content['sources'] = array_values(array_filter(
            array_map('strval', (array) ($content['sources'] ?? [])),
            static fn (string $s): bool => $s !== '',
        ));

        // A view saved before "grow" existed was designed as one fitted sheet,
        // and reopening it must still print that sheet.
        $storedWithoutHeight = is_array($input['page'] ?? null) && !array_key_exists('height', $input['page']);
        $page = self::section($input, 'page', $d['page']);
        $page['paper'] = isset(self::PAPERS[$page['paper']]) ? $page['paper'] : 'letter';
        $page['height'] = $storedWithoutHeight ? 'fit'
            : (in_array($page['height'], self::PAGE_HEIGHTS, true) ? $page['height'] : $d['page']['height']);
        $page['orientation'] = in_array($page['orientation'], ['', 'portrait', 'landscape'], true) ? $page['orientation'] : '';

        $appearance = self::section($input, 'appearance', $d['appearance']);
        $appearance['typeScale'] = max(0.85, min(1.3, (float) ($appearance['typeScale'] ?: 1.0)));
        $appearance['font'] = in_array($appearance['font'], self::FONTS, true) ? $appearance['font'] : 'serif';
        $names = (string) ($appearance['names'] ?? '');
        $names = self::LEGACY_NAME_STYLES[$names] ?? $names;
        $appearance['names'] = in_array($names, self::NAME_STYLES, true) ? $names : 'first';
        $appearance['legend'] = (bool) ($appearance['legend'] ?? true);
        // How a member type is marked on a name: highlighted, or coloured text.
        $appearance['memberMark'] = in_array($appearance['memberMark'] ?? '', MemberTypeStyle::MARKS, true)
            ? (string) $appearance['memberMark'] : 'highlight';
        $appearance['density'] = in_array($appearance['density'], self::DENSITIES, true) ? $appearance['density'] : 'standard';
        $appearance['titleStyle'] = in_array($appearance['titleStyle'], self::TITLE_STYLES, true)
            ? $appearance['titleStyle'] : 'classic';
        // Theme, and then the settings the theme is allowed to have an opinion
        // about. A theme that cannot dress the chosen layout falls back to
        // Classic rather than rendering a design nobody drew.
        // "auto" follows the printed month (CalendarTheme::resolve). A theme id
        // that no longer exists falls back to Classic rather than failing.
        // "pptx:ID" is an uploaded PowerPoint theme, pinned to a version so a
        // saved design keeps looking as it did when the theme is replaced.
        $isPptx = preg_match('/^pptx:\d+$/', (string) ($appearance['theme'] ?? '')) === 1;
        $appearance['theme'] = CalendarTheme::exists((string) ($appearance['theme'] ?? ''))
            || ($appearance['theme'] ?? '') === CalendarTheme::AUTO || $isPptx
            ? (string) $appearance['theme'] : CalendarTheme::DEFAULT;
        $appearance['themeVersion'] = $isPptx ? max(0, (int) ($appearance['themeVersion'] ?? 0)) : 0;
        $layoutId = self::text($input['layout'] ?? $d['layout'], 40) ?: $d['layout'];
        if (!$isPptx && !CalendarTheme::supports($appearance['theme'], $layoutId)
            || $isPptx && !in_array($layoutId, ['monthly', 'weekly'], true)) {
            $appearance['theme'] = CalendarTheme::DEFAULT;
        }
        $appearance['entryDisplay'] = in_array($appearance['entryDisplay'], self::ENTRY_DISPLAYS, true)
            ? $appearance['entryDisplay'] : 'auto';
        // Artwork is offered by the theme, so a set the theme does not carry
        // is not merely invalid — it would ask for a file that is not there.
        $appearance['artwork'] = in_array(
            (string) ($appearance['artwork'] ?? 'none'),
            CalendarTheme::artworkFor($appearance['theme']),
            true,
        ) ? (string) $appearance['artwork'] : 'none';
        $appearance['decoration'] = in_array($appearance['decoration'], CalendarTheme::DECORATIONS, true)
            ? $appearance['decoration'] : 'balanced';
        $appearance['inkFriendly'] = (bool) ($appearance['inkFriendly'] ?? false);
        // A colour reaches a stylesheet, so it is a six-digit hex or it is the
        // church's own — never whatever arrived in a query string.
        $appearance['accent'] = preg_match('/^#[0-9a-f]{6}$/i', (string) ($appearance['accent'] ?? '')) === 1
            ? strtolower((string) $appearance['accent'])
            : '';

        $header = self::section($input, 'header', $d['header']);
        $header['show'] = self::flags($header['show'] ?? [], $d['header']['show']);
        $header['title'] = self::text($header['title'] ?? '', 120);
        $header['subtitle'] = self::text($header['subtitle'] ?? '', 160);

        $footer = self::section($input, 'footer', $d['footer']);
        $footer['show'] = self::flags($footer['show'] ?? [], $d['footer']['show']);
        $footer['note'] = self::text($footer['note'] ?? '', 200);

        $extra = self::section($input, 'additional', $d['additional']);
        foreach (['top', 'bottom'] as $where) {
            $region = is_array($extra[$where] ?? null) ? $extra[$where] : $d['additional'][$where];
            // A region is present only when it has something in it. An enabled
            // but empty region would reserve page height for nothing, which is
            // the one thing these must never do.
            $region['html'] = RichText::clean((string) ($region['html'] ?? ''));
            $region['enabled'] = (bool) ($region['enabled'] ?? false) && $region['html'] !== '';
            $extra[$where] = $region;
        }

        // A picture behind the calendar. The picture is referred to by id; who
        // may see it is PrintBackgroundService's decision at render time.
        $background = self::section($input, 'background', $d['background']);
        $background['id'] = max(0, (int) ($background['id'] ?? 0));
        $background['mode'] = $background['mode'] === 'image' && $background['id'] > 0 ? 'image' : 'none';
        $background['fit'] = in_array($background['fit'] ?? '', ['cover', 'contain'], true) ? $background['fit'] : 'cover';
        $background['x'] = in_array($background['x'] ?? '', ['left', 'center', 'right'], true) ? $background['x'] : 'center';
        $background['y'] = in_array($background['y'] ?? '', ['top', 'center', 'bottom'], true) ? $background['y'] : 'center';
        // How strongly the picture shows, and how much white is laid over it so
        // the writing stays readable. Bounded so no setting hides the calendar.
        $background['opacity'] = round(max(0.1, min(1.0, (float) ($background['opacity'] ?? 0.35))), 2);
        $background['overlay'] = round(max(0.0, min(0.9, (float) ($background['overlay'] ?? 0.55))), 2);

        // Optional screen calendar state. Empty means "this is a print-only
        // view" — opening it on the calendar still applies layers, but does
        // not yank the month/week switcher or the left rail. Old saved rows
        // never had this section, so the default must be absent-looking.
        $screen = self::section($input, 'screen', $d['screen']);
        $screen['view'] = in_array((string) ($screen['view'] ?? ''), ['month', 'week', 'day', 'agenda'], true)
            ? (string) $screen['view']
            : '';
        $screen['left'] = in_array((string) ($screen['left'] ?? ''), ['open', 'collapsed'], true)
            ? (string) $screen['left']
            : '';

        return new self([
            'version' => self::VERSION,
            'layout' => self::text($input['layout'] ?? $d['layout'], 40) ?: $d['layout'],
            'date' => $date,
            'content' => $content,
            'page' => $page,
            'appearance' => $appearance,
            'header' => $header,
            'footer' => $footer,
            'additional' => $extra,
            'background' => $background,
            'screen' => $screen,
        ]);
    }

    /**
     * The calendar this church already prints.
     *
     * Changing anything here changes what somebody gets when they change
     * nothing, which is the most consequential edit in this file.
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'version' => self::VERSION,
            'layout' => 'monthly',
            'date' => ['mode' => 'this-month', 'from' => null, 'to' => null],
            // Empty means "the caller decides" — the setup screen applies its
            // first preset. Baking a source list in here would put the choice
            // in two places.
            'content' => ['sources' => []],
            // One page by default: the calendar prints as a single fitted sheet,
            // with "+n more" on a day that cannot hold everything. Growing to
            // fit is the reader's choice.
            'page' => ['paper' => 'letter', 'orientation' => '', 'height' => 'fit'],
            'appearance' => [
                'typeScale' => 1.0,
                'font' => 'serif',
                'names' => 'first',
                // The member-type key under a sheet that has birthdays on it.
                'legend' => true,
                'memberMark' => 'highlight',
                'themeVersion' => 0,
                'density' => 'standard',
                'titleStyle' => 'classic',
                'accent' => '',
                'theme' => CalendarTheme::DEFAULT,
                // Auto, not compact. A single celebrant used to sit as a 7.4pt
                // line in the corner of an empty cell; auto lets a quiet day
                // use the room it has, and a busy day is unchanged.
                'entryDisplay' => 'auto',
                'artwork' => 'none',
                'decoration' => 'balanced',
                'inkFriendly' => false,
            ],
            'header' => [
                'show' => ['church' => true, 'location' => true, 'period' => true, 'docType' => true],
                'title' => '',
                'subtitle' => '',
            ],
            'footer' => [
                'show' => ['printed' => true, 'website' => true, 'church' => false, 'page' => false],
                'note' => '',
            ],
            'additional' => [
                'top' => ['enabled' => false, 'html' => ''],
                'bottom' => ['enabled' => false, 'html' => ''],
            ],
            'background' => ['mode' => 'none', 'id' => 0, 'fit' => 'cover', 'x' => 'center', 'y' => 'center',
                'opacity' => 0.35, 'overlay' => 0.55],
            'screen' => ['view' => '', 'left' => ''],
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @return mixed */
    public function get(string $path, mixed $fallback = null): mixed
    {
        $node = $this->raw;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $fallback;
            }
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * The dates this configuration means today.
     *
     * A saved view says "this month", not "August 2026". Storing the resolved
     * range is the difference between a view somebody reopens every month and
     * one that quietly prints last year.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    public function resolveRange(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $mode = (string) $this->get('date.mode', 'this-month');

        if ($mode === 'custom') {
            $from = $this->get('date.from');
            $to = $this->get('date.to');
            if ($from !== null && $to !== null) {
                $start = new DateTimeImmutable((string) $from);
                $end = new DateTimeImmutable((string) $to);

                return $end < $start ? [$end, $start] : [$start, $end];
            }
            $mode = 'this-month';
        }

        return match ($mode) {
            'next-month' => [
                $today->modify('first day of next month'),
                $today->modify('last day of next month'),
            ],
            'quarter' => [
                $today->modify('first day of this month'),
                $today->modify('first day of +2 months')->modify('last day of this month'),
            ],
            'year' => [
                $today->modify('first day of January this year'),
                $today->modify('last day of December this year'),
            ],
            default => [
                $today->modify('first day of this month'),
                $today->modify('last day of this month'),
            ],
        };
    }

    /**
     * Build from a query string.
     *
     * The print document is reachable by URL — that is how the preview iframe
     * works, how "open in a new tab" works, and how a saved view's link works.
     * So the flat query form and the nested configuration have to be the same
     * thing, converted in one place rather than parsed slightly differently by
     * each caller.
     *
     * @param array<string,mixed> $q
     */
    public static function fromQuery(array $q): self
    {
        $bool = static fn (string $key, bool $default): bool => array_key_exists($key, $q)
            ? in_array((string) $q[$key], ['1', 'true', 'on', 'yes'], true)
            : $default;
        $d = self::defaults();

        return self::fromArray([
            'layout' => $q['template'] ?? $d['layout'],
            'date' => [
                'mode' => $q['dateMode'] ?? 'custom',
                'from' => $q['start'] ?? null,
                'to' => $q['end'] ?? null,
            ],
            'content' => [
                'sources' => isset($q['sources']) && trim((string) $q['sources']) !== ''
                    ? array_map('trim', explode(',', (string) $q['sources']))
                    : [],
            ],
            'page' => [
                'paper' => $q['paper'] ?? 'letter',
                'orientation' => $q['orientation'] ?? '',
                'height' => $q['height'] ?? $d['page']['height'],
            ],
            'appearance' => [
                'typeScale' => (float) ($q['scale'] ?? 1.0),
                'font' => $q['font'] ?? 'serif',
                'names' => $q['names'] ?? $d['appearance']['names'],
                'legend' => $bool('legend', true),
                'memberMark' => $q['mark'] ?? 'highlight',
                'themeVersion' => (int) ($q['themeV'] ?? 0),
                'density' => $q['density'] ?? 'standard',
                'titleStyle' => $q['titleStyle'] ?? 'classic',
                'accent' => $q['accent'] ?? '',
                'theme' => $q['theme'] ?? CalendarTheme::DEFAULT,
                'entryDisplay' => $q['entry'] ?? 'auto',
                'artwork' => $q['artwork'] ?? 'none',
                'decoration' => $q['decor'] ?? 'balanced',
                'inkFriendly' => $bool('ink', false),
            ],
            'header' => [
                'show' => [
                    'church' => $bool('hChurch', true),
                    'location' => $bool('hLocation', true),
                    'period' => $bool('hPeriod', true),
                    'docType' => $bool('hDocType', true),
                ],
                'title' => $q['hTitle'] ?? '',
                'subtitle' => $q['hSubtitle'] ?? '',
            ],
            'footer' => [
                'show' => [
                    'printed' => $bool('fPrinted', true),
                    'website' => $bool('fWebsite', true),
                    'church' => $bool('fChurch', false),
                    'page' => $bool('fPage', false),
                ],
                'note' => $q['fNote'] ?? '',
            ],
            'additional' => [
                'top' => ['enabled' => true, 'html' => (string) ($q['topInfo'] ?? '')],
                'bottom' => ['enabled' => true, 'html' => (string) ($q['bottomInfo'] ?? '')],
            ],
            'background' => [
                'mode' => (int) ($q['bg'] ?? 0) > 0 ? 'image' : 'none',
                'id' => (int) ($q['bg'] ?? 0),
                'fit' => $q['bgFit'] ?? 'cover',
                'x' => $q['bgX'] ?? 'center',
                'y' => $q['bgY'] ?? 'center',
                'opacity' => $q['bgOpacity'] ?? 0.35,
                'overlay' => $q['bgOverlay'] ?? 0.55,
            ],
            // Not `view`: print-setup already uses that query key for a saved
            // row id. Screen calendar state rides on names that cannot collide.
            'screen' => [
                'view' => $q['screenView'] ?? '',
                'left' => $q['screenLeft'] ?? '',
            ],
        ]);
    }

    /**
     * The flat form, for a URL.
     *
     * Only what differs from the default is emitted. A link to the church's
     * ordinary calendar should read like one, and a query string carrying forty
     * parameters that all say "the default" is unreadable and unshareable.
     *
     * @return array<string,string>
     */
    public function toQuery(): array
    {
        $d = self::defaults();
        $q = ['template' => (string) $this->get('layout')];

        $q['dateMode'] = (string) $this->get('date.mode');
        if ($this->get('date.mode') === 'custom') {
            $q['start'] = (string) $this->get('date.from');
            $q['end'] = (string) $this->get('date.to');
        }
        $q['sources'] = implode(',', (array) $this->get('content.sources', []));

        foreach ([
            'paper' => 'page.paper', 'orientation' => 'page.orientation', 'height' => 'page.height',
            'scale' => 'appearance.typeScale', 'font' => 'appearance.font',
            'names' => 'appearance.names', 'density' => 'appearance.density',
            'titleStyle' => 'appearance.titleStyle', 'accent' => 'appearance.accent',
            'theme' => 'appearance.theme', 'entry' => 'appearance.entryDisplay',
            'artwork' => 'appearance.artwork', 'decor' => 'appearance.decoration', 'mark' => 'appearance.memberMark',
            'themeV' => 'appearance.themeVersion',
        ] as $param => $path) {
            $value = $this->get($path);
            [$section, $key] = explode('.', $path, 2);
            if ((string) $value !== (string) ($d[$section][$key] ?? '')) {
                $q[$param] = (string) $value;
            }
        }

        if ($this->get('appearance.inkFriendly')) {
            $q['ink'] = '1';
        }
        if (!$this->get('appearance.legend', true)) {
            $q['legend'] = '0';
        }

        foreach (['church' => 'hChurch', 'location' => 'hLocation', 'period' => 'hPeriod', 'docType' => 'hDocType'] as $k => $param) {
            if ($this->get('header.show.' . $k) !== $d['header']['show'][$k]) {
                $q[$param] = $this->get('header.show.' . $k) ? '1' : '0';
            }
        }
        foreach (['printed' => 'fPrinted', 'website' => 'fWebsite', 'church' => 'fChurch', 'page' => 'fPage'] as $k => $param) {
            if ($this->get('footer.show.' . $k) !== $d['footer']['show'][$k]) {
                $q[$param] = $this->get('footer.show.' . $k) ? '1' : '0';
            }
        }
        foreach (['header.title' => 'hTitle', 'header.subtitle' => 'hSubtitle', 'footer.note' => 'fNote'] as $path => $param) {
            if ((string) $this->get($path) !== '') {
                $q[$param] = (string) $this->get($path);
            }
        }
        foreach (['top' => 'topInfo', 'bottom' => 'bottomInfo'] as $where => $param) {
            if ($this->get('additional.' . $where . '.enabled')) {
                $q[$param] = (string) $this->get('additional.' . $where . '.html');
            }
        }
        if ($this->get('background.mode') === 'image') {
            $q['bg'] = (string) $this->get('background.id');
            foreach (['fit' => 'bgFit', 'x' => 'bgX', 'y' => 'bgY', 'opacity' => 'bgOpacity', 'overlay' => 'bgOverlay'] as $k => $param) {
                if ((string) $this->get('background.' . $k) !== (string) $d['background'][$k]) {
                    $q[$param] = (string) $this->get('background.' . $k);
                }
            }
        }
        if ((string) $this->get('screen.view', '') !== '') {
            $q['screenView'] = (string) $this->get('screen.view');
        }
        if ((string) $this->get('screen.left', '') !== '') {
            $q['screenLeft'] = (string) $this->get('screen.left');
        }

        return $q;
    }

    /** Whether two configurations describe the same publication. */
    public function equals(self $other): bool
    {
        return $this->normalised() === $other->normalised();
    }

    /** @return array<string,mixed> comparable form, key order irrelevant */
    private function normalised(): array
    {
        $sort = static function (array $a) use (&$sort): array {
            ksort($a);
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $a[$k] = array_is_list($v) ? (static function (array $l) { sort($l); return $l; })($v) : $sort($v);
                }
            }

            return $a;
        };

        return $sort($this->raw);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $default */
    private static function section(array $input, string $key, array $default): array
    {
        $given = is_array($input[$key] ?? null) ? $input[$key] : [];

        return $given + $default;
    }

    /** @param array<string,bool> $default */
    private static function flags(mixed $given, array $default): array
    {
        $given = is_array($given) ? $given : [];
        $out = [];
        foreach ($default as $k => $v) {
            $out[$k] = array_key_exists($k, $given) ? (bool) $given[$k] : $v;
        }

        return $out;
    }

    private static function text(mixed $value, int $max): string
    {
        $s = trim((string) ($value ?? ''));

        return mb_substr($s, 0, $max);
    }

    private static function ymdOrNull(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : null;
    }
}
