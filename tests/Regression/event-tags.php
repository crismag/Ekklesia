<?php

declare(strict_types=1);

/**
 * What makes two tags the same tag.
 *
 * Tags are only worth having if filtering by one finds everything carrying it,
 * and that fails the moment "Christmas", "christmas" and " Christmas " become
 * three rows. So identity is a slug, display is a label, and the rules for
 * both are tested here rather than left to whichever call site got there first.
 */

require_once __DIR__ . '/../../app/Services/Events/TagName.php';

use App\Services\Events\TagName;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

echo "One tag, however it was typed\n";
$same = ['Christmas', 'christmas', ' Christmas ', 'CHRISTMAS', 'christmas!'];
$slugs = array_unique(array_map([TagName::class, 'slug'], $same));
check('casing, spacing and trailing punctuation do not make new tags',
    count($slugs) === 1, json_encode(array_values($slugs)));
check('and the slug is the lowercased word', TagName::slug('Christmas') === 'christmas');
check('spaces become hyphens', TagName::slug('Youth Ministry') === 'youth-ministry');
check('runs of punctuation collapse to one hyphen',
    TagName::slug('Youth  &&  Family') === 'youth-family', TagName::slug('Youth  &&  Family'));

// Non-ASCII names are church names. "Año Nuevo" must not become "a-o-nuevo".
check('accented letters survive', TagName::slug('Año Nuevo') === 'año-nuevo', TagName::slug('Año Nuevo'));
check('non-Latin scripts survive', TagName::slug('聖誕節') === '聖誕節', TagName::slug('聖誕節'));

echo "\nWhat is not a tag\n";
check('blank is not a tag', !TagName::isValid(''));
check('whitespace is not a tag', !TagName::isValid("  \n "));
check('punctuation alone is not a tag', !TagName::isValid('---'));
check('and yields no slug to store', TagName::slug('---') === '');
check('a tag longer than the column is refused, not truncated',
    !TagName::isValid(str_repeat('a', TagName::MAX_LENGTH + 1)));
check('exactly the column length is allowed',
    TagName::isValid(str_repeat('a', TagName::MAX_LENGTH)));

echo "\nDisplay keeps what was typed\n";
check('the label is not lowercased', TagName::label('Christmas Eve') === 'Christmas Eve');
check('but its whitespace is tidied', TagName::label("  Youth   Ministry \n") === 'Youth Ministry');
check('a label and its slug differ where they should',
    TagName::label('Youth Ministry') !== TagName::slug('Youth Ministry'));

echo "\nA submitted list\n";
$list = TagName::normaliseList(['Christmas', 'christmas', 'Family', '', '  ', 'family!', 'Music']);
check('duplicates collapse by slug', count($list) === 3, json_encode(array_column($list, 'slug')));
check('order is preserved', array_column($list, 'slug') === ['christmas', 'family', 'music'],
    json_encode(array_column($list, 'slug')));
check('the first spelling becomes the label',
    $list[0]['label'] === 'Christmas' && $list[1]['label'] === 'Family',
    json_encode(array_column($list, 'label')));
check('empties are dropped rather than stored', count(array_filter($list, static fn ($t) => $t['slug'] === '')) === 0);
check('an empty list stays empty', TagName::normaliseList([]) === []);
check('a list of nothing but rubbish yields nothing',
    TagName::normaliseList(['', ' ', '---', '!!!']) === []);

echo "\nTyping several at once\n";
check('commas separate', TagName::split('Christmas, Family, Music') === ['Christmas', 'Family', 'Music']);
// A tag may contain spaces: splitting on whitespace would make "Youth Ministry"
// into two tags nobody meant.
check('spaces do not separate', TagName::split('Youth Ministry') === ['Youth Ministry']);
check('empty segments are dropped', TagName::split('Christmas,,Family, ') === ['Christmas', 'Family']);
check('nothing typed yields nothing', TagName::split('') === []);
check('a lone comma yields nothing', TagName::split(',') === []);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
