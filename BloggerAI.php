<?php
/**
 * Plugin Name: AI Cron Agent
 * Description: Generates AI posts on a schedule (and optional featured image). Settings in Settings → AI Agent.
 * Version: 1.2.2
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Author: You
 * License: GPL-2.0-or-later
 * Text Domain: ai-cron-agent
 */

if (!defined('ABSPATH')) exit;

define('AI_CRON_AGENT_VERSION', '1.2.2');
define('AI_CRON_AGENT_SLUG', 'ai-cron-agent');
define('AI_CRON_AGENT_EVENT', 'ai_cron_agent_generate_post');
define('AI_CRON_AGENT_LOG_OPTION', 'ai_cron_agent_log'); // rolling array log

//
// ────────────────────────────────────────────────────────────────────────────
// Activation / Deactivation
// ────────────────────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    ai_cron_agent_maybe_schedule();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(AI_CRON_AGENT_EVENT);
});

//
// ────────────────────────────────────────────────────────────────────────────
// Convenience: “Settings” link on Plugins screen
// ────────────────────────────────────────────────────────────────────────────
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $url = admin_url('options-general.php?page=' . AI_CRON_AGENT_SLUG);
    array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
    return $links;
});

//
// ────────────────────────────────────────────────────────────────────────────
// Settings (defaults) + schedule helpers
// ────────────────────────────────────────────────────────────────────────────
function ai_cron_agent_default_options() {
    return [
        'api_key'           => '', // used if OPENAI_API_KEY not defined
        'text_model'        => 'gpt-4o-mini',
        'image_enabled'     => 0,
        'image_model'       => 'gpt-image-1',
        'interval'          => 'hourly', // 30m, hourly, 2h, 3h, 6h, 12h, daily
        'status'            => 'draft', // draft|publish
        'category_id'       => 0,
        'author_id'         => 0,
        'title_prompt'      => 'Create a concise, SEO-friendly blog post title (<=70 chars) for the topic: {{TOPIC}}',
        'body_prompt'       => 'Write an 800-1000 word blog post in HTML with <h2> sections, short intro, bullets where helpful, and a conclusion. Topic: {{TOPIC}}',
        'topic'             => 'Random embedded systems blog, news, how-to-tutorial, or review.',
        'image_prompt'      => 'A clean, modern, blog-friendly illustration about {{TOPIC}}',
        'last_run'          => '',
        'last_error'        => '',
    ];
}

function ai_cron_agent_get_option($key) {
    $opts = get_option('ai_cron_agent_options', []);
    $defaults = ai_cron_agent_default_options();
    $merged = wp_parse_args($opts, $defaults);
    return $merged[$key] ?? ($defaults[$key] ?? null);
}

function ai_cron_agent_update_options($new) {
    $defaults = ai_cron_agent_default_options();
    $merged = wp_parse_args($new, $defaults);
    update_option('ai_cron_agent_options', $merged, false);
}

add_filter('cron_schedules', function($s) {
    $s['every_30_minutes'] = ['interval' => 30 * MINUTE_IN_SECONDS, 'display' => __('Every 30 Minutes','ai-cron-agent')];
    $s['every_2_hours']    = ['interval' => 2 * HOUR_IN_SECONDS,     'display' => __('Every 2 Hours','ai-cron-agent')];
    $s['every_3_hours']    = ['interval' => 3 * HOUR_IN_SECONDS,     'display' => __('Every 3 Hours','ai-cron-agent')];
    $s['every_6_hours']    = ['interval' => 6 * HOUR_IN_SECONDS,     'display' => __('Every 6 Hours','ai-cron-agent')];
    $s['every_12_hours']   = ['interval' => 12 * HOUR_IN_SECONDS,    'display' => __('Every 12 Hours','ai-cron-agent')];
    return $s;
});

function ai_cron_agent_interval_to_wp($interval_key) {
    switch ($interval_key) {
        case '30m':  return 'every_30_minutes';
        case 'hourly': return 'hourly';
        case '2h':   return 'every_2_hours';
        case '3h':   return 'every_3_hours';
        case '6h':   return 'every_6_hours';
        case '12h':  return 'every_12_hours';
        case 'daily':return 'daily';
        default:     return 'hourly';
    }
}

