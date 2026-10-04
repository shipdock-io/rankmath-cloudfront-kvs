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
     * @return array{redirects: int, keys: int, skipped: int, error: ?string}
     */
    public function run(): array
    {
        [$redirects, $skipped] = $this->collect();
        $result = ['redirects' => 0, 'keys' => 0, 'skipped' => $skipped, 'error' => null];

        try {
            $etag = $this->kvs->etag();

            foreach ($this->batches($redirects) as $batch) {
                $keys = array_merge(...array_column($batch, 'keys'));

                if (!$this->dryRun) {
                    $etag = $this->kvs->putKeys($etag, $keys);
                    $this->applyAfterPush(array_column($batch, 'id'));
                }

                $result['redirects'] += count($batch);
                $result['keys'] += count($keys);
            }
        } catch (\RuntimeException $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function collect(): array
    {
        global $wpdb;

        $table = "{$wpdb->prefix}rank_math_redirections";

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            throw new \RuntimeException('Rank Math redirections table not found.');
        }

        $rows = $wpdb->get_results(
            "SELECT id, sources, url_to, header_code FROM {$table} WHERE status = 'active' ORDER BY id"
        );

        $redirects = [];
        $skipped = 0;
        $seen = [];

        foreach ($rows as $row) {
            // First redirect to claim a path wins; later duplicates are dropped.
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

    // Returns null unless every source on the redirect is a plain exact-match path.
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
