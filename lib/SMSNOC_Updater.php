<?php
/**
 * SMS NOC Auto-Updater for WHMCS v1.0.0
 * 
 * Checks the SMS NOC platform for new module versions.
 * Supports: update notices in admin dashboard, force update blocking, one-click update.
 */
if (!defined("WHMCS")) die("This file cannot be accessed directly");

use WHMCS\Database\Capsule;

class SMSNOC_Updater {

    private static $api_endpoint = 'https://smsnoc.com/api/v1/plugin-info';
    private static $cache_key    = 'smsnoc_update_cache';
    private static $cache_ttl    = 43200; // 12 hours

    /**
     * Fetch latest version info from SMS NOC platform.
     */
    public static function getRemoteInfo($force = false) {
        if (!$force) {
            try {
                $cached = Capsule::table('mod_smsnoc_settings')
                    ->where('setting_key', self::$cache_key)->first();
                if ($cached) {
                    $data = json_decode($cached->setting_value, true);
                    if ($data && isset($data['checked_at']) && (time() - $data['checked_at']) < self::$cache_ttl) {
                        return $data;
                    }
                }
            } catch (\Exception $e) {}
        }

        $url = self::$api_endpoint . '?slug=smsnoc-whmcs-module&platform=whmcs';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300 || empty($response)) {
            $errorData = ['error' => true, 'checked_at' => time()];
            self::cacheResult($errorData);
            return null;
        }

        $body = json_decode($response, true);
        if (empty($body) || empty($body['version'])) {
            $errorData = ['error' => true, 'checked_at' => time()];
            self::cacheResult($errorData);
            return null;
        }

        $info = [
            'version'      => $body['version'],
            'download_url' => $body['download_url'] ?? '',
            'description'  => $body['description'] ?? '',
            'changelog'    => $body['changelog'] ?? '',
            'force_update' => !empty($body['force_update']),
            'min_version'  => $body['min_version'] ?? '0.0.0',
            'last_updated' => $body['last_updated'] ?? '',
            'checked_at'   => time(),
        ];

        self::cacheResult($info);
        return $info;
    }

    private static function cacheResult($data) {
        try {
            Capsule::table('mod_smsnoc_settings')->updateOrInsert(
                ['setting_key' => self::$cache_key],
                ['setting_value' => json_encode($data), 'updated_at' => date('Y-m-d H:i:s')]
            );
        } catch (\Exception $e) {}
    }

    /**
     * Get current installed module version.
     */
    public static function getCurrentVersion() {
        return '1.0.0'; // Must match smsnoc_config()['version']
    }

    /**
     * Check if an update is available.
     */
    public static function hasUpdate($force = false) {
        $info = self::getRemoteInfo($force);
        if (!$info || !empty($info['error'])) return false;
        return version_compare($info['version'], self::getCurrentVersion(), '>');
    }

    /**
     * Check if force update is required (blocks module usage).
     */
    public static function isForceUpdateRequired() {
        $info = self::getRemoteInfo();
        if (!$info || !empty($info['error'])) return false;
        return $info['force_update'] && version_compare($info['version'], self::getCurrentVersion(), '>');
    }

    /**
     * Get update status for dashboard display.
     */
    public static function getUpdateStatus() {
        $info = self::getRemoteInfo();
        $current = self::getCurrentVersion();
        if (!$info || !empty($info['error'])) {
            return [
                'status'     => 'unknown',
                'current'    => $current,
                'latest'     => $current,
                'has_update' => false,
            ];
        }
        return [
            'status'       => 'ok',
            'current'      => $current,
            'latest'       => $info['version'],
            'has_update'   => version_compare($info['version'], $current, '>'),
            'force_update' => $info['force_update'],
            'download_url' => $info['download_url'],
            'changelog'    => $info['changelog'],
            'checked_at'   => $info['checked_at'] ?? 0,
        ];
    }

    /**
     * Render update notice HTML for WHMCS admin dashboard.
     */
    public static function renderUpdateNotice() {
        $status = self::getUpdateStatus();
        if (!$status['has_update']) return '';

        $isForce = $status['force_update'] ?? false;
        $borderColor = $isForce ? 'var(--snoc-red)' : 'var(--snoc-amber)';
        $bgColor = $isForce ? 'rgba(239,68,68,0.1)' : 'rgba(245,158,11,0.1)';
        $icon = $isForce ? '🚨' : '🔔';
        $label = $isForce ? 'REQUIRED UPDATE' : 'Update Available';
        $btnColor = $isForce ? 'background:var(--snoc-red)' : 'background:var(--snoc-amber)';

        $html = '<div class="snoc-alert" style="border:1px solid ' . $borderColor . ';background:' . $bgColor . ';border-radius:12px;padding:16px;margin-bottom:16px">';
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
        $html .= '<div>';
        $html .= '<strong style="font-size:14px">' . $icon . ' ' . $label . '</strong><br />';
        $html .= '<span style="font-size:12px;color:var(--snoc-muted)">SMS NOC Gateway v<strong>' . htmlspecialchars($status['latest']) . '</strong> is available. You are running v' . htmlspecialchars($status['current']) . '.</span>';
        if (!empty($status['changelog'])) {
            $html .= '<br /><span style="font-size:11px;color:var(--snoc-muted)">' . htmlspecialchars(substr($status['changelog'], 0, 200)) . '</span>';
        }
        $html .= '</div>';
        if (!empty($status['download_url'])) {
            $html .= '<a href="' . htmlspecialchars($status['download_url']) . '" target="_blank" class="snoc-btn" style="' . $btnColor . ';color:#fff;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;white-space:nowrap">⬇️ Download v' . htmlspecialchars($status['latest']) . '</a>';
        }
        $html .= '</div></div>';

        if ($isForce) {
            $html .= '<div class="snoc-alert" style="border:1px solid var(--snoc-red);background:rgba(239,68,68,0.05);border-radius:12px;padding:12px;margin-bottom:16px">';
            $html .= '<strong style="color:var(--snoc-red)">⚠️ This is a required update.</strong> ';
            $html .= '<span style="font-size:12px">Some features may be restricted until you update to v' . htmlspecialchars($status['latest']) . '.</span>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render compact version badge for dashboard header.
     */
    public static function renderVersionBadge() {
        $status = self::getUpdateStatus();
        if ($status['has_update']) {
            $color = ($status['force_update'] ?? false) ? 'snoc-badge-err' : 'snoc-badge-warn';
            return '<span class="snoc-badge ' . $color . '" style="font-size:10px;margin-left:8px" title="Update available: v' . htmlspecialchars($status['latest']) . '">⬆ v' . htmlspecialchars($status['latest']) . ' available</span>';
        }
        return '<span class="snoc-badge snoc-badge-ok" style="font-size:10px;margin-left:8px">✅ Up to date</span>';
    }
}