function ai_cron_agent_maybe_schedule() {
    $interval_key = ai_cron_agent_get_option('interval');
    $wp_interval  = ai_cron_agent_interval_to_wp($interval_key);
    $ts = wp_next_scheduled(AI_CRON_AGENT_EVENT);
    if ($ts) return; // already scheduled
    wp_schedule_event(time() + 60, $wp_interval, AI_CRON_AGENT_EVENT);
}

function ai_cron_agent_reschedule_if_needed($old_opts, $new_opts) {
    $old_int = $old_opts['interval'] ?? 'hourly';
    $new_int = $new_opts['interval'] ?? 'hourly';
    if ($old_int !== $new_int) {
        $ts = wp_next_scheduled(AI_CRON_AGENT_EVENT);
        if ($ts) wp_unschedule_event($ts, AI_CRON_AGENT_EVENT);
        $wp_interval = ai_cron_agent_interval_to_wp($new_int);
        wp_schedule_event(time() + 60, $wp_interval, AI_CRON_AGENT_EVENT);
    }
}

// Ensure scheduled when an admin visits settings (helps on some hosts)
add_action('admin_init', function () {
    if (current_user_can('manage_options')) ai_cron_agent_maybe_schedule();
});

//
// ────────────────────────────────────────────────────────────────────────────
// Diagnostics helpers
// ────────────────────────────────────────────────────────────────────────────
function ai_cron_agent_has_blocked_http_to_openai() {
    if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
        $allowed = [];
        if (defined('WP_ACCESSIBLE_HOSTS') && WP_ACCESSIBLE_HOSTS) {
            $allowed = array_map('trim', explode(',', WP_ACCESSIBLE_HOSTS));
        }
        return !in_array('api.openai.com', $allowed, true);
    }
    return false;
}

function ai_cron_agent_requirements_ok(&$why = '') {
    if (!ai_cron_agent_get_api_key()) {
        $why = 'Missing API key (OPENAI_API_KEY or settings).';
        return false;
    }
    if (ai_cron_agent_has_blocked_http_to_openai()) {
        $why = 'Outbound HTTP is blocked. Add "api.openai.com" to WP_ACCESSIBLE_HOSTS or disable WP_HTTP_BLOCK_EXTERNAL.';
        return false;
    }
    return true;
}

//
// ────────────────────────────────────────────────────────────────────────────
/** Admin: Settings Page */
// ────────────────────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    add_options_page(
        __('AI Agent','ai-cron-agent'),
        __('AI Agent','ai-cron-agent'),
        'manage_options',
        AI_CRON_AGENT_SLUG,
        'ai_cron_agent_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('ai_cron_agent_group', 'ai_cron_agent_options', [
        'type' => 'array',
        'sanitize_callback' => 'ai_cron_agent_sanitize_options',
        'default' => ai_cron_agent_default_options(),
    ]);
});

function ai_cron_agent_sanitize_options($input) {
    $old = get_option('ai_cron_agent_options', ai_cron_agent_default_options());

    $out = [];
    $out['api_key']       = isset($input['api_key']) ? trim($input['api_key']) : '';
    $out['text_model']    = sanitize_text_field($input['text_model'] ?? 'gpt-4o-mini');
    $out['image_enabled'] = !empty($input['image_enabled']) ? 1 : 0;
    $out['image_model']   = sanitize_text_field($input['image_model'] ?? 'gpt-image-1');
    $allowed_int = ['30m','hourly','2h','3h','6h','12h','daily'];
    $out['interval']      = in_array($input['interval'] ?? 'hourly', $allowed_int, true) ? $input['interval'] : 'hourly';
    $allowed_status = ['draft','publish'];
    $out['status']        = in_array($input['status'] ?? 'draft', $allowed_status, true) ? $input['status'] : 'draft';
    $out['category_id']   = (int)($input['category_id'] ?? 0);
    $out['author_id']     = (int)($input['author_id'] ?? 0);

    $out['title_prompt']  = wp_kses_post($input['title_prompt'] ?? '');
    $out['body_prompt']   = wp_kses_post($input['body_prompt'] ?? '');
    $out['topic']         = sanitize_text_field($input['topic'] ?? '');
    $out['image_prompt']  = sanitize_text_field($input['image_prompt'] ?? '');

    // Preserve logs
    $out['last_run']      = $old['last_run']  ?? '';
    $out['last_error']    = $old['last_error']?? '';

    // Reschedule if interval changed
    ai_cron_agent_reschedule_if_needed($old, $out);

    return $out;
}

