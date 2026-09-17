<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Site-private archive store. Files live outside the web root under
 * storage/private/YYYY/mm/category.type.mm_dd.HHMM.ext
 */
final class PrivateArchiveStore
{
    public function __construct(
        private ?string $root = null,
        private ?DateTimeZone $tz = null,
    ) {
    }

    public function root(): string
    {
        if ($this->root !== null && $this->root !== '') {
            return rtrim($this->root, '/');
        }
        $env = \App\Core\Config\EnvLoader::get('MAINTENANCE_PRIVATE_PATH');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        return dirname(__DIR__, 2) . '/storage/private';
    }

    public function timezone(): DateTimeZone
    {
        return $this->tz ?? new DateTimeZone('America/Toronto');
    }

    /**
     * @return array{relative:string,absolute:string,filename:string}
     */
    public function allocate(string $category, string $type, string $ext, ?DateTimeImmutable $at = null): array
    {
        $at = $at ?? new DateTimeImmutable('now', $this->timezone());
        $category = $this->token($category);
        $type = $this->token($type);
        $ext = strtolower($this->token($ext));
        $dirRel = $at->format('Y') . '/' . $at->format('m');
        $filename = sprintf(
            '%s.%s.%s.%s.%s',
            $category,
            $type,
            $at->format('m_d'),
            $at->format('Hi'),
            $ext
        );
        $dir = $this->root() . '/' . $dirRel;
        $this->ensurePrivateRoot();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the private archive folder.');
        }

        // Filenames are precise to the minute, so two backups started within the
        // same minute resolved to the same path and the second silently
        // overwrote the first — losing a backup at exactly the moment someone
        // was trying hard to take one. Suffix on collision instead.
        $base = substr($filename, 0, -(strlen($ext) + 1));
        $suffix = 1;
        while (is_file($dir . '/' . $filename)) {
            $filename = $base . '-' . $suffix . '.' . $ext;
            $suffix++;
            if ($suffix > 999) {
                throw new RuntimeException('Too many archives in the same minute.');
            }
        }
        $relative = $dirRel . '/' . $filename;
        return [
            'relative' => $relative,
            'absolute' => $this->root() . '/' . $relative,
            'filename' => $filename,
        ];
    }

    public function write(string $category, string $type, string $ext, string $contents, ?DateTimeImmutable $at = null): array
    {
        $slot = $this->allocate($category, $type, $ext, $at);
        if (file_put_contents($slot['absolute'], $contents, LOCK_EX) === false) {
            throw new RuntimeException('Could not write archive file.');
        }
        @chmod($slot['absolute'], 0640);
        $this->appendIndex($slot['relative'], $category, $type, strlen($contents));
        $slot['bytes'] = strlen($contents);
        return $slot;
    }

    public function absolute(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new InvalidArgumentException('Invalid archive path.');
        }
        // Only archives this store wrote (YYYY/MM/<file>). The private folder also
        // holds the live visitors database and other server-only files, which are
        // not downloads.
        if (!self::isArchivePath($relative)) {
            throw new InvalidArgumentException('That archive file was not found.');
        }
        $full = $this->root() . '/' . $relative;
        $root = realpath($this->root()) ?: $this->root();
        $root = rtrim($root, '/');
        $real = realpath($full);
        if ($real === false || ($real !== $root && !str_starts_with($real, $root . '/'))) {
            throw new InvalidArgumentException('That archive file was not found.');
        }
        return $real;
    }

    /**
     * Whether the archive store can actually be read by this process.
     *
     * listRecent() returns [] both when there are no archives and when the
     * directory cannot be traversed — and those mean opposite things. A backup
     * written by a CLI run is owned by that user with 0750 directories, so the
     * web process cannot see it, and the dashboard would report "no backup has
     * ever been taken" while backups sat on disk. Callers that draw a
     * conclusion from emptiness must check this first.
     */
    public function isReadable(): bool
    {
        $root = $this->root();
        if (!is_dir($root) || !is_readable($root) || !is_executable($root)) {
            return false;
        }

        // The root is often looser than the year/month folders beneath it, so
        // checking the root alone reports success while the archives themselves
        // are unreachable. Any dated folder that cannot be entered means this
        // process cannot see what is inside it.
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . '/' . $entry;
            if (is_dir($path) && (!is_readable($path) || !is_executable($path))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{relative:string,filename:string,bytes:int,mtime:int}>
     */
    public function listRecent(int $limit = 40): array
    {
        $root = $this->root();
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if ($name === '.htaccess' || $name === 'index.html' || $name === 'index.json' || $name === '.gitkeep') {
                continue;
            }
            $full = $file->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
            if (!self::isArchivePath($rel)) {
                continue;
            }
            $out[] = [
                'relative' => $rel,
                'filename' => $name,
                'bytes' => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
            ];
        }
        usort($out, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return array_slice($out, 0, max(1, $limit));
    }

    public function ensurePrivateRoot(): void
    {
        $root = $this->root();
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException('Could not create storage/private.');
        }
        $deny = $root . '/.htaccess';
        if (!is_file($deny)) {
            file_put_contents($deny, "Require all denied\nDeny from all\n");
        }
        $idx = $root . '/index.html';
        if (!is_file($idx)) {
            file_put_contents($idx, '');
        }
    }

    private function token(string $raw): string
    {
        $t = strtolower(trim($raw));
        $t = (string) preg_replace('/[^a-z0-9_-]+/', '', $t);
        if ($t === '') {
            throw new InvalidArgumentException('Archive category/type is invalid.');
        }
        return $t;
    }

    private function appendIndex(string $relative, string $category, string $type, int $bytes): void
    {
        $path = $this->root() . '/index.json';
        $rows = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $rows = is_array($decoded) ? $decoded : [];
        }
        $rows[] = [
            'at' => (new DateTimeImmutable('now', $this->timezone()))->format(DateTimeImmutable::ATOM),
            'category' => $category,
            'type' => $type,
            'relative' => $relative,
            'bytes' => $bytes,
        ];
        $rows = array_slice($rows, -200);
        file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
        // The archives themselves are 0640; the index was left at the umask
        // default (0664, group-writable). It holds metadata only, but there is
        // no reason for it to be looser than what it indexes.
        @chmod($path, 0640);
    }

    /**
     * Whether a path relative to the store is one of its archives: a file directly
     * inside a YYYY/MM folder, as allocate() names them.
     */
    public static function isArchivePath(string $relative): bool
    {
        return preg_match('#^\d{4}/\d{2}/[^/]+$#', $relative) === 1;
    }
}
