<?php

namespace RankMathCloudFrontKvs;

final class Cli
{
    /**
     * Push active, exact-match Rank Math redirects to the CloudFront KeyValueStore.
     *
     * [--after=<action>]
     * : What to do with pushed redirects in Rank Math. Defaults to the saved setting.
     * ---
     * options:
     *   - none
     *   - inactive
     *   - delete
     * ---
     *
     * [--dry-run]
     * : Check access to the store and report what would be pushed, without writing anything.
     *
     * ## EXAMPLES
     *
     *     wp rankmath-kvs push --dry-run
     *     wp rankmath-kvs push --after=inactive
     */
    public function push(array $args, array $assocArgs): void
    {
        $dryRun = (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'dry-run', false);

        try {
            $result = Plugin::sync($assocArgs['after'] ?? Settings::afterPush(), $dryRun)->run();
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());
        }

        $message = Plugin::summary($result, $dryRun);
        $result['error'] ? \WP_CLI::error($message) : \WP_CLI::success($message);
    }
}