// Small admin notices: schedule status + blocked HTTP
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'settings_page_' . AI_CRON_AGENT_SLUG) return;

    $ts = wp_next_scheduled(AI_CRON_AGENT_EVENT);
    if ($ts) {
        echo '<div class="notice notice-success"><p>Cron scheduled: ' . esc_html(date_i18n('Y-m-d H:i:s', $ts)) . '</p></div>';
    } else {
        echo '<div class="notice notice-warning"><p>Cron is NOT scheduled yet. Saving settings or visiting this page should schedule it.</p></div>';
    }

    if (ai_cron_agent_has_blocked_http_to_openai()) {
        echo '<div class="notice notice-error"><p>Outbound HTTP is blocked. Add <code>api.openai.com</code> to <code>WP_ACCESSIBLE_HOSTS</code> or disable <code>WP_HTTP_BLOCK_EXTERNAL</code>.</p></div>';
    }
});

function ai_cron_agent_render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $opts = get_option('ai_cron_agent_options', ai_cron_agent_default_options());
    $has_constant_key = defined('OPENAI_API_KEY') && OPENAI_API_KEY;
    $categories = get_categories(['hide_empty' => false]);
    $authors = get_users(['who' => 'authors']);

    $run_url = wp_nonce_url(
        admin_url('admin-post.php?action=ai_cron_agent_run_now'),
        'ai_cron_agent_run_now'
    );
    
    $selftest_url = wp_nonce_url(
        admin_url('admin-post.php?action=ai_cron_agent_self_test'),
        'ai_cron_agent_self_test'
    );
    
    $selftest_img_url = wp_nonce_url(
        admin_url('admin-post.php?action=ai_cron_agent_image_test'),
        'ai_cron_agent_image_test'
    );

    $log = get_option(AI_CRON_AGENT_LOG_OPTION, []);

    ?>
    <div class="wrap">
        <h1>AI Agent</h1>

        <p>
            <a href="<?php echo esc_url($run_url); ?>" class="button button-secondary">Run now</a>
            <a href="<?php echo esc_url($selftest_url); ?>" class="button">Self-test OpenAI</a>
            <a href="<?php echo esc_url($selftest_img_url); ?>" class="button">Self-test Images</a>
        </p>

        <form method="post" action="options.php">
            <?php settings_fields('ai_cron_agent_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label>OpenAI API Key</label></th>
                    <td>
                        <?php if ($has_constant_key): ?>
                            <input type="text" value="Using OPENAI_API_KEY constant" disabled class="regular-text" />
                            <p class="description">Key set in wp-config.php takes precedence.</p>
                        <?php else: ?>
                            <input type="password" name="ai_cron_agent_options[api_key]" value="<?php echo esc_attr($opts['api_key']);?>" class="regular-text" />
                            <p class="description">Stored in DB (less secure). Prefer wp-config.php constant.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Text Model</label></th>
                    <td>
                        <input type="text" name="ai_cron_agent_options[text_model]" value="<?php echo esc_attr($opts['text_model']);?>" class="regular-text" />
                        <p class="description">e.g., gpt-4o-mini</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Enable Featured Image</label></th>
                    <td>
                        <label><input type="checkbox" name="ai_cron_agent_options[image_enabled]" value="1" <?php checked($opts['image_enabled'],1);?>> Generate featured image</label>
                        <p class="description">Uses Images API (model: <code>gpt-image-1</code> by default).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Image Model</label></th>
                    <td>
                        <input type="text" name="ai_cron_agent_options[image_model]" value="<?php echo esc_attr($opts['image_model']);?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Interval</label></th>
                    <td>
                        <select name="ai_cron_agent_options[interval]">
                            <?php
                            $choices = [
                                '30m'   => 'Every 30 minutes',
                                'hourly'=> 'Hourly',
                                '2h'    => 'Every 2 hours',
                                '3h'    => 'Every 3 hours',
                                '6h'    => 'Every 6 hours',
                                '12h'   => 'Every 12 hours',
                                'daily' => 'Daily',
                            ];
                            foreach ($choices as $k=>$label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($k),
                                    selected($opts['interval'], $k, false),
                                    esc_html($label)
                                );
                            }
                            ?>
                        </select>
                        <p class="description">WP-Cron runs when there’s traffic. For exact timing, add a server cron to call <code>wp-cron.php</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Status</label></th>
                    <td>
                        <select name="ai_cron_agent_options[status]">
                            <option value="draft"   <?php selected($opts['status'],'draft');?>>Draft</option>
                            <option value="publish" <?php selected($opts['status'],'publish');?>>Publish</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Category</label></th>
                    <td>
                        <select name="ai_cron_agent_options[category_id]">
                            <option value="0" <?php selected($opts['category_id'],0);?>>— None —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat->term_id;?>" <?php selected($opts['category_id'], (int)$cat->term_id);?>>
                                    <?php echo esc_html($cat->name);?>
                                </option>
                            <?php endforeach;?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Author</label></th>
                    <td>
                        <select name="ai_cron_agent_options[author_id]">
                            <option value="0" <?php selected($opts['author_id'],0);?>>Default</option>
                            <?php foreach ($authors as $user): ?>
                                <option value="<?php echo (int)$user->ID;?>" <?php selected($opts['author_id'], (int)$user->ID);?>>
                                    <?php echo esc_html($user->display_name . ' (#'.$user->ID.')');?>
                                </option>
                            <?php endforeach;?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Topic</label></th>
                    <td>
                        <input type="text" name="ai_cron_agent_options[topic]" value="<?php echo esc_attr($opts['topic']);?>" class="regular-text" />
                        <p class="description">This string substitutes <code>{{TOPIC}}</code> in the prompts below.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Title Prompt</label></th>
                    <td>
                        <textarea name="ai_cron_agent_options[title_prompt]" rows="3" class="large-text code"><?php echo esc_textarea($opts['title_prompt']);?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Body Prompt</label></th>
                    <td>
                        <textarea name="ai_cron_agent_options[body_prompt]" rows="6" class="large-text code"><?php echo esc_textarea($opts['body_prompt']);?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Image Prompt</label></th>
                    <td>
                        <input type="text" name="ai_cron_agent_options[image_prompt]" value="<?php echo esc_attr($opts['image_prompt']);?>" class="regular-text" />
                    </td>
                    
                </tr>
                <tr>
                    <th scope="row"><label>Last Run / Error</label></th>
                    <td>
                        <code><?php echo esc_html($opts['last_run'] ?: '—'); ?></code><br/>
                        <?php if (!empty($opts['last_error'])): ?>
                            <span style="color:#a00">Error: <?php echo esc_html($opts['last_error']); ?></span>
                        <?php else: ?>
                            <span>No errors recorded.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2>Recent Log (last 50)</h2>
        <div style="max-height:220px; overflow:auto; background:#fff; border:1px solid #ddd; padding:10px;">
            <code style="white-space:pre-wrap; display:block;">
