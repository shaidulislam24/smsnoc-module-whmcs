<?php
/**
 * SMS NOC WHMCS Hooks v1.0.0
 * 55+ automated event hooks with per-event toggles
 * Admin/Customer targeting with TTS Voice support
 * Fallback channel support, voice file per-template
 * OTP Login, Registration, Forgot Password with client area forms
 * Failure hooks: Domain/Service registration/renewal failures
 * Additional hooks: Quote accepted, password change, module change password
 * Duplicate message prevention via lock mechanism
 * Hardcoded API: https://smsnoc.com/api/v1
 */

if (!defined("WHMCS")) die("This file cannot be accessed directly");

use WHMCS\Database\Capsule;

// ═══════════════════════════════════════════
//  Helpers
// ═══════════════════════════════════════════

function smsnoc_hook_get_setting($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        try {
            $rows = Capsule::table('mod_smsnoc_settings')->pluck('setting_value', 'setting_key');
            $cache = $rows->toArray();
        } catch (\Exception $e) {
            try {
                $rows = Capsule::table('tbladdonmodules')->where('module', 'smsnoc')->pluck('value', 'setting');
                $cache = $rows->toArray();
            } catch (\Exception $e2) { $cache = []; }
        }
    }
    return $cache[$key] ?? $default;
}

function smsnoc_hook_get_api() {
    require_once __DIR__ . '/lib/SMSNOC_API.php';
    require_once __DIR__ . '/lib/SMSNOC_LocalMedia.php';
    return new SMSNOC_API(smsnoc_hook_get_setting('api_key'));
}

function smsnoc_hook_get_client_phone($clientId) {
    try {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        return $client ? ($client->phonenumber ?: '') : '';
    } catch (\Exception $e) { return ''; }
}

function smsnoc_hook_get_client_name($clientId) {
    try {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        return $client ? trim($client->firstname . ' ' . $client->lastname) : '';
    } catch (\Exception $e) { return ''; }
}

function smsnoc_hook_get_client_email($clientId) {
    try {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        return $client ? ($client->email ?: '') : '';
    } catch (\Exception $e) { return ''; }
}

function smsnoc_hook_get_client_company($clientId) {
    try {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        return $client ? ($client->companyname ?: '') : '';
    } catch (\Exception $e) { return ''; }
}

function smsnoc_hook_get_client_email_by_phone($phone) {
    try {
        $client = Capsule::table('tblclients')->where('phonenumber', $phone)->first();
        return $client ? ($client->email ?: '') : '';
    } catch (\Exception $e) { return ''; }
}

function smsnoc_hook_parse_template($template, $vars = []) {
    foreach ($vars as $key => $value) {
        $template = str_replace('{' . $key . '}', $value, $template);
    }
    return $template;
}

function smsnoc_hook_is_enabled($setting) {
    return smsnoc_hook_get_setting($setting, '0') === '1';
}

function smsnoc_hook_get_template($key) {
    return smsnoc_hook_get_setting($key, '');
}

function smsnoc_hook_get_system_url() {
    try { return rtrim(Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value') ?: '', '/'); }
    catch (\Exception $e) { return ''; }
}

function smsnoc_hook_get_company_name() {
    try { return Capsule::table('tblconfiguration')->where('setting', 'CompanyName')->value('value') ?: 'Our Company'; }
    catch (\Exception $e) { return 'Our Company'; }
}

function smsnoc_hook_get_currency($clientId) {
    try {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if ($client && $client->currency) {
            $cur = Capsule::table('tblcurrencies')->where('id', $client->currency)->first();
            return $cur ? $cur->code : '';
        }
    } catch (\Exception $e) {}
    return '';
}

function smsnoc_hook_get_service_details($vars) {
    $serviceId = $vars['serviceid'] ?? $vars['params']['serviceid'] ?? 0;
    $service = null;
    try { $service = $serviceId ? Capsule::table('tblhosting')->where('id', $serviceId)->first() : null; } catch (\Exception $e) {}
    $clientId = $vars['userid'] ?? $vars['params']['clientsdetails']['userid'] ?? ($service ? $service->userid : 0);
    $product = '';
    if ($service && $service->packageid) {
        try {
            $pkg = Capsule::table('tblproducts')->where('id', $service->packageid)->first();
            if ($pkg) $product = $pkg->name;
        } catch (\Exception $e) {}
    }
    return [
        'service' => $service,
        'client_id' => $clientId,
        'product' => $product,
        'domain' => $service ? ($service->domain ?: '') : '',
        'next_due' => $service ? ($service->nextduedate ?? '') : '',
    ];
}

// ═══════════════════════════════════════════
//  Duplicate Prevention Lock
// ═══════════════════════════════════════════
function smsnoc_hook_dedup_lock($event, $recipient, $ttl = 30) {
    $lockKey = 'smsnoc_lock_' . md5($event . '_' . $recipient);
    try {
        $existing = Capsule::table('mod_smsnoc_settings')
            ->where('setting_key', $lockKey)->first();
        if ($existing) {
            $lockTime = strtotime($existing->setting_value ?? '');
            if ($lockTime && (time() - $lockTime) < $ttl) {
                return false;
            }
        }
        Capsule::table('mod_smsnoc_settings')->updateOrInsert(
            ['setting_key' => $lockKey],
            ['setting_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        );
        return true;
    } catch (\Exception $e) { return true; }
}

// ═══════════════════════════════════════════
//  Send via specific channel
// ═══════════════════════════════════════════
function smsnoc_hook_send_via_channel($api, $channel, $phone, $message, $event = '') {
    $result = null;

    switch ($channel) {
        case 'whatsapp':
            $result = $api->send_whatsapp($phone, $message, smsnoc_hook_get_setting('default_device_id'));
            break;
        case 'email':
            $email = smsnoc_hook_get_client_email_by_phone($phone);
            if (!empty($email)) {
                $result = $api->send_email($email, 'Notification', nl2br($message), $message, smsnoc_hook_get_setting('default_email_config'));
            } else {
                $result = $api->send_sms($phone, $message, smsnoc_hook_get_setting('default_sender'));
            }
            break;
        case 'voice':
            $caller_id = smsnoc_hook_get_setting('default_caller_id', '');
            $voiceUrl = '';
            if (!empty($event)) {
                $voiceUrl = smsnoc_hook_get_setting('voice_file_' . $event, '');
            }
            if (empty($voiceUrl)) {
                $voiceUrl = smsnoc_hook_get_setting('default_voice_file_url', '');
            }
            if (!empty($voiceUrl)) {
                $result = $api->send_voice($phone, $voiceUrl, $caller_id);
                if (!empty($result['success'])) break;
            }
            if (!empty($message)) {
                $tts_lang   = smsnoc_hook_get_setting('tts_language', 'en');
                $tts_gender = smsnoc_hook_get_setting('tts_gender', 'female');
                if (!empty($event)) {
                    $perGender = smsnoc_hook_get_setting('tts_gender_' . $event, '');
                    if (!empty($perGender)) $tts_gender = $perGender;
                }
                $ttsAudioUrl = SMSNOC_LocalMedia::createVoiceUrlFromText($message, $tts_lang, $tts_gender, 'wav', smsnoc_hook_get_setting('api_key'));
                if (!empty($ttsAudioUrl)) {
                    $result = $api->send_voice($phone, $ttsAudioUrl, $caller_id);
                    if (!empty($result['success'])) break;
                }
            }
            $result = $api->send_sms($phone, $message, smsnoc_hook_get_setting('default_sender'));
            break;
        case 'sms':
        default:
            $result = $api->send_sms($phone, $message, smsnoc_hook_get_setting('default_sender'));
            break;
    }

    return $result;
}

// ═══════════════════════════════════════════
//  Unified Send with Fallback + Dedup
// ═══════════════════════════════════════════
function smsnoc_hook_send_notification($phone, $message, $event = '') {
    if (empty($phone) || empty($message)) return;

    if (!smsnoc_hook_dedup_lock($event, $phone)) return;

    $api = smsnoc_hook_get_api();
    
    $perTemplateChannel = '';
    if (!empty($event)) {
        $perTemplateChannel = smsnoc_hook_get_setting('tpl_channel_' . $event, '');
    }
    $primaryChannel = !empty($perTemplateChannel) ? $perTemplateChannel : smsnoc_hook_get_setting('notification_channel', 'sms');
    $fallbackChannel = smsnoc_hook_get_setting('fallback_channel', 'sms');

    $result = smsnoc_hook_send_via_channel($api, $primaryChannel, $phone, $message, $event);
    $usedChannel = $primaryChannel;

    if ((!$result || empty($result['success'])) && $fallbackChannel !== $primaryChannel) {
        $result = smsnoc_hook_send_via_channel($api, $fallbackChannel, $phone, $message, $event);
        $usedChannel = $fallbackChannel . ' (fallback)';
    }

    try {
        Capsule::table('mod_smsnoc_log')->insert([
            'event' => $event ?: 'hook', 'channel' => $usedChannel, 'recipient' => $phone,
            'message' => substr($message, 0, 500),
            'status' => ($result && ($result['success'] ?? false)) ? 'sent' : 'failed',
            'response' => substr(json_encode($result ?? []), 0, 1000),
        ]);
    } catch (\Exception $e) {}

    return $result;
}


// ═══════════════════════════════════════════════════
//  █  INVOICE HOOKS
// ═══════════════════════════════════════════════════

add_hook('InvoiceCreated', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_invoice_created')) return;
    $invoice = Capsule::table('tblinvoices')->where('id', $vars['invoiceid'])->first();
    if (!$invoice) return;
    $phone = smsnoc_hook_get_client_phone($invoice->userid);
    if (empty($phone)) return;
    $sysUrl = smsnoc_hook_get_system_url();
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_invoice_created'), [
        'client_name' => smsnoc_hook_get_client_name($invoice->userid), 'invoice_id' => $invoice->id,
        'amount' => number_format($invoice->total, 2), 'currency' => smsnoc_hook_get_currency($invoice->userid),
        'due_date' => $invoice->duedate,
        'pay_link' => $sysUrl ? $sysUrl . '/viewinvoice.php?id=' . $invoice->id : '',
    ]), 'invoice_created');
});

