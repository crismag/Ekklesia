<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * Draws a PowerPoint theme's artwork from the model PptxThemeReader made.
 *
 * Every value written here came from that model, which holds only numbers,
 * hex colours, approved font families, plain text and asset file names, and
 * is escaped again on the way out. The artwork is a layer behind the page;
 * Ekklesia's own title, month and calendar are placed in the theme's safe
 * regions by the print shell.
 */
final class PptxThemeRenderer
{
    private const FAMILIES = [
        'serif' => '"Iowan Old Style", "Palatino Linotype", Palatino, Georgia, "Times New Roman", serif',
        'sans' => '"Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif',
    ];

    /**
     * The artwork layer, as HTML.
     *
     * @param array<string,mixed> $model
     * @param string $assetBase URL prefix the asset file names are appended to
     */
    public static function artwork(array $model, string $assetBase): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $url = static fn (string $name): string => $e($assetBase . rawurlencode($name));
        $out = '';
        $bg = $model['background'] ?? null;
        if (is_array($bg)) {
            if (isset($bg['asset'])) {
                $out .= '<div class="pa-bg" style="background-image:url(\'' . $url((string) $bg['asset']) . '\');background-size:cover"></div>';
            } elseif (isset($bg['color'])) {
                $out .= '<div class="pa-bg" style="background:' . $e(self::rgba((string) $bg['color'], (float) ($bg['alpha'] ?? 1))) . '"></div>';
            }
        }
        foreach ((array) ($model['elements'] ?? []) as $el) {
            $style = sprintf('left:%.3fin;top:%.3fin;width:%.3fin;height:%.3fin;', $el['x'], $el['y'], $el['w'], $el['h']);
            $transform = [];
            if ((float) ($el['rot'] ?? 0) !== 0.0) {
                $transform[] = 'rotate(' . (float) $el['rot'] . 'deg)';
            }
            if (!empty($el['flipH'])) {
                $transform[] = 'scaleX(-1)';
            }
            if (!empty($el['flipV'])) {
                $transform[] = 'scaleY(-1)';
            }
            if ($transform !== []) {
                $style .= 'transform:' . implode(' ', $transform) . ';';
            }
            $geom = (string) ($el['geom'] ?? 'rect');
            if ($geom === 'ellipse') {
                $style .= 'border-radius:50%;';
            } elseif ($geom === 'round') {
                $style .= sprintf('border-radius:%.3fin;', min((float) $el['w'], (float) $el['h']) * 0.167);
            }
            if (($el['kind'] ?? '') === 'picture') {
                $style .= 'background-image:url(\'' . $url((string) $el['asset']) . '\');' . self::crop($el['crop'] ?? null)
                    . 'opacity:' . number_format((float) ($el['opacity'] ?? 1), 2) . ';';
                $out .= '<div class="pa-el" style="' . $style . '"></div>';
                continue;
            }
            $fill = $el['fill'] ?? null;
            if (is_array($fill)) {
                $style .= isset($fill['asset'])
                    ? 'background-image:url(\'' . $url((string) $fill['asset']) . '\');background-size:cover;background-position:center;'
                    : 'background:' . self::rgba((string) $fill['color'], (float) ($fill['alpha'] ?? 1)) . ';';
            }
            $line = $el['line'] ?? null;
            if (is_array($line)) {
                $style .= sprintf('border:%.2fpt solid %s;', max(0.25, (float) $line['width']), self::rgba((string) $line['color'], (float) ($line['alpha'] ?? 1)));
            }
            $inner = '';
            if (is_array($el['text'] ?? null)) {
                $anchor = ['t' => 'flex-start', 'ctr' => 'center', 'b' => 'flex-end'][$el['text']['anchor'] ?? 't'] ?? 'flex-start';
                $style .= 'display:flex;flex-direction:column;justify-content:' . $anchor . ';padding:0.05in 0.1in;';
                foreach ((array) $el['text']['paras'] as $para) {
                    $align = ['l' => 'left', 'ctr' => 'center', 'r' => 'right', 'just' => 'justify'][$para['align'] ?? 'l'] ?? 'left';
                    $inner .= '<p style="text-align:' . $align . '">';
                    foreach ((array) $para['runs'] as $run) {
                        $inner .= '<span style="font-size:' . number_format((float) $run['sz'], 1) . 'pt;color:' . $e($run['color'])
                            . ';font-family:' . $e(self::FAMILIES[$run['family']] ?? self::FAMILIES['sans'])
                            . (!empty($run['b']) ? ';font-weight:700' : '') . (!empty($run['i']) ? ';font-style:italic' : '')
                            . '">' . $e($run['t']) . '</span>';
                    }
                    $inner .= '</p>';
                }
            }
            $out .= '<div class="pa-el" style="' . $e($style) . '">' . $inner . '</div>';
        }