<?php
if ($log && is_array($log)) {
    foreach ($log as $line) {
        echo esc_html($line) . "\n";
    }
} else {
    echo '— no log yet —';
}
?>
            </code>
        </div>

        <h2>Reliability tip</h2>
        <p>For exact timing, add a real cron (every 15 minutes) to call <code>wp-cron.php</code>, e.g.:
        <pre>curl -s https://YOURDOMAIN.com/wp-cron.php?doing_wp_cron &gt; /dev/null 2&gt;&1</pre>
        </p>
    </div>
    <?php
}

//
// ────────────────────────────────────────────────────────────────────────────
/** Manual run (admin-post) */
// ────────────────────────────────────────────────────────────────────────────
add_action('admin_post_ai_cron_agent_run_now', function () {
    if (!current_user_can('manage_options')) wp_die('No permission');
    check_admin_referer('ai_cron_agent_run_now');

    ai_cron_agent_log('Manual run: handler reached');

    // Run the job synchronously; pass true to indicate manual
    do_action(AI_CRON_AGENT_EVENT, true);

    wp_safe_redirect(admin_url('options-general.php?page=' . AI_CRON_AGENT_SLUG));
    exit;
});

// add_action('admin_post_ai_cron_agent_image_test', function () {
//     if (!current_user_can('manage_options')) wp_die('No permission');
//     check_admin_referer('ai_cron_agent_image_test');

