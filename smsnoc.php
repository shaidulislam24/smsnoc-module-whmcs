<?php
/**
 * SMS NOC Gateway - WHMCS Addon Module v1.0.0
 * Hardcoded API: https://smsnoc.com/api/v1
 * All configuration managed from dashboard (not addon config page)
 * 55+ hooks, custom templates, OTP, voice/TTS, fallback channels,
 * failure hooks, client search/filter/multi-select send, modern dark dashboard
 */

if (!defined("WHMCS")) die("This file cannot be accessed directly");

use WHMCS\Database\Capsule;

function smsnoc_config() {
    return [
        "name"        => "SMS NOC Gateway",
        "description" => "Send SMS, Voice, WhatsApp & Email via SMS NOC. 55+ automated hooks, OTP verification, TTS voice, fallback channels, failure alerts, client search & bulk send, advanced rate limiting.",
        "version"     => "1.0.0",
        "author"      => "SMS NOC",
        "fields"      => [],
    ];
}

function smsnoc_activate() {
    try {
        if (!Capsule::schema()->hasTable('mod_smsnoc_settings')) {
            Capsule::schema()->create('mod_smsnoc_settings', function ($table) {
                $table->string('setting_key', 191)->primary();
                $table->text('setting_value')->nullable();
                $table->timestamp('updated_at')->useCurrent();
            });
        }
        $defaults = [
            'api_key' => '', 'default_sender' => '', 'default_caller_id' => '',
            'default_device_id' => '', 'default_email_config' => '',
            'notification_channel' => 'sms', 'admin_phone' => '',
            'tts_language' => 'en', 'tts_gender' => 'female',
            // Fallback channel settings
            'fallback_channel' => 'sms', 'fallback_sender' => '', 'fallback_caller_id' => '',
            'fallback_device_id' => '', 'fallback_email_config' => '',
            // Voice file defaults
            'default_voice_file_url' => '',
            // OTP / Auth settings
            'enable_otp_login' => '0', 'enable_otp_register' => '0', 'enable_otp_forgot_pass' => '0',
            // Form display controls
            'otp_hide_default_login' => '0', 'otp_hide_default_register' => '0', 'otp_hide_default_reset' => '0',
            'otp_email_optional_register' => '0', 'otp_password_optional_register' => '0',
            'otp_login_template' => 'Your login OTP is {otp_code}. Valid for 5 minutes.',
            'otp_register_template' => 'Your registration OTP is {otp_code}. Welcome to {company_name}!',
            'otp_forgot_pass_template' => 'Your password reset OTP is {otp_code}. Valid for 5 minutes.',
            // OTP Rate Limiting
            'otp_max_per_phone_hour' => '3', 'otp_max_per_phone_day' => '10',
            'otp_max_per_ip_hour' => '10', 'otp_expiry_minutes' => '5',
            'otp_resend_cooldown' => '60', 'otp_max_attempts' => '5',
            // Invoice hooks
            'enable_invoice_created' => '1', 'enable_invoice_paid' => '1',
            'enable_invoice_reminder' => '1', 'enable_invoice_cancelled' => '0',
            'enable_invoice_overdue' => '1', 'enable_invoice_overdue_2nd' => '0',
            'enable_invoice_overdue_3rd' => '0', 'enable_invoice_refunded' => '0',
            // Ticket hooks
            'enable_ticket_opened' => '1', 'enable_ticket_opened_admin' => '1',
            'enable_ticket_reply' => '1', 'enable_ticket_client_reply' => '1',
            'enable_ticket_closed' => '0',
            // Service hooks
            'enable_welcome' => '1', 'enable_service_suspended' => '1',
            'enable_service_unsuspended' => '1', 'enable_service_terminated' => '0',
            'enable_service_ready' => '0', 'enable_addon_activated' => '0',
            'enable_service_expiring_soon' => '1', 'enable_service_expiring_1day' => '1',
            'enable_service_expired' => '1',
            // Service failure hooks
            'enable_service_create_failed' => '1', 'enable_service_suspend_failed' => '1',
            'enable_service_unsuspend_failed' => '1', 'enable_service_terminate_failed' => '1',
            // Order & Payment
            'enable_order_admin' => '1', 'enable_order_confirmation' => '1',
            'enable_payment_confirmation' => '1',
            'enable_credit_added' => '0', 'enable_quote_created' => '0',
            // Domain hooks
            'enable_domain_renewal' => '1', 'enable_domain_transfer' => '0',
            'enable_domain_registered' => '0', 'enable_domain_renewed' => '0',
            'enable_domain_expiring_soon' => '1', 'enable_domain_expiring_1day' => '1',
            'enable_domain_expired' => '1',
            // Domain failure hooks
            'enable_domain_register_failed' => '1', 'enable_domain_renew_failed' => '1',
            'enable_domain_transfer_failed' => '1',
            // Client & Auth
            'enable_client_signup' => '1', 'enable_client_signup_admin' => '1',
            'enable_affiliate_withdrawal' => '0',
            'enable_client_login' => '0', 'enable_client_login_self' => '0',
            'enable_password_reset' => '0', 'enable_product_upgrade' => '0',
            'enable_cancellation_request' => '0', 'enable_client_edit_admin' => '0',
            // Additional hooks v7.1
            'enable_quote_accepted' => '0', 'enable_module_password_change' => '0',
            'enable_auto_renew_notice' => '0',
            // Templates
            'tpl_invoice_created' => 'Hi {client_name}, invoice #{invoice_id} for {amount} {currency} due {due_date}. Pay: {pay_link}',
            'tpl_invoice_paid' => 'Hi {client_name}, payment of {amount} {currency} received for invoice #{invoice_id}. Thank you!',
            'tpl_invoice_reminder' => 'Reminder: Invoice #{invoice_id} for {amount} overdue ({due_date}). Pay: {pay_link}',
            'tpl_invoice_cancelled' => 'Hi {client_name}, invoice #{invoice_id} cancelled.',
            'tpl_invoice_overdue' => 'OVERDUE: Invoice #{invoice_id} for {amount} {currency} was due {due_date}. Pay now: {pay_link}',
            'tpl_invoice_overdue_2nd' => '2nd Reminder: Invoice #{invoice_id} for {amount} {currency} is {days_overdue} days overdue. Pay: {pay_link}',
            'tpl_invoice_overdue_3rd' => 'FINAL: Invoice #{invoice_id} ({amount} {currency}) is {days_overdue} days overdue! Service may be suspended. Pay: {pay_link}',
            'tpl_invoice_refunded' => 'Hi {client_name}, refund of {amount} {currency} processed for invoice #{invoice_id}.',
            'tpl_ticket_opened' => 'Hi {client_name}, ticket #{ticket_id}: {subject}. We\'ll respond soon.',
            'tpl_ticket_opened_admin' => 'New ticket #{ticket_id} from {client_name}: {subject} ({priority})',
            'tpl_ticket_reply' => 'Hi {client_name}, ticket #{ticket_id} updated. Check your area.',
            'tpl_ticket_client_reply' => 'Client {client_name} replied to ticket #{ticket_id}: {message_preview}',
            'tpl_ticket_closed' => 'Hi {client_name}, ticket #{ticket_id} closed.',
            'tpl_welcome' => 'Welcome {client_name}! Service {product} activated. Login: {email}.',
            'tpl_service_suspended' => 'Hi {client_name}, {product} ({domain}) suspended. Pay to restore.',
            'tpl_service_unsuspended' => 'Hi {client_name}, {product} reactivated!',
            'tpl_service_terminated' => 'Hi {client_name}, {product} terminated.',
            'tpl_service_ready' => 'Hi {client_name}, your {product} ({domain}) is ready to use!',
            'tpl_addon_activated' => 'Hi {client_name}, addon "{addon_name}" activated on your account.',
            'tpl_service_expiring_soon' => 'Hi {client_name}, your {product} ({domain}) expires on {expiry_date} ({days_remaining} days left). Please renew.',
            'tpl_service_expiring_1day' => 'URGENT: {client_name}, your {product} ({domain}) expires TOMORROW ({expiry_date}). Renew now!',
            'tpl_service_expired' => 'Hi {client_name}, your {product} ({domain}) has expired today. Renew to avoid data loss.',
            'tpl_order_admin' => 'New order #{order_id} from {client_name}! {amount} {currency}.',
            'tpl_order_confirmation' => 'Hi {client_name}, your order #{order_id} ({amount} {currency}) has been confirmed!',
            'tpl_payment_confirmation' => 'Hi {client_name}, payment {amount} {currency} received. TxID: {transaction_id}.',
            'tpl_credit_added' => 'Hi {client_name}, {amount} {currency} credit added to your account.',
            'tpl_quote_created' => 'Hi {client_name}, quote #{quote_id} for {amount} {currency} created. View: {quote_link}',
            'tpl_domain_renewal' => 'Hi {client_name}, {domain} expires {expiry_date}. Renew now.',
            'tpl_domain_transfer' => 'Hi {client_name}, domain {domain} transfer initiated.',
            'tpl_domain_registered' => 'Hi {client_name}, domain {domain} registered successfully!',
            'tpl_domain_renewed' => 'Hi {client_name}, domain {domain} renewed successfully!',
            'tpl_domain_expiring_soon' => 'Hi {client_name}, your domain {domain} expires on {expiry_date} ({days_remaining} days). Renew: {renew_link}',
            'tpl_domain_expiring_1day' => 'URGENT: {client_name}, domain {domain} expires TOMORROW! Renew: {renew_link}',
            'tpl_domain_expired' => 'Hi {client_name}, domain {domain} has expired ({expiry_date}). Renew immediately to avoid losing it.',
            'tpl_client_signup' => 'Welcome {client_name}! Account created. Email: {email}.',
            'tpl_client_signup_admin' => 'New client registered: {client_name} ({email}) {company}',
            'tpl_affiliate_withdrawal' => 'Hi {client_name}, affiliate withdrawal {amount} processed.',
            'tpl_client_login' => 'Login alert: {client_name} logged in at {time} from {ip}.',
            'tpl_client_login_self' => 'Hi {client_name}, your account was accessed at {time} from IP {ip}. Not you? Change password.',
            'tpl_password_reset' => 'Password reset for {client_name} ({email}) at {time}.',
            'tpl_product_upgrade' => 'Hi {client_name}, your {old_product} upgraded to {new_product}!',
            'tpl_cancellation_request' => 'Cancellation requested by {client_name} for {product} ({domain}). Reason: {reason}',
            'tpl_client_edit_admin' => 'Client {client_name} ({email}) updated their profile.',
            // Additional templates v7.1
            'tpl_quote_accepted' => 'Hi {client_name}, quote #{quote_id} ({amount} {currency}) has been accepted!',
            'tpl_module_password_change' => 'Hi {client_name}, password for {product} ({domain}) has been updated.',
            'tpl_auto_renew_notice' => 'Hi {client_name}, invoice #{invoice_id} ({amount} {currency}) auto-paid for service renewal.',
            // Service failure templates
            'tpl_service_create_failed' => 'ALERT: Service creation failed for {client_name} — {product} ({domain}). Error: {error}',
            'tpl_service_suspend_failed' => 'ALERT: Suspend failed for {client_name} — {product} ({domain}). Error: {error}',
            'tpl_service_unsuspend_failed' => 'ALERT: Unsuspend failed for {client_name} — {product} ({domain}). Error: {error}',
            'tpl_service_terminate_failed' => 'ALERT: Terminate failed for {client_name} — {product} ({domain}). Error: {error}',
            // Domain failure templates
            'tpl_domain_register_failed' => 'Hi {client_name}, domain {domain} registration failed. Contact support.',
            'tpl_domain_register_failed_admin' => 'ALERT: Domain registration failed — {domain} for {client_name}. Error: {error}',
            'tpl_domain_renew_failed' => 'Hi {client_name}, domain {domain} renewal failed. Contact support.',
            'tpl_domain_renew_failed_admin' => 'ALERT: Domain renewal failed — {domain} for {client_name}. Error: {error}',
            'tpl_domain_transfer_failed' => 'Hi {client_name}, domain {domain} transfer failed. Error: {error}',
        ];
        foreach ($defaults as $k => $v) {
            try {
                Capsule::table('mod_smsnoc_settings')->insertOrIgnore(['setting_key' => $k, 'setting_value' => $v]);
            } catch (\Exception $e) {}
        }
        // Activity log table
        if (!Capsule::schema()->hasTable('mod_smsnoc_log')) {
            Capsule::schema()->create('mod_smsnoc_log', function ($table) {
                $table->increments('id');
                $table->string('event', 100);
                $table->string('channel', 20)->default('sms');
                $table->string('recipient', 100);
                $table->text('message')->nullable();
                $table->string('status', 20)->default('sent');
                $table->text('response')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
        // OTP table for WHMCS
        if (!Capsule::schema()->hasTable('mod_smsnoc_otp')) {
            Capsule::schema()->create('mod_smsnoc_otp', function ($table) {
                $table->increments('id');
                $table->string('phone', 30);
                $table->string('code', 10);
                $table->string('purpose', 30)->default('login');
                $table->boolean('verified')->default(false);
                $table->timestamp('expires_at');
                $table->timestamp('created_at')->useCurrent();
            });
        }
    } catch (\Exception $e) {}

    return ['status' => 'success', 'description' => 'SMS NOC Gateway v1.0.0 activated. Open the module dashboard to configure.'];
}

function smsnoc_deactivate() {
    // Clean up all database tables created by this module
    try {
        if (Capsule::schema()->hasTable('mod_smsnoc_settings')) {
            Capsule::schema()->drop('mod_smsnoc_settings');
        }
        if (Capsule::schema()->hasTable('mod_smsnoc_log')) {
            Capsule::schema()->drop('mod_smsnoc_log');
        }
        if (Capsule::schema()->hasTable('mod_smsnoc_otp')) {
            Capsule::schema()->drop('mod_smsnoc_otp');
        }
    } catch (\Exception $e) {
        // Log but don't fail deactivation
        logActivity('SMS NOC deactivation cleanup warning: ' . $e->getMessage());
    }

    // Clean up any dedup lock keys that might be in tbladdonmodules
    try {
        Capsule::table('tbladdonmodules')->where('module', 'smsnoc')->delete();
    } catch (\Exception $e) {}

    return ['status' => 'success', 'description' => 'SMS NOC Gateway deactivated. All module data has been cleaned up.'];
}

// ═══════════════════════════════════════════
//  Settings Helpers (use own table)
// ═══════════════════════════════════════════
function smsnoc_get_setting($key, $default = '') {
    global $_smsnoc_settings_cache;
    if ($_smsnoc_settings_cache === null) {
        try {
            $rows = Capsule::table('mod_smsnoc_settings')->pluck('setting_value', 'setting_key');
            $_smsnoc_settings_cache = $rows->toArray();
        } catch (\Exception $e) { $_smsnoc_settings_cache = []; }
    }
    return $_smsnoc_settings_cache[$key] ?? $default;
}

function smsnoc_set_setting($key, $value) {
    global $_smsnoc_settings_cache;
    try {
        Capsule::table('mod_smsnoc_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')]
        );
        if (is_array($_smsnoc_settings_cache)) {
            $_smsnoc_settings_cache[$key] = $value;
        }
    } catch (\Exception $e) {}
}

function smsnoc_invalidate_cache() {
    global $_smsnoc_settings_cache;
    $_smsnoc_settings_cache = null;
}

function smsnoc_log_activity($event, $channel, $recipient, $message, $status, $response = '') {
    try {
        Capsule::table('mod_smsnoc_log')->insert([
            'event' => $event, 'channel' => $channel, 'recipient' => $recipient,
            'message' => substr($message, 0, 500), 'status' => $status,
            'response' => substr($response, 0, 1000),
        ]);
    } catch (\Exception $e) {}
}

// ═══════════════════════════════════════════
//  Main Output
// ═══════════════════════════════════════════
function smsnoc_output($vars) {
    require_once __DIR__ . '/lib/SMSNOC_API.php';
    require_once __DIR__ . '/lib/SMSNOC_LocalMedia.php';
    require_once __DIR__ . '/lib/SMSNOC_Updater.php';

    $api_key = smsnoc_get_setting('api_key');
    $api = new SMSNOC_API($api_key);
    $activeTab = $_GET['tab'] ?? 'dashboard';

    // Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['smsnoc_action'])) {
        smsnoc_handle_post($api);
    }

    echo '<style>' . smsnoc_admin_css() . '</style>';
    echo '<div class="snoc-wrap">';

    echo '<div class="snoc-module-header">';
    echo '<div class="snoc-logo"><span class="snoc-logo-icon">📡</span> <span class="snoc-logo-text">SMS NOC</span> <span class="snoc-version">v1.0</span>' . SMSNOC_Updater::renderVersionBadge() . '</div>';
    echo '</div>';

    // Show update notice at top of dashboard
    echo SMSNOC_Updater::renderUpdateNotice();

    $tabs = [
        'dashboard'  => ['icon' => '📊', 'label' => 'Dashboard'],
        'send'       => ['icon' => '📤', 'label' => 'Send Message'],
        'templates'  => ['icon' => '📝', 'label' => 'Templates'],
        'hooks'      => ['icon' => '🔔', 'label' => 'Hooks & Events'],
        'devices'    => ['icon' => '📱', 'label' => 'Devices'],
        'auth'       => ['icon' => '🔐', 'label' => 'Auth & OTP'],
        'log'        => ['icon' => '📋', 'label' => 'Activity Log'],
        'settings'   => ['icon' => '⚙️', 'label' => 'Settings'],
    ];

    echo '<div class="snoc-tabs">';
    foreach ($tabs as $key => $tab) {
        $active = $activeTab === $key ? 'active' : '';
        echo '<a href="addonmodules.php?module=smsnoc&tab=' . $key . '" class="snoc-tab ' . $active . '">' . $tab['icon'] . ' ' . $tab['label'] . '</a>';
    }
    echo '</div>';

    switch ($activeTab) {
        case 'send': smsnoc_render_send_tab($api); break;
        case 'templates': smsnoc_render_templates_tab(); break;
        case 'hooks': smsnoc_render_hooks_tab(); break;
        case 'devices': smsnoc_render_devices_tab($api); break;
        case 'auth': smsnoc_render_auth_tab(); break;
        case 'log': smsnoc_render_log_tab($api); break;
        case 'settings': smsnoc_render_settings_tab($api); break;
        default: smsnoc_render_dashboard_tab($api);
    }

    echo '<div class="snoc-footer">SMS NOC Gateway v1.0.0 — <a href="https://smsnoc.com" target="_blank">smsnoc.com</a></div>';
    echo '</div>';
}