add_hook('InvoicePaid', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_invoice_paid')) return;
    $invoice = Capsule::table('tblinvoices')->where('id', $vars['invoiceid'])->first();
    if (!$invoice) return;
    $phone = smsnoc_hook_get_client_phone($invoice->userid);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_invoice_paid'), [
        'client_name' => smsnoc_hook_get_client_name($invoice->userid), 'invoice_id' => $invoice->id,
        'amount' => number_format($invoice->total, 2), 'currency' => smsnoc_hook_get_currency($invoice->userid),
    ]), 'invoice_paid');
});

add_hook('InvoicePaymentReminder', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_invoice_reminder')) return;
    $invoice = Capsule::table('tblinvoices')->where('id', $vars['invoiceid'])->first();
    if (!$invoice) return;
    $phone = smsnoc_hook_get_client_phone($invoice->userid);
    if (empty($phone)) return;
    $tpl = smsnoc_hook_get_template('tpl_invoice_reminder');
    if (empty($tpl)) return;
    $sysUrl = smsnoc_hook_get_system_url();
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, [
        'client_name' => smsnoc_hook_get_client_name($invoice->userid), 'invoice_id' => $invoice->id,
        'amount' => number_format($invoice->total, 2), 'currency' => smsnoc_hook_get_currency($invoice->userid),
        'due_date' => $invoice->duedate,
        'pay_link' => $sysUrl ? $sysUrl . '/viewinvoice.php?id=' . $invoice->id : '',
    ]), 'invoice_reminder');
});

add_hook('InvoiceCancelled', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_invoice_cancelled')) return;
    $invoice = Capsule::table('tblinvoices')->where('id', $vars['invoiceid'])->first();
    if (!$invoice) return;
    $phone = smsnoc_hook_get_client_phone($invoice->userid);
    if (empty($phone)) return;
    $tpl = smsnoc_hook_get_template('tpl_invoice_cancelled');
    if (empty($tpl)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, [
        'client_name' => smsnoc_hook_get_client_name($invoice->userid), 'invoice_id' => $invoice->id,
        'amount' => number_format($invoice->total, 2), 'currency' => smsnoc_hook_get_currency($invoice->userid),
    ]), 'invoice_cancelled');
});

add_hook('InvoiceRefunded', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_invoice_refunded')) return;
    $invoice = Capsule::table('tblinvoices')->where('id', $vars['invoiceid'])->first();
    if (!$invoice) return;
    $phone = smsnoc_hook_get_client_phone($invoice->userid);
    if (empty($phone)) return;
    $tpl = smsnoc_hook_get_template('tpl_invoice_refunded');
    if (empty($tpl)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, [
        'client_name' => smsnoc_hook_get_client_name($invoice->userid), 'invoice_id' => $invoice->id,
        'amount' => number_format((float)($vars['amount'] ?? $invoice->total), 2),
        'currency' => smsnoc_hook_get_currency($invoice->userid),
    ]), 'invoice_refunded');
});


// ═══════════════════════════════════════════════════
//  █  DAILY CRON — Overdue Invoices, Expiry Alerts
// ═══════════════════════════════════════════════════

