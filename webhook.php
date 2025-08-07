<?php
/**
 * LycaPay WhatsApp Bot Webhook Handler
 * Handles incoming WhatsApp messages from Twilio
 */

define('LYCAPAY_BOT', true);
require_once 'config.php';
require_once 'bot.php';

// Set headers for proper response
header('Content-Type: application/xml; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Twilio-Signature');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Log the incoming request for debugging
    $rawInput = file_get_contents('php://input');
    $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
    
    Logger::info("Webhook received", [
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => $allHeaders,
        'post' => $_POST,
        'get' => $_GET,
        'raw_input' => $rawInput,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set'
    ]);
    
    // Validate Twilio signature for security in production
    if (ENVIRONMENT === 'production') {
        $isValid = validateTwilioSignature();
        if (!$isValid) {
            Logger::warning("Invalid Twilio signature", [
                'expected_sig' => $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? 'missing',
                'url' => getCurrentUrl()
            ]);
            http_response_code(403);
            echo '<?xml version="1.0" encoding="UTF-8"?><Response><Message>Unauthorized</Message></Response>';
            exit;
        }
    }
    
    // Handle different request methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetRequest();
            break;
        case 'POST':
            handleIncomingMessage();
            break;
        default:
            Logger::warning("Unsupported HTTP method: " . $_SERVER['REQUEST_METHOD']);
            http_response_code(405);
            echo '<?xml version="1.0" encoding="UTF-8"?><Response><Message>Method not allowed</Message></Response>';
    }
    
} catch (Exception $e) {
    Logger::error("Webhook error: " . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
}
