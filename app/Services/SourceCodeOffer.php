<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config\EnvLoader;

/**
 * Where people using this installation can get its source code.
 *
 * Ekklesia is licensed AGPL-3.0-only. An installation that runs a modified
 * version must offer the people who use it the corresponding source of the
 * version actually running — which only the operator knows. So the link is
 * the operator's to set:
 *
 *   EKKLESIA_SOURCE_URL      the source of the running version, e.g. a tag,
 *                            a release archive, or the operator's own fork
 *   EKKLESIA_SOURCE_VERSION  optional label shown beside it, e.g. "v1.0.0"
 *
 * Without a valid URL the page falls back to the upstream project, and says
 * plainly that this is a reference, not this installation's own source.
 *
 * Only http(s) URLs without embedded credentials are accepted, so a token in
 * a private Git URL is never shown to visitors.
 */
final class SourceCodeOffer
{
    public const UPSTREAM_URL = 'https://github.com/crismag/Ekklesia';
    public const LICENSE_NAME = 'GNU Affero General Public License v3.0 only';
    public const LICENSE_SPDX = 'AGPL-3.0-only';
    public const LICENSE_URL = 'https://www.gnu.org/licenses/agpl-3.0.html';

    private ?string $sourceUrl;
    private ?string $version;

    public function __construct(?string $sourceUrl, ?string $version = null)
    {
        $this->sourceUrl = self::acceptableUrl($sourceUrl);
        $this->version = self::cleanVersion($version);
    }

    public static function fromEnvironment(): self
    {
        return new self(
            EnvLoader::get('EKKLESIA_SOURCE_URL'),
            EnvLoader::get('EKKLESIA_SOURCE_VERSION'),
        );
    }

    /** True when the operator has published this installation's own source link. */
    public function isConfigured(): bool
    {
        return $this->sourceUrl !== null;
    }

    /** The link to show: the operator's source, or the upstream reference. */
    public function url(): string
    {
        return $this->sourceUrl ?? self::UPSTREAM_URL;
    }

    /** The operator's version label, or null when none is set. */
    public function version(): ?string
    {
        return $this->version;
    }

    private static function acceptableUrl(?string $raw): ?string
    {
        $url = trim((string) $raw);
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\s<>"]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['https', 'http'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        return $url;
    }

    private static function cleanVersion(?string $raw): ?string
    {
        $version = trim((string) $raw);
        if ($version === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-\/]{0,79}$/', $version)) {
            return null;
        }
        return $version;
    }
}