// ═══════════════════════════════════════════
//  Dashboard Tab
// ═══════════════════════════════════════════
function smsnoc_render_dashboard_tab($api) {
    $balanceResult = $api->get_balance();
    $ratesResult = $api->get_rates();
    $connected = $balanceResult['success'];

    $hook_keys = smsnoc_get_all_hook_keys();
    $active_hooks = 0;
    foreach ($hook_keys as $hk) { if (smsnoc_get_setting($hk, '0') === '1') $active_hooks++; }
    $total_hooks = count($hook_keys);

    echo '<div class="snoc-hero">';
    echo '<div class="snoc-hero-left">';
    echo '<h2>SMS NOC Gateway</h2>';
    echo '<p>Unified Messaging — SMS, Voice, WhatsApp & Email</p>';
    echo '<span class="snoc-hero-api">API: smsnoc.com/api/v1</span>';
    echo '</div>';
    echo '<div class="snoc-hero-right">';
    echo '<div class="snoc-hero-stat"><span class="snoc-hero-val">' . ($connected ? '৳' . number_format((float)($balanceResult['balance']??0), 0) : '—') . '</span><span class="snoc-hero-lbl">Balance</span></div>';
    echo '<div class="snoc-hero-stat"><span class="snoc-hero-val">' . $active_hooks . '/' . $total_hooks . '</span><span class="snoc-hero-lbl">Hooks</span></div>';
    echo '<div class="snoc-hero-stat"><span class="snoc-hero-val">' . ($connected ? '<span class="snoc-dot-live"></span>' : '○') . '</span><span class="snoc-hero-lbl">' . ($connected ? 'Online' : 'Offline') . '</span></div>';
    echo '</div></div>';

    $rates = ($ratesResult['success'] && isset($ratesResult['rates'])) ? $ratesResult['rates'] : [];
    echo '<div class="snoc-stats">';
    $stats = [
        ['icon'=>'💰','label'=>'Balance','value'=>$connected?'৳'.number_format((float)($balanceResult['balance']??0),2):'N/A','hint'=>'Unified wallet','cls'=>'green'],
        ['icon'=>'📱','label'=>'SMS Rate','value'=>isset($rates['sms_masking'])?'৳'.$rates['sms_masking']:'—','hint'=>'Masking/SMS','cls'=>'teal'],
        ['icon'=>'💬','label'=>'WhatsApp','value'=>isset($rates['whatsapp'])?'৳'.$rates['whatsapp']:'—','hint'=>'Per message','cls'=>'emerald'],
        ['icon'=>'📞','label'=>'Voice','value'=>isset($rates['voice'])?'৳'.$rates['voice']:'—','hint'=>'Per pulse','cls'=>'cyan'],
        ['icon'=>'📧','label'=>'Email','value'=>isset($rates['email'])?'৳'.$rates['email']:'—','hint'=>'Per email','cls'=>'sky'],
    ];
    foreach ($stats as $s) {
        echo '<div class="snoc-stat snoc-stat-'.$s['cls'].'"><div class="snoc-stat-ic">'.$s['icon'].'</div><div class="snoc-stat-lb">'.$s['label'].'</div><div class="snoc-stat-vl">'.$s['value'].'</div><div class="snoc-stat-ht">'.$s['hint'].'</div></div>';
    }
    echo '</div>';

    echo '<div class="snoc-grid">';

    // Connection card
    echo '<div class="snoc-card"><div class="snoc-card-hd"><h3>🔌 Connection</h3></div><div class="snoc-card-bd">';
    if ($connected) {
        echo '<span class="snoc-badge snoc-badge-ok"><span class="snoc-dot-live"></span> Connected</span>';
    } else {
        echo '<span class="snoc-badge snoc-badge-err">' . htmlspecialchars($balanceResult['error'] ?? 'API Key not configured') . '</span>';
    }
    echo '<table class="snoc-tbl" style="margin-top:12px">';
    $ch = smsnoc_get_setting('notification_channel', 'sms');
    $fb = smsnoc_get_setting('fallback_channel', 'sms');
    echo '<tr><td>Primary Channel</td><td><strong>' . strtoupper($ch) . '</strong></td></tr>';
    echo '<tr><td>Fallback Channel</td><td><strong>' . strtoupper($fb) . '</strong></td></tr>';
    $sid = smsnoc_get_setting('default_sender');
    echo '<tr><td>Sender ID</td><td>' . ($sid ? '<code>' . htmlspecialchars($sid) . '</code>' : '<em>Not set</em>') . '</td></tr>';
    echo '<tr><td>Active Hooks</td><td><strong>' . $active_hooks . '</strong> / ' . $total_hooks . '</td></tr>';
    echo '</table></div></div>';

    // Rates card
    echo '<div class="snoc-card"><div class="snoc-card-hd"><h3>📊 Rates</h3></div><div class="snoc-card-bd">';
    if (!empty($rates)) {
        echo '<table class="snoc-tbl">';
        foreach ($rates as $k => $v) { echo '<tr><td>'.htmlspecialchars(ucwords(str_replace('_',' ',$k))).'</td><td><strong>৳'.number_format((float)$v,4).'</strong></td></tr>'; }
        echo '</table>';
    } else { echo '<p class="snoc-muted">Connect to view rates</p>'; }
    echo '</div></div>';

    // Quick actions
    echo '<div class="snoc-card"><div class="snoc-card-hd"><h3>🚀 Quick Actions</h3></div><div class="snoc-card-bd">';
    echo '<div class="snoc-actions">';
    echo '<a href="addonmodules.php?module=smsnoc&tab=send" class="snoc-action">📤 Send Message</a>';
    echo '<a href="addonmodules.php?module=smsnoc&tab=settings" class="snoc-action">⚙️ Settings</a>';
    echo '<a href="addonmodules.php?module=smsnoc&tab=hooks" class="snoc-action">🔔 Manage Hooks</a>';
    echo '<a href="addonmodules.php?module=smsnoc&tab=templates" class="snoc-action">📝 Templates</a>';
    echo '<a href="addonmodules.php?module=smsnoc&tab=devices" class="snoc-action">📱 WhatsApp Devices</a>';
    echo '<a href="addonmodules.php?module=smsnoc&tab=auth" class="snoc-action">🔐 Auth & OTP</a>';
    echo '<a href="addonmodules.php?module=smsnoc&tab=log" class="snoc-action">📋 Activity Log</a>';
    echo '</div></div></div>';

    // Recent log
    echo '<div class="snoc-card"><div class="snoc-card-hd"><h3>📋 Recent Activity</h3></div><div class="snoc-card-bd">';
    try {
        $logs = Capsule::table('mod_smsnoc_log')->orderBy('id', 'desc')->limit(5)->get();
        if ($logs->count()) {
            foreach ($logs as $l) {
                $sc = $l->status === 'sent' ? 'snoc-badge-ok' : 'snoc-badge-err';
                echo '<div class="snoc-log-row"><span class="snoc-log-ev">' . htmlspecialchars($l->event) . '</span><span class="snoc-badge ' . $sc . '">' . $l->status . '</span><span class="snoc-log-to">' . htmlspecialchars($l->recipient) . '</span><span class="snoc-log-tm">' . $l->created_at . '</span></div>';
            }
        } else {
            echo '<p class="snoc-muted">No activity yet.</p>';
        }
    } catch (\Exception $e) {
        echo '<p class="snoc-muted">Activity log table not ready.</p>';
    }
    echo '</div></div>';

    echo '</div>'; // grid
}

