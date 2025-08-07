<?php
// webhook.php Ã¢â‚¬â€ Live bot-enabled WhatsApp handler with fallback response

// Log raw input
file_put_contents(__DIR__ . '/log.txt', date('Y-m-d H:i:s') . " - POST: " . json_encode($_POST) . PHP_EOL, FILE_APPEND);

require_once 'config.php';
require_once 'bot.php'; // Make sure this contains LycaPayBot and its logic

// Ensure POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

$data = $_POST;
$from = isset($data['From']) ? str_replace('whatsapp:', '', $data['From']) : null;
$body = isset($data['Body']) ? trim($data['Body']) : null;

if (!$from || !$body) {
    http_response_code(400);
    echo "Missing 'From' or 'Body'";
    exit;
}

$responseText = "Sorry, we couldn't process your request.";

try {
    $bot = new LycaPayBot();
    // Pass true to $returnOnly to get the response directly
    $responseText = $bot->handleIncomingMessage($from, $body, true);

    if (!$responseText) {
        $responseText = "Thanks, we got your message.";
    }
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/error_log.txt', date('Y-m-d H:i:s') . " - Bot Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    $responseText = $bot->getErrorMessage(); // Use the error message from the bot
}

// Send valid TwiML response
header('Content-Type: text/xml');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
echo '<Message>' . htmlspecialchars($responseText) . '</Message>';
echo '</Response>';
exit;
