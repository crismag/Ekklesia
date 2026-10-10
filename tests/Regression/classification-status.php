<?php

declare(strict_types=1);

/**
 * Which status a classification represents, so the directory can colour it.
 *
 * Green for attending, orange for not. Colour is never the only cue — the
 * circle carries the classification's short code, the full name is on hover
 * and read to assistive technology, and the "By classification" card prints
 * the same circles beside the same names, so the page carries its own key.
 *
 * These tests are mostly about the unmapped case. A classification nobody has
 * described must be drawn neutral, because guessing that an unfamiliar name
 * means "not attending" would put a warning colour on a record for no reason.
 */

require_once __DIR__ . '/../../app/Services/ClassificationStatus.php';

use App\Services\ClassificationStatus;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$c = ClassificationStatus::fromFile(__DIR__ . '/../../config/classification-status.json');

check('a member is attending', $c->statusFor('Member') === 'active');
check('so is a regular attender', $c->statusFor('Regular Attender') === 'active');
check('a guest is neither', $c->statusFor('Guest') === 'prospective');
check('a non-attender is not attending', $c->statusFor('Non-Attender') === 'inactive');
check('and so is a non-attending staff member', $c->statusFor('Non-Attender (staff)') === 'inactive');

// The big one: 245 of 253 people have no classification at all.
check('no classification is neutral, not inactive', $c->statusFor('') === 'unknown');
check('and "Unclassified" is too', $c->statusFor('Unclassified') === 'unknown');
check('a classification nobody has described is neutral',
    $c->statusFor('Something An Admin Just Added') === 'unknown');
check('and is reported as unmapped', !$c->isMapped('Something An Admin Just Added'));
check('while a known one is mapped', $c->isMapped('Member'));

// Matching must not turn on punctuation or case, or a rename breaks the colour.
check('case does not matter', $c->statusFor('member') === $c->statusFor('Member'));
check('nor does spacing', $c->statusFor('  Member  ') === $c->statusFor('Member'));
check('nor does punctuation', $c->statusFor('Non Attender') === $c->statusFor('Non-Attender'));

// Every status has words behind it, because the colour alone is not the message.
foreach (['active', 'prospective', 'inactive', 'unknown'] as $status) {
    check('the status has a plain-language label: ' . $status, trim($c->statusLabel($status)) !== '');
}
check('an unknown status still yields a label', trim($c->statusLabel('nonsense')) !== '');

// Green and orange must never be the same status, or the colouring says nothing.
check('attending and not attending are different statuses',
    $c->statusFor('Member') !== $c->statusFor('Non-Attender'));

// A config that points at a status nobody defined falls back rather than
// emitting a class name the stylesheet has never heard of.
$bogus = new ClassificationStatus(['Odd' => 'chartreuse'], ['active' => 'Attending']);
check('a status with no definition falls back to neutral', $bogus->statusFor('Odd') === 'unknown');

$absent = ClassificationStatus::fromFile(__DIR__ . '/nope.json');
check('a missing config leaves everything neutral', $absent->statusFor('Member') === 'unknown');

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
