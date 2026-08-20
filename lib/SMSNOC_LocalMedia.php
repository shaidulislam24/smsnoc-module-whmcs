<?php
if (!defined("WHMCS")) die("This file cannot be accessed directly");

require_once __DIR__ . '/SMSNOC_API.php';

class SMSNOC_LocalMedia {

    private static function getSystemUrl() {
        try {
            return rtrim(\WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value') ?: '', '/');
        } catch (\Exception $e) {
            return '';
        }
    }

    private static function ensureAudioDir() {
        $dir = dirname(__DIR__) . '/generated-audio';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [false, '', ''];
        }

        @file_put_contents($dir . '/index.html', '');
        $htaccess = "Options -Indexes\n"
            . "AddType audio/wav .wav\n"
            . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|cgi|pl|py|sh|exe)$\">\n"
            . "Deny from all\n"
            . "</FilesMatch>\n";
        @file_put_contents($dir . '/.htaccess', $htaccess);

        $systemUrl = self::getSystemUrl();
        if ($systemUrl === '') {
            return [false, '', ''];
        }

        return [true, $dir, $systemUrl . '/modules/addons/smsnoc/generated-audio'];
    }

    /**
     * Extract a strict WAV audio URL from the TTS API response.
     * Only accepts public HTTP(S) URLs ending in .wav.
     */
    private static function pickWavUrl($result) {
        if (empty($result['success']) || !is_array($result)) {
            return '';
        }

        $fmt = strtolower(trim((string) ($result['format'] ?? '')));
        if ($fmt !== '' && $fmt !== 'wav') {
            return '';
        }

        $candidates = [
            trim((string) ($result['audio_url'] ?? '')),
            trim((string) ($result['url'] ?? '')),
            trim((string) ($result['file_url'] ?? '')),
        ];

        foreach ($candidates as $url) {
            if ($url === '' || !preg_match('#^https?://.+#i', $url)) {
                continue;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path !== '' && preg_match('/\.wav$/i', $path)) {
                return $url;
            }
        }

        return '';
    }

    /**
     * Convert text to a WAV voice audio URL via the SMS NOC TTS API.
     * Always requests WAV. Returns a public URL to the .wav file.
     */
    public static function createVoiceUrlFromText($message, $language = 'en', $gender = 'female', $format = 'wav', $apiKey = '') {
        $message = trim((string) $message);
        if ($message === '' || strlen($message) > 2000 || $apiKey === '') {
            return '';
        }

        $api = new SMSNOC_API($apiKey);

        // ── Step 1: Get a public WAV URL from the backend ──
        $remoteResult = $api->text_to_speech($message, $language, $gender, 'wav', false);
        logActivity('[SMSNOC TTS] Step 1: ' . json_encode(array_diff_key($remoteResult ?: [], ['audio_base64' => 1])));

        $remoteUrl = self::pickWavUrl($remoteResult);
        if ($remoteUrl !== '') {
            logActivity('[SMSNOC TTS] ✓ Got remote WAV: ' . $remoteUrl);
            return $remoteUrl;
        }

        $remoteError = strtolower(trim((string) ($remoteResult['error'] ?? '')));
        if ($remoteError !== '' && strpos($remoteError, 'ffmpeg') !== false) {
            logActivity('[SMSNOC TTS] ✗ Backend cannot generate WAV right now: ' . $remoteError);
            return '';
        }

        // ── Step 2: Get base64 WAV and save locally ──
        logActivity('[SMSNOC TTS] Step 1 failed, trying base64 fallback...');
        $result = $api->text_to_speech($message, $language, $gender, 'wav', true);

        if (empty($result['success']) || empty($result['audio_base64'])) {
            logActivity('[SMSNOC TTS] ✗ Both steps failed.');
            return '';
        }

        $actualFormat = strtolower(trim((string) ($result['format'] ?? 'wav')));
        if ($actualFormat !== 'wav') {
            logActivity('[SMSNOC TTS] ✗ Rejected non-WAV: ' . $actualFormat);
            return '';
        }

        $binary = base64_decode((string) $result['audio_base64'], true);
        if ($binary === false || strlen($binary) < 44) {
            logActivity('[SMSNOC TTS] ✗ Invalid base64 data');
            return '';
        }

        if (substr($binary, 0, 4) !== 'RIFF' || substr($binary, 8, 4) !== 'WAVE') {
            logActivity('[SMSNOC TTS] ✗ Not valid WAV (bad RIFF/WAVE header)');
            return '';
        }

        [$ok, $dir, $baseUrl] = self::ensureAudioDir();
        if (!$ok) {
            logActivity('[SMSNOC TTS] ✗ Could not create audio directory');
            return '';
        }

        $hash = md5($language . '|' . $gender . '|wav|' . $message);
        $filename = 'tts-' . $hash . '.wav';
        $filePath = $dir . '/' . $filename;

        if (!file_exists($filePath)) {
            if (@file_put_contents($filePath, $binary) === false) {
                logActivity('[SMSNOC TTS] ✗ Write failed: ' . $filePath);
                return '';
            }
            @chmod($filePath, 0644);
        }

        logActivity('[SMSNOC TTS] ✓ Saved local WAV: ' . $baseUrl . '/' . $filename);
        return $baseUrl . '/' . $filename;
    }
}
