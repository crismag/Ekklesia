<?php

declare(strict_types=1);

/**
 * Print Studio — choose a publication, look at it, print it.
 *
 * The screen used to be one long form of loose controls that each wrote a
 * query parameter. That is survivable while there are six of them; it stopped
 * being survivable once a sheet could carry a theme, a header, editorial notes
 * and a saved name, because the reader had no way to tell which of forty
 * controls mattered to the thing in front of them.
 *
 * So it is organised the way the work is:
 *
 *   VIEW        which saved publication this is, and whether it has been edited
 *   CONTENT     when, and what goes on it
 *   DESIGN      the layout and the theme dressing it
 *   PAGE        paper and direction
 *   HEADER      what the masthead and colophon say
 *   NOTES       optional writing above or below the calendar
 *   APPEARANCE  type, colour, density, decoration
 *
 * Everything past CONTENT is folded away by default. A volunteer printing the
 * birthday calendar should be able to do it without meeting a single typography
 * control; the person designing the publication opens the section they want.
 *
 * Only controls that mean something for the current theme are shown — a Safari
 * artwork picker beside the Classic calendar is a control that cannot do
 * anything, which is worse than one that is missing.
 *
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array<string,mixed>> $layers
 * @var array<string,array<string,mixed>> $templates
 * @var array<string,array{label:string,sources:list<string>}> $presets
 * @var array<string,array<string,mixed>> $themes
 * @var list<array<string,mixed>> $savedViews
 * @var ?array<string,mixed> $activeView
 * @var array<string,mixed> $initialConfig
 * @var bool $canShareViews
 */

require_once __DIR__ . '/_portal-shell.php';

$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$today = new DateTimeImmutable('today');

$entryDisplays = [
    'auto' => ['Auto', 'Let each day decide: a quiet day gets larger writing, a busy one stays dense.'],
    'compact' => ['Compact', 'The church’s existing wall calendar, exactly. Best when most days are busy.'],
    'readable' => ['Readable', 'Larger type and more air, for calendars with a few entries a day.'],
    'showcase' => ['Showcase', 'One or two names set large and centred. For birthdays and celebrations.'],
];
$artworkLabels = ['none' => 'None', 'garden' => 'Garden', 'confetti' => 'Confetti'];

