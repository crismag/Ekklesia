<?php
/**
 * Classic Ministry Schedule — the sheet that goes on the noticeboard.
 *
 * A publication, not a report. Somebody stands two feet away and looks for
 * their ministry, so the ministry headings are the thing the eye lands on, the
 * roles are next, and the names are set large enough to read without leaning in.
 *
 * This template queries nothing. It receives a MinistryScheduleDocument that
 * has already reconciled two scheduling systems, and its whole job is to set it
 * on paper.
 *
 * Semantic HTML rather than positioned boxes: the headings really are headings,
 * so the page can be read by a screen reader and copied into an email, and the
 * decorative corners are inert to both.
 *
 * @var \App\Documents\MinistryScheduleDocument $document
 * @var list<list<array<string,mixed>>> $columns
 * @var bool $overflows
 */
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="ms-sheet" role="document">
  <!-- Decorative only. Inert to assistive technology: the page says nothing
       here that the text does not say. -->
  <svg class="ms-corner ms-corner--tl" viewBox="0 0 240 240" aria-hidden="true" focusable="false">
    <path d="M0 0 H120 L0 120 Z" fill="var(--ms-primary)"/>
    <path d="M0 130 L130 0 H175 L0 175 Z" fill="var(--ms-accent)" opacity=".85"/>
    <path d="M0 190 L190 0 H215 L0 215 Z" fill="var(--ms-primary-light)" opacity=".7"/>
  </svg>
  <svg class="ms-corner ms-corner--br" viewBox="0 0 240 240" aria-hidden="true" focusable="false">
    <path d="M240 240 H120 L240 120 Z" fill="var(--ms-primary)"/>
    <path d="M240 110 L110 240 H65 L240 65 Z" fill="var(--ms-accent)" opacity=".85"/>
    <path d="M240 50 L50 240 H25 L240 25 Z" fill="var(--ms-primary-light)" opacity=".7"/>
  </svg>

  <header class="ms-head">
    <h1 class="ms-title"><?= $e($document->title) ?></h1>
    <p class="ms-date"><?= $e($document->displayDate) ?></p>
    <?php if ($document->serviceTitle !== ''): ?>
      <p class="ms-service"><?= $e($document->serviceTitle) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($document->isEmpty()): ?>
    <p class="ms-empty">Nothing is scheduled for this date.</p>
  <?php else: ?>
  <div class="ms-columns">
    <?php foreach ($columns as $column): ?>
      <div class="ms-column">
        <?php foreach ($column as $section): ?>
          <?php
            // Every role named, or none of them: the reference sheet shows both
            // shapes — Facilities lists its roles, Emcee is a bare list — and
            // which one a card gets is a fact about the data, not a setting.
            $named = array_filter($section['assignments'], static fn (array $a): bool => ($a['role'] ?? null) !== null);
            $flat = $named === [];
            // Whether this card is taller than a page was decided before the
            // template was called, alongside the column placement. The template
            // reads flags; it does not consult layout services any more than it
            // consults repositories.
            $tall = (bool) ($section['tall'] ?? false);
          ?>
          <section class="ms-card<?= $flat ? ' ms-card--flat' : '' ?><?= $tall ? ' ms-card--tall' : '' ?>">
            <h2 class="ms-card-head"><?= $e($section['title']) ?></h2>
            <div class="ms-card-body">
              <?php foreach ($section['assignments'] as $a): ?>
                <?php $names = implode(', ', array_column($a['people'], 'name')); ?>
                <?php if ($flat): ?>
                  <?php // One row per duty, not per person: two people sharing a
                        // duty are one line, four separate duties are four. ?>
                  <p class="ms-name ms-name--row<?= $a['people'] === [] ? ' ms-unfilled' : '' ?>">
                    <?= $a['people'] === [] ? 'Unfilled' : $e($names) ?></p>
                <?php else: ?>
                  <div class="ms-duty">
                    <?php if (($a['role'] ?? null) !== null): ?>
                      <h3 class="ms-role"><?= $e($a['role']) ?></h3>
                    <?php endif; ?>
                    <?php // A card can mix named duties with unnamed ones — the
                          // reference's Proclaim opens with a bare name. An
                          // invented label there would read like data. ?>
                    <p class="ms-name<?= $a['people'] === [] ? ' ms-unfilled' : '' ?>">
                      <?= $a['people'] === [] ? 'Unfilled' : $e($names) ?></p>
                    <?php if (($a['note'] ?? null) !== null && $a['note'] !== 'Unfilled'): ?>
                      <p class="ms-note"><?= $e($a['note']) ?></p>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($overflows): ?>
    <!-- Said on the sheet, not only in the preview: whoever picks the printout
         up needs to know it continues, and the alternative — shrinking the type
         until it fits — defeats the point of a noticeboard. -->
    <p class="ms-overflow">This schedule continues on the next page.</p>
  <?php endif; ?>
</div>