// ═══════════════════════════════════════════
//  Settings Tab
// ═══════════════════════════════════════════
function smsnoc_render_settings_tab($api) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['smsnoc_action'] ?? '') === 'save_settings') {
        $fields = ['api_key','default_sender','default_caller_id','default_device_id','default_email_config',
            'notification_channel','admin_phone','tts_language','tts_gender',
            'fallback_channel','fallback_sender','fallback_caller_id','fallback_device_id','fallback_email_config',
            'default_voice_file_url'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) smsnoc_set_setting($f, trim($_POST[$f]));
        }
        smsnoc_invalidate_cache();
        $newKey = smsnoc_get_setting('api_key');
        if ($newKey) $api = new SMSNOC_API($newKey);
        echo '<div class="snoc-alert snoc-alert-ok">✅ Settings saved successfully!</div>';
    }

    echo '<div class="snoc-card"><div class="snoc-card-hd"><h3>⚙️ API & Channel Settings</h3></div><div class="snoc-card-bd">';
    echo '<form method="post"><input type="hidden" name="smsnoc_action" value="save_settings" />';

    echo '<div class="snoc-alert snoc-alert-info">🔒 API Endpoint: <strong>https://smsnoc.com/api/v1</strong> (hardcoded)</div>';

    echo '<div class="snoc-form-row">';
    echo '<div class="snoc-fg"><label>🔑 API Key *</label><input type="password" name="api_key" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('api_key')) . '" placeholder="Get from smsnoc.com → Dashboard → API Keys" /><small>Your API key from <a href="https://smsnoc.com" target="_blank">smsnoc.com</a></small></div>';
    echo '</div>';

    echo '<div class="snoc-form-row">';
    echo '<div class="snoc-fg"><label>📱 SMS Sender ID</label><input type="text" name="default_sender" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('default_sender')) . '" placeholder="Text=Masking, Number=Non-Masking" /></div>';
    echo '<div class="snoc-fg"><label>📞 Voice Caller ID</label><input type="text" name="default_caller_id" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('default_caller_id')) . '" placeholder="DID Number" /></div>';
    echo '</div>';

    echo '<div class="snoc-form-row">';
    echo '<div class="snoc-fg"><label>💬 WhatsApp Device ID</label><input type="text" name="default_device_id" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('default_device_id')) . '" placeholder="UUID from dashboard" /></div>';
    echo '<div class="snoc-fg"><label>📧 Email Config ID</label><input type="text" name="default_email_config" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('default_email_config')) . '" placeholder="Optional" /></div>';
    echo '</div>';

    // Voice file URL
    echo '<div class="snoc-form-row">';
    echo '<div class="snoc-fg"><label>🎵 Default Voice File URL</label><input type="url" name="default_voice_file_url" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('default_voice_file_url')) . '" placeholder="https://example.com/audio.mp3 (optional)" /><small>If set, voice notifications will use this file first, then fallback to TTS</small></div>';
    echo '</div>';

    echo '<div class="snoc-form-row">';
    $ch = smsnoc_get_setting('notification_channel', 'sms');
    echo '<div class="snoc-fg"><label>📡 Primary Channel</label><select name="notification_channel" class="snoc-input">';
    foreach (['sms'=>'📱 SMS','whatsapp'=>'💬 WhatsApp','email'=>'📧 Email','voice'=>'📞 Voice (TTS)'] as $v => $l) {
        echo '<option value="'.$v.'"' . ($ch === $v ? ' selected' : '') . '>'.$l.'</option>';
    }
    echo '</select></div>';
    echo '<div class="snoc-fg"><label>📱 Admin Phone</label><input type="text" name="admin_phone" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('admin_phone')) . '" placeholder="For admin alerts" /></div>';
    echo '</div>';

    // Fallback Channel Section
    echo '<div style="margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.1)">';
    echo '<h4 style="color:var(--snoc-amber);margin:0 0 12px">🔄 Fallback Channel (if primary fails)</h4>';
    echo '<div class="snoc-alert snoc-alert-info" style="font-size:12px">If the primary channel fails (e.g., WhatsApp number not found), the system auto-retries via the fallback channel.</div>';

    echo '<div class="snoc-form-row">';
    $fb = smsnoc_get_setting('fallback_channel', 'sms');
    echo '<div class="snoc-fg"><label>🔄 Fallback Channel</label><select name="fallback_channel" class="snoc-input">';
    foreach (['sms'=>'📱 SMS','whatsapp'=>'💬 WhatsApp','email'=>'📧 Email','voice'=>'📞 Voice'] as $v => $l) {
        echo '<option value="'.$v.'"' . ($fb === $v ? ' selected' : '') . '>'.$l.'</option>';
    }
    echo '</select></div>';
    echo '<div class="snoc-fg"><label>📱 Fallback Sender ID</label><input type="text" name="fallback_sender" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('fallback_sender')) . '" placeholder="For SMS fallback" /></div>';
    echo '</div>';

    echo '<div class="snoc-form-row">';
    echo '<div class="snoc-fg"><label>📞 Fallback Caller ID</label><input type="text" name="fallback_caller_id" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('fallback_caller_id')) . '" /></div>';
    echo '<div class="snoc-fg"><label>💬 Fallback Device ID</label><input type="text" name="fallback_device_id" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('fallback_device_id')) . '" /></div>';
    echo '</div>';
    echo '</div>';

    // TTS Voice Settings
    echo '<div class="snoc-form-row" style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.1)">';
    $ttsLang = smsnoc_get_setting('tts_language', 'en');
    echo '<div class="snoc-fg"><label>🗣️ TTS Voice Language</label><select name="tts_language" class="snoc-input">';
    echo '<option value="en"' . ($ttsLang === 'en' ? ' selected' : '') . '>🇺🇸 English</option>';
    echo '<option value="bn"' . ($ttsLang === 'bn' ? ' selected' : '') . '>🇧🇩 বাংলা (Bangla)</option>';
    echo '<option value="hi"' . ($ttsLang === 'hi' ? ' selected' : '') . '>🇮🇳 हिन्दी (Hindi)</option>';
    echo '</select></div>';
    $ttsGender = smsnoc_get_setting('tts_gender', 'female');
    echo '<div class="snoc-fg"><label>👤 TTS Voice Gender</label><select name="tts_gender" class="snoc-input">';
    echo '<option value="female"' . ($ttsGender === 'female' ? ' selected' : '') . '>👩 Female</option>';
    echo '<option value="male"' . ($ttsGender === 'male' ? ' selected' : '') . '>👨 Male</option>';
    echo '</select></div>';
    echo '</div>';

    echo '<button type="submit" class="snoc-btn snoc-btn-primary">💾 Save Settings</button>';
    echo '</form></div></div>';

    // Test connection
    echo '<div class="snoc-card" style="margin-top:16px"><div class="snoc-card-hd"><h3>🔌 Test Connection</h3></div><div class="snoc-card-bd">';
    if (!empty(smsnoc_get_setting('api_key'))) {
        $result = $api->get_balance();
        if ($result['success']) {
            echo '<span class="snoc-badge snoc-badge-ok"><span class="snoc-dot-live"></span> Connected — Balance: ৳' . number_format((float)($result['balance']??0), 2) . '</span>';
        } else {
            echo '<span class="snoc-badge snoc-badge-err">❌ ' . htmlspecialchars($result['error'] ?? 'Failed') . '</span>';
        }
    } else {
        echo '<span class="snoc-badge snoc-badge-warn">⚠️ Enter API Key above first</span>';
    }
    echo '</div></div>';
}

// ═══════════════════════════════════════════
//  Auth & OTP Tab (WHMCS Login/Register/Forgot Password)
// ═══════════════════════════════════════════
function smsnoc_render_auth_tab() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['smsnoc_action'] ?? '') === 'save_auth') {
        $fields = ['enable_otp_login','enable_otp_register','enable_otp_forgot_pass',
            'otp_hide_default_login','otp_hide_default_register','otp_hide_default_reset',
            'otp_email_optional_register','otp_password_optional_register',
            'otp_login_template','otp_register_template','otp_forgot_pass_template',
            'otp_max_per_phone_hour','otp_max_per_phone_day','otp_max_per_ip_hour',
            'otp_expiry_minutes','otp_resend_cooldown','otp_max_attempts'];
        foreach ($fields as $f) {
            if (in_array($f, ['enable_otp_login','enable_otp_register','enable_otp_forgot_pass',
                'otp_hide_default_login','otp_hide_default_register','otp_hide_default_reset',
                'otp_email_optional_register','otp_password_optional_register'])) {
                smsnoc_set_setting($f, isset($_POST[$f]) ? '1' : '0');
            } else {
                if (isset($_POST[$f])) smsnoc_set_setting($f, trim($_POST[$f]));
            }
        }
        smsnoc_invalidate_cache();
        echo '<div class="snoc-alert snoc-alert-ok">✅ Auth & OTP settings saved!</div>';
    }

    echo '<div class="snoc-card"><div class="snoc-card-hd"><h3>🔐 Client Authentication & OTP</h3></div><div class="snoc-card-bd">';
    echo '<div class="snoc-alert snoc-alert-info">These features hook into WHMCS client area for Login, Registration and Password Reset. OTP codes are sent via your configured default channel and verified through the SMS NOC API.</div>';

    echo '<form method="post"><input type="hidden" name="smsnoc_action" value="save_auth" />';

    $features = [
        ['key'=>'enable_otp_login', 'icon'=>'🔑', 'label'=>'Phone OTP Login', 'desc'=>'Allow clients to login using phone + OTP instead of email/password. Hooks into WHMCS client login.'],
        ['key'=>'enable_otp_register', 'icon'=>'📱', 'label'=>'Registration Phone Verification', 'desc'=>'Require phone OTP verification during WHMCS client registration.'],
        ['key'=>'enable_otp_forgot_pass', 'icon'=>'🔒', 'label'=>'Phone Password Reset', 'desc'=>'Allow clients to reset password via phone OTP (alternative to email reset).'],
    ];

    echo '<div class="snoc-hooks-grid">';
    foreach ($features as $feat) {
        $enabled = smsnoc_get_setting($feat['key'], '0') === '1';
        echo '<div class="snoc-hook-item' . ($enabled ? ' snoc-hook-on' : '') . '">';
        echo '<div class="snoc-hook-info"><span class="snoc-hook-ic">' . $feat['icon'] . '</span><div><div class="snoc-hook-nm">' . $feat['label'] . '</div><div class="snoc-hook-ds">' . $feat['desc'] . '</div></div></div>';
        echo '<label class="snoc-switch"><input type="checkbox" name="' . $feat['key'] . '" value="1"' . ($enabled ? ' checked' : '') . ' /><span class="snoc-slider"></span></label>';
        echo '</div>';
    }
    echo '</div>';

    // Form Display Controls
    echo '<h4 style="margin-top:20px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.1);color:var(--snoc-amber)">🎛️ Form Display Controls</h4>';
    echo '<div class="snoc-alert snoc-alert-info" style="font-size:12px">Control what the default WHMCS login/register/reset forms show when OTP is enabled.</div>';
    $displayControls = [
        ['key'=>'otp_hide_default_login', 'icon'=>'🚫', 'label'=>'Hide Default Login Form', 'desc'=>'Hide username/password form. Clients only see phone OTP.'],
        ['key'=>'otp_hide_default_register', 'icon'=>'🚫', 'label'=>'Hide Default Register Form', 'desc'=>'Hide default registration fields when OTP Registration is enabled.'],
        ['key'=>'otp_hide_default_reset', 'icon'=>'🚫', 'label'=>'Hide Default Reset Form', 'desc'=>'Hide email-based password reset when Phone Reset is enabled.'],
        ['key'=>'otp_email_optional_register', 'icon'=>'📧', 'label'=>'Email Optional (Registration)', 'desc'=>'Clients can register with phone only.'],
        ['key'=>'otp_password_optional_register', 'icon'=>'🔐', 'label'=>'Password Optional (Registration)', 'desc'=>'Clients login with OTP only (auto-generated password).'],
    ];
    echo '<div class="snoc-hooks-grid">';
    foreach ($displayControls as $ctrl) {
        $enabled = smsnoc_get_setting($ctrl['key'], '0') === '1';
        echo '<div class="snoc-hook-item' . ($enabled ? ' snoc-hook-on' : '') . '">';
        echo '<div class="snoc-hook-info"><span class="snoc-hook-ic">' . $ctrl['icon'] . '</span><div><div class="snoc-hook-nm">' . $ctrl['label'] . '</div><div class="snoc-hook-ds">' . $ctrl['desc'] . '</div></div></div>';
        echo '<label class="snoc-switch"><input type="checkbox" name="' . $ctrl['key'] . '" value="1"' . ($enabled ? ' checked' : '') . ' /><span class="snoc-slider"></span></label>';
        echo '</div>';
    }
    echo '</div>';

    // OTP Templates
    echo '<h4 style="margin-top:20px;color:var(--snoc-teal)">📝 OTP Message Templates</h4>';
    echo '<div class="snoc-alert snoc-alert-info" style="font-size:12px">Use <code>{otp_code}</code> for the OTP, <code>{client_name}</code>, <code>{company_name}</code>, <code>{phone}</code> as variables.</div>';

    $tpls = [
        ['key'=>'otp_login_template', 'label'=>'Login OTP', 'icon'=>'🔑'],
        ['key'=>'otp_register_template', 'label'=>'Registration OTP', 'icon'=>'📱'],
        ['key'=>'otp_forgot_pass_template', 'label'=>'Password Reset OTP', 'icon'=>'🔒'],
    ];
    foreach ($tpls as $tpl) {
        $val = smsnoc_get_setting($tpl['key'], '');
        echo '<div class="snoc-tpl-card"><div class="snoc-tpl-hd"><h4>' . $tpl['icon'] . ' ' . $tpl['label'] . '</h4></div>';
        echo '<textarea name="' . $tpl['key'] . '" class="snoc-textarea" rows="2">' . htmlspecialchars($val) . '</textarea>';
        echo '<div class="snoc-vars"><span class="snoc-var">{otp_code}</span><span class="snoc-var">{client_name}</span><span class="snoc-var">{company_name}</span><span class="snoc-var">{phone}</span></div></div>';
    }

    $embedUrl = smsnoc_get_setting('api_key') ? rtrim(Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value') ?: '', '/') . '/modules/addons/smsnoc/otp_handler.php' : '{YOUR_WHMCS_URL}/modules/addons/smsnoc/otp_handler.php';
    echo '<div class="snoc-alert snoc-alert-info" style="margin-top:14px;font-size:12px">'
        . '<strong>📋 Manual Embed (for custom .tpl pages):</strong><br />'
        . 'OTP forms are <strong>auto-injected</strong> into Login, Register, and Password Reset pages via hooks. '
        . 'For manual placement in any custom .tpl page, add one of these mount blocks where you want the form to render:<br />'
        . '<code>&lt;div id="smsnoc-login-otp-mount"&gt;&lt;/div&gt;</code><br />'
        . '<code>&lt;div id="smsnoc-register-otp-mount"&gt;&lt;/div&gt;</code><br />'
        . '<code>&lt;div id="smsnoc-forgot-otp-mount"&gt;&lt;/div&gt;</code><br /><br />'
        . 'Optional Smarty flags for conditional rendering:<br />'
        . '<code>{if $smsnocOtpLoginEnabled}</code>, <code>{if $smsnocOtpRegisterEnabled}</code>, <code>{if $smsnocOtpForgotPassEnabled}</code><br /><br />'
        . '<strong>OTP API Endpoint:</strong> <code>' . htmlspecialchars($embedUrl) . '</code><br />'
        . 'Actions: <code>send_otp</code>, <code>verify_otp</code>, <code>otp_login</code>, <code>reset_password</code><br />'
        . 'All rate-limit settings above apply to this endpoint.'
        . '</div>';

    echo '<button type="submit" class="snoc-btn snoc-btn-primary" style="margin-top:12px">💾 Save Auth Settings</button>';
    echo '</form></div></div>';

    // Rate Limiting Controls
    echo '<div class="snoc-card" style="margin-top:16px"><div class="snoc-card-hd"><h3>⚡ OTP Rate Limiting & Security</h3></div><div class="snoc-card-bd">';
    echo '<div class="snoc-alert snoc-alert-info" style="font-size:12px">Configure OTP rate limits to prevent abuse. These limits apply to the OTP handler endpoint.</div>';
    echo '<form method="post"><input type="hidden" name="smsnoc_action" value="save_auth" />';

    $rlFields = [
        ['key'=>'otp_max_per_phone_hour', 'label'=>'Max OTP per Phone (Hourly)', 'icon'=>'📱', 'default'=>'3', 'desc'=>'Max OTPs a single phone can request per hour'],
        ['key'=>'otp_max_per_phone_day', 'label'=>'Max OTP per Phone (Daily)', 'icon'=>'📅', 'default'=>'10', 'desc'=>'Max OTPs a single phone can request per day'],
        ['key'=>'otp_max_per_ip_hour', 'label'=>'Max OTP per IP (Hourly)', 'icon'=>'🌐', 'default'=>'10', 'desc'=>'Max OTPs from a single IP per hour'],
        ['key'=>'otp_expiry_minutes', 'label'=>'OTP Expiry (Minutes)', 'icon'=>'⏱️', 'default'=>'5', 'desc'=>'How long OTP codes remain valid'],
        ['key'=>'otp_resend_cooldown', 'label'=>'Resend Cooldown (Seconds)', 'icon'=>'🔄', 'default'=>'60', 'desc'=>'Minimum seconds between resend attempts'],
        ['key'=>'otp_max_attempts', 'label'=>'Max Wrong Attempts', 'icon'=>'🔒', 'default'=>'5', 'desc'=>'Max wrong OTP entries before code is invalidated'],
    ];

    echo '<div class="snoc-form-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">';
    foreach ($rlFields as $rl) {
        $val = smsnoc_get_setting($rl['key'], $rl['default']);
        echo '<div class="snoc-fg"><label>' . $rl['icon'] . ' ' . $rl['label'] . '</label>';
        echo '<input type="number" name="' . $rl['key'] . '" class="snoc-input" value="' . htmlspecialchars($val) . '" min="1" />';
        echo '<small style="color:var(--snoc-muted)">' . $rl['desc'] . '</small></div>';
    }
    echo '</div>';
    echo '<button type="submit" class="snoc-btn snoc-btn-primary" style="margin-top:12px">💾 Save Rate Limits</button>';
    echo '</form></div></div>';

    // How it works
    echo '<div class="snoc-card" style="margin-top:16px"><div class="snoc-card-hd"><h3>📖 How It Works</h3></div><div class="snoc-card-bd">';
    echo '<table class="snoc-tbl">';
    echo '<tr><td>🔑 OTP Login</td><td>Client enters phone → receives OTP → enters code → logged in. Works alongside normal email/password.</td></tr>';
    echo '<tr><td>📱 Registration</td><td>During signup, client must verify phone with OTP before account is created.</td></tr>';
    echo '<tr><td>🔒 Forgot Password</td><td>Client enters phone → receives OTP → verifies → sets new password. Alternative to email reset.</td></tr>';
    echo '<tr><td>🔄 Fallback</td><td>OTP is sent via Primary Channel first. If it fails, Fallback Channel is used automatically.</td></tr>';
    echo '<tr><td>⚡ Rate Limits</td><td>Per-phone hourly/daily limits, per-IP hourly limits, and configurable expiry/cooldown prevent abuse.</td></tr>';
    echo '</table>';
    echo '</div></div>';
}