ob_start();
?>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
  <?= portal_header(
      $basePath, 'Print studio',
      'Pick a saved publication or build one, then print it or save it as a PDF.',
      is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [],
      $campusSelector['defaultCampusId'] ?? null,
      $actor, [], [], [], 'Sign in', $basePath . '/login',
  ) ?>

  <!-- The sheet is the thing being judged, so the options get out of its way
       on request. In normal flow rather than positioned above the layout: the
       first attempt put it under the sticky header, where it could not be
       clicked at all. -->
  <div class="pc-bar">
    <button class="pc-collapse" type="button" id="pcToggle"
            aria-expanded="true" aria-controls="pcForm">
      <span class="pc-collapse-icon" aria-hidden="true">◀</span>
      <span class="pc-collapse-text">Hide options</span>
    </button>
  </div>
  <div class="pc-layout" id="pcLayout">
    <form class="pc-panel" id="pcForm">

      <!-- VIEW ─────────────────────────────────────────────────────────────
           First, because "which publication is this?" is the question a reader
           arrives with. A saved view stores the date *mode*, not the dates, so
           "September Birthdays" reopened in October prints October. -->
      <section class="pc-view" aria-labelledby="pcViewLab">
        <h2 id="pcViewLab" class="pc-lab">Publication</h2>
        <div class="pc-viewrow">
          <label class="pc-sr" for="pcView">Saved view</label>
          <select id="pcView">
            <option value="">Untitled calendar</option>
            <?php
              $mine = array_values(array_filter($savedViews, static fn (array $v): bool => (bool) $v['mine']));
              $shared = array_values(array_filter($savedViews, static fn (array $v): bool => !$v['mine']));
            ?>
            <?php if ($mine !== []): ?>
              <optgroup label="My views">
                <?php foreach ($mine as $v): ?>
                  <option value="<?= (int) $v['id'] ?>" <?= ($activeView['id'] ?? null) === $v['id'] ? 'selected' : '' ?>><?= $h($v['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
            <?php if ($shared !== []): ?>
              <optgroup label="Shared with everyone">
                <?php foreach ($shared as $v): ?>
                  <option value="<?= (int) $v['id'] ?>" <?= ($activeView['id'] ?? null) === $v['id'] ? 'selected' : '' ?>><?= $h($v['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
          <!-- Announced, because "you have unsaved changes" is exactly the sort
               of thing that must not be visible only as a colour. -->
          <p class="pc-viewnote" id="pcViewNote" role="status"></p>
        </div>
        <div class="pc-viewacts">
          <button type="button" class="pc-mini" id="pcSave" disabled>Save</button>
          <button type="button" class="pc-mini" id="pcSaveAs">Save as…</button>
          <button type="button" class="pc-mini" id="pcReset" disabled>Reset</button>
          <button type="button" class="pc-mini pc-mini--danger" id="pcDelete" hidden>Delete</button>
        </div>
      </section>

      <!-- CONTENT ──────────────────────────────────────────────────────── -->
      <details class="pc-acc" open>
        <summary><span>Content</span><span class="pc-sum" id="pcSumContent"></span></summary>
        <div class="pc-accbody">
          <label class="pc-sr" for="pcRange">Period</label>
          <select id="pcRange" name="range">
            <option value="this-month">This month</option>
            <option value="next-month">Next month</option>
            <option value="quarter">Next three months</option>
            <option value="year">This year</option>
            <option value="custom">Pick dates…</option>
          </select>
          <div class="pc-dates" id="pcDates" hidden>
            <div class="pc-field"><label for="pcStart">From</label><input id="pcStart" type="date" value="<?= $h($today->format('Y-m-01')) ?>"></div>
            <div class="pc-field"><label for="pcEnd">To</label><input id="pcEnd" type="date" value="<?= $h($today->modify('last day of this month')->format('Y-m-d')) ?>"></div>
          </div>

          <?php if (($presets ?? []) !== []): ?>
          <!-- Two things a church actually prints; everything else is a one-off.
               Buttons rather than a third radio group: they set the boxes below
               and then get out of the way, so a reader can start from one and
               adjust. -->
          <div class="pc-presets" role="group" aria-label="Common selections">
            <?php foreach ($presets as $key => $preset): ?>
              <button type="button" class="pc-preset" data-preset="<?= $h($key) ?>"
                      data-sources="<?= $h(implode(',', $preset['sources'])) ?>"><?= $h($preset['label']) ?></button>
            <?php endforeach; ?>
            <button type="button" class="pc-preset pc-preset--clear" data-preset="none" data-sources="">Clear</button>
          </div>
          <?php endif; ?>
          <div class="pc-chips" role="group" aria-label="Calendars to include">
            <?php foreach ($layers as $layer): ?>
              <label class="pc-chip">
                <input type="checkbox" name="source" value="<?= $h($layer['source']) ?>">
                <span class="pc-dot" style="background:<?= $h($layer['color']) ?>" aria-hidden="true"></span>
                <span class="pc-chip-label"><?= $h($layer['label']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="pc-hint" id="pcSrcNote" aria-live="polite"></p>
        </div>
      </details>

      <!-- DESIGN ───────────────────────────────────────────────────────── -->
      <details class="pc-acc" open>
        <summary><span>Design</span><span class="pc-sum" id="pcSumDesign"></span></summary>
        <div class="pc-accbody">
          <h3 class="pc-lab" id="pcLayoutLab">Layout</h3>
          <div class="pc-tiles" role="radiogroup" aria-labelledby="pcLayoutLab">
            <?php $first = true; foreach ($templates as $id => $template): ?>
              <label class="pc-tile" title="<?= $h($template['blurb']) ?>">
                <input type="radio" name="template" value="<?= $h($id) ?>" <?= $first ? 'checked' : '' ?>
                       aria-describedby="pcBlurb-<?= $h($id) ?>">
                <span><?= $h($template['label']) ?></span>
                <span class="pc-sr" id="pcBlurb-<?= $h($id) ?>"><?= $h($template['blurb']) ?></span>
              </label>
            <?php $first = false; endforeach; ?>
          </div>

          <h3 class="pc-lab" id="pcThemeLab">Theme</h3>
          <!-- Cards rather than a list of words: a theme is a look, and four
               words cannot tell anybody what "Editorial" means. The previews
               are drawn in CSS from a handful of divs — no images to fetch and
               nothing heavyweight to render for a picker. -->
          <div class="pc-themes" role="radiogroup" aria-labelledby="pcThemeLab">
            <?php foreach ($themes as $tid => $theme): ?>
              <label class="pc-theme" data-theme="<?= $h($tid) ?>"
                     data-layouts="<?= $h(implode(',', $theme['layouts'])) ?>"
                     data-artwork="<?= $h(implode(',', $theme['artwork'])) ?>"
                     data-defaults="<?= $h(json_encode($theme['defaults'], JSON_THROW_ON_ERROR)) ?>">
                <input type="radio" name="theme" value="<?= $h($tid) ?>"
                       <?= $tid === 'classic' ? 'checked' : '' ?>
                       aria-describedby="pcThemeB-<?= $h($tid) ?>">
                <span class="pc-thumb pc-thumb--<?= $h($tid) ?>" aria-hidden="true">
                  <span class="pc-thumb-t"></span>
                  <span class="pc-thumb-g"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
                </span>
                <span class="pc-theme-name"><?= $h($theme['label']) ?></span>
                <span class="pc-sr" id="pcThemeB-<?= $h($tid) ?>"><?= $h($theme['blurb']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="pc-hint" id="pcThemeNote"></p>

          <h3 class="pc-lab" id="pcEntryLab">How much room entries get</h3>
          <div class="pc-seg" role="radiogroup" aria-labelledby="pcEntryLab">
            <?php foreach ($entryDisplays as $key => [$label, $blurb]): ?>
              <label class="pc-seg-opt" title="<?= $h($blurb) ?>">
                <input type="radio" name="entry" value="<?= $h($key) ?>" <?= $key === 'auto' ? 'checked' : '' ?>
                       aria-describedby="pcEntryB-<?= $h($key) ?>">
                <span><?= $h($label) ?></span>
                <span class="pc-sr" id="pcEntryB-<?= $h($key) ?>"><?= $h($blurb) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </details>

      <!-- PAGE ─────────────────────────────────────────────────────────── -->
      <details class="pc-acc">
        <summary><span>Page</span><span class="pc-sum" id="pcSumPage"></span></summary>
        <div class="pc-accbody">
          <div class="pc-grid2">
            <div class="pc-field"><label for="pcPaper">Paper</label>
              <select id="pcPaper">
                <option value="letter">Letter</option><option value="a4">A4</option>
                <option value="legal">Legal</option><option value="a3">A3</option>
              </select></div>
            <div class="pc-field"><label for="pcOrientation">Direction</label>
              <select id="pcOrientation">
                <option value="">Auto</option><option value="portrait">Portrait</option>
                <option value="landscape">Landscape</option>
              </select></div>
          </div>
        </div>
      </details>

      <!-- HEADER & FOOTER ──────────────────────────────────────────────── -->
      <details class="pc-acc">
        <summary><span>Header &amp; footer</span><span class="pc-sum" id="pcSumHead"></span></summary>
        <div class="pc-accbody">
          <fieldset class="pc-set"><legend>Show in the header</legend>
            <label class="pc-check"><input type="checkbox" id="hChurch" checked><span>Church name</span></label>
            <label class="pc-check"><input type="checkbox" id="hLocation" checked><span>Campus</span></label>
            <label class="pc-check"><input type="checkbox" id="hPeriod" checked><span>Month or period</span></label>
            <label class="pc-check"><input type="checkbox" id="hDocType" checked><span>What it is</span></label>
          </fieldset>
          <div class="pc-field"><label for="hTitle">Title instead of “Monthly calendar”</label>
            <input id="hTitle" type="text" maxlength="120" placeholder="e.g. Birthday calendar"></div>
          <div class="pc-field"><label for="hSubtitle">Line under the church name</label>
            <input id="hSubtitle" type="text" maxlength="160" placeholder="Optional"></div>
          <fieldset class="pc-set"><legend>Show in the footer</legend>
            <label class="pc-check"><input type="checkbox" id="fPrinted" checked><span>Date printed</span></label>
            <label class="pc-check"><input type="checkbox" id="fWebsite" checked><span>Website</span></label>
            <label class="pc-check"><input type="checkbox" id="fChurch"><span>Church name</span></label>
            <label class="pc-check"><input type="checkbox" id="fPage"><span>Page number</span></label>
          </fieldset>
          <div class="pc-field"><label for="fNote">Footer note</label>
            <input id="fNote" type="text" maxlength="200" placeholder="Optional"></div>
        </div>
      </details>

      <!-- NOTES ────────────────────────────────────────────────────────── -->
      <details class="pc-acc">
        <summary><span>Notes on the sheet</span><span class="pc-sum" id="pcSumNotes"></span></summary>
        <div class="pc-accbody">
          <!-- Absent unless written in. An empty region that still reserves
               height is the fastest way to lose a row of a wall calendar, so
               these emit no element at all when blank. -->
          <div class="pc-field"><label for="pcTop">Above the calendar</label>
            <textarea id="pcTop" rows="3" maxlength="4000" placeholder="Optional. Basic formatting is kept."></textarea></div>
          <div class="pc-field"><label for="pcBottom">Below the calendar</label>
            <textarea id="pcBottom" rows="3" maxlength="4000" placeholder="Optional."></textarea></div>
          <p class="pc-hint">Left blank, neither takes any room on the page.</p>
        </div>
      </details>

      <!-- APPEARANCE ───────────────────────────────────────────────────── -->
      <details class="pc-acc">
        <summary><span>Appearance</span><span class="pc-sum" id="pcSumLook"></span></summary>
        <div class="pc-accbody">
          <div class="pc-grid2">
            <div class="pc-field"><label for="pcScale">Text size</label>
              <select id="pcScale">
                <option value="0.92">Small</option><option value="1" selected>Normal</option>
                <option value="1.12">Large</option><option value="1.25">Largest</option>
              </select></div>
            <div class="pc-field"><label for="pcDensity">Density</label>
              <select id="pcDensity" title="How tightly the grid is set. A separate choice from text size: the same large type can have less air around it.">
                <option value="standard">Standard</option><option value="compact">Compact</option>
                <option value="extra-compact">Extra compact</option><option value="auto">Auto</option>
              </select></div>
            <div class="pc-field"><label for="pcNames">Names</label>
              <select id="pcNames" title="Shortened keeps a first name and an initial. Where a cell has room to wrap, the full name is kept anyway.">
                <option value="full">In full</option><option value="short">Shortened</option>
              </select></div>
            <div class="pc-field"><label for="pcFont">Typeface</label>
              <select id="pcFont">
                <option value="serif">Classic serif</option><option value="sans">Plain sans</option>
                <option value="display">Bold display</option><option value="mono">Typewriter</option>
              </select></div>
            <div class="pc-field"><label for="pcTitleStyle">Title style</label>
              <select id="pcTitleStyle">
                <option value="classic">Classic</option><option value="editorial">Editorial</option>
                <option value="banner">Banner</option>
              </select></div>
            <div class="pc-field"><label for="pcAccent">Accent</label>
              <select id="pcAccent">
                <option value="">Church colour</option><option value="#0c5a45">Deep green</option>
                <option value="#13307c">Navy</option><option value="#7a1f3d">Burgundy</option>
                <option value="#3f3f46">Graphite</option>
              </select></div>
          </div>
          <!-- Only for themes that carry artwork. A "Garden" picker beside the
               Classic calendar is a control that cannot do anything. -->
          <div class="pc-grid2" id="pcArtRow" hidden>
            <div class="pc-field"><label for="pcArtwork">Decoration</label>
              <select id="pcArtwork"></select></div>
            <div class="pc-field"><label for="pcDecor">How much</label>
              <select id="pcDecor">
                <option value="minimal">Minimal</option><option value="balanced" selected>Balanced</option>
                <option value="full">Full</option>
              </select></div>
          </div>
          <label class="pc-check"><input type="checkbox" id="pcInk"><span>Ink friendly — drop fills and decoration</span></label>
        </div>
      </details>

      <div class="pc-actions">
        <button class="pc-btn pc-btn--primary" type="button" id="pcPrint">Print</button>
        <button class="pc-btn pc-btn--ghost" type="button" id="pcPdf">Save as PDF</button>
        <a class="pc-btn pc-btn--ghost" id="pcOpen" href="#" target="_blank" rel="noopener">Open in a new tab</a>
      </div>
      <!-- Both buttons open the same dialog, because a browser will not let a
           page choose the destination for you. Saying so beats a button that
           looks like it does something different and then asks the same
           question. -->
      <p class="pc-hint" id="pcPdfHint">Saving opens the same print dialog — choose
        <strong>Save as PDF</strong> as the destination, and turn on background
        graphics so the colours print.</p>
    </form>

    <div class="pc-preview">
      <div class="pc-preview-bar">
        <span id="pcStatus">Preview</span>
        <span class="pc-zoom" id="pcZoom"></span>
      </div>
      <!-- The sheet is rendered at its true paper size and scaled down to fit,
           rather than squeezed into whatever width the frame happens to have.
           It was the latter, so a landscape Letter calendar — 11 inches wide —
           was cut off at the right edge and had to be scrolled to be read. -->
      <div class="pc-stage" id="pcStage">
        <iframe id="pcFrame" title="Calendar preview" src="about:blank"></iframe>
      </div>
    </div>
  </div>

  <?= portal_footer() ?>
</div>

<style>
  .pc-layout{display:grid;grid-template-columns:minmax(250px,300px) minmax(0,1fr);
    gap:16px;align-items:start;margin-top:8px;position:relative}
  /* One column when collapsed, not a zero-width one: hiding the panel removes
     it from the grid, so the preview would take the empty first track and
     render at no width at all. */
  .pc-layout.is-collapsed{grid-template-columns:minmax(0,1fr);gap:0}
  .pc-layout.is-collapsed .pc-panel{display:none}
  @media (max-width:960px){.pc-layout{grid-template-columns:1fr}
    .pc-layout.is-collapsed{grid-template-columns:1fr}}

  .pc-bar{display:flex;margin:10px 0 6px}
  .pc-collapse{display:inline-flex;align-items:center;gap:6px;
    min-height:32px;padding:5px 12px;border:1px solid var(--line,#c7d4cd);border-radius:999px;
    background:#fff;font:inherit;font-size:12.5px;font-weight:800;color:var(--deep,#0c5a45);cursor:pointer}
  .pc-collapse:hover{background:var(--soft,#eef4f0)}
  .pc-collapse:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
  .pc-collapse-icon{transition:transform .15s}
  .pc-layout.is-collapsed .pc-collapse-icon{transform:rotate(180deg)}
  @media (prefers-reduced-motion:reduce){.pc-collapse-icon{transition:none}}

  /* Visible to a screen reader, absent from the page. */
  .pc-sr{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
    clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}
  .pc-panel{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;padding:12px}
  .pc-lab{margin:0 0 6px;font-size:11px;font-weight:800;text-transform:uppercase;
    letter-spacing:.06em;color:var(--muted,#5c6b63)}
  .pc-accbody .pc-lab{margin-top:12px}
  .pc-accbody > .pc-lab:first-child{margin-top:0}

  /* The saved-view block is not an accordion: it is the answer to "what am I
     looking at?", and folding that away would be like hiding a file name. */
  .pc-view{padding:0 0 10px;border-bottom:1px solid var(--line,#e3eae5);margin-bottom:6px}
  .pc-viewrow select{width:100%;font:inherit;font-size:13.5px;font-weight:700;padding:7px 8px;min-height:36px;
    border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff;color:var(--ink,#17211b)}
  .pc-viewnote{margin:5px 0 0;font-size:11.5px;color:var(--muted,#5c6b63);min-height:1em}
  .pc-viewnote.is-dirty{color:#8a5a00;font-weight:700}
  .pc-viewacts{display:flex;gap:5px;flex-wrap:wrap;margin-top:7px}
  .pc-mini{min-height:30px;padding:5px 10px;border:1px solid var(--line,#c7d4cd);border-radius:7px;
    background:#fff;font:inherit;font-size:12px;font-weight:800;color:var(--deep,#0c5a45);cursor:pointer}
  .pc-mini:hover:not(:disabled){background:var(--soft,#eef4f0)}
  .pc-mini:disabled{opacity:.45;cursor:default}
  .pc-mini--danger{color:#8c2f2f;border-color:#e2c9c9}
  .pc-mini:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}

  /* Accordions. <details> rather than scripted panels: they open with the
     keyboard, they are announced as expandable without any aria of ours, and
     they still work if the script never runs. */
  .pc-acc{border-bottom:1px solid var(--line,#eef2f0)}
  .pc-acc:last-of-type{border-bottom:0}
  .pc-acc > summary{display:flex;align-items:center;justify-content:space-between;gap:8px;
    cursor:pointer;list-style:none;padding:9px 2px;min-height:38px;
    font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;
    color:var(--ink,#17211b)}
  .pc-acc > summary::-webkit-details-marker{display:none}
  .pc-acc > summary::after{content:"▸";font-size:11px;color:var(--muted,#5c6b63);
    transition:transform .15s;margin-left:auto;order:3}
  .pc-acc[open] > summary::after{transform:rotate(90deg)}
  @media (prefers-reduced-motion:reduce){.pc-acc > summary::after{transition:none}}
  .pc-acc > summary:hover{color:var(--deep,#0c5a45)}
  .pc-acc > summary:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:-2px;border-radius:6px}
  /* A folded section still says what is inside it, so nothing has to be opened
     just to find out whether it was changed. */
  .pc-sum{font-weight:600;text-transform:none;letter-spacing:0;font-size:11.5px;
    color:var(--muted,#5c6b63);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;order:2}
  .pc-accbody{padding:0 2px 12px}

  #pcRange{width:100%;font:inherit;font-size:13px;padding:6px 8px;min-height:34px;
    border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff;color:var(--ink,#17211b)}
  #pcRange:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:1px}

  .pc-seg{display:flex;flex-wrap:wrap;gap:4px}
  .pc-seg-opt{position:relative;display:inline-flex}
  .pc-seg-opt input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;width:100%;height:100%}
  .pc-seg-opt span:not(.pc-sr){display:inline-flex;align-items:center;min-height:30px;padding:5px 10px;
    border:1px solid var(--line,#c7d4cd);border-radius:999px;background:#fff;
    font-size:12.5px;font-weight:700;color:var(--ink,#17211b);cursor:pointer}
  .pc-seg-opt input:checked + span{background:var(--deep,#0c5a45);border-color:var(--deep,#0c5a45);color:#fff}
  .pc-seg-opt input:focus-visible + span{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}

  .pc-presets{display:flex;flex-wrap:wrap;gap:5px;margin:8px 0 7px}
  .pc-preset{display:inline-flex;align-items:center;min-height:30px;padding:5px 11px;
    border:1px solid var(--deep,#0c5a45);border-radius:999px;background:#fff;
    font:inherit;font-size:12.5px;font-weight:800;color:var(--deep,#0c5a45);cursor:pointer}
  .pc-preset:hover{background:var(--soft,#eef4f0)}
  .pc-preset[aria-pressed=true]{background:var(--deep,#0c5a45);color:#fff}
  .pc-preset--clear{border-color:var(--line,#c7d4cd);color:var(--muted,#5c6b63);font-weight:700}
  .pc-preset:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
  .pc-chips{display:flex;flex-wrap:wrap;gap:4px}
  .pc-chip{position:relative;display:inline-flex;align-items:center;gap:6px;min-height:30px;
    padding:4px 10px;border:1px solid var(--line,#c7d4cd);border-radius:999px;background:#fff;
    font-size:12.5px;font-weight:700;cursor:pointer;max-width:100%}
  .pc-chip input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;width:100%;height:100%}
  .pc-chip:has(input:checked){background:var(--soft,#eef4f0);border-color:var(--deep,#0c5a45);color:var(--deep,#0c5a45)}
  .pc-chip:has(input:focus-visible){outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
  .pc-chip-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  /* Unticked reads as unticked without relying on colour: the dot hollows out. */
  .pc-chip:not(:has(input:checked)) .pc-dot{background:transparent!important;
    box-shadow:inset 0 0 0 2px var(--muted,#5c6b63)}
  .pc-dot{width:9px;height:9px;border-radius:3px;flex:0 0 auto}

  .pc-tiles{display:grid;grid-template-columns:1fr 1fr;gap:4px}
  .pc-tile{position:relative;display:flex}
  .pc-tile input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;width:100%;height:100%}
  .pc-tile span:not(.pc-sr){flex:1;display:inline-flex;align-items:center;min-height:34px;padding:6px 9px;
    border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff;
    font-size:12.5px;font-weight:700;line-height:1.2;cursor:pointer}
  .pc-tile input:checked + span{background:var(--soft,#eef4f0);border-color:var(--deep,#0c5a45);color:var(--deep,#0c5a45)}
  .pc-tile input:focus-visible + span{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
  .pc-tile:hover span:not(.pc-sr){border-color:var(--deep,#0c5a45)}
  .pc-tile[hidden]{display:none}

  /* Theme cards. Each preview is a masthead bar and eight cells drawn from the
     theme's own palette — enough to tell Editorial from Celebration at a
     glance, and nothing to download. */
  .pc-themes{display:grid;grid-template-columns:1fr 1fr;gap:6px}
  .pc-theme{position:relative;display:block;border:1px solid var(--line,#c7d4cd);border-radius:9px;
    padding:5px;background:#fff;cursor:pointer}
  .pc-theme[hidden]{display:none}
  .pc-theme input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;width:100%;height:100%}
  .pc-theme:hover{border-color:var(--deep,#0c5a45)}
  .pc-theme:has(input:checked){border-color:var(--deep,#0c5a45);box-shadow:inset 0 0 0 1px var(--deep,#0c5a45);
    background:var(--soft,#eef4f0)}
  .pc-theme:has(input:focus-visible){outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
  .pc-theme-name{display:block;margin-top:4px;font-size:11.5px;font-weight:800;text-align:center;color:var(--ink,#17211b)}
  .pc-thumb{display:block;height:44px;border-radius:5px;overflow:hidden;background:#fff;
    border:1px solid #e6ece8;padding:3px}
  .pc-thumb-t{display:block;height:8px;border-radius:2px;margin-bottom:3px}
  .pc-thumb-g{display:grid;grid-template-columns:repeat(4,1fr);gap:2px}
  .pc-thumb-g i{display:block;height:9px;border-radius:1px;background:#eef2ef;border:.5px solid #dde5e0}
  .pc-thumb--classic .pc-thumb-t{background:#0c5a45;width:52%;margin-left:auto}
  .pc-thumb--editorial{background:#fff}
  .pc-thumb--editorial .pc-thumb-t{background:#2a2a2a;width:70%;margin:0 auto 5px;height:6px;border-radius:1px}
  .pc-thumb--editorial .pc-thumb-g i{background:#fff;border:0;border-top:1px solid #d8ded9;border-radius:0}
  .pc-thumb--planner{background:#fdf7f0}
  .pc-thumb--planner .pc-thumb-t{background:#c2643a;width:60%}
  .pc-thumb--planner .pc-thumb-g i{background:#fffdfa;border-color:#e2cdbb}
  .pc-thumb--celebration{background:#fff}
  .pc-thumb--celebration .pc-thumb-t{background:#2f6b3c;width:100%;border-radius:999px}
  .pc-thumb--celebration .pc-thumb-g i{border-radius:3px;border-color:#cfe0d2}
  .pc-thumb--celebration .pc-thumb-g i:nth-child(2),
  .pc-thumb--celebration .pc-thumb-g i:nth-child(7){background:#dfeede}

  .pc-set{border:1px solid var(--line,#e3eae5);border-radius:8px;padding:7px 9px 8px;margin:0 0 9px}
  .pc-set legend{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;
    color:var(--muted,#5c6b63);padding:0 4px}
  .pc-check{display:flex;align-items:center;gap:7px;min-height:28px;font-size:12.5px;
    font-weight:600;color:var(--ink,#17211b);cursor:pointer}
  .pc-check input{width:16px;height:16px;flex:0 0 auto;accent-color:var(--deep,#0c5a45);cursor:pointer}
  .pc-check input:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}

  /* `hidden` has to win. A `display:grid` on the same element beats the
     attribute's own rule, which is how the "pick dates" fields stayed on
     screen while the period was "This month" — the same defect that once made
     the events search appear to filter nothing at all. */
  #pcForm [hidden]{display:none!important}
  .pc-dates,.pc-grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
  .pc-grid2{margin-top:0;margin-bottom:9px}
  .pc-field label{display:block;font-size:10.5px;font-weight:800;text-transform:uppercase;
    letter-spacing:.04em;color:var(--muted,#5c6b63);margin-bottom:3px}
  .pc-field input,.pc-field select,.pc-field textarea{width:100%;font:inherit;font-size:13px;
    padding:6px 8px;min-height:34px;border:1px solid var(--line,#c7d4cd);border-radius:8px;
    background:#fff;color:var(--ink,#17211b)}
  .pc-field textarea{min-height:60px;resize:vertical;line-height:1.4}
  .pc-field :focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:1px}
  .pc-accbody > .pc-field{margin-bottom:9px}

  .pc-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
  .pc-btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:9px 16px;
    border-radius:8px;border:1px solid transparent;font:inherit;font-size:13px;font-weight:800;
    cursor:pointer;text-decoration:none}
  .pc-btn--primary{background:var(--deep,#0c5a45);color:#fff}
  .pc-btn--ghost{background:#fff;color:var(--deep,#0c5a45);border-color:var(--line,#c7d4cd)}
  .pc-btn:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
  .pc-hint{font-size:11.5px;color:var(--muted,#5c6b63);margin:7px 0 0;line-height:1.4}

  .pc-preview{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;overflow:hidden;
    position:sticky;top:12px}
  @media (max-width:960px){.pc-preview{position:static}}
  .pc-preview-bar{display:flex;justify-content:space-between;align-items:center;gap:10px;
    padding:8px 13px;border-bottom:1px solid var(--line,#eef2f5);font-size:12px;
    color:var(--muted,#5c6b63);font-weight:700}
  .pc-zoom{font-variant-numeric:tabular-nums;font-weight:600}
  /* The stage owns the visible box; the sheet inside is scaled to fit it, so
     the frame never needs a scrollbar to show a whole page. Never sideways —
     that was the defect. Vertically only, and only when the document genuinely
     runs to more than one page. */
  .pc-stage{position:relative;overflow-x:hidden;overflow-y:auto;background:#eef1f0}
  #pcFrame{border:0;background:#fff;display:block;transform-origin:top left}
</style>
<script>
(function(){
  'use strict';
  var form = document.getElementById('pcForm');
  var frame = document.getElementById('pcFrame');
  var openLink = document.getElementById('pcOpen');
  var status = document.getElementById('pcStatus');
  var dates = document.getElementById('pcDates');
  var base = <?= json_encode($basePath) ?>;
  var THEMES = <?= json_encode(array_map(static fn (array $t): array => [
      'layouts' => $t['layouts'], 'artwork' => $t['artwork'], 'defaults' => $t['defaults'],
  ], $themes), JSON_THROW_ON_ERROR) ?>;
  var ARTWORK_LABELS = <?= json_encode($artworkLabels, JSON_THROW_ON_ERROR) ?>;
  var INITIAL = <?= json_encode($initialConfig, JSON_THROW_ON_ERROR) ?>;
  var ACTIVE = <?= json_encode($activeView, JSON_THROW_ON_ERROR) ?>;
  var CAN_SHARE = <?= json_encode((bool) $canShareViews) ?>;

  function $(id){ return document.getElementById(id); }
  function pick(name){ var el = form.querySelector('input[name=' + name + ']:checked'); return el ? el.value : ''; }
  function setPick(name, value){
    var el = form.querySelector('input[name=' + name + '][value="' + value + '"]');
    if (el) el.checked = true;
  }

  /* The configuration, in the same shape PrintConfig uses on the server.
   *
   * One object, read in one place and written in one place. The screen used to
   * assemble a query string inline from a dozen getElementById calls, which is
   * why "what is the default?" had three different answers; a saved view has
   * to be able to ask that question and get one. */
  function read(){
    var sources = Array.prototype.slice.call(form.querySelectorAll('input[name=source]:checked'))
      .map(function(i){ return i.value; });
    return {
      version: 1,
      layout: pick('template') || 'monthly',
      date: { mode: $('pcRange').value || 'this-month',
              from: $('pcStart').value || null, to: $('pcEnd').value || null },
      content: { sources: sources },
      page: { paper: $('pcPaper').value, orientation: $('pcOrientation').value },
      appearance: {
        typeScale: parseFloat($('pcScale').value) || 1,
        font: $('pcFont').value, names: $('pcNames').value,
        density: $('pcDensity').value, titleStyle: $('pcTitleStyle').value,
        accent: $('pcAccent').value, theme: pick('theme') || 'classic',
        entryDisplay: pick('entry') || 'auto',
        artwork: $('pcArtwork').value || 'none', decoration: $('pcDecor').value,
        inkFriendly: $('pcInk').checked,
      },
      header: {
        show: { church: $('hChurch').checked, location: $('hLocation').checked,
                period: $('hPeriod').checked, docType: $('hDocType').checked },
        title: $('hTitle').value.trim(), subtitle: $('hSubtitle').value.trim(),
      },
      footer: {
        show: { printed: $('fPrinted').checked, website: $('fWebsite').checked,
                church: $('fChurch').checked, page: $('fPage').checked },
        note: $('fNote').value.trim(),
      },
      additional: {
        top: { enabled: $('pcTop').value.trim() !== '', html: $('pcTop').value.trim() },
        bottom: { enabled: $('pcBottom').value.trim() !== '', html: $('pcBottom').value.trim() },
      },
      background: { mode: 'none' },
    };
  }

  function write(c){
    if (!c) return;
    var a = c.appearance || {}, d = c.date || {}, hh = c.header || {}, ff = c.footer || {},
        ad = c.additional || {}, pg = c.page || {};
    setPick('template', c.layout || 'monthly');
    setPick('theme', a.theme || 'classic');
    setPick('entry', a.entryDisplay || 'auto');
    $('pcRange').value = d.mode || 'this-month';
    if (d.from) $('pcStart').value = d.from;
    if (d.to) $('pcEnd').value = d.to;
    dates.hidden = $('pcRange').value !== 'custom';
    var want = (c.content && c.content.sources) || [];
    boxes.forEach(function(b){ b.checked = want.indexOf(b.value) !== -1; });
    $('pcPaper').value = pg.paper || 'letter';
    $('pcOrientation').value = pg.orientation || '';
    $('pcScale').value = String(a.typeScale || 1);
    $('pcFont').value = a.font || 'serif';
    $('pcNames').value = a.names || 'full';
    $('pcDensity').value = a.density || 'standard';
    $('pcTitleStyle').value = a.titleStyle || 'classic';
    $('pcAccent').value = a.accent || '';
    $('pcDecor').value = a.decoration || 'balanced';
    $('pcInk').checked = !!a.inkFriendly;
    ['church','location','period','docType'].forEach(function(k){
      var el = $('h' + k.charAt(0).toUpperCase() + k.slice(1));
      if (el) el.checked = (hh.show || {})[k] !== false;
    });
    ['printed','website','church','page'].forEach(function(k){
      var el = $('f' + k.charAt(0).toUpperCase() + k.slice(1));
      if (el) el.checked = !!(ff.show || {})[k];
    });
    $('hTitle').value = hh.title || '';
    $('hSubtitle').value = hh.subtitle || '';
    $('fNote').value = ff.note || '';
    $('pcTop').value = (ad.top && ad.top.html) || '';
    $('pcBottom').value = (ad.bottom && ad.bottom.html) || '';
    syncTheme(a.artwork || 'none');
  }

  /* The flat query form. Mirrors PrintConfig::toQuery: only what differs from
   * the default is emitted, so a link to the ordinary calendar reads like one
   * rather than carrying forty parameters that all say "the default". */
  var D = <?= json_encode(\App\Services\Calendar\PrintConfig::defaults(), JSON_THROW_ON_ERROR) ?>;
  function query(c){
    var p = new URLSearchParams();
    p.set('template', c.layout);
    p.set('dateMode', c.date.mode);
    if (c.date.mode === 'custom') { p.set('start', c.date.from || ''); p.set('end', c.date.to || ''); }
    p.set('sources', c.content.sources.join(','));
    [['paper', c.page.paper, D.page.paper], ['orientation', c.page.orientation, D.page.orientation],
     ['scale', c.appearance.typeScale, D.appearance.typeScale], ['font', c.appearance.font, D.appearance.font],
     ['names', c.appearance.names, D.appearance.names], ['density', c.appearance.density, D.appearance.density],
     ['titleStyle', c.appearance.titleStyle, D.appearance.titleStyle], ['accent', c.appearance.accent, D.appearance.accent],
     ['theme', c.appearance.theme, D.appearance.theme], ['entry', c.appearance.entryDisplay, D.appearance.entryDisplay],
     ['artwork', c.appearance.artwork, D.appearance.artwork], ['decor', c.appearance.decoration, D.appearance.decoration]
    ].forEach(function(row){ if (String(row[1]) !== String(row[2])) p.set(row[0], String(row[1])); });
    if (c.appearance.inkFriendly) p.set('ink', '1');
    [['hChurch', c.header.show.church, true], ['hLocation', c.header.show.location, true],
     ['hPeriod', c.header.show.period, true], ['hDocType', c.header.show.docType, true],
     ['fPrinted', c.footer.show.printed, true], ['fWebsite', c.footer.show.website, true],
     ['fChurch', c.footer.show.church, false], ['fPage', c.footer.show.page, false]
    ].forEach(function(row){ if (row[1] !== row[2]) p.set(row[0], row[1] ? '1' : '0'); });
    if (c.header.title) p.set('hTitle', c.header.title);
    if (c.header.subtitle) p.set('hSubtitle', c.header.subtitle);
    if (c.footer.note) p.set('fNote', c.footer.note);
    if (c.additional.top.enabled) p.set('topInfo', c.additional.top.html);
    if (c.additional.bottom.enabled) p.set('bottomInfo', c.additional.bottom.html);
    return base + '/calendar/print?' + p.toString();
  }

  /* Theme changes must not quietly discard a deliberate choice.
   *
   * The rule, and it is deliberately a simple one: a theme's recommended
   * settings are applied only to controls the reader has not touched. Touch a
   * control and it is yours; leave it alone and it follows the theme. */
  var touched = Object.create(null);
  var THEMED = { entry: 'entryDisplay', pcDensity: 'density', pcFont: 'font',
                 pcTitleStyle: 'titleStyle', pcAccent: 'accent' };

  function applyThemeDefaults(theme){
    var defs = (THEMES[theme] || {}).defaults || {};
    Object.keys(THEMED).forEach(function(control){
      if (touched[control]) return;
      var value = defs[THEMED[control]];
      if (value === undefined) return;
      if (control === 'entry') setPick('entry', value);
      else if ($(control)) $(control).value = value;
    });
  }

  /* Only offer what the theme can actually do. */
  function syncTheme(keepArtwork){
    var theme = pick('theme') || 'classic';
    var layout = pick('template') || 'monthly';
    var sets = (THEMES[theme] || {}).artwork || ['none'];
    var select = $('pcArtwork');
    var wanted = keepArtwork !== undefined ? keepArtwork : select.value;
    select.innerHTML = '';
    sets.forEach(function(id){
      var o = document.createElement('option');
      o.value = id; o.textContent = ARTWORK_LABELS[id] || id;
      select.appendChild(o);
    });
    select.value = sets.indexOf(wanted) !== -1 ? wanted : 'none';
    // A theme with only "None" has no decoration to configure, so the row goes
    // rather than sitting there as a control that cannot do anything.
    $('pcArtRow').hidden = sets.length <= 1;

    // Themes that cannot dress the chosen layout are withdrawn, and a theme
    // withdrawn under you falls back to Classic rather than to nothing.
    var fallback = false;
    Array.prototype.forEach.call(document.querySelectorAll('.pc-theme'), function(card){
      var ok = (card.dataset.layouts || '').split(',').indexOf(layout) !== -1;
      card.hidden = !ok;
      if (!ok && card.querySelector('input').checked) fallback = true;
    });
    if (fallback) { setPick('theme', 'classic'); syncTheme('none'); return; }

    var note = $('pcThemeNote');
    if (note) {
      var only = Object.keys(THEMES).filter(function(t){
        return (THEMES[t].layouts || []).indexOf(layout) === -1; });
      note.textContent = only.length
        ? only.length + ' theme' + (only.length === 1 ? ' is' : 's are') + ' designed for the monthly grid only.'
        : '';
    }
  }

  /* Summaries on the folded sections, so nothing has to be opened to find out
   * whether it was changed. */
  function summarise(c){
    var t = document.querySelector('.pc-theme input:checked');
    var themeName = t ? t.closest('.pc-theme').querySelector('.pc-theme-name').textContent : '';
    var layoutEl = form.querySelector('input[name=template]:checked');
    var layoutName = layoutEl ? layoutEl.parentNode.querySelector('span:not(.pc-sr)').textContent : '';
    var n = c.content.sources.length;
    set('pcSumContent', $('pcRange').selectedOptions[0].text + ' · ' + (n ? n + ' selected' : 'nothing selected'));
    set('pcSumDesign', layoutName + ' · ' + themeName);
    set('pcSumPage', $('pcPaper').selectedOptions[0].text + ' · ' + $('pcOrientation').selectedOptions[0].text);
    var hidden = ['church','location','period','docType'].filter(function(k){
      return !c.header.show[k]; }).length;
    set('pcSumHead', c.header.title ? c.header.title : (hidden ? hidden + ' hidden' : 'Standard'));
    var notes = (c.additional.top.enabled ? 1 : 0) + (c.additional.bottom.enabled ? 1 : 0);
    set('pcSumNotes', notes === 0 ? 'None' : notes + (notes === 1 ? ' note' : ' notes'));
    set('pcSumLook', $('pcScale').selectedOptions[0].text + ' · ' + $('pcFont').selectedOptions[0].text
      + (c.appearance.inkFriendly ? ' · ink friendly' : ''));
  }
  function set(id, text){ var el = $(id); if (el) el.textContent = text; }

  /* Saved views. */
  var saved = ACTIVE;                    // the view currently open, or null
  var savedConfig = JSON.stringify(INITIAL);
  function isDirty(){ return JSON.stringify(read()) !== savedConfig; }
  function refreshViewState(){
    var dirty = isDirty();
    var note = $('pcViewNote');
    note.classList.toggle('is-dirty', dirty && !!saved);
    note.textContent = saved
      ? (dirty ? 'Edited — not saved yet.'
               : (saved.visibility === 'shared' ? 'Shared with everyone.' : 'Saved.'))
      : (dirty ? 'Not saved. Use “Save as…” to keep this.' : '');
    $('pcSave').disabled = !saved || !saved.canEdit || !dirty;
    $('pcReset').disabled = !dirty;
    $('pcDelete').hidden = !saved || !saved.canEdit;
  }

  function api(method, path, body){
    return fetch(base + '/api/calendar/views' + path, {
      method: method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    }).then(function(r){ return r.json().then(function(j){
      if (!r.ok) throw new Error(j.error || 'That did not work.');
      return j;
    }); });
  }
  function fail(e){ $('pcViewNote').textContent = e.message || 'That did not work.'; }

  $('pcSaveAs').addEventListener('click', function(){
    var name = window.prompt('Name this publication', saved ? saved.name + ' copy' : 'My calendar');
    if (!name) return;
    var visibility = CAN_SHARE && window.confirm('Share it with everyone? Cancel keeps it private.')
      ? 'shared' : 'private';
    api('POST', '', { name: name, visibility: visibility, config: read() })
      .then(function(){ window.location.search = '?saved=1'; })
      .catch(fail);
  });
  $('pcSave').addEventListener('click', function(){
    if (!saved) return;
    api('PUT', '/' + saved.id, { name: saved.name, visibility: saved.visibility, config: read() })
      .then(function(){ savedConfig = JSON.stringify(read()); refreshViewState(); })
      .catch(fail);
  });
  $('pcDelete').addEventListener('click', function(){
    if (!saved || !window.confirm('Delete “' + saved.name + '”? This cannot be undone.')) return;
    api('DELETE', '/' + saved.id).then(function(){ window.location.search = ''; }).catch(fail);
  });
  $('pcReset').addEventListener('click', function(){
    write(JSON.parse(savedConfig)); touched = Object.create(null); refreshViewState(); refresh();
  });
  $('pcView').addEventListener('change', function(){
    // A stable URL, so a saved view can be linked to and reopened. Possession
    // of the link is not access: the server checks every time.
    window.location.search = this.value ? '?view=' + encodeURIComponent(this.value) : '';
  });

  /* Fit a whole page in the frame.
   *
   * The preview used to be an iframe at 100% width, so the print stylesheet
   * inside it laid out for whatever width the frame had — and a landscape
   * Letter calendar, eleven inches across, was simply cut off at the right and
   * had to be scrolled. The sheet is now given its true paper size in CSS
   * pixels and the whole frame is scaled down to fit. */
  var PAPER = { letter: [8.5, 11], legal: [8.5, 14], a4: [8.27, 11.69], a3: [11.69, 16.54] };
  var TEMPLATE_ORIENTATION = <?= json_encode(array_map(
      static fn (array $t): string => $t['orientation'], $templates), JSON_THROW_ON_ERROR) ?>;
  var stage = $('pcStage'), zoom = $('pcZoom');

  function paperPx(){
    var size = PAPER[$('pcPaper').value] || PAPER.letter;
    var orientation = $('pcOrientation').value
      || TEMPLATE_ORIENTATION[pick('template') || 'monthly'] || 'portrait';
    var w = size[0], h = size[1];
    if (orientation === 'landscape') { var t = w; w = h; h = t; }
    return { w: Math.round(w * 96), h: Math.round(h * 96) };
  }
  // How tall the stage may grow before it starts scrolling instead. Larger once
  // the options are folded away, because that is what folding them away is for.
  function maxStageVh(){
    return $('pcLayout').classList.contains('is-collapsed') ? 0.88 : 0.78;
  }
  function fit(){
    if (!stage || !frame) return;
    var page = paperPx(), room = stage.clientWidth;
    if (!room) return;
    // The print shell centres its sheet with a screen margin, so the document
    // inside the frame is taller than one page even when it is one page. Ask it
    // how tall it really is; fall back to the page plus that margin.
    var onePage = page.h + 36, contentH = onePage;
    try {
      var doc = frame.contentDocument;
      if (doc && doc.body) {
        contentH = Math.max(contentH, doc.documentElement.scrollHeight, doc.body.scrollHeight);
      }
    } catch (e) { /* not loaded yet; the fallback is right for one page */ }
    // Fit in *both* directions. Fitting the width alone left a landscape sheet
    // complete but cut off at the bottom — the same defect turned ninety
    // degrees: a preview you have to scroll to read is not showing you a page.
    var cap = Math.round(window.innerHeight * maxStageVh());
    var scale = Math.min(1, room / page.w, cap / onePage);
    var offset = Math.max(0, Math.round((room - page.w * scale) / 2));
    frame.style.width = page.w + 'px';
    frame.style.height = contentH + 'px';
    frame.style.transform = 'translateX(' + offset + 'px) scale(' + scale + ')';
    // A transform does not change layout size, so the stage is told how tall the
    // scaled document actually is. One page always fits; several scroll.
    stage.style.height = Math.min(Math.round(contentH * scale), cap) + 'px';
    if (zoom) {
      var pages = Math.max(1, Math.round(contentH / onePage));
      zoom.textContent = Math.round(scale * 100) + '% of actual size'
        + (pages > 1 ? ' · ' + pages + ' pages' : '');
    }
  }

  /* Presets set the boxes and then get out of the way — they are not a third
   * radio group. Somebody who picks "Birthdays & holidays" and then also wants
   * ministry events should be able to tick it without the preset fighting back. */
  var presetButtons = Array.prototype.slice.call(document.querySelectorAll('.pc-preset'));
  var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name=source]'));
  function currentSources(){
    return boxes.filter(function(b){ return b.checked; }).map(function(b){ return b.value; }).sort().join(',');
  }
  function markPresets(){
    var now = currentSources();
    presetButtons.forEach(function(btn){
      var want = (btn.dataset.sources || '').split(',').filter(Boolean).sort().join(',');
      btn.setAttribute('aria-pressed', want === now ? 'true' : 'false');
    });
    var note = $('pcSrcNote');
    if (note) {
      var n = boxes.filter(function(b){ return b.checked; }).length;
      // An empty selection prints an empty calendar, which looks like a fault
      // rather than a choice. Say which it is.
      note.textContent = n === 0 ? 'Nothing selected — the sheet will be empty.'
        : n + (n === 1 ? ' calendar selected.' : ' calendars selected.');
    }
  }
  presetButtons.forEach(function(btn){
    btn.setAttribute('aria-pressed', 'false');
    btn.addEventListener('click', function(){
      var want = (btn.dataset.sources || '').split(',').filter(Boolean);
      boxes.forEach(function(b){ b.checked = want.indexOf(b.value) !== -1; });
      markPresets(); onChange();
    });
  });

  /* Fold the options away to judge the sheet. Remembered, because somebody
     comparing layouts will collapse it once and want it to stay that way. */
  var layout = $('pcLayout'), toggle = $('pcToggle');
  var COLLAPSE_KEY = 'church_portal_print_options_collapsed_v1';
  function setCollapsed(on){
    layout.classList.toggle('is-collapsed', on);
    toggle.setAttribute('aria-expanded', on ? 'false' : 'true');
    toggle.querySelector('.pc-collapse-text').textContent = on ? 'Show options' : 'Hide options';
    try { localStorage.setItem(COLLAPSE_KEY, on ? '1' : '0'); } catch (e) {}
    fit();
  }
  toggle.addEventListener('click', function(){ setCollapsed(!layout.classList.contains('is-collapsed')); });
  try { if (localStorage.getItem(COLLAPSE_KEY) === '1') setCollapsed(true); } catch (e) {}

  var timer;
  function refresh(){
    clearTimeout(timer);
    timer = setTimeout(function(){
      var next = query(read());
      status.textContent = 'Preparing…';
      frame.src = next;
      openLink.href = next;
      fit();
    }, 200);
  }

  function onChange(){
    var c = read();
    summarise(c);
    refreshViewState();
    refresh();
  }

  form.addEventListener('change', function(e){
    var t = e.target;
    if (t.id === 'pcRange') dates.hidden = t.value !== 'custom';
    if (t.name === 'source') markPresets();
    // Remember that this control is now the reader's, so a later theme change
    // leaves it alone.
    if (t.name === 'entry') touched.entry = true;
    if (THEMED[t.id]) touched[t.id] = true;
    if (t.name === 'theme') { applyThemeDefaults(t.value); syncTheme('none'); }
    if (t.name === 'template') syncTheme();
    onChange();
  });
  form.addEventListener('input', function(e){
    if (e.target.tagName === 'TEXTAREA' || e.target.type === 'text') onChange();
  });
  frame.addEventListener('load', function(){
    if (frame.src !== 'about:blank') status.textContent = 'Preview';
    fit();   // only now is the real height knowable
  });
  window.addEventListener('resize', fit);

  function printSheet(){
    if (frame.contentWindow) { frame.contentWindow.focus(); frame.contentWindow.print(); }
  }
  $('pcPrint').addEventListener('click', printSheet);
  $('pcPdf').addEventListener('click', function(){
    // Same dialog. The hint beside it says which destination to pick, and is
    // announced when this is the route somebody took.
    var hint = $('pcPdfHint');
    if (hint) hint.setAttribute('role', 'status');
    printSheet();
  });

  // Start from the saved view if one was opened; otherwise from the first
  // normal choice, and remember whatever the reader last used.
  var SOURCES_KEY = 'church_portal_print_sources_v1';
  write(INITIAL);
  var qs = new URLSearchParams(location.search);
  var fromCalendar = !saved && (qs.get('sources') || qs.get('dateMode') || qs.get('start'));
  if (fromCalendar) {
    // Calendar "Print this view" lands here with the layers and dates on
    // screen. Paper fields stay at the studio default; we only take content.
    var merged = read();
    merged.date.mode = qs.get('dateMode') || 'custom';
    merged.date.from = qs.get('start') || null;
    merged.date.to = qs.get('end') || null;
    merged.content.sources = (qs.get('sources') || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
    write(merged);
    savedConfig = JSON.stringify(read());
  } else if (!saved && INITIAL.content.sources.length === 0) {
    var restored = null;
    try { restored = localStorage.getItem(SOURCES_KEY); } catch (e) {}
    var want = (restored !== null ? restored
      : (presetButtons.length ? presetButtons[0].dataset.sources || '' : '')).split(',').filter(Boolean);
    boxes.forEach(function(b){ b.checked = want.indexOf(b.value) !== -1; });
    savedConfig = JSON.stringify(read());
  }
  form.addEventListener('change', function(e){
    if (e.target.name !== 'source') return;
    try { localStorage.setItem(SOURCES_KEY, currentSources()); } catch (err) {}
  });

  markPresets();
  syncTheme(INITIAL.appearance.artwork);
  onChange();
  fit();
})();
</script>
<?php
echo (string) ob_get_clean();
