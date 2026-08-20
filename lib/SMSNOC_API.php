<?php
/**
 * SMS NOC API Client for WHMCS v1.0.0
 * Hardcoded API endpoint: https://smsnoc.com/api/v1
 */

class SMSNOC_API {

    private $api_url = 'https://smsnoc.com/api/v1';
    private $api_key;

    public function __construct($api_key = '') {
        $this->api_key = $api_key;
    }

    private function request($endpoint, $body = [], $method = 'POST') {
        if (empty($this->api_key)) {
            return ['success' => false, 'error' => 'API Key not configured'];
        }

        $url = $this->api_url . $endpoint;

        if ($method === 'GET' && !empty($body)) {
            $url .= '?' . http_build_query($body);
            $body = [];
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->api_key,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($body)) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) return ['success' => false, 'error' => 'cURL error: ' . $error];

        $data = json_decode($response, true) ?: [];

        if ($httpCode >= 200 && $httpCode < 300) {
            return array_merge(['success' => true], $data);
        }

        // Handle specific HTTP error codes with clear messages
        $errorMsg = $data['error'] ?? $data['message'] ?? "HTTP $httpCode";
        if ($httpCode === 403) {
            $errorMsg = 'Access denied: ' . ($data['message'] ?? 'Resource not authorized for your account. Check your Sender ID, Caller ID, or Device ID.');
        } elseif ($httpCode === 401) {
            $errorMsg = 'Authentication failed: Invalid or expired API key.';
        } elseif ($httpCode === 402) {
            $errorMsg = 'Insufficient balance: ' . ($data['message'] ?? 'Top up your account.');
        } elseif ($httpCode === 429) {
            $errorMsg = 'Rate limit exceeded. Please wait and retry.';
        }

        return ['success' => false, 'error' => $errorMsg, 'http_code' => $httpCode];
    }

    // ── SMS ──
    public function send_sms($to, $message, $sender_id = '') {
        $body = ['to' => $to, 'message' => $message];
        // Only include sender_id if explicitly provided and non-empty
        // If omitted, the backend will auto-select from user's approved/assigned senders
        if (!empty($sender_id)) $body['sender_id'] = $sender_id;
        return $this->request('/send-sms', $body);
    }

    // ── Voice ──
    public function send_voice($to, $voice_file_url, $caller_id = '', $retry = 0) {
        $body = ['to' => $to, 'voice_file_url' => $voice_file_url, 'retry' => $retry];
        // Only include caller_id if explicitly set — backend will auto-select from approved voice senders
        if (!empty($caller_id)) $body['caller_id'] = $caller_id;
        return $this->request('/send-voice', $body);
    }

    public function get_voice_report($blast_id) {
        return $this->request('/get-voice-report', ['blast_id' => $blast_id]);
    }

    // ── WhatsApp ──
    public function send_whatsapp($to, $message, $device_id = '', $media_url = '', $media_type = '') {
        $body = ['to' => $to, 'message' => $message];
        // Only include device_id if explicitly set — backend will auto-select from user's devices
        if (!empty($device_id)) $body['device_id'] = $device_id;
        if (!empty($media_url)) {
            $body['media_url'] = $media_url;
            if (!empty($media_type)) $body['media_type'] = $media_type;
        }
        return $this->request('/send-whatsapp', $body);
    }

    public function get_whatsapp_devices() {
        return $this->request('/send-whatsapp', ['action' => 'device_list'], 'GET');
    }

    public function get_whatsapp_status($device_id = '') {
        $params = ['action' => 'status'];
        if (!empty($device_id)) $params['device_id'] = $device_id;
        return $this->request('/send-whatsapp', $params, 'GET');
    }

    // ── Email ──
    public function send_email($to, $subject, $html_body = '', $text_body = '', $config_id = '') {
        $body = ['to' => $to, 'subject' => $subject];
        if (!empty($html_body)) $body['html_body'] = $html_body;
        if (!empty($text_body)) $body['text_body'] = $text_body;
        if (!empty($config_id)) $body['config_id'] = $config_id;
        return $this->request('/send-email', $body);
    }

    // ── OTP ──
    public function send_otp($phone, $purpose = 'verification') {
        return $this->request('/send-otp', ['phone' => $phone, 'purpose' => $purpose]);
    }

    public function verify_otp($phone, $code, $purpose = 'verification') {
        return $this->request('/verify-otp', ['phone' => $phone, 'code' => $code, 'purpose' => $purpose]);
    }

    // ── Text-to-Speech ──
    public function text_to_speech($text, $language = 'en', $gender = 'female', $format = 'wav', $return_base64 = false) {
        return $this->request('/tts', [
            'text'          => $text,
            'language'      => $language,
            'gender'        => $gender,
            'format'        => $format,
            'return_base64' => $return_base64,
        ]);
    }

    // ── Balance & Rates ──
    public function get_balance() {
        return $this->request('/balance', [], 'GET');
    }

    public function get_rates() {
        return $this->request('/rates', [], 'GET');
    }

    // ── History ──
    public function get_sms_history($limit = 25, $page = 1) {
        return $this->request('/sms/history', ['limit' => $limit, 'page' => $page], 'GET');
    }
}