add_hook('DailyCronJob', 1, function ($vars) {

    if (smsnoc_hook_is_enabled('enable_invoice_overdue')) {
        try {
            $overdue = Capsule::table('tblinvoices')->where('status', 'Unpaid')
                ->where('duedate', '<', date('Y-m-d'))->where('duedate', '>=', date('Y-m-d', strtotime('-1 day')))->get();
            foreach ($overdue as $invoice) {
                $phone = smsnoc_hook_get_client_phone($invoice->userid);
                if (empty($phone)) continue;
                $tpl = smsnoc_hook_get_template('tpl_invoice_overdue');
                if (empty($tpl)) continue;
                $sysUrl = smsnoc_hook_get_system_url();
                smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, [
                    'client_name' => smsnoc_hook_get_client_name($invoice->userid), 'invoice_id' => $invoice->id,
                    'amount' => number_format($invoice->total, 2), 'currency' => smsnoc_hook_get_currency($invoice->userid),
                    'due_date' => $invoice->duedate, 'days_overdue' => '1',
                    'pay_link' => $sysUrl ? $sysUrl . '/viewinvoice.php?id=' . $invoice->id : '',
                ]), 'invoice_overdue');
            }
        } catch (\Exception $e) {}
    }

    if (smsnoc_hook_is_enabled('enable_invoice_overdue_2nd')) {
        try {
            $overdue2 = Capsule::table('tblinvoices')->where('status', 'Unpaid')->whereRaw('DATEDIFF(CURDATE(), duedate) = 3')->get();
            foreach ($overdue2 as $invoice) {
                $phone = smsnoc_hook_get_client_phone($invoice->userid);
                if (empty($phone)) continue;
                $tpl = smsnoc_hook_get_template('tpl_invoice_overdue_2nd');
                if (empty($tpl)) continue;
                $sysUrl = smsnoc_hook_get_system_url();
                smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, [
                    'client_name' => smsnoc_hook_get_client_name($invoice->userid), 'invoice_id' => $invoice->id,
                    'amount' => number_format($invoice->total, 2), 'currency' => smsnoc_hook_get_currency($invoice->userid),
                    'due_date' => $invoice->duedate, 'days_overdue' => '3',
                    'pay_link' => $sysUrl ? $sysUrl . '/viewinvoice.php?id=' . $invoice->id : '',
                ]), 'invoice_overdue_2nd');
            }
        } catch (\Exception $e) {}
    }

    if (smsnoc_hook_is_enabled('enable_invoice_overdue_3rd')) {
        try {
            $overdue3 = Capsule::table('tblinvoices')->where('status', 'Unpaid')->whereRaw('DATEDIFF(CURDATE(), duedate) = 7')->get();
            foreach ($overdue3 as $invoice) {
                $phone = smsnoc_hook_get_client_phone($invoice->userid);
                if (empty($phone)) continue;
                $tpl = smsnoc_hook_get_template('tpl_invoice_overdue_3rd');
                if (empty($tpl)) continue;
                $sysUrl = smsnoc_hook_get_system_url();
                smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, [
                    'client_name' => smsnoc_hook_get_client_name($invoice->userid), 'invoice_id' => $invoice->id,
                    'amount' => number_format($invoice->total, 2), 'currency' => smsnoc_hook_get_currency($invoice->userid),
                    'due_date' => $invoice->duedate, 'days_overdue' => '7',
                    'pay_link' => $sysUrl ? $sysUrl . '/viewinvoice.php?id=' . $invoice->id : '',
                ]), 'invoice_overdue_3rd');
            }
        } catch (\Exception $e) {}
    }

    // Domain & Service expiry alerts
    $expiryChecks = [
        ['table'=>'tbldomains','status_col'=>'status','date_col'=>'expirydate','days'=>7,'setting'=>'enable_domain_expiring_soon','tpl'=>'tpl_domain_expiring_soon','event'=>'domain_expiring_soon','type'=>'domain'],
        ['table'=>'tbldomains','status_col'=>'status','date_col'=>'expirydate','days'=>1,'setting'=>'enable_domain_expiring_1day','tpl'=>'tpl_domain_expiring_1day','event'=>'domain_expiring_1day','type'=>'domain'],
        ['table'=>'tbldomains','status_col'=>'status','date_col'=>'expirydate','days'=>0,'setting'=>'enable_domain_expired','tpl'=>'tpl_domain_expired','event'=>'domain_expired','type'=>'domain'],
        ['table'=>'tblhosting','status_col'=>'domainstatus','date_col'=>'nextduedate','days'=>7,'setting'=>'enable_service_expiring_soon','tpl'=>'tpl_service_expiring_soon','event'=>'service_expiring_soon','type'=>'service'],
        ['table'=>'tblhosting','status_col'=>'domainstatus','date_col'=>'nextduedate','days'=>1,'setting'=>'enable_service_expiring_1day','tpl'=>'tpl_service_expiring_1day','event'=>'service_expiring_1day','type'=>'service'],
        ['table'=>'tblhosting','status_col'=>'domainstatus','date_col'=>'nextduedate','days'=>0,'setting'=>'enable_service_expired','tpl'=>'tpl_service_expired','event'=>'service_expired','type'=>'service'],
    ];

    foreach ($expiryChecks as $check) {
        if (!smsnoc_hook_is_enabled($check['setting'])) continue;
        try {
            $dateExpr = $check['days'] > 0 ? "DATEDIFF({$check['date_col']}, CURDATE()) = {$check['days']}" : "{$check['date_col']} = CURDATE()";
            $items = Capsule::table($check['table'])->where($check['status_col'], 'Active')->whereRaw($dateExpr)->get();
            foreach ($items as $item) {
                $phone = smsnoc_hook_get_client_phone($item->userid);
                if (empty($phone)) continue;
                $tpl = smsnoc_hook_get_template($check['tpl']);
                if (empty($tpl)) continue;
                $sysUrl = smsnoc_hook_get_system_url();
                $vars = ['client_name' => smsnoc_hook_get_client_name($item->userid)];
                if ($check['type'] === 'domain') {
                    $vars['domain'] = $item->domain;
                    $vars['expiry_date'] = $item->{$check['date_col']};
                    $vars['days_remaining'] = (string)$check['days'];
                    $vars['renew_link'] = $sysUrl ? $sysUrl . '/clientarea.php?action=domaindetails&domainid=' . $item->id : '';
                } else {
                    $product = '';
                    try { $pkg = Capsule::table('tblproducts')->where('id', $item->packageid)->first(); if ($pkg) $product = $pkg->name; } catch (\Exception $e) {}
                    $vars['product'] = $product;
                    $vars['domain'] = $item->domain ?: '';
                    $vars['expiry_date'] = $item->{$check['date_col']};
                    $vars['days_remaining'] = (string)$check['days'];
                }
                smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, $vars), $check['event']);
            }
        } catch (\Exception $e) {}
    }
});


// ═══════════════════════════════════════════════════
//  █  TICKET HOOKS
// ═══════════════════════════════════════════════════

add_hook('TicketOpen', 1, function ($vars) {
    $clientId = $vars['userid'] ?? 0;
    if (smsnoc_hook_is_enabled('enable_ticket_opened') && $clientId) {
        $phone = smsnoc_hook_get_client_phone($clientId);
        if (!empty($phone)) {
            smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_ticket_opened'), [
                'client_name' => smsnoc_hook_get_client_name($clientId), 'ticket_id' => $vars['ticketid'] ?? '',
                'subject' => $vars['subject'] ?? '', 'department' => $vars['deptname'] ?? '', 'priority' => $vars['priority'] ?? '',
            ]), 'ticket_opened');
        }
    }
    if (smsnoc_hook_is_enabled('enable_ticket_opened_admin')) {
        $adminPhone = smsnoc_hook_get_setting('admin_phone');
        if (!empty($adminPhone)) {
            smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_ticket_opened_admin'), [
                'client_name' => smsnoc_hook_get_client_name($clientId), 'ticket_id' => $vars['ticketid'] ?? '',
                'subject' => $vars['subject'] ?? '', 'department' => $vars['deptname'] ?? '', 'priority' => $vars['priority'] ?? '',
            ]), 'ticket_opened_admin');
        }
    }
});

add_hook('TicketAdminReply', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_ticket_reply')) return;
    $ticket = Capsule::table('tbltickets')->where('id', $vars['ticketid'] ?? 0)->first();
    if (!$ticket || !$ticket->userid) return;
    $phone = smsnoc_hook_get_client_phone($ticket->userid);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_ticket_reply'), [
        'client_name' => smsnoc_hook_get_client_name($ticket->userid), 'ticket_id' => $vars['ticketid'], 'subject' => $ticket->title ?? '',
    ]), 'ticket_reply');
});

add_hook('TicketUserReply', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_ticket_client_reply')) return;
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (empty($adminPhone)) return;
    $ticket = Capsule::table('tbltickets')->where('id', $vars['ticketid'] ?? 0)->first();
    if (!$ticket) return;
    smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_ticket_client_reply'), [
        'client_name' => smsnoc_hook_get_client_name($ticket->userid ?? 0), 'ticket_id' => $vars['ticketid'],
        'subject' => $ticket->title ?? '', 'message_preview' => mb_substr($vars['message'] ?? '', 0, 80),
    ]), 'ticket_client_reply');
});

add_hook('TicketClose', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_ticket_closed')) return;
    $ticket = Capsule::table('tbltickets')->where('id', $vars['ticketid'] ?? 0)->first();
    if (!$ticket || !$ticket->userid) return;
    $phone = smsnoc_hook_get_client_phone($ticket->userid);
    if (empty($phone)) return;
    $tpl = smsnoc_hook_get_template('tpl_ticket_closed');
    if (empty($tpl)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, [
        'client_name' => smsnoc_hook_get_client_name($ticket->userid), 'ticket_id' => $vars['ticketid'],
    ]), 'ticket_closed');
});


// ═══════════════════════════════════════════════════
//  █  SERVICE HOOKS
// ═══════════════════════════════════════════════════

add_hook('AfterModuleCreate', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_welcome')) return;
    $sd = smsnoc_hook_get_service_details($vars);
    if (!$sd['client_id']) return;
    $phone = smsnoc_hook_get_client_phone($sd['client_id']);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_welcome'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'],
        'domain' => $sd['domain'], 'email' => smsnoc_hook_get_client_email($sd['client_id']),
    ]), 'welcome');
});

add_hook('AfterModuleSuspend', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_service_suspended')) return;
    $sd = smsnoc_hook_get_service_details($vars);
    if (!$sd['client_id']) return;
    $phone = smsnoc_hook_get_client_phone($sd['client_id']);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_service_suspended'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'], 'domain' => $sd['domain'],
    ]), 'service_suspended');
});

add_hook('AfterModuleUnsuspend', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_service_unsuspended')) return;
    $sd = smsnoc_hook_get_service_details($vars);
    if (!$sd['client_id']) return;
    $phone = smsnoc_hook_get_client_phone($sd['client_id']);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_service_unsuspended'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'], 'domain' => $sd['domain'],
    ]), 'service_unsuspended');
});

add_hook('AfterModuleTerminate', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_service_terminated')) return;
    $sd = smsnoc_hook_get_service_details($vars);
    if (!$sd['client_id']) return;
    $phone = smsnoc_hook_get_client_phone($sd['client_id']);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_service_terminated'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'], 'domain' => $sd['domain'],
    ]), 'service_terminated');
});