// ═══════════════════════════════════════════
//  Hooks & Events Tab
// ═══════════════════════════════════════════
function smsnoc_render_hooks_tab() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['smsnoc_action'] ?? '') === 'save_hooks') {
        $all_hooks = smsnoc_get_all_hook_keys();
        foreach ($all_hooks as $hk) {
            smsnoc_set_setting($hk, isset($_POST[$hk]) ? '1' : '0');
        }
        smsnoc_invalidate_cache();
        echo '<div class="snoc-alert snoc-alert-ok">✅ Hook settings saved!</div>';
    }

    $categories = [
        '💳 Billing & Invoices' => [
            'enable_invoice_created'     => ['📄', 'Invoice Created',            'Notify client of new invoice',              'customer'],
            'enable_invoice_paid'        => ['✅', 'Invoice Paid',              'Confirm payment to client',                  'customer'],
            'enable_invoice_reminder'    => ['⏰', 'Payment Reminder',          'WHMCS payment reminder hook',                'customer'],
            'enable_invoice_cancelled'   => ['🚫', 'Invoice Cancelled',         'Cancelled invoice notice',                   'customer'],
            'enable_invoice_overdue'     => ['⚠️', '1st Overdue (1 day)',       'First overdue alert (daily cron)',           'customer'],
            'enable_invoice_overdue_2nd' => ['⚠️', '2nd Overdue (3 days)',      'Second reminder after 3 days',              'customer'],
            'enable_invoice_overdue_3rd' => ['🚨', '3rd Overdue (7 days)',      'Final warning after 7 days',                'customer'],
            'enable_invoice_refunded'    => ['💸', 'Invoice Refunded',          'Refund processed notification',             'customer'],
            'enable_payment_confirmation'=> ['💰', 'Payment Confirmation',      'Transaction confirmed with TxID',           'customer'],
            'enable_credit_added'        => ['💳', 'Credit Added',              'Account credit notification',               'customer'],
            'enable_quote_created'       => ['📋', 'Quote Created',             'New quote notification',                    'customer'],
        ],
        '🎫 Support Tickets' => [
            'enable_ticket_opened'       => ['🎫', 'Ticket Opened',             'Confirmation to client',                    'customer'],
            'enable_ticket_opened_admin' => ['🔔', 'New Ticket (Admin)',        'Alert admin of new ticket',                 'admin'],
            'enable_ticket_reply'        => ['💬', 'Admin Reply',               'Notify client of admin reply',              'customer'],
            'enable_ticket_client_reply' => ['📩', 'Client Reply (Admin)',      'Alert admin when client replies',            'admin'],
            'enable_ticket_closed'       => ['🔒', 'Ticket Closed',             'Ticket closed notice to client',            'customer'],
        ],
        '🖥️ Services & Products' => [
            'enable_welcome'             => ['🎉', 'Service Activated',         'Welcome when service is provisioned',       'customer'],
            'enable_service_ready'       => ['✅', 'Service Ready',             'Module ready confirmation',                 'customer'],
            'enable_service_suspended'   => ['⚠️', 'Service Suspended',        'Suspension alert to client',                'customer'],
            'enable_service_unsuspended' => ['✅', 'Service Unsuspended',       'Reactivation confirmation',                 'customer'],
            'enable_service_terminated'  => ['🗑️', 'Service Terminated',       'Termination notice',                        'customer'],
            'enable_service_expiring_soon'=>['📅', 'Expiring Soon (7d)',        'Service expiry alert — 7 days before',      'customer'],
            'enable_service_expiring_1day'=>['🔴', 'Expiring Tomorrow',         'URGENT expiry alert — 1 day before',        'customer'],
            'enable_service_expired'     => ['💀', 'Service Expired',           'Service expired today notice',              'customer'],
            'enable_addon_activated'     => ['🧩', 'Addon Activated',           'Addon activation confirmation',             'customer'],
            'enable_product_upgrade'     => ['⬆️', 'Product Upgrade',          'Upgrade confirmation',                      'customer'],
            'enable_cancellation_request'=> ['🚫', 'Cancel Request (Admin)',    'Alert admin on cancel request',             'admin'],
            'enable_service_create_failed'=>['❌', 'Create Failed (Admin)',     'Alert admin when provisioning fails',       'admin'],
            'enable_service_suspend_failed'=>['❌','Suspend Failed (Admin)',    'Alert admin when suspend fails',            'admin'],
            'enable_service_unsuspend_failed'=>['❌','Unsuspend Failed (Admin)','Alert admin when unsuspend fails',          'admin'],
            'enable_service_terminate_failed'=>['❌','Terminate Failed (Admin)','Alert admin when terminate fails',          'admin'],
        ],
        '🌐 Domains' => [
            'enable_domain_registered'   => ['🆕', 'Domain Registered',         'Registration confirmation',                 'customer'],
            'enable_domain_renewed'      => ['🔄', 'Domain Renewed',            'Renewal confirmation',                      'customer'],
            'enable_domain_renewal'      => ['🌐', 'Renewal Reminder',          'WHMCS renewal reminder hook',               'customer'],
            'enable_domain_transfer'     => ['🔄', 'Domain Transfer',           'Transfer initiated notice',                 'customer'],
            'enable_domain_expiring_soon'=> ['📅', 'Expiring Soon (7d)',        'Domain expiry alert — 7 days before',       'customer'],
            'enable_domain_expiring_1day'=> ['🔴', 'Expiring Tomorrow',         'URGENT domain expiry — 1 day before',       'customer'],
            'enable_domain_expired'      => ['💀', 'Domain Expired',            'Domain expired today',                      'customer'],
            'enable_domain_register_failed'=>['❌','Registration Failed',      'Alert on domain registration failure',      'admin'],
            'enable_domain_renew_failed' => ['❌', 'Renewal Failed',           'Alert on domain renewal failure',           'admin'],
            'enable_domain_transfer_failed'=>['❌','Transfer Failed',          'Alert on domain transfer failure',          'customer'],
        ],
        '👤 Clients & Auth' => [
            'enable_client_signup'       => ['👤', 'Welcome (Customer)',         'Welcome message to new client',             'customer'],
            'enable_client_signup_admin' => ['🔔', 'New Client (Admin)',        'Alert admin of new registration',            'admin'],
            'enable_client_login'        => ['🔐', 'Login Alert (Admin)',       'Admin notified on client login',             'admin'],
            'enable_client_login_self'   => ['🛡️', 'Login Alert (Customer)',   'Client notified of own login',               'customer'],
            'enable_password_reset'      => ['🔑', 'Password Reset (Admin)',    'Admin notified on password change',          'admin'],
            'enable_affiliate_withdrawal'=> ['💵', 'Affiliate Payout',          'Payout processed to affiliate',             'customer'],
            'enable_client_edit_admin'   => ['✏️', 'Profile Edit (Admin)',      'Admin alert when client edits profile',      'admin'],
        ],
        '🛒 Orders' => [
            'enable_order_admin'         => ['🔔', 'New Order (Admin)',         'Admin gets new order alert',                 'admin'],
            'enable_order_confirmation'  => ['📦', 'Order Confirmed',           'Order confirmation to customer',             'customer'],
            'enable_quote_accepted'      => ['✅', 'Quote Accepted',            'Customer accepts a quote',                   'customer'],
            'enable_auto_renew_notice'   => ['🔄', 'Auto-Renew Paid',          'Service auto-renewal invoice paid',          'customer'],
        ],
        '🔧 System' => [
            'enable_module_password_change' => ['🔑', 'Module Password Changed', 'Service password changed via module',       'customer'],
        ],
    ];

    echo '<form method="post"><input type="hidden" name="smsnoc_action" value="save_hooks" />';

    $all_hooks = smsnoc_get_all_hook_keys();
    $active = 0; $adminCount = 0; $customerCount = 0;
    $hookTargets = [];
    foreach ($categories as $hooks) { foreach ($hooks as $k => $v) { $hookTargets[$k] = $v[3]; } }
    foreach ($all_hooks as $hk) {
        if (smsnoc_get_setting($hk, '0') === '1') {
            $active++;
            $t = $hookTargets[$hk] ?? 'customer';
            if ($t === 'admin') $adminCount++; else $customerCount++;
        }
    }
    $total = count($all_hooks);

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px">';
    echo '<div class="snoc-stat snoc-stat-teal"><div class="snoc-stat-vl" style="font-size:28px">' . $active . '/' . $total . '</div><div class="snoc-stat-lb">Active Hooks</div></div>';
    echo '<div class="snoc-stat snoc-stat-cyan"><div class="snoc-stat-vl" style="font-size:28px">' . $customerCount . '</div><div class="snoc-stat-lb">👤 Customer</div></div>';
    echo '<div class="snoc-stat snoc-stat-emerald"><div class="snoc-stat-vl" style="font-size:28px">' . $adminCount . '</div><div class="snoc-stat-lb">🛡️ Admin</div></div>';
    echo '</div>';

    echo '<div class="snoc-alert snoc-alert-info">Toggle hooks on/off. <span class="snoc-badge snoc-badge-customer" style="font-size:10px">👤 Customer</span> = sent to client\'s phone. <span class="snoc-badge snoc-badge-admin" style="font-size:10px">🛡️ Admin</span> = sent to admin phone.</div>';

    foreach ($categories as $cat => $hooks) {
        echo '<div class="snoc-card" style="margin-bottom:16px"><div class="snoc-card-hd"><h3>' . $cat . ' <span class="snoc-muted" style="font-weight:400;font-size:12px">(' . count($hooks) . ' hooks)</span></h3></div><div class="snoc-card-bd">';
        echo '<div class="snoc-hooks-grid">';
        foreach ($hooks as $key => $info) {
            $enabled = smsnoc_get_setting($key, '0') === '1';
            $target = $info[3] ?? 'customer';
            $targetBadge = $target === 'admin'
                ? '<span class="snoc-badge snoc-badge-admin">🛡️ Admin</span>'
                : '<span class="snoc-badge snoc-badge-customer">👤 Customer</span>';
            echo '<div class="snoc-hook-item' . ($enabled ? ' snoc-hook-on' : '') . '">';
            echo '<div class="snoc-hook-info"><span class="snoc-hook-ic">' . $info[0] . '</span><div><div class="snoc-hook-nm">' . $info[1] . ' ' . $targetBadge . '</div><div class="snoc-hook-ds">' . $info[2] . '</div></div></div>';
            echo '<label class="snoc-switch"><input type="checkbox" name="' . $key . '" value="1"' . ($enabled ? ' checked' : '') . ' /><span class="snoc-slider"></span></label>';
            echo '</div>';
        }
        echo '</div></div></div>';
    }

    echo '<button type="submit" class="snoc-btn snoc-btn-primary" style="margin-top:8px">💾 Save Hook Settings</button>';
    echo '</form>';
}

