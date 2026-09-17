<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Which broad status a classification represents, so it can be coloured.
 *
 * Green for someone attending, orange for someone who is not, a neutral tone
 * for a guest and grey for a record nobody has classified. That is the whole
 * job: a colour, and a word for what the colour means.
 *
 * Colour is deliberately never the only cue. The circle in the directory
 * carries the classification's short code, its full name is on hover and read
 * to assistive technology, and the "By classification" card prints the same
 * circles beside the same names — so the page teaches its own key rather than
 * asking anyone to remember what orange meant.
 *
 * A classification nobody has mapped is drawn neutral. Guessing that an
 * unfamiliar name means "not attending" would put a red flag on a record for no
 * reason.
 */
final class ClassificationStatus
{
    private const NEUTRAL = 'unknown';

    /** @var array<string,string> comparison key => status key */
    private array $index = [];

    /** @var array<string,string> status key => label */
    private array $statuses = [];

    /**
     * @param array<string,string> $classifications name => status key
     * @param array<string,string> $statuses        status key => label
     */
    public function __construct(array $classifications = [], array $statuses = [])
    {
        foreach ($classifications as $name => $status) {
            $key = $this->key((string) $name);
            if ($key !== '') {
                $this->index[$key] = (string) $status;
            }
        }
        $this->statuses = $statuses;
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return new self();
        }
        $statuses = [];
        foreach ($decoded['statuses'] ?? [] as $key => $meta) {
            $statuses[(string) $key] = (string) ($meta['label'] ?? $key);
        }

        return new self(
            is_array($decoded['classifications'] ?? null) ? $decoded['classifications'] : [],
            $statuses,
        );
    }

    /** Always returns something: an unmapped name is neutral, not a guess. */
    public function statusFor(string $classification): string
    {
        $status = $this->index[$this->key($classification)] ?? self::NEUTRAL;

        return isset($this->statuses[$status]) || $status === self::NEUTRAL ? $status : self::NEUTRAL;
    }

    /** "Attending", "Not attending" — the plain words behind the colour. */
    public function statusLabel(string $status): string
    {
        return $this->statuses[$status] ?? 'Not recorded';
    }

    /** Whether anybody has actually said what this classification means. */
    public function isMapped(string $classification): bool
    {
        return isset($this->index[$this->key($classification)]);
    }

    private function key(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim($name)));
    }
}
