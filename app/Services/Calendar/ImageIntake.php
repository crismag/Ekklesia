<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Exceptions\ValidationFailed;

/**
 * Turns an uploaded picture into one safe to store and print.
 *
 * Only JPEG, PNG and WebP are accepted, identified by their bytes rather than
 * their name. The picture is decoded and **re-encoded** as a fresh JPEG: that
 * drops metadata (camera, location), applies the camera's orientation so a
 * phone photo is not printed sideways, flattens transparency onto white, and
 * leaves nothing of the original file — so a file that is also something else
 * (a "polyglot") does not survive. SVG is refused: it is a document that can
 * carry script, not a picture.
 *
 * A picture too small to print is refused rather than stretched. Whether it is
 * sharp *enough* for a given paper is a separate, softer question the studio
 * answers with a warning (see printDpi()).
 */
final class ImageIntake
{
    public const MAX_BYTES = 12 * 1024 * 1024;
    public const MAX_SIDE = 8000;
    /** The long side must reach 100 dpi on Letter's 11 inches. */
    public const MIN_LONG_SIDE = 1100;
    public const MIN_SHORT_SIDE = 600;
    /** Re-encoded no larger than this, which is 300 dpi on Tabloid's long side. */
    public const STORE_MAX_SIDE = 5100;

    private const TYPES = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
    ];

    /**
     * @return array{jpeg:string,width:int,height:int}
     */
    public static function process(string $path, ?int $reportedBytes = null): array
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new ValidationFailed('This server cannot process pictures (the GD extension is missing).');
        }
        if (!is_file($path)) {
            throw new ValidationFailed('The upload did not arrive. Try again.');
        }
        $bytes = $reportedBytes ?? (int) filesize($path);
        if ($bytes <= 0) {
            throw new ValidationFailed('That file is empty.');
        }
        if ($bytes > self::MAX_BYTES) {
            throw new ValidationFailed('That picture is larger than 12 MB. Save a smaller copy and try again.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!isset(self::TYPES[$mime])) {
            throw new ValidationFailed('Use a JPEG, PNG or WebP picture. '
                . ($mime === 'image/svg+xml' ? 'SVG files cannot be used as backgrounds.' : 'That file is not one of them.'));
        }
        $info = @getimagesize($path);
        if ($info === false || ($info['mime'] ?? '') !== $mime) {
            throw new ValidationFailed('That picture could not be read. It may be damaged; export it again.');
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE) {
            throw new ValidationFailed('That picture is larger than ' . self::MAX_SIDE . ' pixels on a side. Save a smaller copy.');
        }

        $raw = (string) file_get_contents($path);
        $img = @imagecreatefromstring($raw);
        unset($raw);
        if ($img === false) {
            throw new ValidationFailed('That picture could not be decoded. It may be damaged; export it again.');
        }

        // A phone photo is stored sideways with a note saying which way is up.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $img = self::orient($img, (int) ($exif['Orientation'] ?? 1));
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) < self::MIN_LONG_SIDE || min($w, $h) < self::MIN_SHORT_SIDE) {
            imagedestroy($img);
            throw new ValidationFailed(sprintf(
                'That picture is %d × %d pixels, too small to print without looking blurred. '
                . 'Use one at least %d pixels on its long side (ideally 2550 or more).',
                $w, $h, self::MIN_LONG_SIDE,
            ));
        }

        // Downscale very large pictures; never upscale.
        $scale = min(1.0, self::STORE_MAX_SIDE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($tw, $th);
        // Transparency flattens onto the white of the page.
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($img);
        ob_start();
        imagejpeg($out, null, 86);
        $jpeg = (string) ob_get_clean();
        imagedestroy($out);

        return ['jpeg' => $jpeg, 'width' => $tw, 'height' => $th];
    }

    /**
     * Dots per inch the picture gives when it covers a page of this size.
     * Under 150 is worth a warning; the studio says so beside the preview.
     */
    public static function printDpi(int $widthPx, int $heightPx, float $pageWidthIn, float $pageHeightIn): int
    {
        if ($pageWidthIn <= 0 || $pageHeightIn <= 0) {
            return 0;
        }

        return (int) floor(min($widthPx / $pageWidthIn, $heightPx / $pageHeightIn));
    }

    private static function orient(\GdImage $img, int $orientation): \GdImage
    {
        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($img);
            return $rotated;
        }

        return $img;
    }
}
