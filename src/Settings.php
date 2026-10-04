<?php

namespace RankMathCloudFrontKvs;

final class Settings
{
    public const OPTION = 'rm_cf_kvs_settings';
    public const ARN_ENV = 'CLOUDFRONT_KVS_ARN';
    public const AFTER_PUSH = [
        'none' => 'Leave them as they are',
        'inactive' => 'Mark them inactive',
        'delete' => 'Delete them',
    ];

    private const ARN_PATTERN = '#^arn:aws:cloudfront::\d{12}:key-value-store/[\w-]+$#';

    // Constants (e.g. Bedrock's Config::define) take precedence over environment variables.
    public static function env(string $name): ?string
    {
        $value = defined($name) ? constant($name) : (getenv($name) ?: ($_ENV[$name] ?? $_SERVER[$name] ?? null));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function arnFromEnvironment(): ?string
    {
        return self::env(self::ARN_ENV);
    }

    public static function arn(): ?string
    {
        return self::arnFromEnvironment() ?? (self::get()['kvs_arn'] ?: null);
    }

    public static function isValidArn(string $arn): bool
    {
        return (bool) preg_match(self::ARN_PATTERN, $arn);
    }

    public static function afterPush(): string
    {
        return self::get()['after_push'];
    }

    public static function get(): array
    {
        return wp_parse_args(get_option(self::OPTION, []), ['kvs_arn' => '', 'after_push' => 'none']);
    }

    public static function sanitize(mixed $input): array
    {
        $current = self::get();
        $input = is_array($input) ? $input : [];

        // The ARN field is disabled (so not submitted) when the environment variable is set.
        $arn = isset($input['kvs_arn']) ? trim(sanitize_text_field($input['kvs_arn'])) : $current['kvs_arn'];

        if ($arn !== '' && !self::isValidArn($arn)) {
            add_settings_error(self::OPTION, 'kvs_arn', 'That doesn’t look like a CloudFront KeyValueStore ARN.');
            $arn = $current['kvs_arn'];
        }

        $afterPush = $input['after_push'] ?? '';

        return [
            'kvs_arn' => $arn,
            'after_push' => array_key_exists($afterPush, self::AFTER_PUSH) ? $afterPush : 'none',
        ];
    }
}
