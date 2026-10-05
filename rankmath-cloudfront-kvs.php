<?php
/**
 * Plugin Name: Rank Math Redirects to CloudFront KVS
 * Description: Offloads Rank Math exact-match redirects to CloudFront.
 * Version: 1.2.0
 * Requires PHP: 8.1
 */

namespace RankMathCloudFrontKvs;

use Aws\CloudFrontKeyValueStore\CloudFrontKeyValueStoreClient;

defined('ABSPATH') || exit;

if (!class_exists(CloudFrontKeyValueStoreClient::class) && is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (!class_exists(CloudFrontKeyValueStoreClient::class)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Rank Math Redirects to CloudFront KVS needs the AWS SDK for PHP '
            . '(<code>composer require aws/aws-sdk-php</code>).</p></div>';
    });

    return;
}

foreach (['Settings', 'KvsClient', 'RedirectSync', 'Plugin', 'Admin', 'Cli'] as $class) {
    require_once __DIR__ . "/src/{$class}.php";
}

if (is_admin()) {
    (new Admin())->register();
}

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('rankmath-kvs', Cli::class);
}