function smsnoc_get_all_hook_keys() {
    return [
        'enable_invoice_created','enable_invoice_paid','enable_invoice_reminder','enable_invoice_cancelled',
        'enable_invoice_overdue','enable_invoice_overdue_2nd','enable_invoice_overdue_3rd','enable_invoice_refunded',
        'enable_ticket_opened','enable_ticket_opened_admin','enable_ticket_reply','enable_ticket_client_reply','enable_ticket_closed',
        'enable_welcome','enable_service_suspended','enable_service_unsuspended','enable_service_terminated',
        'enable_service_ready','enable_addon_activated',
        'enable_service_expiring_soon','enable_service_expiring_1day','enable_service_expired',
        // Service failure hooks
        'enable_service_create_failed','enable_service_suspend_failed','enable_service_unsuspend_failed','enable_service_terminate_failed',
        'enable_order_admin','enable_order_confirmation','enable_payment_confirmation','enable_credit_added','enable_quote_created',
        'enable_domain_renewal','enable_domain_transfer','enable_domain_registered','enable_domain_renewed',
        'enable_domain_expiring_soon','enable_domain_expiring_1day','enable_domain_expired',
        // Domain failure hooks
        'enable_domain_register_failed','enable_domain_renew_failed','enable_domain_transfer_failed',
        'enable_client_signup','enable_client_signup_admin','enable_affiliate_withdrawal',
        'enable_client_login','enable_client_login_self','enable_password_reset',
        'enable_product_upgrade','enable_cancellation_request','enable_client_edit_admin',
        // v7.1 additional hooks
        'enable_quote_accepted','enable_module_password_change','enable_auto_renew_notice',
    ];
}

// ═══════════════════════════════════════════
//  Templates Tab
// ═══════════════════════════════════════════
function smsnoc_render_templates_tab() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['smsnoc_action'] ?? '') === 'save_templates') {
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'tpl_') === 0) {
                smsnoc_set_setting($k, trim($v));
            }
            // Save per-template voice file URLs
            if (strpos($k, 'voice_file_') === 0) {
                smsnoc_set_setting($k, trim($v));
            }
            // Save per-template channel override
            if (strpos($k, 'tpl_channel_') === 0) {
                smsnoc_set_setting($k, trim($v));
            }
        }
        smsnoc_invalidate_cache();
        echo '<div class="snoc-alert snoc-alert-ok">✅ Templates saved!</div>';
    }

    $templates = [
        ['key'=>'invoice_created','icon'=>'📄','label'=>'Invoice Created','target'=>'customer','vars'=>'{client_name} {invoice_id} {amount} {currency} {due_date} {pay_link}'],
        ['key'=>'invoice_paid','icon'=>'✅','label'=>'Invoice Paid','target'=>'customer','vars'=>'{client_name} {invoice_id} {amount} {currency}'],
        ['key'=>'invoice_reminder','icon'=>'⏰','label'=>'Payment Reminder','target'=>'customer','vars'=>'{client_name} {invoice_id} {amount} {currency} {due_date} {pay_link}'],
        ['key'=>'invoice_cancelled','icon'=>'🚫','label'=>'Invoice Cancelled','target'=>'customer','vars'=>'{client_name} {invoice_id} {amount} {currency}'],
        ['key'=>'invoice_overdue','icon'=>'⚠️','label'=>'1st Overdue (1 day)','target'=>'customer','vars'=>'{client_name} {invoice_id} {amount} {currency} {due_date} {days_overdue} {pay_link}'],
        ['key'=>'invoice_overdue_2nd','icon'=>'⚠️','label'=>'2nd Overdue (3 days)','target'=>'customer','vars'=>'{client_name} {invoice_id} {amount} {currency} {due_date} {days_overdue} {pay_link}'],
        ['key'=>'invoice_overdue_3rd','icon'=>'🚨','label'=>'3rd Overdue (7 days)','target'=>'customer','vars'=>'{client_name} {invoice_id} {amount} {currency} {due_date} {days_overdue} {pay_link}'],
        ['key'=>'invoice_refunded','icon'=>'💸','label'=>'Invoice Refunded','target'=>'customer','vars'=>'{client_name} {invoice_id} {amount} {currency}'],
        ['key'=>'payment_confirmation','icon'=>'💰','label'=>'Payment Confirmation','target'=>'customer','vars'=>'{client_name} {amount} {currency} {transaction_id} {invoice_id}'],
        ['key'=>'credit_added','icon'=>'💳','label'=>'Credit Added','target'=>'customer','vars'=>'{client_name} {amount} {currency}'],
        ['key'=>'quote_created','icon'=>'📋','label'=>'Quote Created','target'=>'customer','vars'=>'{client_name} {quote_id} {amount} {currency} {quote_link}'],
        ['key'=>'ticket_opened','icon'=>'🎫','label'=>'Ticket Opened','target'=>'customer','vars'=>'{client_name} {ticket_id} {subject} {department} {priority}'],
        ['key'=>'ticket_opened_admin','icon'=>'🔔','label'=>'New Ticket (Admin)','target'=>'admin','vars'=>'{client_name} {ticket_id} {subject} {department} {priority}'],
        ['key'=>'ticket_reply','icon'=>'💬','label'=>'Admin Reply','target'=>'customer','vars'=>'{client_name} {ticket_id} {subject}'],
        ['key'=>'ticket_client_reply','icon'=>'📩','label'=>'Client Reply (Admin)','target'=>'admin','vars'=>'{client_name} {ticket_id} {subject} {message_preview}'],
        ['key'=>'ticket_closed','icon'=>'🔒','label'=>'Ticket Closed','target'=>'customer','vars'=>'{client_name} {ticket_id} {subject}'],
        ['key'=>'welcome','icon'=>'🎉','label'=>'Service Activated','target'=>'customer','vars'=>'{client_name} {email} {product} {domain}'],
        ['key'=>'service_ready','icon'=>'✅','label'=>'Service Ready','target'=>'customer','vars'=>'{client_name} {product} {domain}'],
        ['key'=>'service_suspended','icon'=>'⚠️','label'=>'Suspended','target'=>'customer','vars'=>'{client_name} {product} {domain}'],
        ['key'=>'service_unsuspended','icon'=>'✅','label'=>'Unsuspended','target'=>'customer','vars'=>'{client_name} {product} {domain}'],
        ['key'=>'service_terminated','icon'=>'🗑️','label'=>'Terminated','target'=>'customer','vars'=>'{client_name} {product} {domain}'],
        ['key'=>'service_expiring_soon','icon'=>'📅','label'=>'Service Expiring (7d)','target'=>'customer','vars'=>'{client_name} {product} {domain} {expiry_date} {days_remaining}'],
        ['key'=>'service_expiring_1day','icon'=>'🔴','label'=>'Service Expiring (1d)','target'=>'customer','vars'=>'{client_name} {product} {domain} {expiry_date}'],
        ['key'=>'service_expired','icon'=>'💀','label'=>'Service Expired','target'=>'customer','vars'=>'{client_name} {product} {domain} {expiry_date}'],
        ['key'=>'addon_activated','icon'=>'🧩','label'=>'Addon Activated','target'=>'customer','vars'=>'{client_name} {addon_name}'],
        ['key'=>'product_upgrade','icon'=>'⬆️','label'=>'Product Upgrade','target'=>'customer','vars'=>'{client_name} {old_product} {new_product}'],
        ['key'=>'cancellation_request','icon'=>'🚫','label'=>'Cancel Request','target'=>'admin','vars'=>'{client_name} {product} {domain} {reason}'],
        // Service failure templates
        ['key'=>'service_create_failed','icon'=>'❌','label'=>'Create Failed (Admin)','target'=>'admin','vars'=>'{client_name} {product} {domain} {error}'],
        ['key'=>'service_suspend_failed','icon'=>'❌','label'=>'Suspend Failed (Admin)','target'=>'admin','vars'=>'{client_name} {product} {domain} {error}'],
        ['key'=>'service_unsuspend_failed','icon'=>'❌','label'=>'Unsuspend Failed (Admin)','target'=>'admin','vars'=>'{client_name} {product} {domain} {error}'],
        ['key'=>'service_terminate_failed','icon'=>'❌','label'=>'Terminate Failed (Admin)','target'=>'admin','vars'=>'{client_name} {product} {domain} {error}'],
        ['key'=>'domain_registered','icon'=>'🆕','label'=>'Domain Registered','target'=>'customer','vars'=>'{client_name} {domain}'],
        ['key'=>'domain_renewed','icon'=>'🔄','label'=>'Domain Renewed','target'=>'customer','vars'=>'{client_name} {domain}'],
        ['key'=>'domain_renewal','icon'=>'🌐','label'=>'Domain Renewal Reminder','target'=>'customer','vars'=>'{client_name} {domain} {expiry_date}'],
        ['key'=>'domain_transfer','icon'=>'🔄','label'=>'Domain Transfer','target'=>'customer','vars'=>'{client_name} {domain}'],
        ['key'=>'domain_expiring_soon','icon'=>'📅','label'=>'Domain Expiring (7d)','target'=>'customer','vars'=>'{client_name} {domain} {expiry_date} {days_remaining} {renew_link}'],
        ['key'=>'domain_expiring_1day','icon'=>'🔴','label'=>'Domain Expiring (1d)','target'=>'customer','vars'=>'{client_name} {domain} {expiry_date} {renew_link}'],
        ['key'=>'domain_expired','icon'=>'💀','label'=>'Domain Expired','target'=>'customer','vars'=>'{client_name} {domain} {expiry_date}'],
        // Domain failure templates
        ['key'=>'domain_register_failed','icon'=>'❌','label'=>'Registration Failed (Customer)','target'=>'customer','vars'=>'{client_name} {domain} {error}'],
        ['key'=>'domain_register_failed_admin','icon'=>'❌','label'=>'Registration Failed (Admin)','target'=>'admin','vars'=>'{client_name} {domain} {error}'],
        ['key'=>'domain_renew_failed','icon'=>'❌','label'=>'Renewal Failed (Customer)','target'=>'customer','vars'=>'{client_name} {domain} {error}'],
        ['key'=>'domain_renew_failed_admin','icon'=>'❌','label'=>'Renewal Failed (Admin)','target'=>'admin','vars'=>'{client_name} {domain} {error}'],
        ['key'=>'domain_transfer_failed','icon'=>'❌','label'=>'Transfer Failed','target'=>'customer','vars'=>'{client_name} {domain} {error}'],
        ['key'=>'client_signup','icon'=>'👤','label'=>'Welcome (Customer)','target'=>'customer','vars'=>'{client_name} {email} {company}'],
        ['key'=>'client_signup_admin','icon'=>'🔔','label'=>'New Client (Admin)','target'=>'admin','vars'=>'{client_name} {email} {company}'],
        ['key'=>'order_admin','icon'=>'🔔','label'=>'New Order (Admin)','target'=>'admin','vars'=>'{client_name} {order_id} {amount} {currency}'],
        ['key'=>'order_confirmation','icon'=>'📦','label'=>'Order Confirmed','target'=>'customer','vars'=>'{client_name} {order_id} {amount} {currency}'],
        ['key'=>'affiliate_withdrawal','icon'=>'💵','label'=>'Affiliate Payout','target'=>'customer','vars'=>'{client_name} {amount}'],
        ['key'=>'client_login','icon'=>'🔐','label'=>'Login Alert (Admin)','target'=>'admin','vars'=>'{client_name} {time} {ip}'],
        ['key'=>'client_login_self','icon'=>'🛡️','label'=>'Login Alert (Customer)','target'=>'customer','vars'=>'{client_name} {time} {ip}'],
        ['key'=>'password_reset','icon'=>'🔑','label'=>'Password Reset','target'=>'admin','vars'=>'{client_name} {email} {time}'],
        ['key'=>'client_edit_admin','icon'=>'✏️','label'=>'Profile Edit (Admin)','target'=>'admin','vars'=>'{client_name} {email}'],
    ];

    echo '<form method="post"><input type="hidden" name="smsnoc_action" value="save_templates" />';
    echo '<div class="snoc-alert snoc-alert-info">📝 Edit message templates. Each template can optionally have a <strong>Voice File URL</strong> — if set, voice channel will use this file instead of TTS. <span class="snoc-badge snoc-badge-customer" style="font-size:10px">👤 Customer</span> = sent to client. <span class="snoc-badge snoc-badge-admin" style="font-size:10px">🛡️ Admin</span> = sent to admin.</div>';

    $groups = [
        '💳 Billing' => ['invoice_created','invoice_paid','invoice_reminder','invoice_cancelled','invoice_overdue','invoice_overdue_2nd','invoice_overdue_3rd','invoice_refunded','payment_confirmation','credit_added','quote_created'],
        '🎫 Tickets' => ['ticket_opened','ticket_opened_admin','ticket_reply','ticket_client_reply','ticket_closed'],
        '🖥️ Services' => ['welcome','service_ready','service_suspended','service_unsuspended','service_terminated','service_expiring_soon','service_expiring_1day','service_expired','addon_activated','product_upgrade','cancellation_request','service_create_failed','service_suspend_failed','service_unsuspend_failed','service_terminate_failed'],
        '🌐 Domains' => ['domain_registered','domain_renewed','domain_renewal','domain_transfer','domain_expiring_soon','domain_expiring_1day','domain_expired','domain_register_failed','domain_register_failed_admin','domain_renew_failed','domain_renew_failed_admin','domain_transfer_failed'],
        '👤 Clients & Orders' => ['client_signup','client_signup_admin','order_admin','order_confirmation','affiliate_withdrawal','client_login','client_login_self','password_reset','client_edit_admin'],
    ];

    foreach ($groups as $groupName => $groupKeys) {
        echo '<div class="snoc-card" style="margin-bottom:16px"><div class="snoc-card-hd"><h3>' . $groupName . '</h3></div><div class="snoc-card-bd">';
        foreach ($templates as $tpl) {
            if (!in_array($tpl['key'], $groupKeys)) continue;
            $enabled = smsnoc_get_setting('enable_' . $tpl['key'], '0') === '1';
            $tval = smsnoc_get_setting('tpl_' . $tpl['key'], '');
            $voiceUrl = smsnoc_get_setting('voice_file_' . $tpl['key'], '');
            $tplChannel = smsnoc_get_setting('tpl_channel_' . $tpl['key'], '');
            $target = $tpl['target'] ?? 'customer';
            $targetBadge = $target === 'admin'
                ? '<span class="snoc-badge snoc-badge-admin" style="font-size:10px">🛡️ Admin</span>'
                : '<span class="snoc-badge snoc-badge-customer" style="font-size:10px">👤 Customer</span>';
            echo '<div class="snoc-tpl-card' . ($enabled ? ' snoc-tpl-on' : '') . '">';
            echo '<div class="snoc-tpl-hd"><h4>' . $tpl['icon'] . ' ' . $tpl['label'] . ' ' . $targetBadge . '</h4>';
            echo '<span class="snoc-badge ' . ($enabled ? 'snoc-badge-ok' : 'snoc-badge-off') . '">' . ($enabled ? '✅ Active' : '❌ Off') . '</span></div>';
            echo '<div style="display:flex;gap:10px;margin-bottom:6px">';
            echo '<div style="flex:1"><textarea name="tpl_' . $tpl['key'] . '" class="snoc-textarea" rows="2">' . htmlspecialchars($tval) . '</textarea></div>';
            echo '<div style="width:130px"><label style="font-size:11px;color:var(--snoc-muted)">Channel</label><select name="tpl_channel_' . $tpl['key'] . '" class="snoc-input" style="padding:6px 8px;font-size:12px">';
            echo '<option value=""' . ($tplChannel === '' ? ' selected' : '') . '>Default</option>';
            echo '<option value="sms"' . ($tplChannel === 'sms' ? ' selected' : '') . '>📱 SMS</option>';
            echo '<option value="voice"' . ($tplChannel === 'voice' ? ' selected' : '') . '>📞 Voice</option>';
            echo '<option value="whatsapp"' . ($tplChannel === 'whatsapp' ? ' selected' : '') . '>💬 WhatsApp</option>';
            echo '<option value="email"' . ($tplChannel === 'email' ? ' selected' : '') . '>📧 Email</option>';
            echo '</select></div></div>';
            echo '<div style="margin-top:4px"><input type="url" name="voice_file_' . $tpl['key'] . '" class="snoc-input" style="max-width:100%;font-size:11px" value="' . htmlspecialchars($voiceUrl) . '" placeholder="🎵 Voice File URL (optional — overrides TTS for this template)" /></div>';
            echo '<div class="snoc-vars">';
            foreach (explode(' ', $tpl['vars']) as $v) { echo '<span class="snoc-var" onclick="navigator.clipboard.writeText(\'' . htmlspecialchars($v) . '\');this.textContent=\'✓ Copied\';setTimeout(()=>{this.textContent=\'' . htmlspecialchars($v) . '\'},1000)">' . htmlspecialchars($v) . '</span>'; }
            echo '</div></div>';
        }
        echo '</div></div>';
    }

    echo '<button type="submit" class="snoc-btn snoc-btn-primary" style="margin-top:8px">💾 Save All Templates</button>';
    echo '</form>';
}

