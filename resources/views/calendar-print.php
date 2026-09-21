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
<div class="shell" <?= portal_shell_mods('wide') ?>>
  <?= portal_header(
      $basePath, 'Print studio',
      'Pick a saved publication or build one, then print it or save it as a PDF.',
      is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [],
      $campusSelector['defaultCampusId'] ?? null,
      $actor, [], [], [], 'Sign in', $basePath . '/login',
  ) ?>

  <!-- Three parts: what goes on the sheet (left), the sheet itself (centre),
       how it looks (right). Either side folds to a rail so the sheet can take
       the stage; folding hides a panel and never changes a setting. One form
       around all three, because every control is read from it by name. -->
  <form class="ps" id="pcForm" novalidate>
  <div class="ps-grid" id="pcLayout" data-left="open" data-right="open">

    <aside class="ps-side ps-left" id="pcLeft" aria-label="Calendar settings">
      <div class="ps-head">
        <h2 class="ps-title">Calendar</h2>
        <button class="ps-fold" type="button" id="pcLeftToggle" aria-expanded="true" aria-controls="pcLeftBody">
          <span class="ps-fold-icon" aria-hidden="true">‹</span><span class="ps-fold-text">Hide</span>
        </button>
      </div>
      <button class="ps-rail" type="button" id="pcLeftRail" aria-expanded="false" aria-controls="pcLeftBody" hidden>
        <span aria-hidden="true">›</span><span class="ps-rail-text">Calendar settings</span>
      </button>
      <div class="ps-body" id="pcLeftBody">

      <!-- VIEW: which saved publication this is. Not a tab, because it is the
           answer to "what am I looking at?" and must never be folded away. -->
      <section class="pc-view" aria-labelledby="pcViewLab">
        <h3 id="pcViewLab" class="pc-lab">Publication</h3>
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
          <p class="pc-viewnote" id="pcViewNote" role="status"></p>
        </div>
        <div class="pc-viewacts">
          <button type="button" class="pc-mini" id="pcSave" disabled>Save</button>
          <button type="button" class="pc-mini" id="pcSaveAs">Save as…</button>
          <button type="button" class="pc-mini" id="pcReset" disabled>Reset</button>
          <button type="button" class="pc-mini" id="pcRename" hidden>Rename</button>
          <button type="button" class="pc-mini" id="pcDuplicate" hidden>Duplicate</button>
          <button type="button" class="pc-mini pc-mini--danger" id="pcDelete" hidden>Delete</button>
        </div>
      </section>

      <div class="ps-tabs" role="tablist" aria-label="Calendar settings" id="pcTabs">
        <button type="button" role="tab" id="pcTab-content" aria-controls="pcPane-content" aria-selected="true">Content</button>
        <button type="button" role="tab" id="pcTab-people" aria-controls="pcPane-people" aria-selected="false" tabindex="-1">People &amp; events</button>
        <button type="button" role="tab" id="pcTab-layout" aria-controls="pcPane-layout" aria-selected="false" tabindex="-1">Layout</button>
        <button type="button" role="tab" id="pcTab-print" aria-controls="pcPane-print" aria-selected="false" tabindex="-1">Print</button>
      </div>

      <!-- CONTENT: what the sheet is called, which dates, what is written on it. -->
      <div class="ps-pane" role="tabpanel" id="pcPane-content" aria-labelledby="pcTab-content">
        <div class="pc-field"><label for="hTitle">Calendar title</label>
          <input id="hTitle" type="text" maxlength="120" placeholder="Worked out from what is on it, e.g. Birthdays">
          <span class="pc-hint">Printed large at the top. Leave blank to name the sheet after what is on it.</span></div>
        <div class="pc-field"><label for="hSubtitle">Line under the title</label>
          <input id="hSubtitle" type="text" maxlength="160" placeholder="Optional"></div>
        <div class="pc-field"><label for="pcRange">Period</label>
          <select id="pcRange" name="range">
            <option value="this-month">This month</option>
            <option value="next-month">Next month</option>
            <option value="quarter">Next three months</option>
            <option value="year">This year</option>
            <option value="custom">Pick dates…</option>
          </select></div>
        <div class="pc-dates" id="pcDates" hidden>
          <div class="pc-field"><label for="pcStart">From</label><input id="pcStart" type="date" value="<?= $h($today->format('Y-m-01')) ?>"></div>
          <div class="pc-field"><label for="pcEnd">To</label><input id="pcEnd" type="date" value="<?= $h($today->modify('last day of this month')->format('Y-m-d')) ?>"></div>
        </div>
        <!-- Absent unless written in. An empty region that still reserves
             height is the fastest way to lose a row of a wall calendar, so
             these emit no element at all when blank. -->
        <div class="pc-field"><label for="pcTop">Note above the calendar</label>
          <textarea id="pcTop" rows="3" maxlength="4000" placeholder="Optional. Basic formatting is kept."></textarea></div>
        <div class="pc-field"><label for="pcBottom">Note below the calendar</label>
          <textarea id="pcBottom" rows="3" maxlength="4000" placeholder="Optional."></textarea></div>
        <p class="pc-hint">Left blank, neither note takes any room on the page.</p>
      </div>

      <!-- PEOPLE & EVENTS: which calendars go on the sheet and how people are named. -->
      <div class="ps-pane" role="tabpanel" id="pcPane-people" aria-labelledby="pcTab-people" hidden>
        <h3 class="pc-lab">Calendars on the sheet</h3>
        <?php if (($presets ?? []) !== []): ?>
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

        <h3 class="pc-lab">Birthdays</h3>
        <div class="pc-field"><label for="pcNames">Name each celebrant by</label>
          <select id="pcNames">
            <option value="first">The name they go by</option>
            <option value="initial">The name they go by, with a last initial</option>
          </select>
          <span class="pc-hint">Their preferred name, or first name when there is none. Ages are never printed.</span></div>
        <div class="pc-field"><label for="pcMark">Show member types by</label>
          <select id="pcMark">
            <option value="highlight">Highlighting the name</option>
            <option value="text">Colouring the name</option>
          </select>
          <span class="pc-hint">G&amp;A sky blue, Trailblazer green, Radical orange. No extra marks take room in a busy day.</span></div>
        <label class="pc-check"><input type="checkbox" id="pcLegend" checked><span>Print the member-type key</span></label>

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

      <!-- LAYOUT: the document, the paper, and how the type is set. -->
      <div class="ps-pane" role="tabpanel" id="pcPane-layout" aria-labelledby="pcTab-layout" hidden>
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
        <div class="pc-grid2">
          <div class="pc-field"><label for="pcPaper">Paper</label>
            <select id="pcPaper">
              <option value="letter">Letter</option><option value="a4">A4</option>
              <option value="legal">Legal</option><option value="tabloid">Tabloid (11 × 17)</option>
              <option value="a3">A3</option>
            </select></div>
          <div class="pc-field"><label for="pcOrientation">Direction</label>
            <select id="pcOrientation">
              <option value="">Auto</option><option value="portrait">Portrait</option>
              <option value="landscape">Landscape</option>
            </select></div>
        </div>
        <div class="pc-field"><label for="pcHeight">Busy days</label>
          <select id="pcHeight">
            <option value="fit">One page: extra entries become “+n more”</option>
            <option value="grow">Grow to fit: nothing is hidden, may use more pages</option>
          </select>
          <span class="pc-hint">One page keeps each month to a single sheet. Growing shows every entry; a month continues on the next sheet, between weeks, only when it must.</span></div>
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
        </div>
        <fieldset class="pc-set"><legend>Show in the header</legend>
          <label class="pc-check"><input type="checkbox" id="hChurch" checked><span>Church name</span></label>
          <label class="pc-check"><input type="checkbox" id="hLocation" checked><span>Campus</span></label>
          <label class="pc-check"><input type="checkbox" id="hDocType" checked><span>Calendar title</span></label>
          <label class="pc-check"><input type="checkbox" id="hPeriod" checked><span>Month or period</span></label>
        </fieldset>
        <fieldset class="pc-set"><legend>Show in the footer</legend>
          <label class="pc-check"><input type="checkbox" id="fPrinted" checked><span>Date printed</span></label>
          <label class="pc-check"><input type="checkbox" id="fWebsite" checked><span>Website</span></label>
          <label class="pc-check"><input type="checkbox" id="fChurch"><span>Church name</span></label>
          <label class="pc-check"><input type="checkbox" id="fPage"><span>Page number</span></label>
        </fieldset>
        <div class="pc-field"><label for="fNote">Footer note</label>
          <input id="fNote" type="text" maxlength="200" placeholder="Optional"></div>
      </div>

      <!-- PRINT: getting it onto paper. -->
      <div class="ps-pane" role="tabpanel" id="pcPane-print" aria-labelledby="pcTab-print" hidden>
        <div class="pc-actions">
          <button class="pc-btn pc-btn--primary" type="button" id="pcPrint">Print</button>
          <button class="pc-btn pc-btn--ghost" type="button" id="pcPdf">Save as PDF</button>
          <a class="pc-btn pc-btn--ghost" id="pcOpen" href="#" target="_blank" rel="noopener">Open in a new tab</a>
        </div>
        <!-- Both buttons open the same dialog, because a browser will not let a
             page choose the destination for you. -->
        <p class="pc-hint" id="pcPdfHint">Saving opens the same print dialog: choose
          <strong>Save as PDF</strong> as the destination. Turn on <strong>background graphics</strong>
          for fills and decoration to print; without them the calendar is still complete.</p>
        <h3 class="pc-lab">Edit it in PowerPoint</h3>
        <a class="pc-btn pc-btn--ghost" id="pcPptx" href="#" download>Export editable PowerPoint</a>
        <!-- A download, not a print: the same calendar as native slides. -->
        <p class="pc-hint" id="pcPptxHint">One slide per month: the title, dates, events and birthdays are text boxes
          you can change, and a PowerPoint theme's artwork comes along as its own shapes and pictures.
          <span id="pcPptxLayout" hidden>It is always the month grid, whichever layout is chosen here.</span></p>
        <h3 class="pc-lab">Start again</h3>
        <button class="pc-mini" type="button" id="pcDefaults">Restore the default settings</button>
        <p class="pc-hint">Keeps the calendars and dates you chose; resets everything about how it looks.</p>
      </div>

      </div>
    </aside>

    <section class="ps-center pc-preview" aria-label="Preview">
      <div class="pc-preview-bar">
        <span class="pc-status" id="pcStatus" role="status">Preview</span>
        <div class="pc-zoombox" role="group" aria-label="Preview size">
          <button type="button" class="pc-zbtn" id="pcZoomOut" aria-label="Smaller">−</button>
          <button type="button" class="pc-zbtn" id="pcZoomFit" aria-pressed="true">Fit</button>
          <button type="button" class="pc-zbtn" id="pcZoom100" aria-pressed="false">100%</button>
          <button type="button" class="pc-zbtn" id="pcZoomIn" aria-label="Larger">+</button>
        </div>
        <span class="pc-zoom" id="pcZoom"></span>
        <button class="pc-mini" type="button" id="pcEditToggle" aria-pressed="false"
                title="Type the title, notes and footer straight onto the sheet. Print-only: the calendar itself is not changed.">Edit text on the sheet</button>
        <button class="pc-btn pc-btn--primary pc-btn--sm" type="button" id="pcPrintBar">Print</button>
      </div>
      <!-- The sheet is rendered at its true paper size and scaled for the
           screen. Zoom changes only this scale, never the printed size. -->
      <div class="pc-stage" id="pcStage">
        <iframe id="pcFrame" title="Calendar preview" src="about:blank"></iframe>
      </div>
    </section>

    <aside class="ps-side ps-right" id="pcRight" aria-label="Theme">
      <div class="ps-head">
        <h2 class="ps-title">Theme</h2>
        <button class="ps-fold" type="button" id="pcRightToggle" aria-expanded="true" aria-controls="pcRightBody">
          <span class="ps-fold-text">Hide</span><span class="ps-fold-icon" aria-hidden="true">›</span>
        </button>
      </div>
      <button class="ps-rail" type="button" id="pcRightRail" aria-expanded="false" aria-controls="pcRightBody" hidden>
        <span aria-hidden="true">‹</span><span class="ps-rail-text">Theme</span>
      </button>
      <div class="ps-body" id="pcRightBody">
        <!-- Cards rather than a list of words: a theme is a look. Each preview
             shows a title band, a birthday row and an event row in the
             theme's own colours, drawn in CSS with nothing to download. -->
        <?php
          $monthNames = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July',
                         'August', 'September', 'October', 'November', 'December'];
          $card = static function (string $tid, array $theme, string $meta = '') use ($h, $monthNames): string {
              ob_start(); ?>
            <label class="pc-theme" data-theme="<?= $h($tid) ?>"
                   data-layouts="<?= $h(implode(',', $theme['layouts'])) ?>"
                   data-artwork="<?= $h(implode(',', $theme['artwork'])) ?>"
                   data-defaults="<?= $h(json_encode($theme['defaults'], JSON_THROW_ON_ERROR)) ?>">
              <input type="radio" name="theme" value="<?= $h($tid) ?>"
                     <?= $tid === 'classic' ? 'checked' : '' ?>
                     aria-describedby="pcThemeB-<?= $h($tid) ?>">
              <span class="pc-thumb<?= $tid === 'auto' ? ' pc-thumb--auto' : '' ?>" aria-hidden="true"
                    style="<?= $h(\App\Services\Calendar\CalendarTheme::swatchCss($tid === 'auto' ? 'harvest-beginning' : $tid)) ?>">
                <span class="pc-thumb-t"></span>
                <span class="pc-thumb-row pc-thumb-row--b"></span>
                <span class="pc-thumb-row pc-thumb-row--e"></span>
                <span class="pc-thumb-g"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
              </span>
              <span class="pc-theme-name"><?= $h($theme['label']) ?>
                <?php if (($theme['ink'] ?? '') === 'low'): ?><span class="pc-ink">Low ink</span><?php endif; ?></span>
              <?php if ($meta !== ''): ?><span class="pc-theme-meta"><?= $h($meta) ?></span><?php endif; ?>
              <span class="pc-theme-blurb" id="pcThemeB-<?= $h($tid) ?>"><?= $h($theme['blurb']) ?></span>
            </label>
          <?php return (string) ob_get_clean(); };
        ?>
        <!-- Grouped the way a church thinks of the year: follow the month, the
             four Canadian seasons (winter runs December to February), then the
             general designs. One radio group across all of them. -->
        <div class="pc-themes" role="radiogroup" aria-label="Theme">
          <?= $card('auto', [
              'label' => 'Automatic', 'layouts' => ['monthly', 'weekly'], 'artwork' => ['none'], 'defaults' => [],
              'blurb' => 'Each month prints in its own monthly theme, taken from the month on the sheet.',
          ], 'Follows the month') ?>
          <?php foreach (\App\Services\Calendar\CalendarTheme::SEASONS as $sid => $season): ?>
            <h3 class="pc-lab pc-season" id="pcSeason-<?= $h($sid) ?>"><?= $h($season['label']) ?></h3>
            <?php foreach ($season['months'] as $m): $tid = \App\Services\Calendar\CalendarTheme::forMonth($m); ?>
              <?= $card($tid, $themes[$tid], $monthNames[$m] . ' · ' . $season['label']) ?>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <h3 class="pc-lab pc-season">Any month</h3>
          <?php foreach ($themes as $tid => $theme): if (\App\Services\Calendar\CalendarTheme::isSeasonal($tid)) { continue; } ?>
            <?= $card($tid, $theme) ?>
          <?php endforeach; ?>
          <!-- Designed in PowerPoint and uploaded here; filled in by script. -->
          <h3 class="pc-lab pc-season" id="pcPptxLab">PowerPoint themes</h3>
          <div id="pcPptxList" class="pc-pptx-list"></div>
        </div>
        <section class="pc-pptx" aria-labelledby="pcPptxLab">
          <p class="pc-hint" id="pcPptxEmpty">Design a calendar in PowerPoint: download a starter, decorate it around the dashed boxes, and upload it here.</p>
          <details class="pc-pptx-start">
            <summary>Download a theme starter</summary>
            <ul class="pc-starters" id="pcStarters"></ul>
          </details>
          <div id="pcPptxUpload" hidden>
            <div class="pc-field"><label for="pcPptxName">New theme name</label>
              <input id="pcPptxName" type="text" maxlength="120" placeholder="e.g. Harvest welcome"></div>
            <div class="pc-field"><label for="pcPptxFile">Upload a PowerPoint theme (.pptx)</label>
              <input id="pcPptxFile" type="file" accept=".pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation"></div>
          </div>
          <div id="pcPptxStatus" class="pc-pptx-status" role="status"></div>
          <div id="pcPptxManage" class="pc-viewacts" hidden>
            <button type="button" class="pc-mini" id="pcPptxReplace">Upload a new version</button>
            <button type="button" class="pc-mini" id="pcPptxPublish">Share with the church</button>
            <button type="button" class="pc-mini pc-mini--danger" id="pcPptxRetire">Retire theme</button>
            <input id="pcPptxReplaceFile" type="file" accept=".pptx" hidden>
          </div>
        </section>
        <!-- Which theme the sheet is actually in, and the way back to "follow
             the month" after choosing one by hand. -->
        <div class="pc-mode" id="pcThemeMode">
          <p class="pc-hint" id="pcThemeModeText" role="status"></p>
          <button type="button" class="pc-mini" id="pcUseAuto" hidden>Use monthly default</button>
        </div>
        <p class="pc-hint" id="pcThemeNote"></p>
        <div class="pc-field"><label for="pcAccent">Accent</label>
          <select id="pcAccent">
            <option value="">Church colour</option><option value="#0c5a45">Deep green</option>
            <option value="#13307c">Navy</option><option value="#7a1f3d">Burgundy</option>
            <option value="#3f3f46">Graphite</option>
          </select></div>
        <!-- Only for themes that carry artwork. -->
        <div class="pc-grid2" id="pcArtRow" hidden>
          <div class="pc-field"><label for="pcArtwork">Decoration</label>
            <select id="pcArtwork"></select></div>
          <div class="pc-field"><label for="pcDecor">How much</label>
            <select id="pcDecor">
              <option value="minimal">Minimal</option><option value="balanced" selected>Balanced</option>
              <option value="full">Full</option>
            </select></div>
        </div>
        <label class="pc-check"><input type="checkbox" id="pcInk"><span>Ink friendly: drop fills and decoration</span></label>
        <!-- A picture behind the calendar. Combined with whichever theme is
             chosen above; the theme still draws the grid and the writing. -->
        <section class="pc-bg" aria-labelledby="pcBgLab">
          <h3 class="pc-lab pc-season" id="pcBgLab">Background picture</h3>
          <div class="pc-bgs" id="pcBgList" role="radiogroup" aria-labelledby="pcBgLab">
            <label class="pc-bgopt"><input type="radio" name="bgpick" value="" checked><span class="pc-bgnone">None</span></label>
          </div>
          <div class="pc-field">
            <label for="pcBgFile">Add a picture (JPEG, PNG or WebP, up to 12 MB)</label>
            <input id="pcBgFile" type="file" accept="image/jpeg,image/png,image/webp">
          </div>
          <p class="pc-hint" id="pcBgStatus" role="status"></p>
          <div id="pcBgControls" hidden>
            <div class="pc-grid2">
              <div class="pc-field"><label for="pcBgFit">Size</label>
                <select id="pcBgFit"><option value="cover">Fill the page</option><option value="contain">Fit whole picture</option></select></div>
              <div class="pc-field"><label for="pcBgX">Across</label>
                <select id="pcBgX"><option value="left">Left</option><option value="center" selected>Centre</option><option value="right">Right</option></select></div>
              <div class="pc-field"><label for="pcBgY">Up and down</label>
                <select id="pcBgY"><option value="top">Top</option><option value="center" selected>Centre</option><option value="bottom">Bottom</option></select></div>
            </div>
            <div class="pc-field"><label for="pcBgOpacity">Picture strength <output id="pcBgOpacityOut">35%</output></label>
              <input id="pcBgOpacity" type="range" min="10" max="100" step="5" value="35"></div>
            <div class="pc-field"><label for="pcBgOverlay">Readability wash <output id="pcBgOverlayOut">55%</output></label>
              <input id="pcBgOverlay" type="range" min="0" max="90" step="5" value="55">
              <span class="pc-hint">A white wash over the picture keeps names and dates readable. Keep it at 40% or more for busy pictures.</span></div>
            <div class="pc-viewacts">
              <button type="button" class="pc-mini" id="pcBgNone">Remove background</button>
              <button type="button" class="pc-mini pc-mini--danger" id="pcBgDelete" hidden>Delete this picture</button>
            </div>
          </div>
        </section>
        <p class="pc-hint">Member types keep their colours in every theme: G&amp;A sky blue, Trailblazer green, Radical orange.</p>
      </div>
    </aside>

  </div>
  </form>

  <!-- Printing this page prints the editor, not the calendar. Ctrl+P is sent
       to the sheet (see the script); this is the fallback if a browser prints
       the page anyway. -->
  <p class="ps-print-note">To print the calendar, use the Print button in the Print studio.</p>

  <?= portal_footer() ?>
