<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * Asks the geocoders in turn, at a rate each of them permits.
 *
 * Three things are going on, and they are separate on purpose:
 *
 *  1. Pacing. Each provider declares its own minimum interval and this waits
 *     out whatever remains of it before calling that provider again. The wait
 *     is per provider, not global, so alternating between two services lets the
 *     run proceed at roughly twice the rate without either host seeing more
 *     than its policy allows.
 *
 *  2. Rotation. Requests alternate, so neither operator carries the whole run.
 *
 *  3. Retreat. A provider that asks us to back off is stood down for a cooling
 *     period and the others carry on. If every provider is standing down the
 *     run waits rather than hammering them, and gives up entirely after
 *     repeated refusals rather than looping forever.
 *
 * Sleeping is injected so tests can drive throttling behaviour without
 * actually waiting, and so the elapsed-time source can be made deterministic.
 */
final class ProviderPool
{
    /** How long a throttled provider is left alone when it names no interval. */
    private const DEFAULT_COOLDOWN_SECONDS = 60;

    /** Consecutive whole-pool refusals before the run is abandoned. */
    private const MAX_CONSECUTIVE_REFUSALS = 5;

    /** @var list<GeocodingProvider> */
    private array $providers;

    /** @var array<string,float> provider name => monotonic seconds of last call */
    private array $lastCallAt = [];

    /** @var array<string,float> provider name => monotonic seconds it may be used again */
    private array $availableAt = [];

    /** @var array<string,int> */
    private array $stats = ['calls' => 0, 'hits' => 0, 'misses' => 0, 'throttled' => 0, 'waitedMs' => 0];

    private int $cursor = 0;

    /**
     * @param list<GeocodingProvider> $providers
     * @param callable(float):void $sleep   seconds to wait
     * @param callable():float $clock       monotonic seconds
     */
    public function __construct(
        array $providers,
        private $sleep = null,
        private $clock = null,
    ) {
        $this->providers = array_values($providers);
        if ($this->providers === []) {
            throw new \InvalidArgumentException('A geocoding pool needs at least one provider.');
        }
        $this->sleep ??= static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->clock ??= static fn (): float => microtime(true);
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * @param array{street:string,city:string,state:string,zip:string,country:string} $address
     * @throws GeocoderThrottled when every provider has refused repeatedly
     */
    public function lookup(array $address): ?GeocodeHit
    {
        $refusals = 0;

        while ($refusals < self::MAX_CONSECUTIVE_REFUSALS) {
            $provider = $this->nextAvailable();
            if ($provider === null) {
                // Everyone is cooling down. Wait for the earliest to come back
                // rather than spinning.
                $this->waitForSoonest();
                $refusals++;
                continue;
            }

            $this->pace($provider);
            $this->stats['calls']++;
            $this->lastCallAt[$provider->name()] = ($this->clock)();

            try {
                $hit = $provider->lookup($address);
            } catch (GeocoderThrottled $e) {
                $this->stats['throttled']++;
                $cooldown = $e->retryAfterSeconds > 0
                    ? $e->retryAfterSeconds
                    : self::DEFAULT_COOLDOWN_SECONDS;
                $this->availableAt[$provider->name()] = ($this->clock)() + $cooldown;
                $refusals++;
                continue;
            }

            if ($hit !== null) {
                $this->stats['hits']++;

                return $hit;
            }
            // A genuine miss from a working provider. Let a different one try
            // once — they index differently — but do not keep going round.
            $this->stats['misses']++;

            return $this->secondOpinion($provider, $address);
        }

        throw new GeocoderThrottled('Every geocoding provider is refusing requests; stopping.');
    }

    /**
     * @param array{street:string,city:string,state:string,zip:string,country:string} $address
     */
    private function secondOpinion(GeocodingProvider $tried, array $address): ?GeocodeHit
    {
        foreach ($this->providers as $provider) {
            if ($provider->name() === $tried->name() || !$this->isAvailable($provider)) {
                continue;
            }
            $this->pace($provider);
            $this->stats['calls']++;
            $this->lastCallAt[$provider->name()] = ($this->clock)();
            try {
                $hit = $provider->lookup($address);
            } catch (GeocoderThrottled $e) {
                $this->stats['throttled']++;
                $this->availableAt[$provider->name()] = ($this->clock)()
                    + ($e->retryAfterSeconds > 0 ? $e->retryAfterSeconds : self::DEFAULT_COOLDOWN_SECONDS);
                continue;
            }
            if ($hit !== null) {
                $this->stats['hits']++;
                $this->stats['misses']--;

                return $hit;
            }

            return null;
        }

        return null;
    }

    private function nextAvailable(): ?GeocodingProvider
    {
        $count = count($this->providers);
        for ($i = 0; $i < $count; $i++) {
            $provider = $this->providers[($this->cursor + $i) % $count];
            if ($this->isAvailable($provider)) {
                $this->cursor = ($this->cursor + $i + 1) % $count;

                return $provider;
            }
        }

        return null;
    }

    private function isAvailable(GeocodingProvider $provider): bool
    {
        return ($this->availableAt[$provider->name()] ?? 0.0) <= ($this->clock)();
    }

    /** Wait out the remainder of this provider's minimum interval. */
    private function pace(GeocodingProvider $provider): void
    {
        $last = $this->lastCallAt[$provider->name()] ?? null;
        if ($last === null) {
            return;
        }
        $due = $last + ($provider->minIntervalMs() / 1000);
        $wait = $due - ($this->clock)();
        if ($wait > 0) {
            $this->stats['waitedMs'] += (int) round($wait * 1000);
            ($this->sleep)($wait);
        }
    }

    private function waitForSoonest(): void
    {
        $now = ($this->clock)();
        $soonest = null;
        foreach ($this->providers as $provider) {
            $at = $this->availableAt[$provider->name()] ?? 0.0;
            if ($soonest === null || $at < $soonest) {
                $soonest = $at;
            }
        }
        $wait = ($soonest ?? $now) - $now;
        if ($wait > 0) {
            $this->stats['waitedMs'] += (int) round($wait * 1000);
            ($this->sleep)($wait);
        }
    }
}