//     // Simple safe prompt
//     $prompt = 'A simple flat illustration of a microcontroller chip on a circuit board, minimal, blog header style';

//     $b64 = ai_cron_agent_generate_image_base64($prompt);
//     if (!$b64) {
//         wp_die('Image API returned no data. Check "Recent Log" and your server’s connectivity.');
//     }

//     $attach_id = ai_cron_agent_save_b64_image($b64, 'ai-cron-agent-test.png');
//     if (!$attach_id) {
//         wp_die('Failed to save test image. See "Recent Log".');
//     }

//     echo '<p>✅ Image test saved to Media Library. Attachment ID: <code>' . intval($attach_id) . '</code></p>';
//     echo '<p><a href="' . esc_url(admin_url('options-general.php?page=' . AI_CRON_AGENT_SLUG)) . '">Back to settings</a></p>';
//     exit;
// });

add_action('admin_post_ai_cron_agent_image_test', function () {
    if (!current_user_can('manage_options')) wp_die('No permission');
    check_admin_referer('ai_cron_agent_image_test');

    // Build prompt from Image Prompt template + Topic
    $topic          = ai_cron_agent_get_option('topic');
    $image_template = ai_cron_agent_get_option('image_prompt');
    $prompt         = str_replace('{{TOPIC}}', $topic, $image_template);

    // (Optional) allow a one-off POST override if you add a text field named ai_cron_agent_prompt
    if (isset($_POST['ai_cron_agent_prompt'])) {
        $prompt = sanitize_text_field(wp_unslash($_POST['ai_cron_agent_prompt']));
    }

    ai_cron_agent_log('Image self-test prompt: ' . $prompt);

    $b64 = ai_cron_agent_generate_image_base64($prompt);
    if (!$b64) {
        wp_die('Image API returned no data. Check "Recent Log" and your server’s connectivity.');
    }

    $attach_id = ai_cron_agent_save_b64_image($b64, 'ai-cron-agent-test.png');
    if (!$attach_id) {
        wp_die('Failed to save test image. See "Recent Log".');
    }

    echo '<p>✅ Image test saved to Media Library. Attachment ID: <code>' . intval($attach_id) . '</code></p>';
    echo '<p><a href="' . esc_url(admin_url('options-general.php?page=' . AI_CRON_AGENT_SLUG)) . '">Back to settings</a></p>';
    exit;
});


//
// ────────────────────────────────────────────────────────────────────────────
/** OpenAI self-test (no post creation) */
// ────────────────────────────────────────────────────────────────────────────
add_action('admin_post_ai_cron_agent_self_test', function () {
    if (!current_user_can('manage_options')) wp_die('No permission');
    check_admin_referer('ai_cron_agent_self_test');

    $why = '';
    if (!ai_cron_agent_requirements_ok($why)) {
        wp_die('Self-test failed: ' . esc_html($why));
    }

    $api_key = ai_cron_agent_get_api_key();
    $payload = [
        'model' => ai_cron_agent_get_option('text_model') ?: 'gpt-4o-mini',
        'messages' => [
            ['role' => 'user', 'content' => 'Say OK only.'],
        ],
        'temperature' => 0,
        'max_tokens' => 3,
    ];

    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($res)) {
        wp_die('Transport error: ' . esc_html($res->get_error_message()));
    }
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    echo '<pre>Status: ' . intval($code) . "\n\n" . esc_html(substr($body, 0, 2000)) . '</pre>';
    exit;
});