// ═══════════════════════════════════════════
//  Send Message Tab (with client search & multi-select)
// ═══════════════════════════════════════════
function smsnoc_render_send_tab($api) {
    echo '<div class="snoc-card"><div class="snoc-card-hd"><h3>📤 Send Message</h3></div><div class="snoc-card-bd">';
    echo '<form method="post" id="snoc-send-form"><input type="hidden" name="smsnoc_action" value="send_message" />';

    // Channel selector
    echo '<div class="snoc-fg"><label>Channel</label><div class="snoc-channels">';
    $channels = ['sms'=>['📱','SMS'],'voice'=>['📞','Voice'],'whatsapp'=>['💬','WhatsApp'],'email'=>['📧','Email']];
    foreach ($channels as $ch => $info) {
        $active = $ch === 'sms' ? ' snoc-ch-active' : '';
        echo '<div class="snoc-ch' . $active . '" data-ch="' . $ch . '" onclick="snocSelectCh(\'' . $ch . '\')">';
        echo '<span class="snoc-ch-ic">' . $info[0] . '</span><span class="snoc-ch-lb">' . $info[1] . '</span></div>';
    }
    echo '</div><input type="hidden" name="channel" id="snoc-ch" value="sms" /></div>';

    // Recipient mode tabs
    echo '<div class="snoc-fg"><label>Recipients</label>';
    echo '<div class="snoc-recipient-tabs">';
    echo '<button type="button" class="snoc-rtab active" data-mode="manual" onclick="snocRecipientMode(\'manual\')">📝 Manual Input</button>';
    echo '<button type="button" class="snoc-rtab" data-mode="clients" onclick="snocRecipientMode(\'clients\')">👥 WHMCS Clients</button>';
    echo '</div></div>';

    // Manual input
    echo '<div id="snoc-mode-manual"><div class="snoc-fg"><label>Phone/Email (one per line or comma-separated)</label><textarea name="manual_recipients" class="snoc-textarea" rows="3" placeholder="01712345678&#10;01812345678&#10;user@example.com"></textarea></div></div>';

    // Client search/filter/multi-select
    echo '<div id="snoc-mode-clients" style="display:none">';
    echo '<div class="snoc-form-row">';
    echo '<div class="snoc-fg"><label>🔍 Search</label><input type="text" id="snoc-client-search" class="snoc-input" placeholder="Name, email, phone, company..." oninput="snocSearchClients()" /></div>';
    echo '<div class="snoc-fg"><label>🏷️ Filter Status</label><select id="snoc-client-status" class="snoc-input" onchange="snocSearchClients()"><option value="">All</option><option value="Active">Active</option><option value="Inactive">Inactive</option><option value="Closed">Closed</option></select></div>';
    echo '</div>';
    echo '<div style="margin-bottom:8px;display:flex;gap:8px">';
    echo '<button type="button" class="snoc-btn snoc-btn-sm" onclick="snocSelectAllClients()">☑️ Select All</button>';
    echo '<button type="button" class="snoc-btn snoc-btn-sm" onclick="snocDeselectAllClients()">◻️ Deselect All</button>';
    echo '<span id="snoc-client-count" class="snoc-muted" style="line-height:32px"></span>';
    echo '</div>';
    echo '<div id="snoc-client-list" style="max-height:300px;overflow-y:auto;border:1px solid var(--snoc-border);border-radius:8px;padding:8px;background:var(--snoc-bg)">';
    // Render clients
    try {
        $clients = Capsule::table('tblclients')->select('id','firstname','lastname','email','phonenumber','companyname','status')->orderBy('id','desc')->limit(500)->get();
        foreach ($clients as $c) {
            $name = trim($c->firstname . ' ' . $c->lastname);
            $phone = $c->phonenumber ?: '';
            $statusCls = $c->status === 'Active' ? 'snoc-badge-ok' : 'snoc-badge-off';
            echo '<label class="snoc-client-row" data-search="' . htmlspecialchars(strtolower($name . ' ' . $c->email . ' ' . $phone . ' ' . $c->companyname)) . '" data-status="' . $c->status . '">';
            echo '<input type="checkbox" name="client_ids[]" value="' . $c->id . '" data-phone="' . htmlspecialchars($phone) . '" data-email="' . htmlspecialchars($c->email) . '" />';
            echo '<span class="snoc-client-info"><strong>' . htmlspecialchars($name) . '</strong>';
            if ($c->companyname) echo ' <span class="snoc-muted">(' . htmlspecialchars($c->companyname) . ')</span>';
            echo '<br/><span class="snoc-muted">' . htmlspecialchars($c->email) . ' · ' . htmlspecialchars($phone) . '</span></span>';
            echo '<span class="snoc-badge ' . $statusCls . '" style="font-size:10px">' . $c->status . '</span>';
            echo '</label>';
        }
    } catch (\Exception $e) {
        echo '<p class="snoc-muted">Could not load clients.</p>';
    }
    echo '</div></div>';

    // Channel-specific fields
    echo '<div class="snoc-ch-f snoc-ch-sms"><div class="snoc-fg"><label>Sender ID</label><input type="text" name="sender_id" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('default_sender')) . '" /></div></div>';
    echo '<div class="snoc-ch-f snoc-ch-voice" style="display:none"><div class="snoc-fg"><label>Audio URL <span style="font-weight:400;color:var(--snoc-muted);font-size:11px">(optional — leave empty for TTS)</span></label><input type="url" name="voice_url" class="snoc-input" placeholder="https://example.com/audio.wav — or leave empty to auto-convert text to voice" /><small style="color:var(--snoc-teal)">💡 If empty, the message text will be automatically converted to a voice file using Text-to-Speech (' . htmlspecialchars(smsnoc_get_setting('tts_language', 'en') === 'bn' ? 'বাংলা' : (smsnoc_get_setting('tts_language', 'en') === 'hi' ? 'हिन्दी' : 'English')) . ', ' . htmlspecialchars(smsnoc_get_setting('tts_gender', 'female')) . ')</small></div><div class="snoc-fg"><label>Caller ID</label><input type="text" name="caller_id" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('default_caller_id')) . '" /></div></div>';
    echo '<div class="snoc-ch-f snoc-ch-whatsapp" style="display:none"><div class="snoc-fg"><label>Device ID</label><input type="text" name="device_id" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('default_device_id')) . '" /></div></div>';
    echo '<div class="snoc-ch-f snoc-ch-email" style="display:none"><div class="snoc-fg"><label>Subject</label><input type="text" name="subject" class="snoc-input" /></div><div class="snoc-fg"><label>Config ID</label><input type="text" name="email_config" class="snoc-input" value="' . htmlspecialchars(smsnoc_get_setting('default_email_config')) . '" /></div></div>';
    echo '<div class="snoc-fg"><label>Message</label><textarea name="message" id="snoc-msg-textarea" class="snoc-textarea" rows="5" oninput="snocUpdateCounter()"></textarea>';
    echo '<div id="snoc-char-counter" style="text-align:right;font-size:12px;margin-top:6px;padding:6px 10px;background:var(--snoc-bg);border-radius:6px;border:1px solid var(--snoc-border);color:var(--snoc-muted)">📱 Type a message...</div></div>';
    echo '<button type="submit" class="snoc-btn snoc-btn-primary">📤 Send</button></form></div></div>';

    // JavaScript
    echo '<script>
    var snocCurrentCh = "sms";
    var snocRecipientModeVal = "manual";
    function snocSelectCh(c){
        snocCurrentCh = c;
        document.getElementById("snoc-ch").value=c;
        document.querySelectorAll(".snoc-ch").forEach(function(e){e.classList.remove("snoc-ch-active")});
        document.querySelector("[data-ch=\'"+c+"\']").classList.add("snoc-ch-active");
        document.querySelectorAll(".snoc-ch-f").forEach(function(e){e.style.display="none"});
        document.querySelectorAll(".snoc-ch-"+c).forEach(function(e){e.style.display="block"});
        snocUpdateCounter();
    }
    function snocRecipientMode(mode){
        snocRecipientModeVal = mode;
        document.querySelectorAll(".snoc-rtab").forEach(function(e){e.classList.remove("active")});
        document.querySelector("[data-mode=\'"+mode+"\']").classList.add("active");
        document.getElementById("snoc-mode-manual").style.display = mode === "manual" ? "block" : "none";
        document.getElementById("snoc-mode-clients").style.display = mode === "clients" ? "block" : "none";
    }
    function snocSearchClients(){
        var q = (document.getElementById("snoc-client-search").value || "").toLowerCase();
        var st = document.getElementById("snoc-client-status").value;
        var rows = document.querySelectorAll(".snoc-client-row");
        var shown = 0;
        rows.forEach(function(r){
            var match = true;
            if (q && r.getAttribute("data-search").indexOf(q) === -1) match = false;
            if (st && r.getAttribute("data-status") !== st) match = false;
            r.style.display = match ? "flex" : "none";
            if (match) shown++;
        });
        document.getElementById("snoc-client-count").textContent = shown + " clients shown";
    }
    function snocSelectAllClients(){
        document.querySelectorAll("#snoc-client-list .snoc-client-row").forEach(function(r){
            if(r.style.display !== "none") r.querySelector("input").checked = true;
        });
        snocUpdateClientCount();
    }
    function snocDeselectAllClients(){
        document.querySelectorAll("#snoc-client-list input").forEach(function(i){ i.checked = false; });
        snocUpdateClientCount();
    }
    function snocUpdateClientCount(){
        var c = document.querySelectorAll("#snoc-client-list input:checked").length;
        document.getElementById("snoc-client-count").textContent = c + " selected";
    }
    document.querySelectorAll("#snoc-client-list input").forEach(function(i){
        i.addEventListener("change", snocUpdateClientCount);
    });
    function snocSmsCount(text) {
        if (!text || text.length === 0) return {chars:0,parts:0,encoding:"GSM-7",maxPerPart:160,remaining:160};
        var gsm7 = "@£$¥èéùìòÇ\\nØø\\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !\\"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        var gsm7ext = "^{}\\\\[~]|€";
        var isGsm = true, charCount = 0;
        for (var i = 0; i < text.length; i++) {
            var c = text.charAt(i);
            if (gsm7.indexOf(c) !== -1) { charCount += 1; }
            else if (gsm7ext.indexOf(c) !== -1) { charCount += 2; }
            else { isGsm = false; break; }
        }
        if (!isGsm) { charCount = text.length; }
        var encoding = isGsm ? "GSM-7 (English)" : "Unicode (বাংলা)";
        var singleMax = isGsm ? 160 : 70;
        var multiMax = isGsm ? 153 : 67;
        var parts, remaining;
        if (charCount <= singleMax) { parts = charCount === 0 ? 0 : 1; remaining = singleMax - charCount; }
        else { parts = Math.ceil(charCount / multiMax); remaining = (parts * multiMax) - charCount; }
        return {chars:charCount, parts:parts, encoding:encoding, maxPerPart: parts <= 1 ? singleMax : multiMax, remaining:remaining};
    }
    function snocUpdateCounter() {
        var text = document.getElementById("snoc-msg-textarea") ? document.getElementById("snoc-msg-textarea").value : "";
        var el = document.getElementById("snoc-char-counter");
        if (!el) return;
        if (snocCurrentCh !== "sms") {
            if (snocCurrentCh === "voice") { el.innerHTML = "📞 Voice — audio file or TTS from text"; }
            else if (snocCurrentCh === "whatsapp") { el.innerHTML = "💬 WhatsApp — " + text.length + " chars"; }
            else if (snocCurrentCh === "email") { el.innerHTML = "📧 Email — " + text.length + " chars"; }
            return;
        }
        var r = snocSmsCount(text);
        if (r.chars === 0) { el.innerHTML = "📱 Type a message..."; return; }
        var color = r.parts > 3 ? "var(--snoc-red)" : r.parts > 1 ? "var(--snoc-amber)" : "var(--snoc-emerald)";
        el.innerHTML = "<span style=\\"color:"+color+";font-weight:700\\">" + r.chars + "</span> chars · "
            + "<span style=\\"color:"+color+";font-weight:700\\">" + r.parts + "</span> SMS · "
            + "<span style=\\"font-weight:600\\">" + r.encoding + "</span> · "
            + r.remaining + " remaining";
    }
    </script>';
}

