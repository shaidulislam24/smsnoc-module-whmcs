<?php
/**
 * SMS NOC OTP Handler for WHMCS Client Area v1.0.0
 * LOCAL OTP generation/verification (not dependent on remote smsnoc.com OTP)
 * Handles send_otp, verify_otp, otp_login, reset_password actions
 * Called via AJAX from client area JavaScript injections
 */

// Bootstrap WHMCS
$whmcsPath = dirname(dirname(dirname(__DIR__)));
if (file_exists($whmcsPath . '/init.php')) {
    require_once $whmcsPath . '/init.php';
} elseif (file_exists($whmcsPath . '/includes/functions.php')) {
    require_once $whmcsPath . '/includes/functions.php';
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/SMSNOC_API.php';

function smsnoc_otp_get_setting($key, $default = '') {
    try {
        $row = Capsule::table('mod_smsnoc_settings')->where('setting_key', $key)->first();
        return $row ? $row->setting_value : $default;
    } catch (\Exception $e) { return $default; }
}

function smsnoc_otp_get_company_name() {
    try { return Capsule::table('tblconfiguration')->where('setting', 'CompanyName')->value('value') ?: 'Our Company'; }
    catch (\Exception $e) { return 'Our Company'; }
}

// ═══════════════════════════════════════════
//  LOCAL OTP Engine (stored in mod_smsnoc_otp table)
// ═══════════════════════════════════════════

function smsnoc_local_generate_otp() {
    return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function smsnoc_local_store_otp($phone, $purpose, $code) {
    $phone_clean = preg_replace('/[^0-9]/', '', $phone);
    $expiry_minutes = (int) smsnoc_otp_get_setting('otp_expiry_minutes', '5');
    if ($expiry_minutes < 1) $expiry_minutes = 5;
    try {
        // Clean up old expired OTPs for this phone
        Capsule::table('mod_smsnoc_otp')
            ->where('phone', $phone_clean)
            ->where('purpose', $purpose)
            ->where('verified', false)
            ->delete();
        
        // Insert new OTP
        Capsule::table('mod_smsnoc_otp')->insert([
            'phone'      => $phone_clean,
            'code'       => $code,
            'purpose'    => $purpose,
            'verified'   => false,
            'expires_at' => date('Y-m-d H:i:s', time() + ($expiry_minutes * 60)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

function smsnoc_local_verify_otp($phone, $purpose, $code) {
    $phone_clean = preg_replace('/[^0-9]/', '', $phone);
    try {
        $otp = Capsule::table('mod_smsnoc_otp')
            ->where('phone', $phone_clean)
            ->where('purpose', $purpose)
            ->where('verified', false)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->orderBy('created_at', 'desc')
            ->first();
        
        if (!$otp) return 'OTP expired or not found. Please request a new one.';
        
        if ($otp->code !== $code) {
            return 'Invalid OTP code.';
        }
        
        // Mark as verified
        Capsule::table('mod_smsnoc_otp')->where('id', $otp->id)->update(['verified' => true]);
        return true;
    } catch (\Exception $e) {
        return 'Verification error. Please try again.';
    }
}

function smsnoc_send_otp_sms($api, $phone, $code, $purpose) {
    $company = smsnoc_otp_get_company_name();
    
    // Try to use per-purpose OTP template
    $templateKey = 'otp_' . $purpose . '_template';
    $template = smsnoc_otp_get_setting($templateKey, '');
    
    if (!empty($template)) {
        $message = str_replace(
            ['{otp_code}', '{company_name}', '{phone}'],
            [$code, $company, $phone],
            $template
        );
    } else {
        $message = "[{$company}] Your verification code is: {$code}. Valid for 5 minutes. Do not share this code.";
    }
    
    // Send via configured channel (try SMS first, most reliable for OTP)
    $result = $api->send_sms($phone, $message, smsnoc_otp_get_setting('default_sender'));
    return $result;
}


$api = new SMSNOC_API(smsnoc_otp_get_setting('api_key'));
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ═══════════════════════════════════════════
//  SEND OTP (LOCAL generation, remote SMS delivery)
// ═══════════════════════════════════════════
if ($action === 'send_otp') {
    $phone = trim($_POST['phone'] ?? '');
    $purpose = trim($_POST['purpose'] ?? 'login');
    if (empty($phone)) { echo json_encode(['success' => false, 'error' => 'Phone required']); exit; }
    
    $phone_clean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone_clean) < 10 || strlen($phone_clean) > 15) {
        echo json_encode(['success' => false, 'error' => 'Enter a valid phone number']);
        exit;
    }

    // Configurable rate limiting
    $maxPerHour = (int) smsnoc_otp_get_setting('otp_max_per_phone_hour', '3');
    $maxPerDay = (int) smsnoc_otp_get_setting('otp_max_per_phone_day', '10');
    $maxPerIpHour = (int) smsnoc_otp_get_setting('otp_max_per_ip_hour', '10');
    $resendCooldown = (int) smsnoc_otp_get_setting('otp_resend_cooldown', '60');
    if ($maxPerHour < 1) $maxPerHour = 3;
    if ($maxPerDay < 1) $maxPerDay = 10;
    if ($maxPerIpHour < 1) $maxPerIpHour = 10;
    if ($resendCooldown < 10) $resendCooldown = 60;

    // Per-phone hourly limit
    $lockKeyHour = 'otp_rl_hr_' . md5($phone_clean . $purpose);
    try {
        $existing = Capsule::table('mod_smsnoc_settings')->where('setting_key', $lockKeyHour)->first();
        if ($existing) {
            $data = json_decode($existing->setting_value, true) ?: [];
            $recentCount = 0;
            $cutoff = time() - 3600;
            foreach ($data as $ts) { if ($ts > $cutoff) $recentCount++; }
            if ($recentCount >= $maxPerHour) {
                echo json_encode(['success' => false, 'error' => 'Too many OTP requests this hour. Please try later.']);
                exit;
            }
            // Check resend cooldown
            $lastSent = max($data);
            if ((time() - $lastSent) < $resendCooldown) {
                $wait = $resendCooldown - (time() - $lastSent);
                echo json_encode(['success' => false, 'error' => "Please wait {$wait}s before requesting another OTP."]);
                exit;
            }
            $data[] = time();
            $data = array_filter($data, function($ts) use ($cutoff) { return $ts > $cutoff; });
            Capsule::table('mod_smsnoc_settings')->where('setting_key', $lockKeyHour)
                ->update(['setting_value' => json_encode(array_values($data)), 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            Capsule::table('mod_smsnoc_settings')->insert([
                'setting_key' => $lockKeyHour,
                'setting_value' => json_encode([time()]),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (\Exception $e) {}

    // Per-phone daily limit
    $lockKeyDay = 'otp_rl_day_' . md5($phone_clean);
    try {
        $existing = Capsule::table('mod_smsnoc_settings')->where('setting_key', $lockKeyDay)->first();
        $cutoffDay = time() - 86400;
        if ($existing) {
            $data = json_decode($existing->setting_value, true) ?: [];
            $dayCount = 0;
            foreach ($data as $ts) { if ($ts > $cutoffDay) $dayCount++; }
            if ($dayCount >= $maxPerDay) {
                echo json_encode(['success' => false, 'error' => 'Daily OTP limit reached. Try again tomorrow.']);
                exit;
            }
            $data[] = time();
            $data = array_filter($data, function($ts) use ($cutoffDay) { return $ts > $cutoffDay; });
            Capsule::table('mod_smsnoc_settings')->where('setting_key', $lockKeyDay)
                ->update(['setting_value' => json_encode(array_values($data)), 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            Capsule::table('mod_smsnoc_settings')->insert([
                'setting_key' => $lockKeyDay, 'setting_value' => json_encode([time()]), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (\Exception $e) {}

    // Per-IP hourly limit
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockKeyIp = 'otp_rl_ip_' . md5($clientIp);
    try {
        $existing = Capsule::table('mod_smsnoc_settings')->where('setting_key', $lockKeyIp)->first();
        $cutoffIp = time() - 3600;
        if ($existing) {
            $data = json_decode($existing->setting_value, true) ?: [];
            $ipCount = 0;
            foreach ($data as $ts) { if ($ts > $cutoffIp) $ipCount++; }
            if ($ipCount >= $maxPerIpHour) {
                echo json_encode(['success' => false, 'error' => 'Too many requests from your IP. Please try later.']);
                exit;
            }
            $data[] = time();
            $data = array_filter($data, function($ts) use ($cutoffIp) { return $ts > $cutoffIp; });
            Capsule::table('mod_smsnoc_settings')->where('setting_key', $lockKeyIp)
                ->update(['setting_value' => json_encode(array_values($data)), 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            Capsule::table('mod_smsnoc_settings')->insert([
                'setting_key' => $lockKeyIp, 'setting_value' => json_encode([time()]), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (\Exception $e) {}

    // Generate OTP locally
    $code = smsnoc_local_generate_otp();
    $stored = smsnoc_local_store_otp($phone_clean, $purpose, $code);
    
    if (!$stored) {
        echo json_encode(['success' => false, 'error' => 'Failed to generate OTP. Please try again.']);
        exit;
    }
    
    // Send via SMS using the API (delivery only, code generated locally)
    $result = smsnoc_send_otp_sms($api, $phone, $code, $purpose);
    echo json_encode($result);
    exit;
}

// ═══════════════════════════════════════════
//  VERIFY OTP (LOCAL verification)
// ═══════════════════════════════════════════
if ($action === 'verify_otp') {
    $phone = trim($_POST['phone'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $purpose = trim($_POST['purpose'] ?? 'login');
    if (empty($phone) || empty($code)) { echo json_encode(['success' => false, 'error' => 'Phone and code required']); exit; }

    $result = smsnoc_local_verify_otp($phone, $purpose, $code);
    if ($result === true) {
        echo json_encode(['success' => true, 'verified' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $result]);
    }
    exit;
}

// ═══════════════════════════════════════════
//  OTP LOGIN
// ═══════════════════════════════════════════
if ($action === 'otp_login') {
    $phone = trim($_POST['phone'] ?? '');
    $code = trim($_POST['code'] ?? '');
    if (empty($phone) || empty($code)) { echo json_encode(['success' => false, 'error' => 'Phone and OTP required']); exit; }

    // Verify OTP locally
    $result = smsnoc_local_verify_otp($phone, 'login', $code);
    if ($result !== true) { echo json_encode(['success' => false, 'error' => $result]); exit; }

    // Find client by phone
    try {
        $phoneClean = preg_replace('/[^0-9]/', '', $phone);
        $client = Capsule::table('tblclients')->where('phonenumber', $phone)->first();
        if (!$client) {
            $client = Capsule::table('tblclients')->whereRaw("REPLACE(REPLACE(REPLACE(phonenumber, '+', ''), '-', ''), ' ', '') LIKE ?", ['%' . substr($phoneClean, -10)])->first();
        }
        if (!$client) { echo json_encode(['success' => false, 'error' => 'No account found with this phone number']); exit; }

        // Set WHMCS session for login
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['uid'] = $client->id;
        $_SESSION['upw'] = $client->password;

        $sysUrl = '';
        try { $sysUrl = rtrim(Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value') ?: '', '/'); }
        catch (\Exception $e) {}

        echo json_encode(['success' => true, 'redirect' => $sysUrl . '/clientarea.php']);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Login failed: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════
//  RESET PASSWORD
// ═══════════════════════════════════════════
if ($action === 'reset_password') {
    $phone = trim($_POST['phone'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    if (empty($phone) || empty($code) || empty($newPassword)) {
        echo json_encode(['success' => false, 'error' => 'All fields required']);
        exit;
    }
    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
        exit;
    }

    // Verify OTP locally
    $result = smsnoc_local_verify_otp($phone, 'forgot_password', $code);
    if ($result !== true) { echo json_encode(['success' => false, 'error' => $result]); exit; }

    // Find client and update password
    try {
        $phoneClean = preg_replace('/[^0-9]/', '', $phone);
        $client = Capsule::table('tblclients')->where('phonenumber', $phone)->first();
        if (!$client) {
            $client = Capsule::table('tblclients')->whereRaw("REPLACE(REPLACE(REPLACE(phonenumber, '+', ''), '-', ''), ' ', '') LIKE ?", ['%' . substr($phoneClean, -10)])->first();
        }
        if (!$client) { echo json_encode(['success' => false, 'error' => 'No account found']); exit; }

        // Use WHMCS internal API for proper password hashing
        $command = 'UpdateClient';
        $postData = ['clientid' => $client->id, 'password2' => $newPassword];
        $results = localAPI($command, $postData);
        if ($results['result'] !== 'success') {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            Capsule::table('tblclients')->where('id', $client->id)->update(['password' => $hashedPassword]);
        }

        echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Password reset failed']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);