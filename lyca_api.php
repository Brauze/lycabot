<?php
// lyca_api.php - Aryagami LTE (Lycamobile Uganda) API Integration with Logging, Retry, and Centralized Error Codes

require_once 'config.php';

class LycaMobileAPI {
    private $apiKey;
    private $baseUrl;
    private $maxRetries = 3;
    private $retryDelay = 2; // seconds

    public function __construct() {
        $config = Config::getInstance();
        $this->apiKey = $config->getLycaApiKey();
        $this->baseUrl = $config->getLycaApiUrl();
    }

    private function sendRequest($method, $endpoint, $payload = null) {
        $url = $this->baseUrl . $endpoint;
        $headers = [
            "API_KEY: {$this->apiKey}",
            "Content-Type: application/json"
        ];

        $attempts = 0;
        do {
            $attempts++;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            Logger::info("API Request", [
                'url' => $url,
                'method' => $method,
                'payload' => $payload,
                'response' => $response,
                'httpCode' => $httpCode,
                'attempt' => $attempts
            ]);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                if (!isset($data['status']) || $data['status'] !== 'SUCCESS') {
                    $code = $data['responseCode'] ?? 'Unknown';
                    $message = ErrorCodes::getMessage($code);
                    
                    if (in_array($code, ['-10017'])) { // Retry on technical error
                        sleep($this->retryDelay);
                        continue;
                    }
                    throw new Exception("API Error [$code]: $message");
                }

                return $data;
            } else {
                Logger::warning("HTTP Error", [
                    'url' => $url,
                    'code' => $httpCode,
                    'error' => $curlError
                ]);

                sleep($this->retryDelay); // Retry delay
            }

        } while ($attempts < $this->maxRetries);

        throw new Exception("API request failed after {$this->maxRetries} attempts.");
    }

    public function getWalletBalance() {
        return $this->sendRequest('GET', '/check_reseller_float_wallet_balance/');
    }

    public function getSubscriptionInfo($subscriptionId) {
        return $this->sendRequest('GET', "/get_subscription_info/{$subscriptionId}");
    }

    public function getEbalanceSupportedPlans() {
        return $this->sendRequest('GET', '/get_float_enabled_plans/FloatBundle');
    }

    public function purchaseBundle($subscriptionId, $serviceBundleToken, $transactionId) {
        $payload = [
            "subscriptionId" => $subscriptionId,
            "immediateRecharge" => false,
            "transactionId" => $transactionId,
            "serviceBundleToken" => $serviceBundleToken
        ];
        return $this->sendRequest('POST', '/efloat_reseller_request_direct_v1/', $payload);
    }

    public function purchaseAirtime($subscriptionId, $amount, $transactionId) {
        $payload = [
            "ebalanceAmount" => $amount,
            "subscriptionId" => $subscriptionId,
            "immediateRecharge" => false,
            "transactionId" => $transactionId
        ];
        return $this->sendRequest('POST', '/reseller_request_ebalance_direct_v1/', $payload);
    }

    public function checkTransactionStatus($transactionId, $subscriptionId) {
        return $this->sendRequest('GET', "/check_ebalance_transaction_status/{$transactionId}/{$subscriptionId}");
    }
}

class ErrorCodes {
    private static $errors = [
        '1' => 'SUCCESS',
        '-10001' => 'INVALID_PARAMS',
        '-10002' => 'INVALID_SUBSCRIPTION',
        '-10003' => 'Top-up value is less than minimum 500 UGX',
        '-10005' => 'Invalid API Key',
        '-10006' => 'Invalid Transaction ID',
        '-10010' => 'Subscriber has reached maximum recharge limit for the hour',
        '-10016' => 'Inactive Subscription',
        '-10017' => 'Technical Error',
        '-10021' => 'Reseller Not Found',
        '-10022' => 'Wallet Not Configured',
        '-10023' => 'Failed to Deduct Ebalance from Wallet',
        '-10024' => 'Recharge Service Unavailable',
        '-10029' => 'Plan Group Not Found',
        '-10030' => 'Reseller Balance Insufficient',
        '-10031' => 'Plans Not Available for Float Channel',
        '-10032' => 'Recharge Service Unavailable',
        '-10033' => 'Recharge Already Found with this Transaction ID',
        '-10035' => 'Second Recharge of Same Amount Not Allowed within 5 Min',
        '-10036' => 'Second Recharge of Same Bundle Not Allowed within 5 Min',
        '-10037' => 'User Documents Not Approved',
    ];

    public static function getMessage($code) {
        return self::$errors[$code] ?? 'Unknown Error';
    }
}