// ═══════════════════════════════════════════
//  Devices Tab
// ═══════════════════════════════════════════
function smsnoc_render_devices_tab($api) {
    $result = $api->get_whatsapp_devices();
    echo '<div class="snoc-card"><div class="snoc-card-hd"><h3>📱 WhatsApp Devices</h3></div><div class="snoc-card-bd">';
    if ($result['success'] && !empty($result['devices'])) {
        echo '<table class="snoc-data-tbl"><thead><tr><th>Device ID</th><th>Name</th><th>Phone</th><th>Provider</th><th>Copy</th></tr></thead><tbody>';
        foreach ($result['devices'] as $d) {
            $did = htmlspecialchars($d['device_id'] ?? '');
            echo '<tr><td><code class="snoc-code">' . $did . '</code></td><td>' . htmlspecialchars($d['name']??'') . '</td><td><strong>' . htmlspecialchars($d['phone_number']??'') . '</strong></td><td><span class="snoc-badge snoc-badge-info">' . htmlspecialchars($d['provider']??'') . '</span></td><td><button class="snoc-btn snoc-btn-sm" onclick="navigator.clipboard.writeText(\'' . $did . '\');alert(\'Copied!\')">📋</button></td></tr>';
        }
        echo '</tbody></table>';
    } else { echo '<div class="snoc-alert snoc-alert-info">No devices found. Connect from <a href="https://smsnoc.com" target="_blank">smsnoc.com</a> Dashboard.</div>'; }
    echo '</div></div>';

    $did = smsnoc_get_setting('default_device_id');
    if (!empty($did)) {
        $st = $api->get_whatsapp_status($did);
        echo '<div class="snoc-card" style="margin-top:16px"><div class="snoc-card-hd"><h3>📡 Default Device Status</h3></div><div class="snoc-card-bd">';
        echo '<code class="snoc-code">' . htmlspecialchars($did) . '</code><br/>';
        echo ($st['success'] && ($st['connected']??false)) ? '<span class="snoc-badge snoc-badge-ok" style="margin-top:8px"><span class="snoc-dot-live"></span> Connected</span>' : '<span class="snoc-badge snoc-badge-err" style="margin-top:8px">Offline</span>';
        echo '</div></div>';
    }
}

// ═══════════════════════════════════════════
//  Activity Log Tab
// ═══════════════════════════════════════════
function smsnoc_render_log_tab($api) {
    $filterChannel = $_GET['log_channel'] ?? '';
    $filterStatus = $_GET['log_status'] ?? '';
    $filterSearch = $_GET['log_search'] ?? '';
    $filterLimit = (int)($_GET['log_limit'] ?? 50);
    if ($filterLimit < 10) $filterLimit = 50;

    echo '<div class="snoc-card" style="margin-bottom:16px"><div class="snoc-card-hd"><h3>🔍 Filters</h3></div><div class="snoc-card-bd">';
    echo '<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">';
    echo '<input type="hidden" name="module" value="smsnoc" /><input type="hidden" name="tab" value="log" />';
    echo '<div class="snoc-fg" style="margin-bottom:0;min-width:100px"><label>Channel</label><select name="log_channel" class="snoc-input" style="padding:8px"><option value="">All</option><option value="sms"' . ($filterChannel === 'sms' ? ' selected' : '') . '>📱 SMS</option><option value="voice"' . ($filterChannel === 'voice' ? ' selected' : '') . '>📞 Voice</option><option value="whatsapp"' . ($filterChannel === 'whatsapp' ? ' selected' : '') . '>💬 WhatsApp</option><option value="email"' . ($filterChannel === 'email' ? ' selected' : '') . '>📧 Email</option></select></div>';
    echo '<div class="snoc-fg" style="margin-bottom:0;min-width:100px"><label>Status</label><select name="log_status" class="snoc-input" style="padding:8px"><option value="">All</option><option value="sent"' . ($filterStatus === 'sent' ? ' selected' : '') . '>✅ Sent</option><option value="failed"' . ($filterStatus === 'failed' ? ' selected' : '') . '>❌ Failed</option></select></div>';
    echo '<div class="snoc-fg" style="margin-bottom:0;min-width:130px"><label>Search</label><input type="text" name="log_search" class="snoc-input" value="' . htmlspecialchars($filterSearch) . '" placeholder="Phone..." style="padding:8px" /></div>';
    echo '<div class="snoc-fg" style="margin-bottom:0"><label>Limit</label><select name="log_limit" class="snoc-input" style="padding:8px"><option value="25"' . ($filterLimit === 25 ? ' selected' : '') . '>25</option><option value="50"' . ($filterLimit === 50 ? ' selected' : '') . '>50</option><option value="100"' . ($filterLimit === 100 ? ' selected' : '') . '>100</option></select></div>';
    echo '<button type="submit" class="snoc-btn snoc-btn-primary snoc-btn-sm" style="height:36px">🔍 Filter</button>';
    echo '</form></div></div>';

    echo '<div class="snoc-card"><div class="snoc-card-hd"><h3>📋 Activity Log</h3></div><div class="snoc-card-bd">';
    try {
        $query = Capsule::table('mod_smsnoc_log');
        if ($filterChannel) $query->where('channel', $filterChannel);
        if ($filterStatus) $query->where('status', $filterStatus);
        if ($filterSearch) $query->where('recipient', 'like', '%' . $filterSearch . '%');
        $logs = $query->orderBy('id', 'desc')->limit($filterLimit)->get();
        if ($logs->count()) {
            echo '<div style="margin-bottom:10px;font-size:12px;color:var(--snoc-muted)">' . $logs->count() . ' records shown</div>';
            echo '<table class="snoc-data-tbl"><thead><tr><th>Event</th><th>Channel</th><th>Recipient</th><th>Message</th><th>Status</th><th>Time</th></tr></thead><tbody>';
            foreach ($logs as $l) {
                $sc = $l->status === 'sent' ? 'snoc-badge-ok' : 'snoc-badge-err';
                $chIcon = strtoupper($l->channel) === 'SMS' ? '📱' : (strtoupper($l->channel) === 'VOICE' ? '📞' : (strtoupper($l->channel) === 'WHATSAPP' ? '💬' : '📧'));
                $msgPreview = htmlspecialchars(mb_substr($l->message ?? '', 0, 40));
                echo '<tr><td><strong>' . htmlspecialchars($l->event) . '</strong></td><td><span class="snoc-badge snoc-badge-info">' . $chIcon . ' ' . strtoupper($l->channel) . '</span></td><td>' . htmlspecialchars($l->recipient) . '</td><td class="snoc-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . htmlspecialchars($l->message ?? '') . '">' . $msgPreview . '</td><td><span class="snoc-badge ' . $sc . '">' . $l->status . '</span></td><td class="snoc-muted" style="font-size:11px">' . $l->created_at . '</td></tr>';
            }
            echo '</tbody></table>';
        } else { echo '<p class="snoc-muted">No log entries match your filters.</p>'; }
    } catch (\Exception $e) {
        echo '<p class="snoc-muted">Activity log table not ready.</p>';
    }
    echo '</div></div>';
}

// ═══════════════════════════════════════════
//  POST Handler (enhanced with multi-send)
// ═══════════════════════════════════════════
function smsnoc_handle_post($api) {
    $action = $_POST['smsnoc_action'];

    if ($action === 'send_test') {
        // Legacy single send
        $ch = $_POST['channel'] ?? 'sms';
        $to = trim($_POST['recipient'] ?? '');
        $msg = trim($_POST['message'] ?? '');
        $result = smsnoc_send_via_channel($api, $ch, $to, $msg, $_POST);
        smsnoc_log_activity('manual_send', $ch, $to, $msg, $result['success'] ? 'sent' : 'failed', json_encode($result));
        echo $result['success'] ? '<div class="snoc-alert snoc-alert-ok">✅ Sent via ' . strtoupper($ch) . '</div>' : '<div class="snoc-alert snoc-alert-err">❌ ' . htmlspecialchars($result['error']??'') . '</div>';
    }

    if ($action === 'send_message') {
        $ch = $_POST['channel'] ?? 'sms';
        $msg = trim($_POST['message'] ?? '');
        $recipients = [];

        // Collect from manual input
        $manual = trim($_POST['manual_recipients'] ?? '');
        if (!empty($manual)) {
            $lines = preg_split('/[\n,;]+/', $manual);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) $recipients[] = $line;
            }
        }

        // Collect from selected clients
        $clientIds = $_POST['client_ids'] ?? [];
        if (!empty($clientIds)) {
            foreach ($clientIds as $cid) {
                try {
                    $client = Capsule::table('tblclients')->where('id', (int)$cid)->first();
                    if ($client) {
                        if ($ch === 'email' && !empty($client->email)) {
                            $recipients[] = $client->email;
                        } elseif (!empty($client->phonenumber)) {
                            $recipients[] = $client->phonenumber;
                        }
                    }
                } catch (\Exception $e) {}
            }
        }

        $recipients = array_unique(array_filter($recipients));

        if (empty($recipients)) {
            echo '<div class="snoc-alert snoc-alert-err">❌ No recipients found.</div>';
            return;
        }

        $sent = 0; $failed = 0;
        foreach ($recipients as $to) {
            $result = smsnoc_send_via_channel($api, $ch, $to, $msg, $_POST);
            $status = ($result && ($result['success'] ?? false)) ? 'sent' : 'failed';
            smsnoc_log_activity('bulk_send', $ch, $to, $msg, $status, json_encode($result ?? []));
            if ($status === 'sent') $sent++; else $failed++;
        }

        echo '<div class="snoc-alert snoc-alert-ok">📤 Sent: <strong>' . $sent . '</strong> ✅ | Failed: <strong>' . $failed . '</strong> ❌ | Total: ' . count($recipients) . '</div>';
    }
}

/**
 * Send via specific channel with voice file + TTS fallback
 */
function smsnoc_send_via_channel($api, $ch, $to, $msg, $params = []) {
    switch ($ch) {
        case 'sms':
            return $api->send_sms($to, $msg, $params['sender_id'] ?? smsnoc_get_setting('default_sender'));
        case 'voice':
            $voiceUrl = $params['voice_url'] ?? '';
            $caller_id = $params['caller_id'] ?? smsnoc_get_setting('default_caller_id');
            // 1. Try provided URL first
            if (!empty($voiceUrl)) {
                return $api->send_voice($to, $voiceUrl, $caller_id);
            }
            // 2. Try default voice file
            $defaultVoice = smsnoc_get_setting('default_voice_file_url');
            if (!empty($defaultVoice)) {
                $result = $api->send_voice($to, $defaultVoice, $caller_id);
                if (!empty($result['success'])) return $result;
            }
            // 3. TTS conversion — generate locally on this WHMCS domain
            if (!empty($msg)) {
                $ttsAudioUrl = SMSNOC_LocalMedia::createVoiceUrlFromText($msg, smsnoc_get_setting('tts_language', 'en'), smsnoc_get_setting('tts_gender', 'female'), 'wav', smsnoc_get_setting('api_key'));
                if (!empty($ttsAudioUrl)) {
                    $result = $api->send_voice($to, $ttsAudioUrl, $caller_id);
                    if (!empty($result['success'])) return $result;
                    return ['success' => false, 'error' => 'Local TTS audio generated (' . $ttsAudioUrl . ') but voice call failed: ' . ($result['error'] ?? 'unknown')];
                }
                return ['success' => false, 'error' => 'TTS conversion failed: could not generate a local audio file on this WHMCS domain.'];
            }
            // No message text either — error
            return ['success' => false, 'error' => 'Voice requires an audio URL or message text for TTS'];
        case 'whatsapp':
            return $api->send_whatsapp($to, $msg, $params['device_id'] ?? smsnoc_get_setting('default_device_id'));
        case 'email':
            return $api->send_email($to, $params['subject'] ?? 'Notification', nl2br($msg), $msg, $params['email_config'] ?? smsnoc_get_setting('default_email_config'));
        default:
            return $api->send_sms($to, $msg, smsnoc_get_setting('default_sender'));
    }
}