add_hook('AfterModuleReady', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_service_ready')) return;
    $sd = smsnoc_hook_get_service_details($vars);
    if (!$sd['client_id']) return;
    $phone = smsnoc_hook_get_client_phone($sd['client_id']);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_service_ready'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'], 'domain' => $sd['domain'],
    ]), 'service_ready');
});

add_hook('AddonActivated', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_addon_activated')) return;
    $clientId = $vars['userid'] ?? 0;
    if (!$clientId) return;
    $phone = smsnoc_hook_get_client_phone($clientId);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_addon_activated'), [
        'client_name' => smsnoc_hook_get_client_name($clientId), 'addon_name' => $vars['name'] ?? 'Addon',
    ]), 'addon_activated');
});

// Service failure hooks (admin alerts)
add_hook('AfterModuleCreate', 2, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_service_create_failed')) return;
    if (($vars['completed'] ?? true) !== false && empty($vars['error'])) return;
    $sd = smsnoc_hook_get_service_details($vars);
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (empty($adminPhone)) return;
    smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_service_create_failed'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'],
        'domain' => $sd['domain'], 'error' => $vars['error'] ?? 'Unknown error',
    ]), 'service_create_failed');
});

add_hook('AfterModuleSuspend', 2, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_service_suspend_failed')) return;
    if (($vars['completed'] ?? true) !== false && empty($vars['error'])) return;
    $sd = smsnoc_hook_get_service_details($vars);
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (empty($adminPhone)) return;
    smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_service_suspend_failed'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'],
        'domain' => $sd['domain'], 'error' => $vars['error'] ?? 'Unknown error',
    ]), 'service_suspend_failed');
});

add_hook('AfterModuleUnsuspend', 2, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_service_unsuspend_failed')) return;
    if (($vars['completed'] ?? true) !== false && empty($vars['error'])) return;
    $sd = smsnoc_hook_get_service_details($vars);
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (empty($adminPhone)) return;
    smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_service_unsuspend_failed'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'],
        'domain' => $sd['domain'], 'error' => $vars['error'] ?? 'Unknown error',
    ]), 'service_unsuspend_failed');
});

add_hook('AfterModuleTerminate', 2, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_service_terminate_failed')) return;
    if (($vars['completed'] ?? true) !== false && empty($vars['error'])) return;
    $sd = smsnoc_hook_get_service_details($vars);
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (empty($adminPhone)) return;
    smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_service_terminate_failed'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'],
        'domain' => $sd['domain'], 'error' => $vars['error'] ?? 'Unknown error',
    ]), 'service_terminate_failed');
});


// ═══════════════════════════════════════════════════
//  █  DOMAIN HOOKS
// ═══════════════════════════════════════════════════

add_hook('AfterRegistrarRegistration', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_domain_registered')) return;
    $clientId = $vars['userid'] ?? 0;
    if (!$clientId) return;
    $phone = smsnoc_hook_get_client_phone($clientId);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_domain_registered'), [
        'client_name' => smsnoc_hook_get_client_name($clientId), 'domain' => $vars['domain'] ?? '',
    ]), 'domain_registered');
});

add_hook('AfterRegistrarRenewal', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_domain_renewed')) return;
    $clientId = $vars['userid'] ?? 0;
    if (!$clientId) return;
    $phone = smsnoc_hook_get_client_phone($clientId);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_domain_renewed'), [
        'client_name' => smsnoc_hook_get_client_name($clientId), 'domain' => $vars['domain'] ?? '',
    ]), 'domain_renewed');
});

add_hook('AfterRegistrarTransfer', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_domain_transfer')) return;
    $clientId = $vars['userid'] ?? 0;
    if (!$clientId) return;
    $phone = smsnoc_hook_get_client_phone($clientId);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_domain_transfer'), [
        'client_name' => smsnoc_hook_get_client_name($clientId), 'domain' => $vars['domain'] ?? '',
    ]), 'domain_transfer');
});

// Domain failure hooks
add_hook('AfterRegistrarRegistrationFailed', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_domain_register_failed')) return;
    $clientId = $vars['userid'] ?? 0;
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (!empty($adminPhone)) {
        smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_domain_register_failed_admin'), [
            'client_name' => smsnoc_hook_get_client_name($clientId), 'domain' => $vars['domain'] ?? '', 'error' => $vars['error'] ?? 'Unknown',
        ]), 'domain_register_failed');
    }
    if ($clientId) {
        $phone = smsnoc_hook_get_client_phone($clientId);
        if (!empty($phone)) {
            smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_domain_register_failed'), [
                'client_name' => smsnoc_hook_get_client_name($clientId), 'domain' => $vars['domain'] ?? '',
            ]), 'domain_register_failed_client');
        }
    }
});

add_hook('AfterRegistrarRenewalFailed', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_domain_renew_failed')) return;
    $clientId = $vars['userid'] ?? 0;
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (!empty($adminPhone)) {
        smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_domain_renew_failed_admin'), [
            'client_name' => smsnoc_hook_get_client_name($clientId), 'domain' => $vars['domain'] ?? '', 'error' => $vars['error'] ?? 'Unknown',
        ]), 'domain_renew_failed');
    }
});

add_hook('AfterRegistrarTransferFailed', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_domain_transfer_failed')) return;
    $clientId = $vars['userid'] ?? 0;
    if (!$clientId) return;
    $phone = smsnoc_hook_get_client_phone($clientId);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_domain_transfer_failed'), [
        'client_name' => smsnoc_hook_get_client_name($clientId), 'domain' => $vars['domain'] ?? '', 'error' => $vars['error'] ?? 'Unknown',
    ]), 'domain_transfer_failed');
});


// ═══════════════════════════════════════════════════
//  █  CLIENT & AUTH HOOKS
// ═══════════════════════════════════════════════════

add_hook('ClientAdd', 1, function ($vars) {
    $clientId = $vars['userid'] ?? $vars['client_id'] ?? 0;
    if (smsnoc_hook_is_enabled('enable_client_signup') && $clientId) {
        $phone = smsnoc_hook_get_client_phone($clientId);
        if (!empty($phone)) {
            smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_client_signup'), [
                'client_name' => smsnoc_hook_get_client_name($clientId), 'email' => smsnoc_hook_get_client_email($clientId),
                'company' => smsnoc_hook_get_client_company($clientId),
            ]), 'client_signup');
        }
    }
    if (smsnoc_hook_is_enabled('enable_client_signup_admin')) {
        $adminPhone = smsnoc_hook_get_setting('admin_phone');
        if (!empty($adminPhone)) {
            smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_client_signup_admin'), [
                'client_name' => smsnoc_hook_get_client_name($clientId), 'email' => smsnoc_hook_get_client_email($clientId),
                'company' => smsnoc_hook_get_client_company($clientId),
            ]), 'client_signup_admin');
        }
    }
});

add_hook('ClientLogin', 1, function ($vars) {
    $clientId = $vars['userid'] ?? 0;
    if (smsnoc_hook_is_enabled('enable_client_login')) {
        $adminPhone = smsnoc_hook_get_setting('admin_phone');
        if (!empty($adminPhone)) {
            smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_client_login'), [
                'client_name' => smsnoc_hook_get_client_name($clientId), 'time' => date('Y-m-d H:i'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]), 'client_login');
        }
    }
    if (smsnoc_hook_is_enabled('enable_client_login_self') && $clientId) {
        $phone = smsnoc_hook_get_client_phone($clientId);
        if (!empty($phone)) {
            smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_client_login_self'), [
                'client_name' => smsnoc_hook_get_client_name($clientId), 'time' => date('Y-m-d H:i'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]), 'client_login_self');
        }
    }
});

add_hook('ClientChangePassword', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_password_reset')) return;
    $clientId = $vars['userid'] ?? 0;
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (!empty($adminPhone)) {
        smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_password_reset'), [
            'client_name' => smsnoc_hook_get_client_name($clientId), 'email' => smsnoc_hook_get_client_email($clientId), 'time' => date('Y-m-d H:i'),
        ]), 'password_reset');
    }
});

add_hook('AffiliateWithdrawalRequest', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_affiliate_withdrawal')) return;
    $clientId = $vars['userid'] ?? 0;
    if (!$clientId) return;
    $phone = smsnoc_hook_get_client_phone($clientId);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_affiliate_withdrawal'), [
        'client_name' => smsnoc_hook_get_client_name($clientId), 'amount' => $vars['balance'] ?? '0',
    ]), 'affiliate_withdrawal');
});