//
// ────────────────────────────────────────────────────────────────────────────
/** Cron task */
// ────────────────────────────────────────────────────────────────────────────
add_action(AI_CRON_AGENT_EVENT, 'ai_cron_agent_generate_post_cb', 10, 1);

function ai_cron_agent_generate_post_cb($manual = false) {
    ai_cron_agent_log('Generator entered' . ($manual ? ' (manual)' : ''));

    $why = '';
    if (!ai_cron_agent_requirements_ok($why)) {
        ai_cron_agent_log('Requirements not met: ' . $why);
        return;
    }


    $topic_prompt = "Give me a trending blog topic about embedded systems.";
    $topic = ai_cron_agent_call_openai_text($topic_prompt, $err, 1);
    // $topic        = ai_cron_agent_get_option('topic');
    
    $title_p      = "Do Not Use Quotes." . str_replace('{{TOPIC}}', $topic, ai_cron_agent_get_option('title_prompt'));
    $body_p       = str_replace('{{TOPIC}}', $topic, ai_cron_agent_get_option('body_prompt'));
    $img_enabled  = (int)ai_cron_agent_get_option('image_enabled') === 1;
    $img_prompt =   str_replace('{{TOPIC}}', $topic, ai_cron_agent_get_option('image_prompt'));
    // $img_prompt =   'Make me an image about' . $title . "Cartoon. ";
    $category_id  = (int)ai_cron_agent_get_option('category_id');
    $status       = ai_cron_agent_get_option('status');
    $author_id    = (int)ai_cron_agent_get_option('author_id');

    $title_err = $body_err = null;
    $title = ai_cron_agent_call_openai_text($title_p, $title_err, 2);
    $content_html = ai_cron_agent_call_openai_text($body_p, $body_err, 3);

    if (!$title || !$content_html) {
        $detail = $title_err ?: $body_err ?: 'unknown';
        ai_cron_agent_log('Failed to generate title or content | ' . $detail);
        return;
    }

    $args = [
        'post_title'   => wp_strip_all_tags($title),
        'post_content' => wp_kses_post($content_html),
        'post_status'  => $status,
    ];
    if ($category_id > 0) $args['post_category'] = [$category_id];
    if ($author_id > 0)   $args['post_author']   = $author_id;

    $post_id = wp_insert_post($args);
    if (is_wp_error($post_id) || !$post_id) {
        ai_cron_agent_log('wp_insert_post failed');
        return;
    }

    if ($img_enabled && !empty($img_prompt)) {
        $b64 = ai_cron_agent_generate_image_base64($img_prompt);
        if ($b64) {
            $thumb_id = ai_cron_agent_save_b64_image($b64, sanitize_title($title) . '.png');
            if ($thumb_id) {
                if (set_post_thumbnail($post_id, $thumb_id)) {
                    ai_cron_agent_log('Featured image set. Attachment ID: ' . $thumb_id);
                } else {
                    // Fallback: try updating meta directly
                    $ok = update_post_meta($post_id, '_thumbnail_id', $thumb_id);
                    ai_cron_agent_log($ok ? 'Featured image meta set via update_post_meta.' : 'Failed to set featured image.');
                }
            }
        } else {
            ai_cron_agent_log('No image b64 returned from generator.');
        }
    }

    ai_cron_agent_log('Created post ID: ' . $post_id . ($manual ? ' (manual)' : ''));
}

