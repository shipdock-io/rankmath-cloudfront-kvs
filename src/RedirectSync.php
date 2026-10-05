<?php

namespace RankMathCloudFrontKvs;

final class RedirectSync
{
    private const MAX_KEYS_PER_REQUEST = 50;
    private const MAX_KEY_BYTES = 512;
    private const MAX_VALUE_BYTES = 1024;

    public function __construct(
        private KvsClient $kvs,
        private string $afterPush,
        private bool $dryRun = false,
    ) {
    }

    /**
     * Pushes everything in one go. This does not batch requests, and so should
     * not be used via HTTP as it's likely to result in a timeout.
     */
    public function run(): array
    {
        return $this->step($this->plan());
    }

    /**
     * Snapshots what to push, so that a push split across requests isn't
     * affected by its own changes (e.g. an older duplicate no longer
     * being shadowed once the newer is inactive).
     *
     * @return array{
     *  batches: list<array{
     *    ids: int[],
     *    keys: array<string, string>
     *  }>,
     *  total: int,
     *  redirects: int,
     *  keys: int,
     *  skipped: int,
     *  error: ?string,
     *  after_push: string,
     *  dry_run: bool
     * }
     */
    public function plan(): array
    {
        [$redirects, $skipped] = $this->collect();
        $batches = [];

        foreach ($this->batches($redirects) as $batch) {
            $batches[] = [
                'ids' => array_column($batch, 'id'),
                'keys' => array_merge(...array_column($batch, 'keys')),
            ];
        }

        return [
            'batches' => $batches,
            'total' => count($redirects),
            'redirects' => 0,
            'keys' => 0,
            'skipped' => $skipped,
            'error' => null,
            'after_push' => $this->afterPush,
            'dry_run' => $this->dryRun,
        ];
    }

    /**
     * Pushes batches from the plan until it is exhausted, or the deadline
     * passes. At least one batch is always pushed, so each step makes progress.
     */
    public function step(array $job, ?float $deadline = null): array
    {
        try {
            $etag = $this->kvs->etag();

            while ($batch = array_shift($job['batches'])) {
                if (!$this->dryRun) {
                    $etag = $this->kvs->putKeys($etag, $batch['keys']);
                    $this->applyAfterPush($batch['ids']);
                }

                $job['redirects'] += count($batch['ids']);
                $job['keys'] += count($batch['keys']);

                if ($deadline !== null && microtime(true) >= $deadline) {
                    break;
                }
            }
        } catch (\RuntimeException $e) {
            if (isset($batch)) {
                // Leave failed batches at the front of the plan.
                array_unshift($job['batches'], $batch);
            }

            $job['error'] = $e->getMessage();
        }

        return $job;
    }

    public static function isDone(array $job): bool
    {
        return !$job['batches'] || $job['error'] !== null;
    }

    private function collect(): array
    {
        global $wpdb;

        $table = "{$wpdb->prefix}rank_math_redirections";

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            throw new \RuntimeException('Rank Math redirections table not found.');
        }

        $rows = $wpdb->get_results(
            "SELECT id, sources, url_to, header_code FROM {$table} WHERE status = 'active' ORDER BY updated DESC, id ASC"
        );

        $redirects = [];
        $skipped = 0;
        $seen = [];

        foreach ($rows as $row) {
            $keys = array_diff_key($this->keysFor($row) ?? [], $seen);

            if (!$keys || count($keys) > self::MAX_KEYS_PER_REQUEST) {
                $skipped++;
                continue;
            }

            $seen += $keys;
            $redirects[] = ['id' => (int) $row->id, 'keys' => $keys];
        }

        return [$redirects, $skipped];
    }

    // Returns null unless every source on the redirect is a plain exact-match.
    private function keysFor(object $row): ?array
    {
        $sources = maybe_unserialize($row->sources);

        if (!is_array($sources) || !$sources) {
            return null;
        }

        $value = wp_json_encode(['status' => (int) $row->header_code, 'to' => (string) $row->url_to]);

        if (strlen($value) > self::MAX_VALUE_BYTES) {
            return null;
        }

        $keys = [];

        foreach ($sources as $source) {
            $pattern = (string) ($source['pattern'] ?? '');

            if (($source['comparison'] ?? '') !== 'exact' || $pattern === '' || str_contains($pattern, '?')) {
                return null;
            }

            $key = '/' . trim((string) wp_parse_url($pattern, PHP_URL_PATH), '/');

            if (($source['ignore'] ?? '') === 'case') {
                $key = strtolower($key);
            }

            if (strlen($key) > self::MAX_KEY_BYTES) {
                return null;
            }

            $keys[$key] = $value;
        }

        return $keys;
    }

    private function batches(array $redirects): \Generator
    {
        $batch = [];
        $keyCount = 0;

        foreach ($redirects as $redirect) {
            if ($keyCount + count($redirect['keys']) > self::MAX_KEYS_PER_REQUEST) {
                yield $batch;
                $batch = [];
                $keyCount = 0;
            }

            $batch[] = $redirect;
            $keyCount += count($redirect['keys']);
        }

        if ($batch) {
            yield $batch;
        }
    }

    private function applyAfterPush(array $ids): void
    {
        global $wpdb;

        if ($this->afterPush === 'none') {
            return;
        }

        $in = implode(',', array_map('intval', $ids));
        $table = "{$wpdb->prefix}rank_math_redirections";

        if ($this->afterPush === 'delete') {
            $wpdb->query("DELETE FROM {$table} WHERE id IN ({$in})");
        } else {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'inactive', updated = %s WHERE id IN ({$in})",
                current_time('mysql')
            ));
        }

        $wpdb->query("DELETE FROM {$wpdb->prefix}rank_math_redirections_cache WHERE redirection_id IN ({$in})");
    }
}