// ═══════════════════════════════════════════
//  CSS — SMS NOC Dark Theme v6.0
// ═══════════════════════════════════════════
function smsnoc_admin_css() {
    return '
    :root { --snoc-bg: #0a0f1a; --snoc-card: #111827; --snoc-border: #1e293b; --snoc-text: #e2e8f0; --snoc-muted: #64748b; --snoc-teal: #14b8a6; --snoc-teal-glow: rgba(20,184,166,0.15); --snoc-cyan: #06b6d4; --snoc-emerald: #10b981; --snoc-red: #ef4444; --snoc-amber: #f59e0b; --snoc-radius: 14px; }
    .snoc-wrap { max-width: 1280px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: var(--snoc-text); }
    .snoc-module-header { display: flex; align-items: center; margin-bottom: 20px; }
    .snoc-logo { display: flex; align-items: center; gap: 8px; }
    .snoc-logo-icon { font-size: 28px; }
    .snoc-logo-text { font-size: 22px; font-weight: 800; background: linear-gradient(135deg, var(--snoc-teal), var(--snoc-cyan)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .snoc-version { font-size: 11px; padding: 2px 8px; border-radius: 12px; background: var(--snoc-teal-glow); color: var(--snoc-teal); font-weight: 600; }

    .snoc-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 22px; padding: 12px; background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01)); border: 1px solid var(--snoc-border); border-radius: 18px; box-shadow: inset 0 1px 0 rgba(255,255,255,0.04), inset 0 1px 4px rgba(0,0,0,0.2); }
    .snoc-tab { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding: 10px 18px; border-radius: 12px; font-size: 13px; font-weight: 700; color: var(--snoc-muted); text-decoration: none; white-space: nowrap; transition: all 0.22s ease; background: rgba(255,255,255,0.02); border: 1px solid transparent; cursor: pointer; position: relative; box-shadow: inset 0 -1px 0 rgba(255,255,255,0.02); }
    .snoc-tab:hover { color: #fff; background: rgba(20,184,166,0.08); border-color: rgba(20,184,166,0.24); transform: translateY(-1px); box-shadow: 0 10px 24px rgba(0,0,0,0.18); }
    .snoc-tab.active { color: #fff; background: linear-gradient(135deg, var(--snoc-teal), var(--snoc-cyan)); border-color: rgba(20,184,166,0.55); box-shadow: 0 12px 28px rgba(20,184,166,0.34), inset 0 1px 0 rgba(255,255,255,0.14); font-weight: 800; transform: translateY(-1px); }
    .snoc-tab.active:hover { box-shadow: 0 14px 32px rgba(20,184,166,0.42), inset 0 1px 0 rgba(255,255,255,0.18); transform: translateY(-2px); }

    .snoc-hero { background: linear-gradient(135deg, #0d2137 0%, #0a1628 50%, #0f1d32 100%); border: 1px solid var(--snoc-border); border-radius: var(--snoc-radius); padding: 28px 32px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; position: relative; overflow: hidden; }
    .snoc-hero::before { content: ""; position: absolute; top: -60%; right: -15%; width: 350px; height: 350px; background: radial-gradient(circle, rgba(20,184,166,0.1) 0%, transparent 70%); border-radius: 50%; }
    .snoc-hero-left h2 { font-size: 22px; font-weight: 800; margin: 0 0 4px; color: #fff; }
    .snoc-hero-left p { font-size: 13px; color: var(--snoc-muted); margin: 0; }
    .snoc-hero-api { font-size: 11px; color: var(--snoc-teal); opacity: 0.7; }
    .snoc-hero-right { display: flex; gap: 28px; z-index: 1; }
    .snoc-hero-stat { text-align: center; }
    .snoc-hero-val { font-size: 26px; font-weight: 800; display: block; color: #fff; }
    .snoc-hero-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: var(--snoc-muted); }

    .snoc-dot-live { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--snoc-emerald); box-shadow: 0 0 8px rgba(16,185,129,0.6); animation: snocPulse 2s infinite; }
    @keyframes snocPulse { 0%,100%{box-shadow:0 0 8px rgba(16,185,129,0.4)} 50%{box-shadow:0 0 16px rgba(16,185,129,0.8)} }

    .snoc-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 20px; }
    @media (max-width: 900px) { .snoc-stats { grid-template-columns: repeat(3, 1fr); } }
    .snoc-stat { background: var(--snoc-card); border: 1px solid var(--snoc-border); border-radius: 12px; padding: 16px 18px; transition: transform 0.2s, border-color 0.2s; }
    .snoc-stat:hover { transform: translateY(-2px); border-color: rgba(20,184,166,0.3); }
    .snoc-stat-ic { font-size: 20px; margin-bottom: 10px; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
    .snoc-stat-green .snoc-stat-ic { background: rgba(16,185,129,0.15); }
    .snoc-stat-teal .snoc-stat-ic { background: var(--snoc-teal-glow); }
    .snoc-stat-emerald .snoc-stat-ic { background: rgba(16,185,129,0.15); }
    .snoc-stat-cyan .snoc-stat-ic { background: rgba(6,182,212,0.15); }
    .snoc-stat-sky .snoc-stat-ic { background: rgba(14,165,233,0.15); }
    .snoc-stat-lb { font-size: 11px; color: var(--snoc-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .snoc-stat-vl { font-size: 22px; font-weight: 800; color: #fff; margin: 2px 0; }
    .snoc-stat-ht { font-size: 11px; color: var(--snoc-muted); }

    .snoc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-bottom: 20px; }
    .snoc-card { background: var(--snoc-card); border: 1px solid var(--snoc-border); border-radius: var(--snoc-radius); overflow: hidden; margin-bottom: 16px; }
    .snoc-card-hd { padding: 16px 20px 12px; border-bottom: 1px solid var(--snoc-border); }
    .snoc-card-hd h3 { font-size: 15px; font-weight: 700; margin: 0; color: #fff; }
    .snoc-card-bd { padding: 18px 20px; }

    .snoc-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    .snoc-badge-ok { background: rgba(16,185,129,0.15); color: var(--snoc-emerald); }
    .snoc-badge-err { background: rgba(239,68,68,0.15); color: var(--snoc-red); }
    .snoc-badge-warn { background: rgba(245,158,11,0.15); color: var(--snoc-amber); }
    .snoc-badge-info { background: rgba(6,182,212,0.15); color: var(--snoc-cyan); }
    .snoc-badge-off { background: rgba(100,116,139,0.15); color: var(--snoc-muted); }
    .snoc-badge-customer { background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 10px; padding: 2px 8px; }
    .snoc-badge-admin { background: rgba(168,85,247,0.15); color: #c084fc; font-size: 10px; padding: 2px 8px; }

    .snoc-tbl { width: 100%; border-collapse: separate; border-spacing: 0; }
    .snoc-tbl td { padding: 8px 0; border-bottom: 1px solid rgba(30,41,59,0.5); font-size: 13px; color: var(--snoc-text); }
    .snoc-tbl tr:last-child td { border-bottom: none; }
    .snoc-tbl td:first-child { color: var(--snoc-muted); width: 45%; }
    .snoc-tbl td:last-child { font-weight: 600; }

    .snoc-data-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
    .snoc-data-tbl thead th { text-align: left; padding: 10px 12px; background: rgba(20,184,166,0.08); color: var(--snoc-teal); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--snoc-border); }
    .snoc-data-tbl tbody td { padding: 10px 12px; border-bottom: 1px solid rgba(30,41,59,0.3); color: var(--snoc-text); }
    .snoc-data-tbl tbody tr:hover { background: rgba(20,184,166,0.04); }

    .snoc-muted { color: var(--snoc-muted); font-size: 13px; }
    .snoc-code { font-size: 11px; background: rgba(20,184,166,0.1); color: var(--snoc-teal); padding: 2px 8px; border-radius: 4px; font-family: monospace; }

    .snoc-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
    @media (max-width: 700px) { .snoc-actions { grid-template-columns: repeat(2, 1fr); } }
    .snoc-action { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border: 1px solid var(--snoc-border); border-radius: 10px; color: var(--snoc-text); text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.2s; }
    .snoc-action:hover { border-color: var(--snoc-teal); background: var(--snoc-teal-glow); color: var(--snoc-teal); }

    .snoc-log-row { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid rgba(30,41,59,0.3); font-size: 13px; }
    .snoc-log-row:last-child { border-bottom: none; }
    .snoc-log-ev { font-weight: 600; color: var(--snoc-text); }
    .snoc-log-to { color: var(--snoc-muted); flex: 1; }
    .snoc-log-tm { color: var(--snoc-muted); font-size: 11px; }

    .snoc-fg { margin-bottom: 16px; }
    .snoc-fg label { display: block; font-size: 13px; font-weight: 600; color: var(--snoc-text); margin-bottom: 5px; }
    .snoc-fg small { font-size: 11px; color: var(--snoc-muted); display: block; margin-top: 3px; }
    .snoc-input, .snoc-textarea { width: 100%; max-width: 600px; padding: 10px 14px; border: 1.5px solid var(--snoc-border); border-radius: 8px; font-size: 13px; color: var(--snoc-text); background: var(--snoc-bg); transition: border-color 0.2s; box-sizing: border-box; }
    .snoc-input:focus, .snoc-textarea:focus { outline: none; border-color: var(--snoc-teal); box-shadow: 0 0 0 3px var(--snoc-teal-glow); }
    .snoc-textarea { resize: vertical; min-height: 60px; max-width: 100%; }
    .snoc-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 700px) { .snoc-form-row { grid-template-columns: 1fr; } }

    .snoc-channels { display: flex; gap: 8px; flex-wrap: wrap; }
    .snoc-ch { min-width: 90px; padding: 12px; border: 2px solid var(--snoc-border); border-radius: 10px; text-align: center; cursor: pointer; transition: all 0.2s; background: var(--snoc-bg); }
    .snoc-ch:hover { border-color: rgba(20,184,166,0.4); }
    .snoc-ch-active { border-color: var(--snoc-teal) !important; background: var(--snoc-teal-glow); }
    .snoc-ch-ic { font-size: 22px; display: block; margin-bottom: 4px; }
    .snoc-ch-lb { font-size: 12px; font-weight: 600; color: var(--snoc-text); }

    .snoc-btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
    .snoc-btn-primary { background: linear-gradient(135deg, var(--snoc-teal), var(--snoc-cyan)); color: #fff; }
    .snoc-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(20,184,166,0.3); }
    .snoc-btn-sm { padding: 6px 14px; font-size: 12px; background: var(--snoc-bg); color: var(--snoc-text); border: 1px solid var(--snoc-border); border-radius: 6px; }
    .snoc-btn-sm:hover { border-color: var(--snoc-teal); color: var(--snoc-teal); }

    .snoc-alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 14px; }
    .snoc-alert-ok { background: rgba(16,185,129,0.12); color: var(--snoc-emerald); border: 1px solid rgba(16,185,129,0.2); }
    .snoc-alert-err { background: rgba(239,68,68,0.12); color: var(--snoc-red); border: 1px solid rgba(239,68,68,0.2); }
    .snoc-alert-info { background: rgba(6,182,212,0.08); color: var(--snoc-cyan); border: 1px solid rgba(6,182,212,0.15); }
    .snoc-alert-warn { background: rgba(245,158,11,0.1); color: var(--snoc-amber); border: 1px solid rgba(245,158,11,0.2); }

    .snoc-footer { text-align: center; padding: 16px; color: var(--snoc-muted); font-size: 12px; margin-top: 20px; }
    .snoc-footer a { color: var(--snoc-teal); text-decoration: none; }

    /* Hooks grid */
    .snoc-hooks-grid { display: grid; gap: 8px; }
    .snoc-hook-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border: 1px solid var(--snoc-border); border-radius: 10px; transition: border-color 0.2s; }
    .snoc-hook-item:hover { border-color: rgba(20,184,166,0.3); }
    .snoc-hook-on { border-color: rgba(20,184,166,0.25); background: rgba(20,184,166,0.04); }
    .snoc-hook-info { display: flex; align-items: center; gap: 12px; flex: 1; }
    .snoc-hook-ic { font-size: 20px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: rgba(20,184,166,0.1); border-radius: 8px; }
    .snoc-hook-nm { font-size: 13px; font-weight: 600; color: var(--snoc-text); }
    .snoc-hook-ds { font-size: 11px; color: var(--snoc-muted); margin-top: 2px; }

    /* Toggle switch */
    .snoc-switch { position: relative; width: 44px; height: 24px; display: inline-block; flex-shrink: 0; }
    .snoc-switch input { opacity: 0; width: 0; height: 0; }
    .snoc-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: var(--snoc-border); transition: 0.3s; border-radius: 24px; }
    .snoc-slider:before { content: ""; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: var(--snoc-muted); transition: 0.3s; border-radius: 50%; }
    .snoc-switch input:checked + .snoc-slider { background: var(--snoc-teal); }
    .snoc-switch input:checked + .snoc-slider:before { transform: translateX(20px); background: #fff; }

    /* Templates */
    .snoc-tpl-card { padding: 14px; border: 1px solid var(--snoc-border); border-radius: 10px; margin-bottom: 12px; }
    .snoc-tpl-on { border-color: rgba(20,184,166,0.25); background: rgba(20,184,166,0.03); }
    .snoc-tpl-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .snoc-tpl-hd h4 { font-size: 13px; font-weight: 600; color: var(--snoc-text); margin: 0; }
    .snoc-vars { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
    .snoc-var { font-size: 10px; padding: 2px 8px; border-radius: 4px; background: rgba(20,184,166,0.1); color: var(--snoc-teal); cursor: pointer; font-family: monospace; }
    .snoc-var:hover { background: var(--snoc-teal); color: #fff; }

    /* Recipient tabs */
    .snoc-recipient-tabs { display: flex; gap: 4px; margin-bottom: 12px; }
    .snoc-rtab { padding: 8px 16px; border: 1px solid var(--snoc-border); border-radius: 8px; font-size: 12px; font-weight: 600; color: var(--snoc-muted); background: var(--snoc-bg); cursor: pointer; transition: all 0.2s; }
    .snoc-rtab.active { background: var(--snoc-teal-glow); color: var(--snoc-teal); border-color: rgba(20,184,166,0.3); }
    .snoc-rtab:hover { border-color: rgba(20,184,166,0.4); }

    /* Client list */
    .snoc-client-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-bottom: 1px solid rgba(30,41,59,0.3); cursor: pointer; transition: background 0.15s; }
    .snoc-client-row:hover { background: rgba(20,184,166,0.05); }
    .snoc-client-row:last-child { border-bottom: none; }
    .snoc-client-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--snoc-teal); flex-shrink: 0; }
    .snoc-client-info { flex: 1; font-size: 13px; }
    ';
}