add_hook('ClientEdit', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_client_edit_admin')) return;
    $clientId = $vars['userid'] ?? 0;
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (empty($adminPhone)) return;
    smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_client_edit_admin'), [
        'client_name' => smsnoc_hook_get_client_name($clientId), 'email' => smsnoc_hook_get_client_email($clientId),
    ]), 'client_edit_admin');
});


// ═══════════════════════════════════════════════════
//  █  ORDER HOOKS
// ═══════════════════════════════════════════════════

add_hook('AcceptOrder', 1, function ($vars) {
    $orderId = $vars['orderid'] ?? 0;
    try {
        $order = Capsule::table('tblorders')->where('id', $orderId)->first();
        if (!$order) return;
    } catch (\Exception $e) { return; }

    if (smsnoc_hook_is_enabled('enable_order_admin')) {
        $adminPhone = smsnoc_hook_get_setting('admin_phone');
        if (!empty($adminPhone)) {
            smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_order_admin'), [
                'client_name' => smsnoc_hook_get_client_name($order->userid), 'order_id' => $orderId,
                'amount' => number_format((float)$order->amount, 2), 'currency' => smsnoc_hook_get_currency($order->userid),
            ]), 'order_admin');
        }
    }
    if (smsnoc_hook_is_enabled('enable_order_confirmation')) {
        $phone = smsnoc_hook_get_client_phone($order->userid);
        if (!empty($phone)) {
            smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_order_confirmation'), [
                'client_name' => smsnoc_hook_get_client_name($order->userid), 'order_id' => $orderId,
                'amount' => number_format((float)$order->amount, 2), 'currency' => smsnoc_hook_get_currency($order->userid),
            ]), 'order_confirmation');
        }
    }
});

add_hook('LogTransaction', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_payment_confirmation')) return;
    $invoiceId = $vars['invoiceid'] ?? 0;
    if (!$invoiceId) return;
    try {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) return;
    } catch (\Exception $e) { return; }
    $phone = smsnoc_hook_get_client_phone($invoice->userid);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_payment_confirmation'), [
        'client_name' => smsnoc_hook_get_client_name($invoice->userid),
        'amount' => number_format($invoice->total, 2),
        'currency' => smsnoc_hook_get_currency($invoice->userid),
        'transaction_id' => $vars['transid'] ?? 'N/A',
    ]), 'payment_confirmation');
});

add_hook('AddCredit', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_credit_added')) return;
    $clientId = $vars['userid'] ?? 0;
    if (!$clientId) return;
    $phone = smsnoc_hook_get_client_phone($clientId);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_credit_added'), [
        'client_name' => smsnoc_hook_get_client_name($clientId),
        'amount' => number_format((float)($vars['amount'] ?? 0), 2),
        'currency' => smsnoc_hook_get_currency($clientId),
    ]), 'credit_added');
});

add_hook('QuoteCreated', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_quote_created')) return;
    $quoteId = $vars['quoteid'] ?? 0;
    try {
        $quote = Capsule::table('tblquotes')->where('id', $quoteId)->first();
        if (!$quote) return;
    } catch (\Exception $e) { return; }
    $phone = smsnoc_hook_get_client_phone($quote->userid);
    if (empty($phone)) return;
    $sysUrl = smsnoc_hook_get_system_url();
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_quote_created'), [
        'client_name' => smsnoc_hook_get_client_name($quote->userid), 'quote_id' => $quoteId,
        'amount' => number_format((float)$quote->total, 2), 'currency' => smsnoc_hook_get_currency($quote->userid),
        'quote_link' => $sysUrl ? $sysUrl . '/viewquote.php?id=' . $quoteId : '',
    ]), 'quote_created');
});

// Additional hooks
add_hook('AcceptQuote', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_quote_accepted')) return;
    $quoteId = $vars['quoteid'] ?? 0;
    try { $quote = Capsule::table('tblquotes')->where('id', $quoteId)->first(); if (!$quote) return; }
    catch (\Exception $e) { return; }
    $phone = smsnoc_hook_get_client_phone($quote->userid);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_quote_accepted'), [
        'client_name' => smsnoc_hook_get_client_name($quote->userid), 'quote_id' => $quoteId,
        'amount' => number_format((float)$quote->total, 2), 'currency' => smsnoc_hook_get_currency($quote->userid),
    ]), 'quote_accepted');
});

add_hook('AfterModuleChangePassword', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_module_password_change')) return;
    $sd = smsnoc_hook_get_service_details($vars);
    if (!$sd['client_id']) return;
    $phone = smsnoc_hook_get_client_phone($sd['client_id']);
    if (empty($phone)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_module_password_change'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'], 'domain' => $sd['domain'],
    ]), 'module_password_change');
});

add_hook('InvoicePaid', 2, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_auto_renew_notice')) return;
    $invoice = Capsule::table('tblinvoices')->where('id', $vars['invoiceid'])->first();
    if (!$invoice || $invoice->status !== 'Paid') return;
    $phone = smsnoc_hook_get_client_phone($invoice->userid);
    if (empty($phone)) return;
    $tpl = smsnoc_hook_get_template('tpl_auto_renew_notice');
    if (empty($tpl)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, [
        'client_name' => smsnoc_hook_get_client_name($invoice->userid),
        'invoice_id' => $invoice->id,
        'amount' => number_format($invoice->total, 2),
        'currency' => smsnoc_hook_get_currency($invoice->userid),
    ]), 'auto_renew_notice');
});

add_hook('CancellationRequest', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_cancellation_request')) return;
    $sd = smsnoc_hook_get_service_details($vars);
    $adminPhone = smsnoc_hook_get_setting('admin_phone');
    if (empty($adminPhone)) return;
    smsnoc_hook_send_notification($adminPhone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_cancellation_request'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']), 'product' => $sd['product'],
        'domain' => $sd['domain'], 'reason' => $vars['reason'] ?? 'No reason given',
    ]), 'cancellation_request');
});

add_hook('AfterModuleCreate', 3, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_product_upgrade')) return;
    // Only trigger on upgrade orders
    $sd = smsnoc_hook_get_service_details($vars);
    if (!$sd['client_id']) return;
    try {
        $upgrade = Capsule::table('tblupgrades')->where('relid', $sd['service'] ? $sd['service']->id : 0)->orderBy('id', 'desc')->first();
        if (!$upgrade) return;
    } catch (\Exception $e) { return; }
    $phone = smsnoc_hook_get_client_phone($sd['client_id']);
    if (empty($phone)) return;
    $oldProduct = '';
    try { $oldPkg = Capsule::table('tblproducts')->where('id', $upgrade->originalvalue ?? 0)->first(); if ($oldPkg) $oldProduct = $oldPkg->name; } catch (\Exception $e) {}
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template(smsnoc_hook_get_template('tpl_product_upgrade'), [
        'client_name' => smsnoc_hook_get_client_name($sd['client_id']),
        'old_product' => $oldProduct, 'new_product' => $sd['product'],
    ]), 'product_upgrade');
});

add_hook('DomainRenewal', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_domain_renewal')) return;
    $clientId = $vars['userid'] ?? 0;
    if (!$clientId) return;
    $phone = smsnoc_hook_get_client_phone($clientId);
    if (empty($phone)) return;
    $tpl = smsnoc_hook_get_template('tpl_domain_renewal');
    if (empty($tpl)) return;
    smsnoc_hook_send_notification($phone, smsnoc_hook_parse_template($tpl, [
        'client_name' => smsnoc_hook_get_client_name($clientId),
        'domain' => $vars['domain'] ?? '',
        'expiry_date' => $vars['expirydate'] ?? '',
    ]), 'domain_renewal');
});


// ═══════════════════════════════════════════════════
//  █  CLIENT AREA OTP INJECTION (v3 — fully reliable)
//  FIX: Use DOMContentLoaded wrapper inside footer output
//  FIX: CSS always outputs when any OTP feature is enabled
//  FIX: Broader selectors + page URL detection as fallback
// ═══════════════════════════════════════════════════

