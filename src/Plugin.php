<?php

namespace RankMathCloudFrontKvs;

final class Plugin
{
    public static function sync(string $afterPush, bool $dryRun): RedirectSync
    {
        $arn = Settings::arn();

        if (!$arn || !Settings::isValidArn($arn)) {
            throw new \RuntimeException('No valid KeyValueStore ARN configured.');
        }

        if (!extension_loaded('awscrt')) {
            throw new \RuntimeException('The awscrt PHP extension is required to sign CloudFront KeyValueStore requests.');
        }

        return new RedirectSync(new KvsClient($arn), $afterPush, $dryRun);
    }

    public static function summary(array $result, bool $dryRun): string
    {
        $message = sprintf(
            '%s %d redirects (%d keys). Skipped %d (not exact-match, has a query string, duplicate or too long).',
            $dryRun ? 'Would push' : 'Pushed',
            $result['redirects'],
            $result['keys'],
            $result['skipped'],
        );

        return $result['error'] ? "{$message} Stopped early: {$result['error']}" : $message;
    }
}