//
// ────────────────────────────────────────────────────────────────────────────
// Logging utilities (PHP 7.4-safe)
// ────────────────────────────────────────────────────────────────────────────
function ai_cron_agent_log($msg) {
    // Update lightweight status
    $opts = get_option('ai_cron_agent_options', ai_cron_agent_default_options());
    $opts['last_run'] = date_i18n('Y-m-d H:i:s');

    $lower = strtolower($msg);
    if (strpos($lower, 'failed') !== false || strpos($lower, 'error') !== false) {
        $opts['last_error'] = $msg;
    } else {
        // treat these as info, not errors
        if ($msg === 'Manual run: handler reached' || substr($msg, 0, 17) === 'Generator entered') {
            $opts['last_error'] = '';
        } else {
            // leave as-is
            $opts['last_error'] = $opts['last_error'] ?? '';
        }
    }
    update_option('ai_cron_agent_options', $opts, false);

    // Rolling log (keep last 50)
    $log = get_option(AI_CRON_AGENT_LOG_OPTION, []);
    if (!is_array($log)) $log = [];
    $log[] = date('Y-m-d H:i:s') . ' - ' . $msg;
    if (count($log) > 50) {
        $log = array_slice($log, -50);
    }
    update_option(AI_CRON_AGENT_LOG_OPTION, $log, false);
}

//
// ────────────────────────────────────────────────────────────────────────────
/** OpenAI helpers */
// ────────────────────────────────────────────────────────────────────────────
function ai_cron_agent_get_api_key() {
    if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) return OPENAI_API_KEY;
    return ai_cron_agent_get_option('api_key');
}

function ai_cron_agent_call_openai_text($prompt, &$err = null, $type = 1) {
    $err = null;

    $api_key = ai_cron_agent_get_api_key();
    if (!$api_key) { $err = 'Missing API key'; ai_cron_agent_log('Missing API key'); return ''; }

    $model = ai_cron_agent_get_option('text_model') ?: 'gpt-4o-mini';
    switch ($type) {
        case 1:
            $system_prompt = "You are a creative research assistant. Your job is to generate a unique, random, and very specific blog topic within the broad field of embedded computing
                        Constraints:
                        - Each response should focus on a different *niche* or *industry application*.
                        - Be concrete, not generic (e.g., 'Low-power MCU design for wearable ECG monitors' is good, 'IoT in healthcare' is too broad).
                        - Choose a random industry (automotive, aerospace, medical devices, robotics, consumer electronics, industrial automation, etc.).
                        - Return just ONE topic";
            break;
        case 2:
            $system_prompt = "Return a SEO-optimized, straightforward blog title with no quotes, no html, just the title.";
            break;
        case 3:
$system_prompt = "Write ONLY the blog body in valid HTML for WordPress. 
                  Rules:
                    - Do NOT include <html>, <head>, <body>, or <title>.
                    - Do NOT include a main <h1> title at the top. Start directly with <h3> or <h4> sections.
                    - Use <h3>, <h4>, <p>, <ul>, <li>, and <strong> tags for structure.
                    - Return ONLY the HTML snippet for the article body.
                    Topic: {{TOPIC}}
                ";
          break;
        default:
            $system_prompt = "You are a helpful writing assistant. Write in clean HTML format.";
    }

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt], 
            ['role' => 'user',   'content' => $prompt],
        ],
        'temperature' => 0.7,
    ];

    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($res)) {
        $err = 'OpenAI text error (transport): ' . $res->get_error_message();
        ai_cron_agent_log($err);
        return '';
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code !== 200) {
        $snippet = substr($body, 0, 400);
        $err = 'OpenAI text error: HTTP ' . $code . ' | ' . $snippet;
        ai_cron_agent_log($err);
        return '';
    }

    $data = json_decode($body, true);
    if (isset($data['error']['message'])) {
        $err = 'OpenAI text error: ' . $data['error']['message'];
        ai_cron_agent_log($err);
        return '';
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    if (!$content) {
        $err = 'OpenAI text parse error: empty content. Body: ' . substr($body, 0, 400);
        ai_cron_agent_log($err);
        return '';
    }

    return $content;
}