// ── CSS injection in <head> — only when OTP features are enabled ──
add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
    $loginEnabled = smsnoc_hook_is_enabled('enable_otp_login');
    $regEnabled = smsnoc_hook_is_enabled('enable_otp_register');
    $resetEnabled = smsnoc_hook_is_enabled('enable_otp_forgot_pass');

    if (!$loginEnabled && !$regEnabled && !$resetEnabled) return '';

    $output = '<style>';

    // Shared OTP form styles
    $output .= '
    .snoc-otp-box{position:relative;margin:20px 0;padding:24px;border-radius:16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;overflow:hidden}
    .snoc-otp-box::before{content:"";position:absolute;inset:0;border-radius:16px;padding:2px;background:var(--snoc-grad);-webkit-mask:linear-gradient(#fff 0 0) content-box,linear-gradient(#fff 0 0);-webkit-mask-composite:xor;mask-composite:exclude;pointer-events:none}
    .snoc-otp-box.teal{--snoc-grad:linear-gradient(135deg,#0d9488,#2dd4bf);background:linear-gradient(135deg,#f0fdfa,#ecfdf5)}
    .snoc-otp-box.amber{--snoc-grad:linear-gradient(135deg,#d97706,#fbbf24);background:linear-gradient(135deg,#fffbeb,#fef3c7)}
    .snoc-otp-box .snoc-title{display:flex;align-items:center;gap:10px;margin:0 0 16px;font-size:16px;font-weight:700}
    .snoc-otp-box.teal .snoc-title{color:#0d9488}
    .snoc-otp-box.amber .snoc-title{color:#d97706}
    .snoc-otp-box .snoc-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
    .snoc-otp-box.teal .snoc-icon{background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;box-shadow:0 4px 12px rgba(13,148,136,0.25)}
    .snoc-otp-box.amber .snoc-icon{background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;box-shadow:0 4px 12px rgba(217,119,6,0.25)}
    .snoc-otp-box input[type="tel"],.snoc-otp-box input[type="text"],.snoc-otp-box input[type="password"]{width:100%;padding:12px 16px;border:1.5px solid #d1d5db;border-radius:10px;font-size:14px;margin-bottom:10px;box-sizing:border-box;transition:border-color .2s,box-shadow .2s;background:#fff}
    .snoc-otp-box input:focus{outline:none;border-color:#14b8a6;box-shadow:0 0 0 3px rgba(20,184,166,0.12)}
    .snoc-otp-box input.otp-code{font-size:22px;text-align:center;letter-spacing:8px;font-family:"SF Mono",SFMono-Regular,Menlo,monospace;font-weight:600}
    .snoc-otp-box .snoc-btn{width:100%;padding:12px;border:none;border-radius:10px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px}
    .snoc-otp-box .snoc-btn:hover{transform:translateY(-1px)}
    .snoc-otp-box .snoc-btn:disabled{opacity:0.6;cursor:not-allowed;transform:none}
    .snoc-otp-box .btn-teal{background:linear-gradient(135deg,#0d9488,#14b8a6);box-shadow:0 4px 12px rgba(13,148,136,0.3)}
    .snoc-otp-box .btn-green{background:linear-gradient(135deg,#059669,#10b981);box-shadow:0 4px 12px rgba(5,150,105,0.3)}
    .snoc-otp-box .btn-amber{background:linear-gradient(135deg,#d97706,#f59e0b);box-shadow:0 4px 12px rgba(217,119,6,0.3)}
    .snoc-otp-box .snoc-msg{padding:10px 14px;border-radius:8px;font-size:13px;margin-top:10px;text-align:center}
    .snoc-otp-box .snoc-msg.ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
    .snoc-otp-box .snoc-msg.err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
    .snoc-otp-box .snoc-msg.info{background:#dbeafe;color:#1e40af;border:1px solid #93c5fd}
    .snoc-otp-box .snoc-secured{text-align:center;font-size:11px;color:#9ca3af;margin-top:12px}
    ';

    // Hide default forms via CSS
    if ($loginEnabled && smsnoc_hook_get_setting('otp_hide_default_login', '0') === '1') {
        $output .= '
        form[action*="dologin"] .form-group, form[action*="dologin"] fieldset,
        form[action*="dologin"] .row:not([id^="snoc-"]), form[action*="dologin"] p.form-group,
        form[action*="dologin"] > p:not([id^="snoc-"]), form[action*="dologin"] .form-actions,
        form[action*="dologin"] .rememberme, form[action*="dologin"] > label,
        form[action*="dologin"] input[type="submit"]:not(.snoc-btn),
        form[action*="dologin"] button[type="submit"]:not(.snoc-btn),
        form[action$="dologin.php"] .form-group, form[action$="dologin.php"] fieldset,
        form[action$="dologin.php"] label:not(.snoc-label),
        form[action$="dologin.php"] input[type="submit"]:not(.snoc-btn),
        form[action$="dologin.php"] button[type="submit"]:not(.snoc-btn),
        form[action$="dologin.php"] .rememberme,
        form[action$="dologin.php"] p:not([id^="snoc-"]),
        .logincontainer .form-group, .logincontainer fieldset, .logincontainer > label,
        #Primary_Sidebar-Login .form-group { display:none !important; }
        ';
    }

    if ($regEnabled && smsnoc_hook_get_setting('otp_hide_default_register', '0') === '1') {
        $output .= '
        #frmRegister input[name="email"], #frmRegister input[name="password"], #frmRegister input[name="password2"],
        #frmRegister .field-container:has(input[name="email"]), #frmRegister .field-container:has(input[name="password"]), #frmRegister .field-container:has(input[name="password2"]),
        form[action*="register"] input[name="email"], form[action*="register"] input[name="password"], form[action*="register"] input[name="password2"] { display:none !important; }
        ';
    }

    if ($regEnabled && smsnoc_hook_get_setting('otp_password_optional_register', '0') === '1') {
        $output .= '
        .generate-password, [data-action="generate-password"], .btn-generate-pw,
        a[href*="genpassword"], .generatepw, .pwstrength,
        .password-strength-result, #password-strength { display:none !important; }
        ';
    }

    if ($regEnabled && smsnoc_hook_get_setting('otp_email_optional_register', '0') === '1') {
        $output .= '
        #frmRegister input[name="email"], form[action*="register"] input[name="email"] { display:none !important; }
        ';
    }

    if ($resetEnabled && smsnoc_hook_get_setting('otp_hide_default_reset', '0') === '1') {
        $output .= '
        form[action*="pwreset"] .form-group, form[action*="pwreset"] fieldset,
        form[action*="pwreset"] > p:not([id^="snoc-"]),
        form[action*="pwreset"] input[type="submit"]:not(.snoc-btn),
        form[action*="pwreset"] button[type="submit"]:not(.snoc-btn),
        form[action*="password/reset"] .form-group, form[action*="password/reset"] > p:not([id^="snoc-"]),
        form[action*="password/reset"] input[type="submit"]:not(.snoc-btn),
        form[action*="password/reset"] button[type="submit"]:not(.snoc-btn),
        #frmPasswordReset .form-group, #frmPasswordReset input[type="submit"]:not(.snoc-btn),
        #frmPasswordReset button[type="submit"]:not(.snoc-btn),
        .pw-reset form .form-group, .pw-reset form input[type="submit"]:not(.snoc-btn),
        .pw-reset form button[type="submit"]:not(.snoc-btn) { display:none !important; }
        ';
    }

    $output .= '</style>';
    return $output;
});

// ── JS injection in footer (AFTER DOM is rendered) ──
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $loginEnabled = smsnoc_hook_is_enabled('enable_otp_login');
    $regEnabled = smsnoc_hook_is_enabled('enable_otp_register');
    $resetEnabled = smsnoc_hook_is_enabled('enable_otp_forgot_pass');

    if (!$loginEnabled && !$regEnabled && !$resetEnabled) return '';

    $sysUrl = smsnoc_hook_get_system_url();
    $otpEndpoint = $sysUrl . '/modules/addons/smsnoc/otp_handler.php';
    $companyName = addslashes(smsnoc_hook_get_company_name());

    // Build script — snocMsg helper FIRST, then DOMContentLoaded wrapper
    $output = '<script>';

    // Global helper — must be defined before any form code uses it
    $output .= 'function snocMsg(id,msg,type){var el=document.getElementById(id);if(!el)return;el.style.display="block";el.className="snoc-msg "+(type||"");el.innerHTML=msg;}';

    // Wrap all form injection in DOMContentLoaded for reliability
    $output .= 'document.addEventListener("DOMContentLoaded",function(){';

    // ═══════ OTP LOGIN ═══════
    if ($loginEnabled) {
        $hideDefault = smsnoc_hook_get_setting('otp_hide_default_login', '0') === '1';
        $loginTitle = $hideDefault ? 'Sign In with Phone' : 'Or Sign In with Phone';
        $output .= '
(function(){
    var f = document.querySelector("form[action*=\'dologin\']") || document.querySelector("form[action$=\'dologin.php\']") || document.querySelector("#loginFrm") || document.querySelector(".logincontainer form") || document.querySelector("#login form");
    var isLoginPage = /\/(login|dologin)/i.test(location.pathname) || document.querySelector("#loginfrm,#loginFrm,.login-form-container");
    if (!f && isLoginPage) {
        var ff = document.querySelectorAll("form");
        for (var i=0;i<ff.length;i++){if(ff[i].querySelector("input[name=\'username\'],input[name=\'email\'],input[type=\'password\']")){f=ff[i];break;}}
    }
    if (!f || document.getElementById("snoc-login-otp")) return;
    var d=document.createElement("div");d.id="snoc-login-otp";
    d.innerHTML=\'<div class="snoc-otp-box teal"><div class="snoc-title"><div class="snoc-icon">📱</div><div>' . $loginTitle . '</div></div><input type="tel" id="snoc-lp" placeholder="Enter your phone number" /><button type="button" id="snoc-ls" class="snoc-btn btn-teal">📱 Send OTP</button><div id="snoc-lc" style="display:none;margin-top:10px"><input type="text" id="snoc-lo" class="otp-code" placeholder="••••••" maxlength="6" inputmode="numeric" /><button type="button" id="snoc-lv" class="snoc-btn btn-green">🔑 Sign In</button></div><div id="snoc-lm" class="snoc-msg" style="display:none"></div><div class="snoc-secured">🔒 Secured by ' . $companyName . '</div></div>\';
    ' . ($hideDefault ? 'f.insertBefore(d,f.firstChild);' : 'f.parentNode.insertBefore(d,f.nextSibling);') . '
    document.getElementById("snoc-ls").addEventListener("click",function(){
        var p=document.getElementById("snoc-lp").value.trim();
        if(!p){snocMsg("snoc-lm","Enter phone number","err");return;}
        this.disabled=true;this.textContent="⏳ Sending...";var b=this;
        var fd=new FormData();fd.append("action","send_otp");fd.append("phone",p);fd.append("purpose","login");
        fetch("' . $otpEndpoint . '",{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){
            b.disabled=false;b.textContent="📱 Resend OTP";
            if(r.success){document.getElementById("snoc-lc").style.display="block";snocMsg("snoc-lm","OTP sent! Check your phone.","ok");document.getElementById("snoc-lo").focus();}
            else{snocMsg("snoc-lm",r.error||"Failed to send OTP","err");}
        }).catch(function(){b.disabled=false;b.textContent="📱 Send OTP";snocMsg("snoc-lm","Network error","err");});
    });
    document.getElementById("snoc-lv").addEventListener("click",function(){
        var p=document.getElementById("snoc-lp").value.trim(),c=document.getElementById("snoc-lo").value.trim();
        if(!c){snocMsg("snoc-lm","Enter OTP code","err");return;}
        this.disabled=true;this.textContent="⏳ Verifying...";var b=this;
        var fd=new FormData();fd.append("action","otp_login");fd.append("phone",p);fd.append("code",c);
        fetch("' . $otpEndpoint . '",{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){
            b.disabled=false;b.textContent="🔑 Sign In";
            if(r.success){snocMsg("snoc-lm","✅ Success! Redirecting...","ok");setTimeout(function(){location.href=r.redirect||"' . $sysUrl . '/clientarea.php"},800);}
            else{snocMsg("snoc-lm",r.error||"Invalid OTP","err");}
        }).catch(function(){b.disabled=false;b.textContent="🔑 Sign In";snocMsg("snoc-lm","Network error","err");});
    });
    document.getElementById("snoc-lo").addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();document.getElementById("snoc-lv").click();}});
})();';
    }

    // ═══════ OTP REGISTRATION ═══════
    if ($regEnabled) {
        $emailOptional = smsnoc_hook_get_setting('otp_email_optional_register', '0') === '1';
        $passOptional = smsnoc_hook_get_setting('otp_password_optional_register', '0') === '1';
        $output .= '
(function(){
    var f = document.querySelector("#frmRegister") || document.querySelector("form[action*=\'register.php\']") || document.querySelector("form[action*=\'register\']:not([action*=\'dologin\'])");
    var isRegPage = /\/(register)/i.test(location.pathname);
    if (!f && isRegPage) {
        var ff=document.querySelectorAll("form");
        for(var i=0;i<ff.length;i++){if(ff[i].querySelector("input[name=\'firstname\'],input[name=\'phonenumber\']")){f=ff[i];break;}}
    }
    if (!f || document.getElementById("snoc-reg-otp")) return;
    var sub=f.querySelector("input[type=submit],button[type=submit]");
    if(!sub)return;
    var pf=f.querySelector("input[name=\'phonenumber\']")||f.querySelector("input[name=\'phone\']");
    f.addEventListener("submit",function(e){
        var vf=document.getElementById("snoc-reg-ok");
        if(!vf||vf.value!=="1"){e.preventDefault();snocMsg("snoc-rm","Please verify your phone number first!","err");return false;}';
        if ($emailOptional) {
            $output .= 'var ef=f.querySelector("input[name=\'email\']");if(ef&&!ef.value.trim()){var ph=pf?pf.value.replace(/[^0-9]/g,""):"0";ef.value=ph+"@phone.local";}';
        }
        if ($passOptional) {
            $output .= 'var pw=f.querySelector("input[name=\'password\']"),p2=f.querySelector("input[name=\'password2\']");if(pw&&!pw.value){var rp=Math.random().toString(36).slice(-12)+"A1!";pw.value=rp;if(p2)p2.value=rp;}';
        }
        $output .= '
    });
    var od=document.createElement("div");od.id="snoc-reg-otp";
    od.innerHTML=\'<div class="snoc-otp-box teal"><div class="snoc-title"><div class="snoc-icon">✅</div><div>Phone Verification</div></div><p style="font-size:13px;color:#6b7280;margin:0 0 12px">Enter your phone number above, then verify with OTP.</p><button type="button" id="snoc-rs" class="snoc-btn btn-teal">📱 Send Verification OTP</button><div id="snoc-rc" style="display:none;margin-top:10px"><input type="text" id="snoc-ro" class="otp-code" placeholder="••••••" maxlength="6" inputmode="numeric" /><button type="button" id="snoc-rv" class="snoc-btn btn-green">✅ Verify Phone</button></div><input type="hidden" name="smsnoc_otp_verified" id="snoc-reg-ok" value="0" /><div id="snoc-rm" class="snoc-msg" style="display:none"></div></div>\';
    if(pf){var pg=pf.closest(".form-group")||pf.closest(".row")||pf.parentElement;if(pg&&pg.parentElement)pg.parentElement.insertBefore(od,pg.nextSibling);else sub.parentNode.insertBefore(od,sub);}
    else{sub.parentNode.insertBefore(od,sub);}
    document.getElementById("snoc-rs").addEventListener("click",function(){
        var p=pf?pf.value.trim():"";
        if(!p||p.replace(/[^0-9]/g,"").length<7){snocMsg("snoc-rm","Enter a valid phone number above","err");return;}
        this.disabled=true;this.textContent="⏳ Sending...";var b=this;
        var fd=new FormData();fd.append("action","send_otp");fd.append("phone",p);fd.append("purpose","register");
        fetch("' . $otpEndpoint . '",{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){
            b.disabled=false;b.textContent="📱 Resend OTP";
            if(r.success){document.getElementById("snoc-rc").style.display="block";snocMsg("snoc-rm","OTP sent!","ok");document.getElementById("snoc-ro").focus();}
            else{snocMsg("snoc-rm",r.error||"Failed","err");}
        }).catch(function(){b.disabled=false;b.textContent="📱 Send OTP";});
    });
    document.getElementById("snoc-rv").addEventListener("click",function(){
        var p=pf?pf.value.trim():"",c=document.getElementById("snoc-ro").value.trim();
        if(!c){snocMsg("snoc-rm","Enter OTP","err");return;}
        this.disabled=true;this.textContent="⏳ Verifying...";var b=this;
        var fd=new FormData();fd.append("action","verify_otp");fd.append("phone",p);fd.append("code",c);fd.append("purpose","register");
        fetch("' . $otpEndpoint . '",{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){
            b.disabled=false;
            if(r.success){document.getElementById("snoc-reg-ok").value="1";snocMsg("snoc-rm","✅ Phone verified!","ok");b.textContent="✅ Verified";b.disabled=true;b.style.background="#059669";document.getElementById("snoc-rs").style.display="none";document.getElementById("snoc-rc").style.display="none";}
            else{b.textContent="✅ Verify Phone";snocMsg("snoc-rm",r.error||"Invalid OTP","err");}
        }).catch(function(){b.disabled=false;b.textContent="✅ Verify Phone";});
    });
    document.getElementById("snoc-ro").addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();document.getElementById("snoc-rv").click();}});
})();';
    }

    // ═══════ OTP FORGOT PASSWORD ═══════
    if ($resetEnabled) {
        $hideDefault = smsnoc_hook_get_setting('otp_hide_default_reset', '0') === '1';
        $resetTitle = $hideDefault ? 'Reset via Phone OTP' : 'Or Reset via Phone OTP';
        $output .= '
(function(){
    var mount=document.getElementById("smsnoc-forgot-otp-mount");
    var f=document.querySelector("form[action*=\'pwreset\']")||document.querySelector("form[action*=\'password/reset\']")||document.querySelector("#frmPasswordReset")||document.querySelector(".pw-reset form");
    var isResetPage=/\/(password|pwreset|lost-password)/i.test(location.pathname)||/action=lostpassword/i.test(location.search);
    if(!f&&isResetPage&&!mount){var ff=document.querySelectorAll("form");for(var i=0;i<ff.length;i++){if(ff[i].querySelector("input[name=\'email\']")&&!ff[i].querySelector("input[type=\'password\']")){f=ff[i];break;}}}
    if((!f&&!mount)||document.getElementById("snoc-reset-otp"))return;
    var d=document.createElement("div");d.id="snoc-reset-otp";
    d.innerHTML=\'<div class="snoc-otp-box amber"><div class="snoc-title"><div class="snoc-icon">🔒</div><div>' . addslashes($resetTitle) . '</div></div><input type="tel" id="snoc-fp" placeholder="Your registered phone number" /><button type="button" id="snoc-fs" class="snoc-btn btn-amber">📱 Send Reset OTP</button><div id="snoc-fc" style="display:none;margin-top:10px"><input type="text" id="snoc-fo" class="otp-code" placeholder="••••••" maxlength="6" inputmode="numeric" /><input type="password" id="snoc-fn" placeholder="New password (min 6 chars)" style="margin-top:4px" /><button type="button" id="snoc-fv" class="snoc-btn btn-green">🔒 Reset Password</button></div><div id="snoc-fm" class="snoc-msg" style="display:none"></div><div class="snoc-secured">🔒 Secured by ' . $companyName . '</div></div>\';
    if(mount){mount.innerHTML="";mount.appendChild(d);} else {' . ($hideDefault ? 'f.insertBefore(d,f.firstChild);' : 'f.parentNode.insertBefore(d,f);') . '}
    document.getElementById("snoc-fs").addEventListener("click",function(){
        var p=document.getElementById("snoc-fp").value.trim();
        if(!p){snocMsg("snoc-fm","Enter phone number","err");return;}
        this.disabled=true;this.textContent="⏳ Sending...";var b=this;
        var fd=new FormData();fd.append("action","send_otp");fd.append("phone",p);fd.append("purpose","forgot_password");
        fetch("' . $otpEndpoint . '",{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){
            b.disabled=false;b.textContent="📱 Resend OTP";
            if(r.success){document.getElementById("snoc-fc").style.display="block";snocMsg("snoc-fm","OTP sent!","ok");document.getElementById("snoc-fo").focus();}
            else{snocMsg("snoc-fm",r.error||"Failed","err");}
        }).catch(function(){b.disabled=false;b.textContent="📱 Send OTP";});
    });
    document.getElementById("snoc-fv").addEventListener("click",function(){
        var p=document.getElementById("snoc-fp").value.trim(),c=document.getElementById("snoc-fo").value.trim(),pw=document.getElementById("snoc-fn").value;
        if(!c||!pw){snocMsg("snoc-fm","Fill all fields","err");return;}
        if(pw.length<6){snocMsg("snoc-fm","Password must be at least 6 characters","err");return;}
        this.disabled=true;this.textContent="⏳ Resetting...";var b=this;
        var fd=new FormData();fd.append("action","reset_password");fd.append("phone",p);fd.append("code",c);fd.append("new_password",pw);
        fetch("' . $otpEndpoint . '",{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){
            b.disabled=false;b.textContent="🔒 Reset Password";
            if(r.success){snocMsg("snoc-fm","✅ Password reset! <a href=\'' . $sysUrl . '/clientarea.php\' style=\'color:#059669;font-weight:600\'>Login →</a>","ok");b.disabled=true;}
            else{snocMsg("snoc-fm",r.error||"Failed","err");}
        }).catch(function(){b.disabled=false;b.textContent="🔒 Reset Password";});
    });
    document.getElementById("snoc-fo").addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();document.getElementById("snoc-fv").click();}});
})();';
    }

    $output .= '});'; // end DOMContentLoaded
    $output .= '</script>';
    return $output;
});


// ═══════════════════════════════════════════════════
//  █  SMARTY TEMPLATE VARIABLES (for manual template integration)
//  Usage in .tpl files: {if $smsnocOtpLoginEnabled}...{/if}
// ═══════════════════════════════════════════════════

add_hook('ClientAreaPageLogin', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_otp_login')) return;
    return ['smsnocOtpLoginEnabled' => true];
});

add_hook('ClientAreaPageRegister', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_otp_register')) return;
    return ['smsnocOtpRegisterEnabled' => true];
});

add_hook('ClientAreaPagePasswordReset', 1, function ($vars) {
    if (!smsnoc_hook_is_enabled('enable_otp_forgot_pass')) return;
    return ['smsnocOtpForgotPassEnabled' => true, 'smsnocOtpResetEnabled' => true];
});

add_hook('ClientAreaPage', 1, function ($vars) {
    return [
        'smsnocOtpLoginEnabled' => smsnoc_hook_is_enabled('enable_otp_login'),
        'smsnocOtpRegisterEnabled' => smsnoc_hook_is_enabled('enable_otp_register'),
        'smsnocOtpForgotPassEnabled' => smsnoc_hook_is_enabled('enable_otp_forgot_pass'),
        'smsnocOtpResetEnabled' => smsnoc_hook_is_enabled('enable_otp_forgot_pass'),
    ];
});


// ═══════════════════════════════════════════════════
//  █  CLIENT PROFILE: Show Phone Verification Status
// ═══════════════════════════════════════════════════
add_hook('AdminClientProfileTabFields', 1, function ($vars) {
    $clientId = $vars['userid'] ?? 0;
    if (!$clientId) return [];
    
    $phone = smsnoc_hook_get_client_phone($clientId);
    $isVerified = false;
    
    try {
        $row = Capsule::table('mod_smsnoc_settings')
            ->where('setting_key', 'phone_verified_' . $clientId)
            ->first();
        $isVerified = $row && $row->setting_value === '1';
    } catch (\Exception $e) {}
    
    if (!$isVerified && !empty($phone)) {
        try {
            $phoneClean = preg_replace('/[^0-9]/', '', $phone);
            $isVerified = Capsule::table('mod_smsnoc_otp')
                ->where('phone', $phoneClean)
                ->where('verified', true)
                ->exists();
        } catch (\Exception $e) {}
    }
    
    $statusLabel = $isVerified 
        ? '<span style="color:#059669;font-weight:600">✅ Verified</span>' 
        : '<span style="color:#dc2626;font-weight:600">❌ Not Verified</span>';
    
    return [
        'SMS NOC Phone Status' => $statusLabel . ' <small>(' . htmlspecialchars($phone ?: 'No phone') . ')</small>',
    ];
});