        return '<div class="pptx-art" aria-hidden="true">' . $out . '</div>';
    }

    /**
     * A small picture of the artwork for the theme gallery, drawn with GD:
     * background, shapes and pictures; decorative text is left out.
     *
     * @param array<string,mixed> $model
     */
    public static function thumbnail(array $model, string $assetDir, string $outPath, int $width = 240): void
    {
        $pw = (float) $model['page']['w'];
        $ph = (float) $model['page']['h'];
        $scale = $width / $pw;
        $height = (int) round($ph * $scale);
        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 255, 255, 255));
        $bg = $model['background'] ?? null;
        if (is_array($bg) && isset($bg['color'])) {
            imagefilledrectangle($img, 0, 0, $width, $height, self::gdColor($img, (string) $bg['color'], (float) ($bg['alpha'] ?? 1)));
        } elseif (is_array($bg) && isset($bg['asset'])) {
            self::paste($img, $assetDir . '/' . $bg['asset'], 0, 0, $width, $height, 1.0);
        }
        foreach ((array) ($model['elements'] ?? []) as $el) {
            $x = (int) round($el['x'] * $scale);
            $y = (int) round($el['y'] * $scale);
            $w = max(1, (int) round($el['w'] * $scale));
            $h = max(1, (int) round($el['h'] * $scale));
            if (($el['kind'] ?? '') === 'picture') {
                self::paste($img, $assetDir . '/' . $el['asset'], $x, $y, $w, $h, (float) ($el['opacity'] ?? 1));
                continue;
            }
            if (!isset($el['fill']['color'])) {
                continue;
            }
            $c = self::gdColor($img, (string) $el['fill']['color'], (float) ($el['fill']['alpha'] ?? 1));
            if (($el['geom'] ?? '') === 'ellipse') {
                imagefilledellipse($img, $x + intdiv($w, 2), $y + intdiv($h, 2), $w, $h, $c);
            } else {
                imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, $c);
            }
        }
        // The regions Ekklesia writes in, as faint outlines, so a card shows
        // where the calendar will go.
        $guide = (int) imagecolorallocatealpha($img, 27, 127, 181, 70);
        foreach ((array) ($model['regions'] ?? []) as $r) {
            imagerectangle($img, (int) round($r['x'] * $scale), (int) round($r['y'] * $scale),
                (int) round(($r['x'] + $r['w']) * $scale), (int) round(($r['y'] + $r['h']) * $scale), $guide);
        }
        imagepng($img, $outPath, 6);
        imagedestroy($img);
    }

    /** CSS for a page-sized region box. @param array<string,float> $box */
    public static function box(array $box): string
    {
        return sprintf('left:%.3fin;top:%.3fin;width:%.3fin;height:%.3fin', $box['x'], $box['y'], $box['w'], $box['h']);
    }

    /** @param array<string,float>|null $crop */
    private static function crop(?array $crop): string
    {
        if ($crop === null) {
            return 'background-size:100% 100%;';
        }
        $w = max(0.05, 1 - $crop['l'] - $crop['r']);
        $h = max(0.05, 1 - $crop['t'] - $crop['b']);
        $px = $crop['l'] + $crop['r'] > 0 ? $crop['l'] / ($crop['l'] + $crop['r']) * 100 : 50;
        $py = $crop['t'] + $crop['b'] > 0 ? $crop['t'] / ($crop['t'] + $crop['b']) * 100 : 50;

        return sprintf('background-size:%.2f%% %.2f%%;background-position:%.2f%% %.2f%%;', 100 / $w, 100 / $h, $px, $py);
    }

    private static function rgba(string $hex, float $alpha): string
    {
        if (preg_match('/^#[0-9a-f]{6}$/i', $hex) !== 1) {
            return 'transparent';
        }
        [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));

        return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, max(0, min(1, $alpha)));
    }

    private static function gdColor(\GdImage $img, string $hex, float $alpha): int
    {
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

        return (int) imagecolorallocatealpha($img, $r, $g, $b, (int) round((1 - max(0, min(1, $alpha))) * 127));
    }

    private static function paste(\GdImage $dst, string $path, int $x, int $y, int $w, int $h, float $opacity): void
    {
        $src = is_file($path) ? @imagecreatefromstring((string) file_get_contents($path)) : false;
        if ($src === false) {
            return;
        }
        imagecopyresampled($dst, $src, $x, $y, 0, 0, $w, $h, imagesx($src), imagesy($src));
        imagedestroy($src);
    }
}