function ai_cron_agent_generate_image_base64($prompt) {
    $api_key = ai_cron_agent_get_api_key();
    if (!$api_key) { ai_cron_agent_log('Missing API key'); return ''; }

    $model = ai_cron_agent_get_option('image_model') ?: 'gpt-image-1';

    // NOTE: do NOT send 'response_format' — some deployments 400 on it.
    $payload = [
        'model'  => $model,
        'prompt' => $prompt,
        'size'   => '1024x1024',
        'n'      => 1,
    ];

    $res = wp_remote_post('https://api.openai.com/v1/images/generations', [
        'timeout' => 120,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($res)) {
        ai_cron_agent_log('OpenAI image error (transport): ' . $res->get_error_message());
        return '';
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    if ($code !== 200) {
        ai_cron_agent_log('OpenAI image error: HTTP ' . $code . ' | ' . substr($body, 0, 400));
        return '';
    }

    $data = json_decode($body, true);
    if (isset($data['error']['message'])) {
        ai_cron_agent_log('OpenAI image error: ' . $data['error']['message']);
        return '';
    }

    // Prefer base64 if present, otherwise fetch the URL and convert to base64
    $item = $data['data'][0] ?? [];
    if (!empty($item['b64_json'])) {
        return $item['b64_json'];
    }
    if (!empty($item['url'])) {
        $b64 = ai_cron_agent_fetch_image_b64_from_url($item['url']);
        if ($b64) return $b64;
        ai_cron_agent_log('OpenAI image parse error: URL fetch failed.');
        return '';
    }

    ai_cron_agent_log('OpenAI image parse error: no b64_json or url in response. Body: ' . substr($body, 0, 400));
    return '';
}

function ai_cron_agent_fetch_image_b64_from_url($url) {
    $get = wp_remote_get($url, ['timeout' => 120]);
    if (is_wp_error($get)) {
        ai_cron_agent_log('Image URL fetch error: ' . $get->get_error_message());
        return '';
    }
    $code = wp_remote_retrieve_response_code($get);
    if ($code !== 200) {
        ai_cron_agent_log('Image URL fetch HTTP ' . $code);
        return '';
    }
    $bytes = wp_remote_retrieve_body($get);
    if ($bytes === '' || $bytes === null) {
        ai_cron_agent_log('Image URL fetch error: empty body');
        return '';
    }
    return base64_encode($bytes);
}


function ai_cron_agent_save_b64_image($b64, $filename) {
    // Decode
    $bytes = base64_decode($b64);
    if ($bytes === false || $bytes === null) {
        ai_cron_agent_log('Image save error: base64 decode failed');
        return 0;
    }

    // Safe filename (PNG default)
    $safe = sanitize_file_name($filename ?: ('ai-cron-agent-' . time() . '.png'));
    if (!preg_match('/\.(png|jpg|jpeg|gif|webp)$/i', $safe)) {
        $safe .= '.png';
    }

    // Let WP create dirs, unique name, etc.
    $upload = wp_upload_bits($safe, null, $bytes);
    if (!empty($upload['error'])) {
        ai_cron_agent_log('Image save error: ' . $upload['error']);
        return 0;
    }

    // Create attachment
    $file_for_type = $upload['file']; // full path
    $ft = wp_check_filetype_and_ext($file_for_type, basename($file_for_type));
    $mime = $ft['type'] ?: 'image/png';

    $attachment = [
        'post_mime_type' => $mime,
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($file_for_type)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    $attach_id = wp_insert_attachment($attachment, $file_for_type);
    if (is_wp_error($attach_id) || !$attach_id) {
        ai_cron_agent_log('Image save error: wp_insert_attachment failed');
        return 0;
    }

    // Generate sizes & metadata
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata($attach_id, $file_for_type);
    if (is_wp_error($attach_data) || empty($attach_data)) {
        ai_cron_agent_log('Image save warning: metadata generation failed');
    } else {
        wp_update_attachment_metadata($attach_id, $attach_data);
    }

    ai_cron_agent_log('Image saved as attachment ID: ' . $attach_id);
    return $attach_id;
}