</div>

<style>
  /* ---- Workspace: left settings, centre sheet, right theme ---------------- */
  .ps{margin-top:10px}
  .ps-grid{display:grid;gap:12px;align-items:start;
    grid-template-columns:minmax(270px,310px) minmax(0,1fr) minmax(220px,260px)}
  .ps-grid[data-left="closed"]{grid-template-columns:40px minmax(0,1fr) minmax(220px,260px)}
  .ps-grid[data-right="closed"]{grid-template-columns:minmax(270px,310px) minmax(0,1fr) 40px}
  .ps-grid[data-left="closed"][data-right="closed"]{grid-template-columns:40px minmax(0,1fr) 40px}
  @media (max-width:1280px){
    .ps-grid{grid-template-columns:minmax(250px,280px) minmax(0,1fr) minmax(200px,230px)}
    .ps-grid[data-left="closed"]{grid-template-columns:40px minmax(0,1fr) minmax(200px,230px)}
    .ps-grid[data-right="closed"]{grid-template-columns:minmax(250px,280px) minmax(0,1fr) 40px}
  }
  .ps-side{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;min-width:0;
    position:sticky;top:12px;max-height:calc(100vh - 24px);display:flex;flex-direction:column}
  .ps-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 10px 8px 12px;
    border-bottom:1px solid var(--line,#e3eae5)}
  .ps-title{margin:0;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--ink,#17211b)}
  .ps-body{padding:10px 12px 14px;overflow-y:auto;overscroll-behavior:contain}
  .ps-fold-icon,.ps-rail > span[aria-hidden]{font-size:16px;line-height:1;font-weight:700}
  .ps-fold{display:inline-flex;align-items:center;gap:5px;min-height:30px;padding:4px 10px;border:1px solid var(--line,#c7d4cd);
    border-radius:999px;background:#fff;font:inherit;font-size:12px;font-weight:800;color:var(--deep,#0c5a45);cursor:pointer}
  .ps-fold:hover,.ps-rail:hover{background:var(--soft,#eef4f0)}
  /* A folded panel keeps a labelled rail, so the way back is always visible. */
  .ps-rail{display:flex;flex-direction:column;align-items:center;gap:8px;width:100%;min-height:180px;padding:10px 0;
    border:0;background:transparent;font:inherit;font-size:12px;font-weight:800;color:var(--deep,#0c5a45);cursor:pointer;border-radius:10px}
  .ps-rail-text{writing-mode:vertical-rl;letter-spacing:.04em}
  .ps-grid[data-left="closed"] .ps-left > .ps-head,.ps-grid[data-left="closed"] .ps-left > .ps-body,
  .ps-grid[data-right="closed"] .ps-right > .ps-head,.ps-grid[data-right="closed"] .ps-right > .ps-body{display:none}
  .ps-fold:focus-visible,.ps-rail:focus-visible,.ps-tabs [role=tab]:focus-visible,.pc-zbtn:focus-visible{
    outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}

  .ps-tabs{display:flex;flex-wrap:wrap;gap:2px;margin:10px 0 12px;border-bottom:1px solid var(--line,#e3eae5)}
  .ps-tabs [role=tab]{min-height:34px;padding:6px 9px;border:0;border-bottom:3px solid transparent;background:none;
    font:inherit;font-size:12.5px;font-weight:700;color:var(--muted,#5c6b63);cursor:pointer;margin-bottom:-1px}
  .ps-tabs [role=tab][aria-selected=true]{color:var(--deep,#0c5a45);border-bottom-color:var(--deep,#0c5a45)}
  .ps-pane > .pc-field,.ps-pane > .pc-grid2,.ps-pane > .pc-set,.ps-pane > .pc-check{margin-bottom:10px}
  .ps-pane .pc-lab{margin-top:14px}
  .ps-pane > .pc-lab:first-child{margin-top:0}

  /* Narrower screens: the theme panel starts folded, and below 900px the
     panels stack above the sheet in normal flow, never on top of it. */
  @media (max-width:900px){
    .ps-grid,.ps-grid[data-left],.ps-grid[data-right]{grid-template-columns:minmax(0,1fr)!important}
    .ps-side{position:static;max-height:none}
    .ps-left{order:1}.ps-right{order:2}.ps-center{order:3}
    .ps-rail{min-height:0;flex-direction:row;justify-content:flex-start;padding:10px 12px}
    .ps-rail-text{writing-mode:horizontal-tb}
  }

  .ps-print-note{display:none}
  @media print{
    .shell > *:not(.ps-print-note){display:none!important}
    .ps-print-note{display:block;font:14pt/1.4 Georgia,serif;margin:1in}
  }

  /* Visible to a screen reader, absent from the page. */
  .pc-sr{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
    clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}
  .pc-lab{margin:0 0 6px;font-size:11px;font-weight:800;text-transform:uppercase;
    letter-spacing:.06em;color:var(--muted,#5c6b63)}

  .pc-view{padding:0 0 10px;border-bottom:1px solid var(--line,#e3eae5)}
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

  .pc-seg{display:flex;flex-wrap:wrap;gap:4px}
  .pc-seg-opt{position:relative;display:inline-flex}
  .pc-seg-opt input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;width:100%;height:100%}
  .pc-seg-opt span:not(.pc-sr){display:inline-flex;align-items:center;min-height:30px;padding:5px 10px;
    border:1px solid var(--line,#c7d4cd);border-radius:999px;background:#fff;
    font-size:12.5px;font-weight:700;color:var(--ink,#17211b);cursor:pointer}
  .pc-seg-opt input:checked + span{background:var(--deep,#0c5a45);border-color:var(--deep,#0c5a45);color:#fff}
  .pc-seg-opt input:focus-visible + span{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}

  .pc-presets{display:flex;flex-wrap:wrap;gap:5px;margin:0 0 7px}
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

  .pc-tiles{display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:10px}
  .pc-tile{position:relative;display:flex}
  .pc-tile input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;width:100%;height:100%}
  .pc-tile span:not(.pc-sr){flex:1;display:inline-flex;align-items:center;min-height:34px;padding:6px 9px;
    border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff;
    font-size:12.5px;font-weight:700;line-height:1.2;cursor:pointer}
  .pc-tile input:checked + span{background:var(--soft,#eef4f0);border-color:var(--deep,#0c5a45);color:var(--deep,#0c5a45)}
  .pc-tile input:focus-visible + span{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
  .pc-tile:hover span:not(.pc-sr){border-color:var(--deep,#0c5a45)}
  .pc-tile[hidden]{display:none}

  /* Theme cards: a title band, a birthday row and an event row in the theme's
     own colours (CalendarTheme::swatchCss), so a card says what the sheet will
     look like. Selected is marked by a border, a fill *and* a tick. */
  .pc-themes{display:grid;grid-template-columns:1fr;gap:8px;margin-bottom:10px}
  .pc-theme{position:relative;display:grid;grid-template-columns:74px 1fr;gap:3px 10px;align-items:start;
    border:1px solid var(--line,#c7d4cd);border-radius:9px;padding:7px;background:#fff;cursor:pointer}
  .pc-theme[hidden]{display:none}
  .pc-theme input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;width:100%;height:100%}
  .pc-theme:hover{border-color:var(--deep,#0c5a45)}
  .pc-theme:has(input:checked){border-color:var(--deep,#0c5a45);box-shadow:inset 0 0 0 1px var(--deep,#0c5a45);
    background:var(--soft,#eef4f0)}
  .pc-theme:has(input:checked) .pc-theme-name::after{content:" ✓";color:var(--deep,#0c5a45)}
  .pc-theme:has(input:focus-visible){outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
  .pc-theme-name{grid-column:2;font-size:12.5px;font-weight:800;color:var(--ink,#17211b)}
  .pc-theme-meta{grid-column:2;font-size:10.5px;font-weight:700;letter-spacing:.03em;color:var(--deep,#0c5a45)}
  .pc-season{margin:10px 0 0}
  .pc-thumb--auto .pc-thumb-t{background:linear-gradient(90deg,#4a7fb0 0 25%,#5f8f63 25% 50%,#b88322 50% 75%,#b44a1c 75%)}
  .pc-pptx-list{display:grid;gap:8px}
  .pc-thumb--pptx{padding:0;overflow:hidden;background:#fff}
  .pc-thumb--pptx img{display:block;width:100%;height:100%;object-fit:contain}
  .pc-pptx{margin:6px 0 10px}
  .pc-pptx-start summary{cursor:pointer;font-size:12.5px;font-weight:700;color:var(--deep,#0c5a45);min-height:28px}
  .pc-starters{margin:4px 0 8px;padding-left:18px;font-size:12.5px}
  .pc-starters a{color:var(--deep,#0c5a45)}
  .pc-pptx-status{font-size:11.5px;line-height:1.4}
  .pc-pptx-status .is-error{color:#8c2f2f;font-weight:700}
  .pc-pptx-status ul{margin:4px 0 0;padding-left:16px;color:#8a5a00}
  .pc-scope{display:inline-block;margin-left:4px;padding:0 5px;border:1px solid var(--line,#c7d4cd);border-radius:999px;font-size:10px;font-weight:700;color:var(--muted,#5c6b63)}
  .pc-bgs{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:4px 0 8px}
  .pc-bgopt{position:relative;display:block;cursor:pointer}
  .pc-bgopt input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;width:100%;height:100%}
  .pc-bgopt img,.pc-bgnone{display:block;width:100%;aspect-ratio:3/4;object-fit:cover;border:1px solid var(--line,#c7d4cd);border-radius:6px;background:#fff}
  .pc-bgnone{display:grid;place-items:center;font-size:11.5px;font-weight:700;color:var(--muted,#5c6b63)}
  .pc-bgopt:has(input:checked) img,.pc-bgopt:has(input:checked) .pc-bgnone{outline:3px solid var(--deep,#0c5a45);outline-offset:1px}
  .pc-bgopt:has(input:checked)::after{content:"✓";position:absolute;top:3px;right:5px;font-weight:800;color:#fff;
    background:var(--deep,#0c5a45);border-radius:999px;width:18px;height:18px;display:grid;place-items:center;font-size:11px}
  .pc-bgopt:has(input:focus-visible){outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:3px;border-radius:6px}
  .pc-bgopt .pc-bgname{display:block;font-size:10.5px;line-height:1.2;margin-top:2px;color:var(--muted,#5c6b63);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .pc-field input[type=range]{padding:0;min-height:24px;border:0}
  .pc-field output{font-weight:700;text-transform:none;letter-spacing:0}
  .pc-mode{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:0 0 10px}
  .pc-mode .pc-hint{margin:0}
  .pc-theme-blurb{grid-column:2;font-size:11px;line-height:1.35;color:var(--muted,#5c6b63)}
  .pc-ink{display:inline-block;margin-left:4px;padding:0 5px;border:1px solid var(--line,#c7d4cd);border-radius:999px;
    font-size:10px;font-weight:700;color:var(--muted,#5c6b63)}
  .pc-thumb{grid-row:1 / span 2;display:flex;flex-direction:column;gap:2px;height:62px;border-radius:5px;overflow:hidden;
    background:var(--sw-paper,#fff);border:1px solid #dde5e0;padding:4px}
  .pc-thumb-t{display:block;height:9px;border-radius:2px;background:var(--sw-band,#0c5a45);width:70%}
  .pc-thumb-row{display:block;height:5px;border-radius:1px;border-left:3px solid;background:var(--sw-cell,#eef2ef)}
  .pc-thumb-row--b{border-left-color:#2f8a4a;width:60%}
  .pc-thumb-row--e{border-left-color:var(--sw-accent,#2c6ea5);width:80%}
  .pc-thumb-g{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;margin-top:auto}
  .pc-thumb-g i{display:block;height:9px;background:var(--sw-cell,#eef2ef);border:.5px solid var(--sw-grid,#dde5e0)}

  .pc-set{border:1px solid var(--line,#e3eae5);border-radius:8px;padding:7px 9px 8px;margin:0 0 9px}
  .pc-set legend{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;
    color:var(--muted,#5c6b63);padding:0 4px}
  .pc-check{display:flex;align-items:center;gap:7px;min-height:28px;font-size:12.5px;
    font-weight:600;color:var(--ink,#17211b);cursor:pointer}
  .pc-check input{width:16px;height:16px;flex:0 0 auto;accent-color:var(--deep,#0c5a45);cursor:pointer}
  .pc-check input:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}

  /* `hidden` has to win over a display rule on the same element. */
  #pcForm [hidden]{display:none!important}
  .pc-dates,.pc-grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .pc-dates{margin-bottom:10px}
  .pc-field label{display:block;font-size:10.5px;font-weight:800;text-transform:uppercase;
    letter-spacing:.04em;color:var(--muted,#5c6b63);margin-bottom:3px}
  .pc-field input,.pc-field select,.pc-field textarea{width:100%;font:inherit;font-size:13px;
    padding:6px 8px;min-height:34px;border:1px solid var(--line,#c7d4cd);border-radius:8px;
    background:#fff;color:var(--ink,#17211b)}
  .pc-field textarea{min-height:60px;resize:vertical;line-height:1.4}
  .pc-field :focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:1px}
  .pc-field .pc-hint{display:block}

  .pc-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px}
  .pc-btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:9px 16px;
    border-radius:8px;border:1px solid transparent;font:inherit;font-size:13px;font-weight:800;
    cursor:pointer;text-decoration:none}
  .pc-btn--sm{min-height:32px;padding:5px 14px}
  .pc-btn--primary{background:var(--deep,#0c5a45);color:#fff}
  .pc-btn--ghost{background:#fff;color:var(--deep,#0c5a45);border-color:var(--line,#c7d4cd)}
  .pc-btn:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
  .pc-hint{font-size:11.5px;color:var(--muted,#5c6b63);margin:5px 0 0;line-height:1.4}

  .pc-preview{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;overflow:hidden;min-width:0}
  .pc-preview-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;
    padding:7px 10px 7px 13px;border-bottom:1px solid var(--line,#eef2f5);font-size:12px;
    color:var(--muted,#5c6b63);font-weight:700}
  .pc-status{margin-right:auto}
  .pc-zoombox{display:inline-flex;border:1px solid var(--line,#c7d4cd);border-radius:8px;overflow:hidden}
  .pc-zbtn{min-width:34px;min-height:30px;padding:3px 9px;border:0;border-right:1px solid var(--line,#c7d4cd);background:#fff;
    font:inherit;font-size:12px;font-weight:800;color:var(--deep,#0c5a45);cursor:pointer}
  .pc-zbtn:last-child{border-right:0}
  .pc-zbtn[aria-pressed=true]{background:var(--soft,#eef4f0)}
  .pc-zoom{font-variant-numeric:tabular-nums;font-weight:600}
  /* The stage owns the visible box. At "Fit" one page always fits; zoomed in,
     it scrolls in both directions rather than cutting the sheet off. */
  .pc-stage{position:relative;overflow:auto;background:#e9edeb}
  #pcFrame{border:0;background:#fff;display:block;transform-origin:top left}
</style>
<script>
(function(){
  'use strict';
  var form = document.getElementById('pcForm');
  var frame = document.getElementById('pcFrame');
  var openLink = document.getElementById('pcOpen');
  var pptxLink = document.getElementById('pcPptx');
  var status = document.getElementById('pcStatus');
  var dates = document.getElementById('pcDates');
  var base = <?= json_encode($basePath) ?>;
  var THEMES = <?= json_encode(array_map(static fn (array $t): array => [
      'layouts' => $t['layouts'], 'artwork' => $t['artwork'], 'defaults' => $t['defaults'],
      'label' => $t['label'], 'month' => $t['month'] ?? null,
  ], $themes) + ['auto' => ['layouts' => ['monthly', 'weekly'], 'artwork' => ['none'], 'defaults' => new stdClass(),
      'label' => 'Automatic', 'month' => null]], JSON_THROW_ON_ERROR) ?>;
  var MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  function monthlyThemeFor(m){
    var id = Object.keys(THEMES).filter(function(t){ return THEMES[t].month === m; })[0];
    return id ? THEMES[id].label : '';
  }
  /* The first month the sheet will print, worked out as the server will. */
  function firstPrintedMonth(){
    var mode = $('pcRange').value, now = new Date();
    if (mode === 'custom' && $('pcStart').value) return parseInt($('pcStart').value.slice(5, 7), 10);
    if (mode === 'next-month') return (now.getMonth() + 1) % 12 + 1;
    if (mode === 'year') return 1;
    return now.getMonth() + 1;
  }
  function spansMonths(){
    var mode = $('pcRange').value;
    if (mode === 'quarter' || mode === 'year') return true;
    return mode === 'custom' && $('pcStart').value.slice(0, 7) !== $('pcEnd').value.slice(0, 7);
  }
  /* Say which theme the sheet is in. "Automatic" names the month's theme;
   * a theme chosen by hand says so and offers the way back. */
  function updateThemeMode(){
    var theme = pick('theme') || 'classic', m = firstPrintedMonth();
    var text = $('pcThemeModeText'), back = $('pcUseAuto');
    var autoCard = document.querySelector('.pc-theme[data-theme="auto"]');
    var autoOk = autoCard && !autoCard.hidden;
    if (theme === 'auto') {
      text.textContent = 'Automatic: ' + MONTH_NAMES[m - 1] + ' prints in ' + monthlyThemeFor(m)
        + (spansMonths() ? ', and each later month in its own theme.' : '.');
      back.hidden = true;
    } else {
      text.textContent = autoOk ? 'Chosen by hand: this overrides the monthly theme ('
        + MONTH_NAMES[m - 1] + ' would be ' + monthlyThemeFor(m) + ').' : '';
      back.hidden = !autoOk;
    }
  }
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
      page: { paper: $('pcPaper').value, orientation: $('pcOrientation').value, height: $('pcHeight').value || 'fit' },
      appearance: {
        typeScale: parseFloat($('pcScale').value) || 1,
        font: $('pcFont').value, names: $('pcNames').value,
        density: $('pcDensity').value, titleStyle: $('pcTitleStyle').value,
        accent: $('pcAccent').value, theme: pick('theme') || 'classic', themeVersion: pptxVersionFor(pick('theme')),
        entryDisplay: pick('entry') || 'auto',
        artwork: $('pcArtwork').value || 'none', decoration: $('pcDecor').value,
        inkFriendly: $('pcInk').checked, legend: $('pcLegend').checked, memberMark: $('pcMark').value,
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
      background: (function(){
        var id = parseInt(pick('bgpick') || '0', 10) || 0;
        return { mode: id > 0 ? 'image' : 'none', id: id, fit: $('pcBgFit').value,
          x: $('pcBgX').value, y: $('pcBgY').value,
          opacity: (parseInt($('pcBgOpacity').value, 10) || 35) / 100,
          overlay: (parseInt($('pcBgOverlay').value, 10) || 0) / 100 };
      })(),
    };
  }

  function write(c){
    if (!c) return;
    var a = c.appearance || {}, d = c.date || {}, hh = c.header || {}, ff = c.footer || {},
        ad = c.additional || {}, pg = c.page || {};
    setPick('template', c.layout || 'monthly');
    pinnedPptx = /^pptx:/.test(a.theme || '') ? { theme: a.theme, version: a.themeVersion || 0 } : null;
    if (typeof ensurePptxCard === 'function') ensurePptxCard(a.theme);
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
    $('pcHeight').value = pg.height || 'fit';
    var bg = c.background || {};
    $('pcBgFit').value = bg.fit || 'cover';
    $('pcBgX').value = bg.x || 'center';
    $('pcBgY').value = bg.y || 'center';
    $('pcBgOpacity').value = String(Math.round((bg.opacity || 0.35) * 100));
    $('pcBgOverlay').value = String(Math.round((bg.overlay !== undefined ? bg.overlay : 0.55) * 100));
    pendingBg = bg.mode === 'image' ? (bg.id || 0) : 0;
    if (typeof selectBackground === 'function') selectBackground(pendingBg);
    $('pcLegend').checked = a.legend !== false;
    $('pcMark').value = a.memberMark === 'text' ? 'text' : 'highlight';
    $('pcScale').value = String(a.typeScale || 1);
    $('pcFont').value = a.font || 'serif';
    $('pcNames').value = a.names === 'initial' || a.names === 'short' ? 'initial' : 'first';
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
     ['height', c.page.height, D.page.height],
     ['scale', c.appearance.typeScale, D.appearance.typeScale], ['font', c.appearance.font, D.appearance.font],
     ['names', c.appearance.names, D.appearance.names], ['density', c.appearance.density, D.appearance.density],
     ['titleStyle', c.appearance.titleStyle, D.appearance.titleStyle], ['accent', c.appearance.accent, D.appearance.accent],
     ['theme', c.appearance.theme, D.appearance.theme], ['entry', c.appearance.entryDisplay, D.appearance.entryDisplay],
     ['artwork', c.appearance.artwork, D.appearance.artwork], ['decor', c.appearance.decoration, D.appearance.decoration],
     ['mark', c.appearance.memberMark, D.appearance.memberMark],
     ['themeV', c.appearance.themeVersion, D.appearance.themeVersion]
    ].forEach(function(row){ if (String(row[1]) !== String(row[2])) p.set(row[0], String(row[1])); });
    if (c.appearance.inkFriendly) p.set('ink', '1');
    if (!c.appearance.legend) p.set('legend', '0');
    if (c.background.mode === 'image') {
      p.set('bg', String(c.background.id));
      [['bgFit', c.background.fit, D.background.fit], ['bgX', c.background.x, D.background.x],
       ['bgY', c.background.y, D.background.y], ['bgOpacity', c.background.opacity, D.background.opacity],
       ['bgOverlay', c.background.overlay, D.background.overlay]
      ].forEach(function(row){ if (String(row[1]) !== String(row[2])) p.set(row[0], String(row[1])); });
    }
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
    // The campus chosen in the top bar, so a link or a PDF says which campus
    // it shows. (Changing the campus reloads this page.)
    var campusSel = document.getElementById('campusSelect') || document.getElementById('campusSelectMobile');
    if (campusSel && parseInt(campusSel.value || '0', 10) > 0) p.set('current_campus_id', campusSel.value);
    if (editing) p.set('edit', '1');
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
        ? only.length + ' theme' + (only.length === 1 ? ' is' : 's are') + ' hidden: not designed for this layout.'
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
    $('pcRename').hidden = !saved || !saved.canEdit;
    $('pcDuplicate').hidden = !saved;
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
  var PAPER = <?= json_encode(\App\Services\Calendar\PrintConfig::PAPERS, JSON_THROW_ON_ERROR) ?>;
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
  /* Preview size. "Fit" shows one whole page; any other zoom is a fixed
   * scale that scrolls. Only the preview's transform changes: the document in
   * the frame, and so the printed page, is identical at every zoom. */
  var zoomMode = 'fit', zoomScale = 1;
  var ZOOM_STEPS = [0.25, 0.33, 0.5, 0.67, 0.75, 0.9, 1, 1.25, 1.5, 2];
  function fitScale(page, onePage){
    var room = stage.clientWidth - 24;
    var cap = Math.max(260, window.innerHeight - stage.getBoundingClientRect().top - 24);
    return { scale: Math.min(1, room / page.w, cap / onePage), cap: cap };
  }
  function fit(){
    if (!stage || !frame || !stage.clientWidth) return;
    var page = paperPx();
    // The print shell centres its sheet with a screen margin, so the document
    // is taller than one page even when it is one page. Ask it how tall it is.
    var onePage = page.h + 36, contentH = onePage;
    try {
      var doc = frame.contentDocument;
      if (doc && doc.body) {
        contentH = Math.max(contentH, doc.documentElement.scrollHeight, doc.body.scrollHeight);
      }
    } catch (e) { /* not loaded yet; the fallback is right for one page */ }
    // One page is fitted whole, at the height it really has on screen (the
    // sheet's screen padding makes it a little taller than the paper). Longer
    // documents fit a page and scroll.
    var pagesNow = Math.max(1, Math.round(contentH / onePage));
    var f = fitScale(page, pagesNow === 1 ? Math.max(onePage, contentH) : onePage);
    var scale = zoomMode === 'fit' ? f.scale : zoomScale;
    var offset = Math.max(12, Math.round((stage.clientWidth - page.w * scale) / 2));
    frame.style.width = page.w + 'px';
    frame.style.height = contentH + 'px';
    frame.style.transform = 'translate(' + offset + 'px, 12px) scale(' + scale + ')';
    // A transform does not change layout size, so a spacer box is sized to the
    // scaled sheet: that is what gives the stage its scrollbars when zoomed.
    frame.style.marginRight = '0';
    stage.style.height = Math.min(Math.round(contentH * scale) + 24, f.cap) + 'px';
    spacer.style.width = Math.round(page.w * scale + offset + 12) + 'px';
    spacer.style.height = Math.round(contentH * scale + 24) + 'px';
    if (zoom) {
      var pages = Math.max(1, Math.round(contentH / onePage));
      zoom.textContent = Math.round(scale * 100) + '%' + (pages > 1 ? ' · ' + pages + ' pages' : ' · 1 page');
    }
    $('pcZoomFit').setAttribute('aria-pressed', zoomMode === 'fit' ? 'true' : 'false');
    $('pcZoom100').setAttribute('aria-pressed', zoomMode !== 'fit' && zoomScale === 1 ? 'true' : 'false');
  }
  var spacer = document.createElement('div');
  spacer.setAttribute('aria-hidden', 'true');
  spacer.style.cssText = 'position:absolute;top:0;left:0;pointer-events:none';
  stage.appendChild(spacer);
  frame.style.position = 'absolute';
  frame.style.top = '0';
  frame.style.left = '0';
  function currentScale(){
    var t = /scale\(([\d.]+)\)/.exec(frame.style.transform || '');
    return t ? parseFloat(t[1]) : 1;
  }
  function step(dir){
    var now = zoomMode === 'fit' ? currentScale() : zoomScale;
    var next = dir > 0 ? ZOOM_STEPS.find(function(s){ return s > now + 0.001; })
                       : ZOOM_STEPS.slice().reverse().find(function(s){ return s < now - 0.001; });
    if (next) { zoomMode = 'scale'; zoomScale = next; fit(); }
  }
  $('pcZoomFit').addEventListener('click', function(){ zoomMode = 'fit'; fit(); });
  $('pcZoom100').addEventListener('click', function(){ zoomMode = 'scale'; zoomScale = 1; fit(); });
  $('pcZoomIn').addEventListener('click', function(){ step(1); });
  $('pcZoomOut').addEventListener('click', function(){ step(-1); });

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
      note.textContent = n === 0 ? 'Nothing selected yet: tick the calendars to print, or choose a preset.'
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

  /* Panels. Each side folds to a labelled rail on its own; folding hides a
   * panel and changes no setting. Remembered per browser, because somebody
   * comparing designs folds them once and wants them to stay folded. The
   * theme panel starts folded on narrower screens so the sheet has the room. */
  var grid = $('pcLayout');
  function setPanel(side, open, remember){
    grid.setAttribute('data-' + side, open ? 'open' : 'closed');
    var cap = side.charAt(0).toUpperCase() + side.slice(1);
    $('pc' + cap + 'Toggle').setAttribute('aria-expanded', open ? 'true' : 'false');
    $('pc' + cap + 'Rail').hidden = open;
    $('pc' + cap + 'Rail').setAttribute('aria-expanded', open ? 'true' : 'false');
    if (remember) { try { localStorage.setItem('ekklesia_print_panel_' + side, open ? 'open' : 'closed'); } catch (e) {} }
    fit();
  }
  ['left', 'right'].forEach(function(side){
    var cap = side.charAt(0).toUpperCase() + side.slice(1);
    $('pc' + cap + 'Toggle').addEventListener('click', function(){
      setPanel(side, false, true); $('pc' + cap + 'Rail').focus(); });
    $('pc' + cap + 'Rail').addEventListener('click', function(){
      setPanel(side, true, true); $('pc' + cap + 'Toggle').focus(); });
    var stored = null;
    try { stored = localStorage.getItem('ekklesia_print_panel_' + side); } catch (e) {}
    // Below 1100px three columns leave the sheet too small to judge (and on a
    // phone the theme list would push it far down), so the theme panel starts
    // folded there whatever was remembered.
    var narrow = window.innerWidth < 1100;
    var open = side === 'right' && narrow ? false : (stored !== null ? stored === 'open' : true);
    setPanel(side, open, false);
  });

  /* Tabs, with the keyboard behaviour a tab list promises: arrows move,
   * Home and End jump, and only the selected tab is in the tab order. */
  var tabs = Array.prototype.slice.call(document.querySelectorAll('#pcTabs [role=tab]'));
  function selectTab(tab, focus){
    tabs.forEach(function(t){
      var on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      $(t.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) tab.focus();
    try { localStorage.setItem('ekklesia_print_tab', tab.id); } catch (e) {}
  }
  tabs.forEach(function(tab, i){
    tab.addEventListener('click', function(){ selectTab(tab, false); });
    tab.addEventListener('keydown', function(e){
      var to = null;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') to = tabs[(i + 1) % tabs.length];
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') to = tabs[(i - 1 + tabs.length) % tabs.length];
      else if (e.key === 'Home') to = tabs[0];
      else if (e.key === 'End') to = tabs[tabs.length - 1];
      if (to) { e.preventDefault(); selectTab(to, true); }
    });
  });
  try {
    var lastTab = localStorage.getItem('ekklesia_print_tab');
    if (lastTab && $(lastTab)) selectTab($(lastTab), false);
  } catch (e) {}

  // Nothing here submits: every control acts at once.
  form.addEventListener('submit', function(e){ e.preventDefault(); });

  /* PowerPoint themes. A card's radio value is "pptx:ID"; choosing one pins
   * its current version into the design, and a design opened later keeps the
   * version it was saved with (the sheet says when a newer one exists). */
  var PPTX_API = base + '/api/print/themes';
  var pinnedPptx = pinnedPptx || null;
  var pptxThemes = {};
  function pptxVersionFor(theme){
    if (!/^pptx:/.test(theme || '')) return 0;
    if (pinnedPptx && pinnedPptx.theme === theme && pinnedPptx.version) return pinnedPptx.version;
    var t = pptxThemes[theme.slice(5)];
    return t ? t.version : 0;
  }
  function pptxCard(t){
    var id = 'pptx:' + t.id;
    var label = document.createElement('label');
    label.className = 'pc-theme';
    label.dataset.theme = id;
    label.dataset.layouts = 'monthly,weekly';
    label.dataset.artwork = 'none';
    label.dataset.defaults = '{}';
    var input = document.createElement('input');
    input.type = 'radio'; input.name = 'theme'; input.value = id;
    input.setAttribute('aria-describedby', 'pcThemeB-pptx-' + t.id);
    var thumb = document.createElement('span');
    thumb.className = 'pc-thumb pc-thumb--pptx'; thumb.setAttribute('aria-hidden', 'true');
    var img = document.createElement('img');
    img.alt = ''; img.loading = 'lazy';
    img.src = base + '/print/themes/' + t.id + '/' + t.version + '/thumb.png';
    thumb.appendChild(img);
    var name = document.createElement('span'); name.className = 'pc-theme-name'; name.textContent = t.name;
    var scope = document.createElement('span'); scope.className = 'pc-scope';
    scope.textContent = t.scope === 'church' ? 'Shared' : 'Private';
    name.appendChild(scope);
    var meta = document.createElement('span'); meta.className = 'pc-theme-meta';
    meta.textContent = (t.paper === 'a4' ? 'A4' : t.paper.charAt(0).toUpperCase() + t.paper.slice(1)) + ' ' + t.orientation + ' · v' + t.version;
    var blurb = document.createElement('span'); blurb.className = 'pc-theme-blurb'; blurb.id = 'pcThemeB-pptx-' + t.id;
    blurb.textContent = 'By ' + (t.creator || 'unknown') + ', ' + String(t.updatedAt || '').slice(0, 10)
      + (t.warnings && t.warnings.length ? ' · ' + t.warnings.length + ' note' + (t.warnings.length === 1 ? '' : 's') : ' · checked');
    label.appendChild(input); label.appendChild(thumb); label.appendChild(name); label.appendChild(meta); label.appendChild(blurb);
    return label;
  }
  /* A design may name a theme this viewer cannot list (retired, or someone
   * else's private one); it stays selected and the sheet says it prints in
   * Classic, rather than silently changing the design. */
  function ensurePptxCard(theme){
    if (!/^pptx:/.test(theme || '') || document.querySelector('.pc-theme[data-theme="' + theme + '"]')) return;
    var holder = $('pcPptxList');
    holder.appendChild(pptxCard({ id: theme.slice(5), name: 'PowerPoint theme (not available)', scope: 'private',
      version: 1, paper: 'letter', orientation: 'portrait', creator: '', updatedAt: '', warnings: [] }));
  }
  function showPptxStatus(kind, lines){
    var box = $('pcPptxStatus');
    box.textContent = '';
    if (!lines || !lines.length) return;
    var head = document.createElement('p');
    head.className = kind === 'error' ? 'is-error' : '';
    head.textContent = lines[0];
    box.appendChild(head);
    if (lines.length > 1) {
      var ul = document.createElement('ul');
      lines.slice(1).forEach(function(l){ var li = document.createElement('li'); li.textContent = l; ul.appendChild(li); });
      box.appendChild(ul);
    }
  }
  function syncPptxManage(){
    var theme = pick('theme') || '';
    var t = /^pptx:/.test(theme) ? pptxThemes[theme.slice(5)] : null;
    $('pcPptxManage').hidden = !(t && t.canEdit);
    if (t) {
      $('pcPptxPublish').hidden = !t.canPublish;
      $('pcPptxPublish').textContent = t.scope === 'church' ? 'Make private' : 'Share with the church';
    }
  }
  function loadPptxThemes(select){
    return fetch(PPTX_API, { credentials: 'same-origin' })
      .then(function(r){ return r.ok ? r.json() : { themes: [], canUpload: false, starters: {} }; })
      .then(function(j){
        var holder = $('pcPptxList');
        var chosen = pick('theme');
        holder.textContent = '';
        pptxThemes = {};
        (j.themes || []).forEach(function(t){ pptxThemes[t.id] = t; holder.appendChild(pptxCard(t)); });
        $('pcPptxEmpty').hidden = (j.themes || []).length > 0;
        $('pcPptxUpload').hidden = !j.canUpload;
        var list = $('pcStarters');
        list.textContent = '';
        Object.keys(j.starters || {}).forEach(function(k){
          var li = document.createElement('li'), a = document.createElement('a');
          a.href = base + '/print/theme-starters/' + k; a.textContent = j.starters[k]; a.setAttribute('download', '');
          li.appendChild(a); list.appendChild(li);
        });
        var want = select || chosen;
        if (want) { ensurePptxCard(want); setPick('theme', want); }
        syncTheme();
        syncPptxManage();
      })
      .catch(function(){});
  }
  function uploadPptx(url, file, extra){
    var data = new FormData();
    data.append('pptx', file);
    Object.keys(extra || {}).forEach(function(k){ data.append(k, extra[k]); });
    showPptxStatus('', ['Checking “' + file.name + '”…']);
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: data })
      .then(function(r){ return r.json().then(function(j){ if (!r.ok) throw new Error(j.error || 'That presentation could not be used.'); return j; }); })
      .then(function(j){
        var warns = j.warnings || [];
        showPptxStatus('', [(url === PPTX_API ? 'Added' : 'Updated') + ' “' + j.theme.name + '” (' + j.theme.paper + ' '
          + j.theme.orientation + '). ' + (warns.length ? 'Notes:' : 'No problems found.')].concat(warns));
        pinnedPptx = null;
        return loadPptxThemes('pptx:' + j.theme.id);
      })
      .then(function(){ onChange(); })
      .catch(function(e){ showPptxStatus('error', [e.message]); });
  }
  $('pcPptxFile').addEventListener('change', function(){
    var f = this.files && this.files[0];
    if (!f) return;
    uploadPptx(PPTX_API, f, { name: $('pcPptxName').value.trim() }).finally(function(){ $('pcPptxFile').value = ''; });
  });
  $('pcPptxReplace').addEventListener('click', function(){ $('pcPptxReplaceFile').click(); });
  $('pcPptxReplaceFile').addEventListener('change', function(){
    var f = this.files && this.files[0], theme = pick('theme') || '';
    if (!f || !/^pptx:/.test(theme)) return;
    uploadPptx(PPTX_API + '/' + theme.slice(5) + '/versions', f).finally(function(){ $('pcPptxReplaceFile').value = ''; });
  });
  function putTheme(id, body){
    return fetch(PPTX_API + '/' + id, { method: 'PUT', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function(r){ return r.json().then(function(j){ if (!r.ok) throw new Error(j.error || 'That did not work.'); return j; }); });
  }
  $('pcPptxPublish').addEventListener('click', function(){
    var theme = pick('theme') || '', t = pptxThemes[theme.slice(5)];
    if (!t) return;
    var scope = t.scope === 'church' ? 'private' : 'church';
    putTheme(t.id, { scope: scope })
      .then(function(){ showPptxStatus('', [scope === 'church' ? 'Shared with everyone in the church.' : 'Now private to you.']); return loadPptxThemes(theme); })
      .catch(function(e){ showPptxStatus('error', [e.message]); });
  });
  $('pcPptxRetire').addEventListener('click', function(){
    var theme = pick('theme') || '', t = pptxThemes[theme.slice(5)];
    if (!t || !window.confirm('Retire “' + t.name + '”? It leaves the gallery, and calendars saved with it print in Classic.')) return;
    putTheme(t.id, { status: 'archived' })
      .then(function(){ showPptxStatus('', ['Retired “' + t.name + '”.']); setPick('theme', 'classic'); return loadPptxThemes(); })
      .then(function(){ onChange(); })
      .catch(function(e){ showPptxStatus('error', [e.message]); });
  });
  form.addEventListener('change', function(e){ if (e.target.name === 'theme') { pinnedPptx = null; syncPptxManage(); } });
  loadPptxThemes();

  /* Background pictures. The list is this account's own pictures (all of
   * them, for an administrator); a design opened from someone else may use a
   * picture not in it, which is kept and shown as "From this design". */
  var pendingBg = pendingBg || 0;
  var BG_API = base + '/api/print/backgrounds';
  var bgList = $('pcBgList'), bgStatus = $('pcBgStatus'), bgMine = {};
  function bgOption(id, label, canDelete){
    var l = document.createElement('label');
    l.className = 'pc-bgopt';
    var i = document.createElement('input');
    i.type = 'radio'; i.name = 'bgpick'; i.value = String(id);
    var img = document.createElement('img');
    img.src = base + '/print/backgrounds/' + id; img.alt = label; img.loading = 'lazy';
    var n = document.createElement('span'); n.className = 'pc-bgname'; n.textContent = label;
    l.appendChild(i); l.appendChild(img); l.appendChild(n);
    bgMine[id] = !!canDelete;
    return l;
  }
  function selectBackground(id){
    var want = String(id || '');
    var el = bgList.querySelector('input[name=bgpick][value="' + want + '"]');
    if (!el && id) {
      bgList.appendChild(bgOption(id, 'From this design', false));
      el = bgList.querySelector('input[name=bgpick][value="' + want + '"]');
    }
    if (el) el.checked = true;
    syncBgControls();
  }
  function syncBgControls(){
    var id = parseInt(pick('bgpick') || '0', 10) || 0;
    $('pcBgControls').hidden = id === 0;
    $('pcBgDelete').hidden = !(id && bgMine[id]);
    $('pcBgOpacityOut').textContent = $('pcBgOpacity').value + '%';
    $('pcBgOverlayOut').textContent = $('pcBgOverlay').value + '%';
  }
  function loadBackgrounds(){
    return fetch(BG_API, { credentials: 'same-origin' })
      .then(function(r){ return r.ok ? r.json() : { backgrounds: [] }; })
      .then(function(j){
        Array.prototype.slice.call(bgList.querySelectorAll('.pc-bgopt')).forEach(function(n){
          if (n.querySelector('input').value !== '') n.remove(); });
        (j.backgrounds || []).forEach(function(b){ bgList.appendChild(bgOption(b.id, b.label, b.canDelete)); });
        selectBackground(pendingBg);
      })
      .catch(function(){ selectBackground(pendingBg); });
  }
  $('pcBgFile').addEventListener('change', function(){
    var f = this.files && this.files[0];
    if (!f) return;
    var data = new FormData(); data.append('picture', f);
    bgStatus.textContent = 'Uploading and checking “' + f.name + '”…';
    fetch(BG_API, { method: 'POST', credentials: 'same-origin', body: data })
      .then(function(r){ return r.json().then(function(j){ if (!r.ok) throw new Error(j.error || 'That picture could not be used.'); return j; }); })
      .then(function(j){
        bgStatus.textContent = 'Added “' + j.background.label + '” (' + j.background.width + ' × ' + j.background.height + ' pixels).';
        pendingBg = j.background.id;
        return loadBackgrounds();
      })
      .then(function(){ onChange(); })
      .catch(function(e){ bgStatus.textContent = e.message; })
      .finally(function(){ $('pcBgFile').value = ''; });
  });
  $('pcBgNone').addEventListener('click', function(){ selectBackground(0); onChange(); });
  $('pcBgDelete').addEventListener('click', function(){
    var id = parseInt(pick('bgpick') || '0', 10) || 0;
    if (!id || !window.confirm('Delete this picture? Designs that use it must choose another first.')) return;
    fetch(BG_API + '/' + id, { method: 'DELETE', credentials: 'same-origin' })
      .then(function(r){ return r.json().then(function(j){ if (!r.ok) throw new Error(j.error || 'That did not work.'); return j; }); })
      .then(function(){ bgStatus.textContent = 'Picture deleted.'; pendingBg = 0; return loadBackgrounds(); })
      .then(function(){ onChange(); })
      .catch(function(e){ bgStatus.textContent = e.message; });
  });
  form.addEventListener('input', function(e){
    if (e.target.id === 'pcBgOpacity' || e.target.id === 'pcBgOverlay') { syncBgControls(); onChange(); }
  });
  form.addEventListener('change', function(e){ if (e.target.name === 'bgpick') syncBgControls(); });
  loadBackgrounds();

  /* Saved designs: rename and duplicate. Rename keeps the saved settings (not
   * unsaved edits); Duplicate copies what is on screen now into a new private
   * design of your own. */
  $('pcRename').addEventListener('click', function(){
    if (!saved || !saved.canEdit) return;
    var name = window.prompt('Rename this publication', saved.name);
    if (!name || name === saved.name) return;
    api('PUT', '/' + saved.id, { name: name, visibility: saved.visibility, config: JSON.parse(savedConfig) })
      .then(function(){
        saved.name = name;
        var opt = $('pcView').querySelector('option[value="' + saved.id + '"]');
        if (opt) opt.textContent = name;
        refreshViewState();
      }).catch(fail);
  });
  $('pcDuplicate').addEventListener('click', function(){
    if (!saved) return;
    var name = window.prompt('Name the copy', saved.name + ' copy');
    if (!name) return;
    api('POST', '', { name: name, visibility: 'private', config: read() })
      .then(function(j){ window.location.search = '?view=' + encodeURIComponent(j.id); })
      .catch(fail);
  });

  /* Editing on the sheet. The print document marks its print-only text
   * (title, line under it, notes, footer note) with data-edit; typing there
   * writes straight into the matching setting here, so saving, the URL and
   * printing all see it. The sheet is redrawn only when you leave a region,
   * so the caret is not lost mid-word. Calendar entries are not editable:
   * an event offers "Edit calendar event", which opens the real editor. */
  var editing = false;
  var FIELD = { title: 'hTitle', subtitle: 'hSubtitle', footer: 'fNote', top: 'pcTop', bottom: 'pcBottom' };
  $('pcEditToggle').addEventListener('click', function(){
    editing = !editing;
    this.setAttribute('aria-pressed', editing ? 'true' : 'false');
    this.textContent = editing ? 'Done editing' : 'Edit text on the sheet';
    status.textContent = editing ? 'Editing: click the title, notes or footer on the sheet' : 'Preview';
    refresh();
  });
  function wireEditing(){
    if (!editing) return;
    var doc;
    try { doc = frame.contentDocument; } catch (e) { return; }
    if (!doc) return;
    try { doc.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
    var bar = doc.createElement('div');
    bar.className = 'no-print';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Formatting');
    bar.style.cssText = 'position:fixed;top:8px;left:50%;transform:translateX(-50%);z-index:9;display:none;gap:4px;'
      + 'padding:5px;background:#fff;border:1px solid #c7d4cd;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.15);'
      + 'font:600 12px system-ui,sans-serif';
    [['bold', 'B', 'Bold'], ['italic', 'I', 'Italic'], ['justifyLeft', '⇤', 'Align left'],
     ['justifyCenter', '↔', 'Centre'], ['justifyRight', '⇥', 'Align right'],
     ['p', 'Normal', 'Normal text'], ['h4', 'Medium', 'Medium heading'], ['h3', 'Large', 'Large heading']
    ].forEach(function(b){
      var btn = doc.createElement('button');
      btn.type = 'button'; btn.textContent = b[1]; btn.title = b[2]; btn.setAttribute('aria-label', b[2]);
      btn.style.cssText = 'min-width:30px;min-height:28px;border:1px solid #c7d4cd;border-radius:6px;background:#fff;cursor:pointer';
      // mousedown, not click: focus must stay in the note being formatted.
      btn.addEventListener('mousedown', function(e){
        e.preventDefault();
        if (b[0] === 'p' || b[0] === 'h3' || b[0] === 'h4') doc.execCommand('formatBlock', false, b[0]);
        else doc.execCommand(b[0], false, null);
        var el = doc.activeElement;
        if (el && el.dataset && el.dataset.edit) push(el);
      });
      bar.appendChild(btn);
    });
    doc.body.appendChild(bar);

    function push(el){
      var id = FIELD[el.dataset.edit];
      if (!id) return;
      if (el.dataset.rich) {
        // Cleaned on a copy, never on the note being typed in: changing the
        // live text would lose the selection a formatting button acts on.
        var copy = el.cloneNode(true);
        // Browser alignment arrives as a style; the server keeps only its two
        // classes, so translate here and let RichText do the real judging.
        Array.prototype.forEach.call(copy.querySelectorAll('[style*="text-align"]'), function(n){
          var m = /text-align:\s*(center|right)/.exec(n.getAttribute('style') || '');
          n.removeAttribute('style');
          if (m) n.className = 'al-' + m[1];
        });
        // Browsers wrap formatted text in spans with inline sizes; the sheet's
        // own sizes are the headings, so the wrappers go (their text stays).
        Array.prototype.forEach.call(copy.querySelectorAll('span, font'), function(n){
          while (n.firstChild) n.parentNode.insertBefore(n.firstChild, n);
          n.parentNode.removeChild(n);
        });
        $(id).value = copy.textContent.trim() === '' ? '' : copy.innerHTML.trim();
      } else {
        $(id).value = el.textContent.replace(/\s+/g, ' ').trim();
      }
      summarise(read()); refreshViewState();
    }
    Array.prototype.forEach.call(doc.querySelectorAll('[data-edit]'), function(el){
      el.setAttribute('contenteditable', el.dataset.rich ? 'true' : 'plaintext-only');
      if (el.contentEditable !== 'plaintext-only' && !el.dataset.rich) el.setAttribute('contenteditable', 'true');
      el.setAttribute('role', 'textbox');
      el.setAttribute('aria-label', (el.dataset.placeholder || 'Text') + ' (print only)');
      el.title = 'Print only: this changes the printed copy, not the calendar';
      el.addEventListener('input', function(){ push(el); });
      el.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !el.dataset.rich) { e.preventDefault(); el.blur(); }
      });
      // Pasted text arrives as plain text: styling from elsewhere is not kept.
      el.addEventListener('paste', function(e){
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain');
        doc.execCommand('insertText', false, text);
      });
      el.addEventListener('focus', function(){ bar.style.display = el.dataset.rich ? 'flex' : 'none'; });
      el.addEventListener('blur', function(){
        bar.style.display = 'none';
        push(el);
        onChange();
      });
    });
    // Calendar entries: data, not print text.
    Array.prototype.forEach.call(doc.querySelectorAll('[data-href]'), function(el){
      el.title = 'Calendar data. Click to edit the event itself.';
      el.addEventListener('click', function(){
        if (window.confirm('This is an event on the calendar, not text on the sheet.\n\n'
            + 'Open the event? If you may edit it, changes there apply to the real calendar for everyone.')) {
          window.open(base + el.dataset.href, '_blank', 'noopener');
        }
      });
    });
  }
  frame.addEventListener('load', wireEditing);

  var timer;
  function refresh(){
    clearTimeout(timer);
    timer = setTimeout(function(){
      var next = query(read());
      status.textContent = 'Preparing…';
      frame.src = next;
      openLink.href = next;
      pptxLink.href = next.replace('/calendar/print?', '/calendar/export.pptx?').replace(/([?&])edit=1(&|$)/, '$1').replace(/[?&]$/, '');
      document.getElementById('pcPptxLayout').hidden = read().layout === 'monthly';
      fit();
    }, 200);
  }

  function onChange(){
    var c = read();
    summarise(c);
    updateThemeMode();
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
  $('pcPrintBar').addEventListener('click', printSheet);
  $('pcUseAuto').addEventListener('click', function(){
    setPick('theme', 'auto'); applyThemeDefaults('auto'); syncTheme('none'); onChange();
    var card = document.querySelector('.pc-theme[data-theme="auto"] input');
    if (card) card.focus();
  });
  // Ctrl+P on this page would print the editor. Send it to the sheet instead.
  window.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'p' || e.key === 'P')) {
      e.preventDefault(); printSheet();
    }
  });
  // Restore how it looks; keep what is on it (calendars, dates, title, notes).
  $('pcDefaults').addEventListener('click', function(){
    var c = read();
    var fresh = JSON.parse(JSON.stringify(D));
    fresh.date = c.date; fresh.content = c.content; fresh.additional = c.additional;
    fresh.header.title = c.header.title; fresh.header.subtitle = c.header.subtitle;
    fresh.layout = c.layout;
    write(fresh); touched = Object.create(null); onChange();
  });
  $('pcPdf').addEventListener('click', function(){
    // Same dialog. The hint beside it says which destination to pick, and is
    // announced when this is the route somebody took.
    var hint = $('pcPdfHint');
    if (hint) hint.setAttribute('role', 'status');
    printSheet();
  });

  // Start from the saved view if one was opened, or from the calendar's
  // "Print this view". Otherwise every calendar starts unticked: what goes on
  // the sheet is always the reader's choice, never a leftover from last time.
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
  }
  // The selection used to be remembered per browser; drop what is stored.
  try { localStorage.removeItem('church_portal_print_sources_v1'); } catch (e) {}

  markPresets();
  syncTheme(INITIAL.appearance.artwork);
  onChange();
  fit();
})();
</script>
<?php
echo (string) ob_get_clean();
