<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Batched read/write access to the cached availability verdicts.
 *
 * The lookup used to do one Cache::get() per TLD and one Cache::put() per
 * result. Against the database store that is a MySQL round-trip each way: a
 * full-list run measured 1287 SELECTs (0.36 s) plus roughly 1.7 s of upserts —
 * about 2 s and 2600 queries of bookkeeping before a single byte went out to a
 * registry, repeated per concurrent request.
 *
 * Reads are one Cache::many(); writes accumulate and flush with
 * Cache::putMany(), grouped by TTL so the "a non-answer is not an answer" rule
 * survives the batching.
 */
final class VerdictCache
{
    /** @var array<string, string> verdict => key => value, full TTL */
    private array $verdicts = [];

    /** @var array<string, string> non-answers, short TTL */
    private array $nonAnswers = [];

    public function __construct(
        private readonly string $domain,
        private readonly int $flushAt = 50,
    ) {}

    /**
     * Read every TLD's cached verdict in one round-trip.
     *
     * @param  array<string>  $tlds
     * @return array<string, string> tld => status, misses omitted
     */
    public function many(array $tlds): array
    {
        if ($tlds === []) {
            return [];
        }

        $keys = [];
        foreach ($tlds as $tld) {
            $keys[$this->key($tld)] = $tld;
        }

        $hits = [];

        foreach (Cache::many(array_keys($keys)) as $key => $value) {
            if ($value !== null && isset($keys[$key])) {
                $hits[$keys[$key]] = $value;
            }
        }

        return $hits;
    }

    /**
     * Buffer a verdict. Flushes once the buffer is worth a round-trip, so
     * results still reach the cache while a long run is in progress.
     */
    public function put(string $tld, string $status): void
    {
        if ($status === 'unknown') {
            $this->nonAnswers[$this->key($tld)] = $status;
        } else {
            $this->verdicts[$this->key($tld)] = $status;
        }

        if (count($this->verdicts) + count($this->nonAnswers) >= $this->flushAt) {
            $this->flush();
        }
    }

    /**
     * Write the buffer out.
     *
     * 'unknown' means a registry refused us, timed out or answered in a shape
     * we could not read. Storing that for the full 15 minutes made every retry
     * return the same non-answer instantly, so a blip during a client call
     * lasted the whole call. A short TTL still damps hammering.
     */
    public function flush(): void
    {
        if ($this->verdicts !== []) {
            Cache::putMany($this->verdicts, (int) config('domain-checker.cache.result_ttl', 900));
            $this->verdicts = [];
        }

        if ($this->nonAnswers !== []) {
            Cache::putMany($this->nonAnswers, (int) config('domain-checker.cache.unknown_ttl', 60));
            $this->nonAnswers = [];
        }
    }

    private function key(string $tld): string
    {
        return "domain_check_{$this->domain}_{$tld}";
    }
}
