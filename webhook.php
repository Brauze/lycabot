<?php
/**
 * LycaPay WhatsApp Bot Webhook Handler
 * Handles incoming WhatsApp messages from Twilio
 */

define('LYCAPAY_BOT', true);
require_once 'config.php';
require_once 'bot.php'; // Ensure this file defines the LycaPayBot class

// Set headers
header('Content-Type: application/xml');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Log the incoming request
    $rawInput = file_get_contents('php://input');
    Logger::info("Webhook received", [
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => getallheaders(),
        'body' => $rawInput,
        'get' => $_GET,
        'post' => $_POST
    ]);
    
    // Validate Twilio signature (optional but recommended for production)
    if (ENVIRONMENT === 'production') {
        $isValid = validateTwilioSignature();
        if (!$isValid) {
            Logger::warning("Invalid Twilio signature");
            http_response_code(403);
            exit;
        }
    }
    
    // Handle GET request (webhook verification)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        handleWebhookVerification();
        exit;
    }
    
    // Handle POST request (incoming message)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handleIncomingMessage();
    } else {
        Logger::warning("Unsupported HTTP method: " . $_SERVER['REQUEST_METHOD']);
        http_response_code(405);
        echo "Method not allowed";
    }
    
} catch (Exception $e) {
    Logger::error("Webhook error: " . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
}

/**
* Validate Twilio signature for security
*/
function validateTwilioSignature() {
$signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
$url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// Build data string
$data = '';
foreach ($_POST as $key => $value) {
$data .= $key . $value;
}

// Calculate expected signature
$expectedSignature = base64_encode(hash_hmac('sha1', $url . $data, TWILIO_AUTH_TOKEN, true));

return hash_equals($expectedSignature, $signature);
}

/**
* Handle webhook verification (for initial setup)
*/
function handleWebhookVerification() {
Logger::info("Webhook verification request");

echo '
<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
    echo '<Message>LycaPay WhatsApp Bot is ready!</Message>';
    echo '</Response>';
}

/**
* Handle incoming WhatsApp message
*/
function handleIncomingMessage() {
// Extract message data from Twilio webhook
$messageData = extractMessageData();

if (!$messageData) {
Logger::warning("No valid message data found");
echo '
<?xml version="1.0" encoding="UTF-8"?>
<Response></Response>';
return;
}

// Check for duplicate messages (Twilio sometimes sends duplicates)
if (isDuplicateMessage($messageData['MessageSid'])) {
Logger::info("Duplicate message ignored", ['sid' => $messageData['MessageSid']]);
echo '
<?xml version="1.0" encoding="UTF-8"?>
<Response></Response>';
return;
}

// Process the message
$bot = new LycaPayBot();
$bot->processMessage(
$messageData['From'],
$messageData['Body'],
$messageData['MessageSid']
);

// Store message ID to prevent duplicates
storeMessageId($messageData['MessageSid']);

// Return empty TwiML response
echo '
<?xml version="1.0" encoding="UTF-8"?>
<Response></Response>';
}

/**
* Extract message data from Twilio webhook
*/
function extractMessageData() {
$requiredFields = ['From', 'Body', 'MessageSid'];
$messageData = [];

foreach ($requiredFields as $field) {
if (empty($_POST[$field])) {
Logger::warning("Missing required field: $field", $_POST);
return null;
}
$messageData[$field] = $_POST[$field];
}

// Clean up the 'From' number (remove 'whatsapp:' prefix)
$messageData['From'] = str_replace('whatsapp:', '', $messageData['From']);

// Add optional fields
$optionalFields = ['ProfileName', 'WaId', 'AccountSid', 'NumMedia'];
foreach ($optionalFields as $field) {
$messageData[$field] = $_POST[$field] ?? null;
}

Logger::info("Message data extracted", $messageData);

return $messageData;
}

/**
* Check if message is duplicate
*/
function isDuplicateMessage($messageSid) {
try {
$config = Config::getInstance();
$db = $config->getDatabase();

$stmt = $db->prepare("
SELECT COUNT(*) FROM message_logs
WHERE webhook_data->>'$.MessageSid' = ?
AND created_date > DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$stmt->execute([$messageSid]);

return $stmt->fetchColumn() > 0;

} catch (Exception $e) {
Logger::error("Error checking duplicate message: " . $e->getMessage());
return false; // Allow processing if we can't check
}
}

/**
* Store message ID to track duplicates
*/
function storeMessageId($messageSid) {
try {
$config = Config::getInstance();
$db = $config->getDatabase();

$stmt = $db->prepare("
UPDATE message_logs
SET webhook_data = JSON_SET(COALESCE(webhook_data, '{}'), '$.MessageSid', ?)
WHERE phone_number = ? AND created_date > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
ORDER BY created_date DESC
LIMIT 1
");
$stmt->execute([$messageSid, $_POST['From'] ?? '']);

} catch (Exception $e) {
Logger::error("Error storing message ID: " . $e->getMessage());
}
}

/**
* Health check endpoint
*/
if (isset($_GET['health']) && $_GET['health'] === 'check') {
try {
$config = Config::getInstance();
$db = $config->getDatabase();

// Test database connection
$stmt = $db->query("SELECT 1");
$stmt->fetch();

// Test API configuration
$apiUrl = $config->getLycaApiUrl();
$apiKey = $config->getLycaApiKey();

$health = [
'status' => 'healthy',
'timestamp' => date('Y-m-d H:i:s'),
'version' => BOT_VERSION,
'environment' => ENVIRONMENT,
'database' => 'connected',
'api_configured' => !empty($apiKey),
'api_url' => $apiUrl
];

header('Content-Type: application/json');
echo json_encode($health, JSON_PRETTY_PRINT);

} catch (Exception $e) {
http_response_code(500);
header('Content-Type: application/json');
echo json_encode([
'status' => 'unhealthy',
'error' => $e->getMessage(),
'timestamp' => date('Y-m-d H:i:s')
]);
}
exit;
}

/**
* Status endpoint for monitoring
*/
if (isset($_GET['status']) && $_GET['status'] === 'check') {
try {
$config = Config::getInstance();
$db = $config->getDatabase();

// Get basic statistics
$stmt = $db->query("SELECT COUNT(*) as total_users FROM users WHERE status = 'active'");
$userCount = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) as total_transactions FROM transactions WHERE created_date > DATE_SUB(NOW(),
INTERVAL 24 HOUR)");
$transactionCount = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) as total_messages FROM message_logs WHERE created_date > DATE_SUB(NOW(), INTERVAL 24
HOUR)");
$messageCount = $stmt->fetchColumn();

$status = [
'bot_status' => 'running',
'timestamp' => date('Y-m-d H:i:s'),
'stats_24h' => [
'active_users' => (int)$userCount,
'transactions' => (int)$transactionCount,
'messages' => (int)$messageCount
],
'uptime' => getSystemUptime()
];

header('Content-Type: application/json');
echo json_encode($status, JSON_PRETTY_PRINT);

} catch (Exception $e) {
http_response_code(500);
header('Content-Type: application/json');
echo json_encode([
'error' => $e->getMessage(),
'timestamp' => date('Y-m-d H:i:s')
]);
}
exit;
}

/**
* Get system uptime (simplified)
*/
function getSystemUptime() {
if (function_exists('sys_getloadavg')) {
$load = sys_getloadavg();
return [
'load_average' => $load[0] ?? 0,
'server_time' => date('Y-m-d H:i:s')
];
}

return [
'server_time' => date('Y-m-d H:i:s')
];
}

/**
* Test endpoint for development
*/
if (isset($_GET['test']) && ENVIRONMENT === 'development') {
$testMessage = $_GET['message'] ?? 'menu';
$testPhone = $_GET['phone'] ?? '+256772123456';

try {
Logger::info("Test message initiated", [
'phone' => $testPhone,
'message' => $testMessage
]);

$bot = new LycaPayBot();
$bot->processMessage($testPhone, $testMessage, 'test_' . time());

echo json_encode([
'status' => 'success',
'message' => 'Test message processed',
'phone' => $testPhone,
'input' => $testMessage
]);

} catch (Exception $e) {
http_response_code(500);
echo json_encode([
'status' => 'error',
'message' => $e->getMessage()
]);
}
exit;
}

?>