<?php

namespace RankMathCloudFrontKvs;

final class Admin
{
    private const PAGE = 'rankmath-cloudfront-kvs';
    private const PUSH_ACTION = 'rm_cf_kvs_push';
    private const STEP_ACTION = 'rm_cf_kvs_push_step';
    private const JOB_OPTION = 'rm_cf_kvs_job';

    // Less than typical 30s proxy, load balancer or PHP timeouts.
    private const STEP_SECONDS = 15;

    public function register(): void
    {
        add_action('admin_menu', function () {
            add_management_page('Redirects to CloudFront', 'Redirects to CloudFront', 'manage_options', self::PAGE, [$this, 'render']);
        });

        add_action('admin_init', function () {
            register_setting(self::PAGE, Settings::OPTION, ['sanitize_callback' => [Settings::class, 'sanitize']]);
        });

        add_action('wp_ajax_' . self::PUSH_ACTION, [$this, 'handleStart']);
        add_action('wp_ajax_' . self::STEP_ACTION, [$this, 'handleStep']);
    }

    public function handleStart(): void
    {
        $this->authorize();

        try {
            $job = Plugin::sync(Settings::afterPush(), !empty($_POST['dry_run']))->plan();
        } catch (\RuntimeException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        // Replace any unfinished push.
        update_option(self::JOB_OPTION, $job, false);
        $this->respond($job, false);
    }

    public function handleStep(): void
    {
        $this->authorize();

        $job = get_option(self::JOB_OPTION);

        if (!is_array($job)) {
            wp_send_json_error(['message' => 'There is no push in progress.']);
        }

        try {
            $job = Plugin::sync($job['after_push'], $job['dry_run'])->step($job, microtime(true) + self::STEP_SECONDS);
        } catch (\RuntimeException $e) {
            $job['error'] = $e->getMessage();
        }

        $done = RedirectSync::isDone($job);
        $done ? delete_option(self::JOB_OPTION) : update_option(self::JOB_OPTION, $job, false);
        $this->respond($job, $done);
    }

    private function authorize(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You are not allowed to do that.'], 403);
        }

        check_ajax_referer(self::PUSH_ACTION);
    }

    private function respond(array $job, bool $done): never
    {
        wp_send_json_success([
            'done' => $done,
            'error' => $job['error'] !== null,
            'redirects' => $job['redirects'],
            'total' => $job['total'],
            'message' => $done
                ? Plugin::summary($job, $job['dry_run'])
                : sprintf('%s %d of %d redirects…', $job['dry_run'] ? 'Checked' : 'Pushed', $job['redirects'], $job['total']),
        ]);
    }

    public function render(): void
    {
        $settings = Settings::get();
        $envArn = Settings::arnFromEnvironment();
        $name = Settings::OPTION;
        ?>
        <div class="wrap">
            <h1>Redirects to CloudFront</h1>

            <?php settings_errors(Settings::OPTION); ?>

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
            <p>Large pushes are split across several requests. Keep this page open until the push finishes.</p>
            <form id="rm-cf-kvs-push" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::PUSH_ACTION); ?>">
                <?php wp_nonce_field(self::PUSH_ACTION); ?>
                <p><label><input type="checkbox" name="dry_run" value="1" checked> Dry run</label></p>
                <?php submit_button('Push redirects', 'primary', 'submit', false); ?>
                <p><progress max="1" value="0" style="width: 100%; max-width: 40em" hidden></progress></p>
                <div class="notice inline" role="status" hidden><p></p></div>
            </form>
            <script>
                (() => {
                    const form = document.getElementById('rm-cf-kvs-push');

                    const url = form.getAttribute('action');
                    const button = form.querySelector('[type="submit"]');
                    const progress = form.querySelector('progress');
                    const status = form.querySelector('[role="status"]');
                    const warn = (event) => event.preventDefault();

                    const show = (type, message) => {
                        status.className = `notice notice-${type} inline`;
                        status.firstElementChild.textContent = message;
                        status.hidden = false;
                    };

                    const post = async (action) => {
                        const data = new FormData(form);
                        data.set('action', action);

                        const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin' });
                        const json = await response.json().catch(() => null);

                        if (!json) {
                            throw new Error(`The request failed (HTTP ${response.status}).`);
                        }

                        if (!json.success) {
                            throw new Error(json.data.message);
                        }

                        progress.value = json.data.total ? json.data.redirects / json.data.total : 0;
                        show(json.data.error ? 'error' : (json.data.done ? 'success' : 'info'), json.data.message);

                        return json.data;
                    };

                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        button.disabled = true;
                        progress.hidden = false;
                        progress.value = 0;
                        addEventListener('beforeunload', warn);

                        try {
                            let job = await post(<?php echo wp_json_encode(self::PUSH_ACTION); ?>);

                            while (!job.done) {
                                job = await post(<?php echo wp_json_encode(self::STEP_ACTION); ?>);
                            }
                        } catch (error) {
                            show('error', error.message);
                        } finally {
                            removeEventListener('beforeunload', warn);
                            button.disabled = false;
                        }
                    });
                })();
            </script>
        </div>
        <?php
    }
}
