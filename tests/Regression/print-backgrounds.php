<?php

declare(strict_types=1);

/**
 * Background pictures behind a printed calendar.
 *
 * Only real JPEG, PNG and WebP pictures are accepted, judged by their bytes,
 * and every one is re-encoded (metadata dropped, orientation applied). A
 * picture too small to print is refused rather than stretched; a merely soft
 * one gets a warning. A missing picture never stops the calendar printing.
 * The test pictures are drawn here; nothing real is used.
 */

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use App\Exceptions\ValidationFailed;
use App\Services\Calendar\ImageIntake;
use App\Services\Calendar\PrintComposer;
use App\Services\Calendar\PrintConfig;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}
$refused = static function (callable $fn, string $needle = ''): bool {
    try {
        $fn();
    } catch (ValidationFailed $e) {
        return $needle === '' || str_contains($e->getMessage(), $needle);
    }
    return false;
};

$tmp = sys_get_temp_dir() . '/ekk-bg-' . bin2hex(random_bytes(4));
mkdir($tmp);
$picture = static function (string $name, int $w, int $h, string $format = 'jpeg') use ($tmp): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 220, 235));
    $path = "$tmp/$name";
    match ($format) {
        'png' => imagepng($im, $path),
        'webp' => imagewebp($im, $path),
        default => imagejpeg($im, $path, 85),
    };
    return $path;
};

echo "What is accepted\n";
$ok = ImageIntake::process($picture('a.jpg', 2550, 3300));
check('a large JPEG is accepted and re-encoded as JPEG',
    $ok['width'] === 2550 && $ok['height'] === 3300 && str_starts_with($ok['jpeg'], "\xFF\xD8"));
check('a PNG is accepted and stored as JPEG', str_starts_with(ImageIntake::process($picture('b.png', 1600, 1200, 'png'))['jpeg'], "\xFF\xD8"));
if (function_exists('imagewebp')) {
    check('a WebP is accepted', ImageIntake::process($picture('c.webp', 1400, 1100, 'webp'))['width'] === 1400);
}
$huge = ImageIntake::process($picture('d.jpg', 6000, 4000));
check('a very large picture is scaled down, never up', $huge['width'] === ImageIntake::STORE_MAX_SIDE);

echo "\nWhat is refused\n";
check('a picture too small to print, with its size in the message',
    $refused(fn () => ImageIntake::process($picture('e.jpg', 800, 600)), '800 × 600'));
file_put_contents("$tmp/f.jpg", '<?php echo "not a picture"; ?>');
check('a script named .jpg', $refused(fn () => ImageIntake::process("$tmp/f.jpg"), 'JPEG, PNG or WebP'));
file_put_contents("$tmp/g.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
check('an SVG, which can carry script', $refused(fn () => ImageIntake::process("$tmp/g.svg"), 'SVG'));
$bytes = (string) file_get_contents($picture('h.jpg', 1400, 1100));
file_put_contents("$tmp/h-cut.jpg", substr($bytes, 0, 200));
check('a damaged picture', $refused(fn () => ImageIntake::process("$tmp/h-cut.jpg")));
check('a file over 12 MB, before it is even opened',
    $refused(fn () => ImageIntake::process($picture('i.jpg', 1400, 1100), 13 * 1024 * 1024), '12 MB'));
file_put_contents("$tmp/j.jpg", '');
check('an empty file', $refused(fn () => ImageIntake::process("$tmp/j.jpg"), 'empty'));

echo "\nSharp enough for the paper\n";
check('2550 × 3300 is 300 dpi on Letter', ImageIntake::printDpi(2550, 3300, 8.5, 11.0) === 300);
check('the same picture on Tabloid is about 194 dpi', ImageIntake::printDpi(2550, 3300, 11.0, 17.0) === 194);

echo "\nSettings\n";
$d = PrintConfig::fromArray([]);
check('no background by default', $d->get('background.mode') === 'none');
$c = PrintConfig::fromArray(['background' => ['mode' => 'image', 'id' => 7, 'fit' => 'contain', 'x' => 'left',
    'y' => 'bottom', 'opacity' => 3, 'overlay' => 2]]);
check('a picture is kept by id with its placement', $c->get('background.id') === 7 && $c->get('background.fit') === 'contain'
    && $c->get('background.x') === 'left' && $c->get('background.y') === 'bottom');
check('strength and readability wash are bounded', $c->get('background.opacity') === 1.0 && $c->get('background.overlay') === 0.9);
check('an image background with no picture is no background',
    PrintConfig::fromArray(['background' => ['mode' => 'image', 'id' => 0]])->get('background.mode') === 'none');
check('nonsense placement falls back', PrintConfig::fromArray(['background' => ['mode' => 'image', 'id' => 2, 'x' => 'url(evil)']])->get('background.x') === 'center');
check('the settings survive the query round trip', PrintConfig::fromQuery($c->toQuery())->get('background') === $c->get('background'));

echo "\nOn the page\n";
$composer = new PrintComposer($root . '/resources/views');
$render = static fn (array $options): string => $composer->render([], new DateTimeImmutable('2026-09-01'),
    new DateTimeImmutable('2026-09-30'), $options + ['template' => 'monthly', 'paper' => 'letter', 'orientation' => 'portrait']);
$with = $render(['background' => ['url' => '/print/backgrounds/3', 'width' => 2550, 'height' => 3300, 'fit' => 'cover',
    'x' => 'center', 'y' => 'top', 'opacity' => 0.4, 'overlay' => 0.6]]);
check('the picture is a layer behind the calendar, with a readability wash',
    str_contains($with, 'class="bg-layer"') && str_contains($with, "url('/print/backgrounds/3')")
    && str_contains($with, 'class="bg-wash"') && str_contains($with, '--bg-wash:0.60'));
check('it repeats on every printed page rather than stretching', str_contains($with, '.bg-layer, .bg-wash { position: fixed; }'));
$soft = $render(['background' => ['url' => '/x', 'width' => 1200, 'height' => 1600, 'fit' => 'cover', 'x' => 'center',
    'y' => 'center', 'opacity' => 0.4, 'overlay' => 0.6]]);
check('a soft picture is warned about on screen only', str_contains($soft, 'print-note no-print') && str_contains($soft, 'dpi'));
$gone = $render(['backgroundMissing' => true]);
check('a missing picture prints the calendar without it, and says so on screen',
    str_contains($gone, 'could not be found') && !str_contains($gone, 'class="bg-layer"') && str_contains($gone, '<table class="cal'));

array_map('unlink', glob("$tmp/*") ?: []);
rmdir($tmp);
printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
