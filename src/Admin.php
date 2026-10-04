<?php

namespace RankMathCloudFrontKvs;

final class Admin
{
    private const PAGE = 'rankmath-cloudfront-kvs';
    private const PUSH_ACTION = 'rm_cf_kvs_push';

    public function register(): void
    {
        add_action('admin_menu', function () {
            add_management_page('Redirects to CloudFront', 'Redirects to CloudFront', 'manage_options', self::PAGE, [$this, 'render']);
        });

        add_action('admin_init', function () {
            register_setting(self::PAGE, Settings::OPTION, ['sanitize_callback' => [Settings::class, 'sanitize']]);
        });

        add_action('admin_post_' . self::PUSH_ACTION, [$this, 'handlePush']);
    }

    public function handlePush(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to do that.', 403);
        }

        check_admin_referer(self::PUSH_ACTION);

        $dryRun = !empty($_POST['dry_run']);

        try {
            $result = Plugin::sync(Settings::afterPush(), $dryRun)->run();
            $notice = [$result['error'] ? 'error' : 'success', Plugin::summary($result, $dryRun)];
        } catch (\RuntimeException $e) {
            $notice = ['error', $e->getMessage()];
        }

        set_transient($this->noticeKey(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('tools.php?page=' . self::PAGE));
        exit;
    }

    public function render(): void
    {
        $notice = get_transient($this->noticeKey());
        delete_transient($this->noticeKey());

        $settings = Settings::get();
        $envArn = Settings::arnFromEnvironment();
        $name = Settings::OPTION;
        ?>
        <div class="wrap">
            <h1>Redirects to CloudFront</h1>

            <?php settings_errors(Settings::OPTION); ?>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice[0]); ?>"><p><?php echo esc_html($notice[1]); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::PAGE); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="kvs_arn">KeyValueStore ARN</label></th>
                        <td>
                            <input id="kvs_arn" name="<?php echo esc_attr($name); ?>[kvs_arn]" type="text" class="large-text code"
                                value="<?php echo esc_attr($envArn ?? $settings['kvs_arn']); ?>" <?php disabled($envArn !== null); ?>>
                            <?php if ($envArn) : ?>
                                <p class="description">Set by <code><?php echo esc_html(Settings::ARN_ENV); ?></code>.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="after_push">After pushing, Rank Math redirects should be</label></th>
                        <td>
                            <select id="after_push" name="<?php echo esc_attr($name); ?>[after_push]">
                                <?php foreach (Settings::AFTER_PUSH as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['after_push'], $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>

            <h2>Push</h2>
            <p>Pushes all active, exact-match Rank Math redirects to the KeyValueStore.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::PUSH_ACTION); ?>">
                <?php wp_nonce_field(self::PUSH_ACTION); ?>
                <p><label><input type="checkbox" name="dry_run" value="1" checked> Dry run</label></p>
                <?php submit_button('Push redirects', 'primary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function noticeKey(): string
    {
        return 'rm_cf_kvs_notice_' . get_current_user_id();
    }
}
