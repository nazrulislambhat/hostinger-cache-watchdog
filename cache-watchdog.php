    <?php
/**
 * Plugin Name: Hostinger Cache Watchdog
 * Description: Monitors the WooCommerce shop page every minute. Sends Slack alerts when products go missing, clears all caches, then confirms when products are restored (with a live screenshot).
 * Version: 2.0.0
 * Author: Nazrul Islam
 * Author URI: https://nazrulislam.dev
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────────────────────
define( 'SCW_SHOP_URL',      home_url( '/shop/' ) );
define( 'SCW_LOG_FILE',      WP_CONTENT_DIR . '/sana-cache-watchdog.log' );
define( 'SCW_MAX_LOG_LINES', 500 );
define( 'SCW_CRON_HOOK',     'scw_check_shop_hook' );
define( 'SCW_INTERVAL_NAME', 'scw_every_minute' );
define( 'SCW_OPTION_KEY',    'scw_settings' );

// ─────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────
function scw_settings() {
    return wp_parse_args( get_option( SCW_OPTION_KEY, [] ), [
        'slack_webhook' => '',
        'alert_email'   => get_option( 'admin_email' ),
        'email_enabled' => '0',
    ] );
}

function scw_webhook() {
    $s = scw_settings();
    return trim( $s['slack_webhook'] );
}

// ─────────────────────────────────────────────────────────────
// 1. CRON INTERVAL
// ─────────────────────────────────────────────────────────────
add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules[ SCW_INTERVAL_NAME ] = [
        'interval' => 60,
        'display'  => 'Every Minute',
    ];
    return $schedules;
} );

// ─────────────────────────────────────────────────────────────
// 2. ACTIVATION / DEACTIVATION
// ─────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    if ( ! wp_next_scheduled( SCW_CRON_HOOK ) ) {
        wp_schedule_event( time(), SCW_INTERVAL_NAME, SCW_CRON_HOOK );
    }
    update_option( 'scw_last_status', 'ok' );
    scw_log( 'PLUGIN ACTIVATED – watchdog cron scheduled.' );
} );

register_deactivation_hook( __FILE__, function () {
    $ts = wp_next_scheduled( SCW_CRON_HOOK );
    if ( $ts ) wp_unschedule_event( $ts, SCW_CRON_HOOK );
    scw_log( 'PLUGIN DEACTIVATED – cron removed.' );
} );

// ─────────────────────────────────────────────────────────────
// 3. MAIN CRON CALLBACK
// ─────────────────────────────────────────────────────────────
add_action( SCW_CRON_HOOK, 'scw_run_check' );
function scw_run_check( $manual = false ) {
    $label = $manual ? '[MANUAL]' : '[AUTO]';
    scw_log( "{$label} CHECK START → " . SCW_SHOP_URL );

    $products_found = scw_fetch_and_detect();
    $last_status    = get_option( 'scw_last_status', 'ok' );

    if ( ! $products_found ) {
        // ── Products missing ──────────────────────────────────
        scw_log( "{$label} ⚠️  NO PRODUCTS DETECTED on shop page." );
        update_option( 'scw_last_status', 'down' );
        update_option( 'scw_down_since', current_time( 'mysql' ) );

        // Alert 1: products are gone
        scw_slack( [
            'color' => '#FF0000',
            'title' => '🚨 Shop Products Missing!',
            'text'  => "No products were found on the shop page.\n*Site:* " . SCW_SHOP_URL . "\n*Time:* " . current_time( 'mysql' ) . "\n\nClearing all caches now…",
        ] );

        // Clear caches
        $cleared = scw_clear_all_caches();
        scw_log( "{$label} Caches cleared: " . implode( ', ', $cleared ) );

        // Alert 2: caches cleared
        scw_slack( [
            'color' => '#FFA500',
            'title' => '🧹 Cache Cleared – Monitoring for Recovery',
            'text'  => "All caches have been purged:\n• " . implode( "\n• ", $cleared ) . "\n\nWill send a recovery confirmation once products are detected again.",
        ] );

    } else {
        // ── Products present ──────────────────────────────────
        scw_log( "{$label} ✅ Products detected. Shop is healthy." );

        if ( $last_status === 'down' ) {
            // Products just came back → send recovery notification WITH screenshot
            update_option( 'scw_last_status', 'ok' );
            $down_since     = get_option( 'scw_down_since', 'unknown' );
            $screenshot_url = scw_screenshot_url( SCW_SHOP_URL );

            scw_slack( [
                'color'     => '#36a64f',
                'title'     => '✅ Products Are Back Online!',
                'text'      => "Shop products have been restored after cache clear.\n*Down since:* {$down_since}\n*Recovered:* " . current_time( 'mysql' ) . "\n*URL:* " . SCW_SHOP_URL,
                'image_url' => $screenshot_url,
            ] );
        }
    }
}

// ─────────────────────────────────────────────────────────────
// 4. FETCH SHOP PAGE & DETECT PRODUCTS
// ─────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────
// 4. FETCH SHOP PAGE & DETECT PRODUCTS
// ─────────────────────────────────────────────────────────────
function scw_fetch_and_detect() {

    $response = wp_remote_get( add_query_arg( '_scw', time(), SCW_SHOP_URL ), [
        'timeout'   => 20,
        'sslverify' => false,
        'headers'   => [
            'Cache-Control' => 'no-cache, no-store',
            'Pragma'        => 'no-cache',
            'User-Agent'    => 'SanaCacheWatchdog/2.0 (internal health check)',
        ],
    ] );

    // ── Safety: don't trigger alerts on HTTP failures ──
    if ( is_wp_error( $response ) ) {
        scw_log( 'HTTP ERROR: ' . $response->get_error_message() );
        return true;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        scw_log( "HTTP {$code} – skipping detection." );
        return true;
    }

    $body = wp_remote_retrieve_body( $response );

    // ─────────────────────────────────────────────
    // HTML detection: ensure product UL contains LI
    // ─────────────────────────────────────────────
    $has_html_products = false;

    // 1️⃣ Standard WooCommerce product item
    if ( strpos( $body, '<li class="product' ) !== false ) {
        $has_html_products = true;
    }

    // 2️⃣ Woo Blocks product item
    if ( strpos( $body, 'wc-block-grid__product' ) !== false ) {
        $has_html_products = true;
    }

    // 3️⃣ Theme product UL must contain at least one <li>
    if ( preg_match(
        '/<ul[^>]*class="[^"]*products[^"]*"[^>]*>(.*?)<\/ul>/is',
        $body,
        $m
    ) ) {
        if ( strpos( $m[1], '<li' ) !== false ) {
            $has_html_products = true;
        } else {
            scw_log( 'DETECT → Products UL found but EMPTY' );
        }
    } else {
        scw_log( 'DETECT → No products UL found in HTML' );
    }

    // ─────────────────────────────────────────────
    // DB detection: published WooCommerce products
    // ─────────────────────────────────────────────
    $counts = wp_count_posts( 'product' );
    $product_count = isset( $counts->publish ) ? (int) $counts->publish : 0;

    // Log debug info
    scw_log( "DETECT → HTML:" . ($has_html_products ? 'yes' : 'no') . " | DB:{$product_count}" );

    // Healthy only if BOTH HTML and DB confirm products
    return ( $has_html_products && $product_count > 0 );
}

// ─────────────────────────────────────────────────────────────
// 5. SCREENSHOT  (thum.io – free, no API key needed)
// ─────────────────────────────────────────────────────────────
function scw_screenshot_url( $url ) {
    return 'https://image.thum.io/get/width/1280/crop/900/noanimate/' . urlencode( $url );
}

// ─────────────────────────────────────────────────────────────
// 6. SLACK NOTIFICATION
// ─────────────────────────────────────────────────────────────
function scw_slack( $args ) {
    $webhook = scw_webhook();
    if ( empty( $webhook ) ) {
        scw_log( 'SLACK: No webhook URL configured – notification skipped.' );
        return false;
    }

    $a = wp_parse_args( $args, [
        'color'     => '#36a64f',
        'title'     => '',
        'text'      => '',
        'image_url' => '',
        'footer'    => 'Hostinger Cache Watchdog · ' . get_bloginfo( 'name' ),
    ] );

    $attachment = [
        'color'      => $a['color'],
        'title'      => $a['title'],
        'title_link' => SCW_SHOP_URL,
        'text'       => $a['text'],
        'footer'     => $a['footer'],
        'ts'         => time(),
        'mrkdwn_in'  => [ 'text' ],
    ];

    if ( ! empty( $a['image_url'] ) ) {
        $attachment['image_url'] = $a['image_url'];
    }

    $result = wp_remote_post( $webhook, [
        'body'    => wp_json_encode( [ 'attachments' => [ $attachment ] ] ),
        'headers' => [ 'Content-Type' => 'application/json' ],
        'timeout' => 10,
    ] );

    if ( is_wp_error( $result ) ) {
        scw_log( 'SLACK ERROR: ' . $result->get_error_message() );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $result );
    $body = wp_remote_retrieve_body( $result );

    if ( $code === 200 && $body === 'ok' ) {
        scw_log( 'SLACK: ✓ Sent – "' . $a['title'] . '"' );
        return true;
    }

    scw_log( "SLACK ERROR: HTTP {$code} – {$body}" );
    return false;
}

// ─────────────────────────────────────────────────────────────
// 7. CACHE CLEARING
// ─────────────────────────────────────────────────────────────
function scw_clear_all_caches() {
    $cleared = [];

    // ─────────────────────────────
    // 1️⃣ HOSTINGER / LITESPEED SERVER CACHE
    // ─────────────────────────────
    if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
        LiteSpeed_Cache_API::purge_all();
        $cleared[] = 'LiteSpeed Server';
    }

    if ( has_action( 'litespeed_purge_all' ) ) {
        do_action( 'litespeed_purge_all' );
        $cleared[] = 'LiteSpeed Action';
    }

    // Hostinger MU plugin hooks
    if ( has_action( 'hostinger_purge_all' ) ) {
        do_action( 'hostinger_purge_all' );
        $cleared[] = 'Hostinger Server';
    }

    if ( has_action( 'hcache_purge_all' ) ) {
        do_action( 'hcache_purge_all' );
        $cleared[] = 'Hostinger hCache';
    }

    // Hostinger CDN purge
    if ( function_exists( 'hostinger_cdn_purge_all' ) ) {
        hostinger_cdn_purge_all();
        $cleared[] = 'Hostinger CDN';
    }

    // ─────────────────────────────
    // 2️⃣ WORDPRESS / PLUGIN CACHES
    // ─────────────────────────────
    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
        $cleared[] = 'WP Rocket';
    }

    if ( function_exists( 'w3tc_flush_all' ) ) {
        w3tc_flush_all();
        $cleared[] = 'W3 Total Cache';
    }

    if ( function_exists( 'wp_cache_clear_cache' ) ) {
        wp_cache_clear_cache();
        $cleared[] = 'WP Super Cache';
    }

    if ( class_exists( 'autoptimizeCache' ) ) {
        autoptimizeCache::clearall();
        $cleared[] = 'Autoptimize';
    }

    if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
        sg_cachepress_purge_cache();
        $cleared[] = 'SG Optimizer';
    }

    // WP object cache
    wp_cache_flush();
    $cleared[] = 'WP Object Cache';

    // Woo transients
    scw_flush_woo_transients();
    $cleared[] = 'Woo Transients';

    scw_log( 'CACHE PURGE → ' . implode( ', ', $cleared ) );

    return $cleared;
}

function scw_flush_woo_transients() {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\_transient\_wc\_%'
            OR option_name LIKE '\_transient\_timeout\_wc\_%'"
    );
}

// ─────────────────────────────────────────────────────────────
// 8. LOGGING
// ─────────────────────────────────────────────────────────────
function scw_log( $message ) {
    $line = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL;
    if ( file_exists( SCW_LOG_FILE ) ) {
        $lines = file( SCW_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( count( $lines ) >= SCW_MAX_LOG_LINES ) {
            $lines = array_slice( $lines, -450 );
            file_put_contents( SCW_LOG_FILE, implode( PHP_EOL, $lines ) . PHP_EOL );
        }
    }
    file_put_contents( SCW_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
}

// ─────────────────────────────────────────────────────────────
// 9. ADMIN PAGE  (Settings → Cache Watchdog)
// ─────────────────────────────────────────────────────────────
add_action( 'admin_menu', function () {
    add_options_page(
        'Cache Watchdog',
        '🐶 Cache Watchdog',
        'manage_options',
        'scw-settings',
        'scw_admin_page'
    );
} );

function scw_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $notice  = '';
    $n_class = 'success';

    if ( isset( $_POST['scw_save'] ) && check_admin_referer( 'scw_settings_save' ) ) {
        update_option( SCW_OPTION_KEY, [
            'slack_webhook' => sanitize_text_field( $_POST['slack_webhook'] ?? '' ),
            'alert_email'   => sanitize_email( $_POST['alert_email'] ?? '' ),
            'email_enabled' => isset( $_POST['email_enabled'] ) ? '1' : '0',
        ] );
        $notice = '✅ Settings saved.';
    }

    if ( isset( $_POST['scw_clear'] ) && check_admin_referer( 'scw_manual_clear' ) ) {
        $cleared = scw_clear_all_caches();
        scw_log( '[MANUAL] Cache cleared by admin.' );
        scw_slack( [
            'color' => '#FFA500',
            'title' => '🧹 Cache Manually Cleared by Admin',
            'text'  => "An admin manually cleared all caches.\n*Cleared:* " . implode( ', ', $cleared ) . "\n*Time:* " . current_time( 'mysql' ),
        ] );
        $notice = '✅ Cache cleared! (' . implode( ', ', $cleared ) . ')';
    }

    if ( isset( $_POST['scw_run_now'] ) && check_admin_referer( 'scw_manual_run' ) ) {
        scw_run_check( true );
        $notice = '✅ Manual check complete. See log below.';
    }

    if ( isset( $_POST['scw_test_slack'] ) && check_admin_referer( 'scw_test_slack' ) ) {
        $sent = scw_slack( [
            'color'     => '#439FE0',
            'title'     => '🧪 Test Notification – Hostinger Cache Watchdog',
            'text'      => "Your Slack webhook is working correctly! ✅\n*Shop URL:* " . SCW_SHOP_URL . "\n*Site:* " . get_bloginfo( 'name' ) . "\n*Time:* " . current_time( 'mysql' ),
            'image_url' => scw_screenshot_url( SCW_SHOP_URL ),
        ] );
        $notice  = $sent ? '✅ Test notification sent! Check your #status channel.' : '❌ Failed. Check webhook URL and log below.';
        $n_class = $sent ? 'success' : 'error';
    }

    $settings  = scw_settings();
    $next      = wp_next_scheduled( SCW_CRON_HOOK );
    $next_str  = $next
        ? get_date_from_gmt( date( 'Y-m-d H:i:s', $next ) ) . ' (site time)'
        : '⚠️ NOT SCHEDULED – deactivate & reactivate the plugin';
    $last_stat = get_option( 'scw_last_status', 'ok' );
    $log_lines = file_exists( SCW_LOG_FILE )
        ? array_reverse( file( SCW_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) )
        : [];
    $log_html  = $log_lines ? esc_html( implode( "\n", $log_lines ) ) : 'No log entries yet.';
    ?>

    <style>
        .scw-wrap { max-width: 980px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .scw-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 20px; }
        .scw-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 22px; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        .scw-card h2 { margin-top: 0; font-size: 15px; border-bottom: 1px solid #f0f0f0; padding-bottom: 10px; margin-bottom: 14px; }
        .scw-full { grid-column: 1 / -1; }
        .scw-pill { display:inline-block; padding:3px 12px; border-radius:20px; font-weight:600; font-size:12px; }
        .scw-pill.ok   { background:#d4edda; color:#155724; }
        .scw-pill.down { background:#f8d7da; color:#721c24; }
        .scw-log { background:#0d1117; color:#c9d1d9; font-family:'SFMono-Regular',Consolas,monospace; font-size:11.5px; padding:14px; height:380px; overflow-y:scroll; border-radius:8px; white-space:pre; }
        .scw-log .w { color:#e3b341; }
        .scw-log .g { color:#56d364; }
        .scw-log .r { color:#f85149; }
        .scw-log .p { color:#bc8cff; }
        .scw-actions { display:flex; flex-wrap:wrap; gap:10px; }
        .scw-actions form { margin:0; }
        .btn-danger  { background:#d32f2f!important; border-color:#b71c1c!important; color:#fff!important; }
        .btn-slack   { background:#4a154b!important; border-color:#300d30!important; color:#fff!important; }
        .btn-primary { background:#2271b1!important; color:#fff!important; }
        .scw-code { background:#0d1117; color:#56d364; padding:10px 14px; border-radius:6px; font-family:monospace; font-size:12px; display:block; word-break:break-all; margin:8px 0; }
        .scw-step { display:flex; gap:12px; align-items:flex-start; margin:8px 0; }
        .scw-step-num { background:#2271b1; color:#fff; border-radius:50%; width:22px; height:22px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; margin-top:1px; }
    </style>

    <div class="wrap scw-wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">🐶 Hostinger Cache Watchdog
            <span style="font-size:12px;font-weight:400;color:#666;background:#f0f0f0;padding:3px 10px;border-radius:10px;">v2.0</span>
        </h1>

        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo $n_class; ?> is-dismissible"><p><strong><?php echo esc_html( $notice ); ?></strong></p></div>
        <?php endif; ?>

        <div class="scw-grid">

            <!-- STATUS -->
            <div class="scw-card">
                <h2>📊 Live Status</h2>
                <p style="font-size:14px;">
                    Shop:&nbsp;
                    <span class="scw-pill <?php echo esc_attr( $last_stat ); ?>">
                        <?php echo $last_stat === 'ok' ? '✅ Products Online' : '🚨 Products Missing'; ?>
                    </span>
                </p>
                <p style="margin:6px 0;"><strong>Monitoring:</strong><br>
                    <a href="<?php echo esc_url( SCW_SHOP_URL ); ?>" target="_blank" style="word-break:break-all"><?php echo esc_html( SCW_SHOP_URL ); ?></a>
                </p>
                <p style="font-size:12px;color:#666;margin-top:12px;">
                    <strong>Next auto-check:</strong><br><?php echo esc_html( $next_str ); ?>
                </p>
            </div>

            <!-- QUICK ACTIONS -->
            <div class="scw-card">
                <h2>⚡ Actions</h2>
                <div class="scw-actions">

                    <form method="post">
                        <?php wp_nonce_field( 'scw_manual_run' ); ?>
                        <button class="button button-primary btn-primary" name="scw_run_now" value="1">▶ Run Check Now</button>
                    </form>

                    <form method="post">
                        <?php wp_nonce_field( 'scw_manual_clear' ); ?>
                        <button class="button btn-danger" name="scw_clear" value="1"
                            onclick="return confirm('Clear all caches now? Your shop may be briefly slower while it rebuilds.')">
                            🧹 Clear Cache Now
                        </button>
                    </form>

                    <form method="post">
                        <?php wp_nonce_field( 'scw_test_slack' ); ?>
                        <button class="button btn-slack" name="scw_test_slack" value="1">
                            💬 Send Test Slack Message
                        </button>
                    </form>

                </div>
                <p style="font-size:11px;color:#999;margin-top:14px;">
                    The test notification includes a live screenshot of your shop page so you can confirm it renders correctly in Slack.
                </p>
            </div>

            <!-- SETTINGS -->
            <div class="scw-card scw-full">
                <h2>⚙️ Settings</h2>
                <form method="post">
                    <?php wp_nonce_field( 'scw_settings_save' ); ?>
                    <table class="form-table" style="margin:0">
                        <tr>
                            <th style="width:180px;padding-left:0">
                                <label for="slack_webhook">
                                    <span style="font-size:16px;">💬</span> Slack Webhook URL
                                </label>
                            </th>
                            <td>
                                <input type="url" id="slack_webhook" name="slack_webhook"
                                    style="width:100%;max-width:580px;border-radius:4px;"
                                    placeholder="https://hooks.slack.com/services/..."
                                    value="<?php echo esc_attr( $settings['slack_webhook'] ); ?>">
                                <p class="description">
                                    Paste your Incoming Webhook URL. Notifications will go to whichever channel the webhook is configured for.
                                    <a href="https://api.slack.com/messaging/webhooks" target="_blank">How to create a webhook →</a>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p><button class="button button-primary btn-primary" name="scw_save" value="1">💾 Save Settings</button></p>
                </form>
            </div>

            <!-- LOG -->
            <div class="scw-card scw-full">
                <h2>📋 Activity Log
                    <span style="font-size:11px;font-weight:400;color:#999;"><?php echo count($log_lines); ?> entries · newest first</span>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=scw-settings' ) ); ?>"
                       class="button button-small" style="margin-left:10px;">🔄 Refresh</a>
                </h2>
                <div class="scw-log" id="scwLog"><?php echo $log_html; ?></div>
                <p style="font-size:11px;color:#999;margin-top:6px;">Log path: <?php echo esc_html( SCW_LOG_FILE ); ?></p>
            </div>

            <!-- SETUP GUIDE -->
            <div class="scw-card scw-full" style="background:#f9fafb;border-color:#e5e7eb;">
                <h2>📖 Setup Guide</h2>

                <p><strong>Notification flow when products go missing:</strong></p>
                <div class="scw-step"><div class="scw-step-num">1</div><div>🚨 <strong>Alert:</strong> "No products found – clearing cache now" (red Slack message)</div></div>
                <div class="scw-step"><div class="scw-step-num">2</div><div>🧹 <strong>Alert:</strong> "Cache cleared – monitoring for recovery" (orange Slack message)</div></div>
                <div class="scw-step"><div class="scw-step-num">3</div><div>✅ <strong>Alert:</strong> "Products are back online!" — includes a <strong>live screenshot</strong> of your shop (green Slack message)</div></div>

                <p style="margin-top:18px;"><strong>Make checks truly every minute</strong> (WordPress cron only fires on page visits).</p>
                <p>Add this in <strong>Hostinger cPanel → Advanced → Cron Jobs</strong>:</p>
                <code class="scw-code">* * * * * php <?php echo esc_html( ABSPATH ); ?>wp-cron.php</code>
                <p style="font-size:12px;color:#666;">Set frequency to "Every Minute" (or manually enter <code>* * * * *</code>)</p>
            </div>

        </div>
    </div>

    <script>
    (function() {
        var box = document.getElementById('scwLog');
        if (!box) return;
        box.innerHTML = box.innerHTML
            .replace(/(⚠️[^\n]*|NO PRODUCTS[^\n]*|WARNING[^\n]*|⚠[^\n]*)/g, '<span class="w">$1</span>')
            .replace(/(✅[^\n]*)|(OK –[^\n]*)/g, '<span class="g">$1$2</span>')
            .replace(/(ERROR[^\n]*|❌[^\n]*)/g, '<span class="r">$1</span>')
            .replace(/(SLACK:[^\n]*)/g, '<span class="p">$1</span>');
    })();
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────
// 10. PLUGIN ACTION LINK
// ─────────────────────────────────────────────────────────────
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    $url = admin_url( 'options-general.php?page=scw-settings' );
    array_unshift( $links, '<a href="' . esc_url( $url ) . '">⚙️ Settings</a>' );
    return $links;
} );
