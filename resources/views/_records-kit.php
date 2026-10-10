<?php

declare(strict_types=1);

/**
 * People & Records — the few pieces every records page shares: notices, the
 * pager, the list-to-cards table on a phone, record page layout, and the
 * compact history list. Built on the kit (ek-*); only what the kit does not
 * already say lives here, under rec-*.
 */

require_once __DIR__ . '/_admin-shell.php';

if (!function_exists('records_h')) {
    function records_h(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('records_styles')) {
    function records_styles(): string
    {
        return <<<'CSS'
<style>
/* Record page: the record on the left, its standing facts on the right. */
.rec-layout{display:grid;gap:var(--sp-4,16px);grid-template-columns:minmax(0,1fr) minmax(260px,320px);align-items:start}
.rec-main,.rec-aside{display:grid;gap:var(--sp-4,16px);min-width:0}
@media(max-width:960px){.rec-layout{grid-template-columns:minmax(0,1fr)}}
.rec-dl{display:grid;grid-template-columns:minmax(96px,max-content) minmax(0,1fr);gap:6px 16px;margin:0;font-size:14px}
.rec-dl dt{color:var(--muted,#627169);font-size:13px}
.rec-dl dd{margin:0;min-width:0;overflow-wrap:anywhere}
.rec-muted{color:var(--muted,#627169)}
.rec-small{font-size:12px}
.rec-card-foot{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px);align-items:center;padding:var(--sp-3,12px) var(--sp-5,20px);border-top:1px solid var(--line,#d9e4dd)}
.rec-card-body-flush{padding:0}
.rec-link{color:var(--teal-ink,#117b6d);font-weight:650;text-decoration:none}
.rec-link:hover{text-decoration:underline}
.ek-btn:focus-visible,.rec-link:focus-visible,.ek-tab:focus-visible,.ek-chip:focus-visible{outline:2px solid var(--teal,#117b6d);outline-offset:2px}
.rec-actions{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px);align-items:center}
.rec-filters{display:grid;gap:var(--sp-3,12px);grid-template-columns:repeat(auto-fill,minmax(min(100%,170px),1fr));align-items:end}
.rec-filters .rec-grow{grid-column:span 2}
@media(max-width:520px){.rec-filters .rec-grow{grid-column:auto}}
.rec-filters-actions{display:flex;gap:var(--sp-2,8px);flex-wrap:wrap}
.rec-pager{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px);align-items:center;justify-content:space-between;padding:var(--sp-3,12px) var(--sp-5,20px);border-top:1px solid var(--line,#d9e4dd);font-size:13px}
.rec-fields{display:grid;gap:var(--sp-3,12px) var(--sp-4,16px);grid-template-columns:repeat(3,minmax(0,1fr))}
.rec-fields .is-wide{grid-column:span 2}
.rec-fields .is-full{grid-column:1/-1}
@media(max-width:760px){.rec-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:480px){.rec-fields{grid-template-columns:minmax(0,1fr)}.rec-fields .is-wide{grid-column:auto}}
.rec-req{color:var(--rose-ink,#b84957)}
.rec-check{display:flex;gap:8px;align-items:center;min-height:40px;font-size:14px}
.rec-check input{width:18px;height:18px}
.rec-history{list-style:none;margin:0;padding:0;display:grid}
.rec-history li{display:grid;gap:2px;padding:var(--sp-2,8px) 0;border-bottom:1px solid var(--line,#d9e4dd)}
.rec-history li:last-child{border-bottom:0}
.rec-history .rec-when{font-size:12px;color:var(--muted,#627169)}
.rec-avatar{width:64px;height:64px;border-radius:50%;flex:0 0 64px;display:grid;place-items:center;position:relative;overflow:hidden;
  background:var(--soft,#eef4f0);color:var(--teal-ink,#117b6d);font-size:22px;font-weight:750}
.rec-avatar img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.rec-identity{display:flex;gap:var(--sp-4,16px);align-items:center;min-width:0}
.rec-identity h2{margin:0;font-size:18px}
.rec-inline-form{display:inline;margin:0}

/* Lists become cards on a phone: each cell labelled from data-label, the
   first cell as the card title. Nothing is hidden, nothing scrolls sideways. */
@media(max-width:720px){
  .rec-cards thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
  .rec-cards,.rec-cards tbody,.rec-cards tr,.rec-cards td{display:block;width:auto}
  .rec-cards tr{padding:var(--sp-3,12px) var(--sp-4,16px);border-bottom:1px solid var(--line,#d9e4dd)}
  .rec-cards tbody tr:last-child{border-bottom:0}
  .rec-cards td{border:0!important;padding:2px 0!important;display:flex;gap:var(--sp-3,12px);align-items:flex-start;white-space:normal!important}
  .rec-cards td::before{content:attr(data-label);flex:0 0 96px;font-size:12px;font-weight:650;color:var(--muted,#627169);padding-top:1px}
  .rec-cards td.rec-title{font-size:15px;margin-bottom:4px}
  .rec-cards td.rec-title::before,.rec-cards td.rec-action::before{display:none}
  .rec-cards td.is-blank{display:none}
  .rec-cards tbody tr:hover td{background:transparent}
}
</style>
CSS;
    }
}

if (!function_exists('records_notice')) {
    /** A saved/deleted/error notice. $kind is 'ok' or 'error'. */
    function records_notice(string $kind, string $message): string
    {
        $isError = $kind !== 'ok';

        return '<div class="ek-alert ' . ($isError ? 'is-error' : 'is-ok') . '" role="' . ($isError ? 'alert' : 'status') . '">'
            . ($isError ? '<strong>Not saved.</strong> ' : '') . '<span>' . records_h($message) . '</span></div>';
    }
}

if (!function_exists('records_notice_for')) {
    /**
     * The notice for a ?notice= value, from a map of value => [kind, message].
     *
     * @param array<string,array{0:string,1:string}> $map
     */
    function records_notice_for(string $notice, array $map): string
    {
        if ($notice === '' || !isset($map[$notice])) {
            return '';
        }

        return records_notice($map[$notice][0], $map[$notice][1]);
    }
}

if (!function_exists('records_pager')) {
    /** @param callable(int):string $url */
    function records_pager(int $page, int $pages, int $total, string $noun, callable $url): string
    {
        if ($pages <= 1) {
            return '';
        }
        $out = '<nav class="rec-pager" aria-label="Pages"><span class="rec-muted">Page ' . $page . ' of ' . $pages
            . ' · ' . $total . ' ' . records_h($noun) . '</span><span class="rec-actions">';
        if ($page > 1) {
            $out .= '<a class="ek-btn" rel="prev" href="' . records_h($url($page - 1)) . '">&larr; Previous</a>';
        }
        if ($page < $pages) {
            $out .= '<a class="ek-btn" rel="next" href="' . records_h($url($page + 1)) . '">Next &rarr;</a>';
        }

        return $out . '</span></nav>';
    }
}

if (!function_exists('records_when')) {
    function records_when(string $datetime, bool $withTime = true): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }

        return date($withTime ? 'j M Y, H:i' : 'j M Y', $ts);
    }
}

if (!function_exists('records_history_list')) {
    /**
     * The latest history entries for one record, as a short list.
     *
     * @param list<array<string,mixed>> $entries RecordHistoryService entries
     */
    function records_history_list(array $entries, string $emptyText): string
    {
        if ($entries === []) {
            return '<p class="rec-muted" style="margin:0">' . records_h($emptyText) . '</p>';
        }
        $out = '<ul class="rec-history">';
        foreach ($entries as $e) {
            $out .= '<li><span><strong>' . records_h($e['actionLabel']) . '</strong>'
                . ($e['summary'] !== '' ? ' — ' . records_h($e['summary']) : '') . '</span>'
                . '<span class="rec-when"><time datetime="' . records_h(str_replace(' ', 'T', (string) $e['occurredAt'])) . '">'
                . records_h(records_when((string) $e['occurredAt'])) . '</time> · ' . records_h($e['who']) . '</span></li>';
        }

        return $out . '</ul>';
    }
}
