<?php
/**
 * LycaPay WhatsApp Bot Configuration File
 * Contains all configuration settings, database connection, and API settings
 */

// Prevent direct access
if (!defined('LYCAPAY_BOT')) {
    exit('Access denied');
}

// Environment Configuration
define('ENVIRONMENT', 'development'); // development, staging, production

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'lycapay_bot');
define('DB_USER', 'jmusiwa');
define('DB_PASS', 'Test2025');
define('DB_CHARSET', 'utf8mb4');

// Twilio WhatsApp API configuration
define('TWILIO_ACCOUNT_SID', 'ACa48b38d1e407d492da5c5c0b1245bb0d');
define('TWILIO_AUTH_TOKEN', 'b026df96ca69171ae5d08ae344f2989a');
define('TWILIO_WHATSAPP_NUMBER', 'whatsapp:+14155238886'); // Twilio Sandbox number

// Lyca Mobile API Configuration
define('LYCA_API_TEST_URL', 'http://172.18.9.25/recharge');
define('LYCA_API_PRODUCTION_URL', 'http://10.20.15.41:8080/reseller');
define('LYCA_API_KEY', '1c8328c286c8w198c1vk3a11b3989aaw');

// Bot Configuration
define('BOT_NAME', 'LycaPay');
define('BOT_VERSION', '1.0.0');
define('SESSION_TIMEOUT', 1800); // 30 minutes in seconds
define('MAX_RECHARGE_PER_HOUR', 2);
define('MIN_AIRTIME_AMOUNT', 500);

// Admin Configuration
define('ADMIN_EMAIL', 'cs@lycamobile.ug');
define('ADMIN_PHONE', '+256726100100');

// Security Configuration
define('JWT_SECRET', 'your_jwt_secret_key_here');
define('ENCRYPTION_KEY', 'your_encryption_key_here');
define('API_RATE_LIMIT', 100); // requests per minute

// Logging Configuration
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR
define('LOG_FILE', __DIR__ . '/logs/bot.log');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB

class Config {
    private static $instance = null;
    private $settings = [];
    private $pdo = null;
    
    private function __construct() {
        $this->loadDatabaseSettings();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get database connection
     */
    public function getDatabase() {
        if ($this->pdo === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);
            } catch (PDOException $e) {
                Logger::error("Database connection failed: " . $e->getMessage());
                throw new Exception("Database connection failed");
            }
        }
        return $this->pdo;
    }
    
    /**
     * Load settings from database
     */
    private function loadDatabaseSettings() {
        try {
            $pdo = $this->getDatabase();
            $stmt = $pdo->query("SELECT setting_key, setting_value, setting_type FROM bot_settings");
            
            while ($row = $stmt->fetch()) {
                $value = $row['setting_value'];
                
                // Convert based on type
                switch ($row['setting_type']) {
                    case 'number':
                        $value = is_numeric($value) ? (float)$value : 0;
                        break;
                    case 'boolean':
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'json':
                        $value = json_decode($value, true) ?: [];
                        break;
                }
                
                $this->settings[$row['setting_key']] = $value;
            }
        } catch (Exception $e) {
            Logger::warning("Could not load database settings: " . $e->getMessage());
        }
    }
    
    /**
     * Get setting value
     */
    public function get($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
    
    /**
     * Set setting value
     */
    public function set($key, $value, $type = 'string') {
        try {
            $pdo = $this->getDatabase();
            $stmt = $pdo->prepare("
                INSERT INTO bot_settings (setting_key, setting_value, setting_type) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = ?, setting_type = ?
            ");
            
            $valueStr = is_array($value) ? json_encode($value) : (string)$value;
            $stmt->execute([$key, $valueStr, $type, $valueStr, $type]);
            
            $this->settings[$key] = $value;
            return true;
        } catch (Exception $e) {
            Logger::error("Could not save setting $key: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get Lyca API URL based on environment
     */
    public function getLycaApiUrl() {
        $env = $this->get('api_environment', 'test');
        return $env === 'production' ? LYCA_API_PRODUCTION_URL : LYCA_API_TEST_URL;
    }
    
    /**
     * Get API key for Lyca Mobile
     */
    public function getLycaApiKey() {
        return $this->get('default_api_key', LYCA_API_KEY);
    }
}

/**
 * Simple Logger Class
 */
class Logger {
    private static $levels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3
    ];
    
    public static function log($level, $message, $context = []) {
        if (self::$levels[$level] < self::$levels[LOG_LEVEL]) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logMessage = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;
        
        // Ensure log directory exists
        $logDir = dirname(LOG_FILE);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Rotate log if too large
        if (file_exists(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_SIZE) {
            rename(LOG_FILE, LOG_FILE . '.' . date('Y-m-d-H-i-s'));
        }
        
        file_put_contents(LOG_FILE, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    public static function debug($message, $context = []) {
        self::log('DEBUG', $message, $context);
    }
    
    public static function info($message, $context = []) {
        self::log('INFO', $message, $context);
    }
    
    public static function warning($message, $context = []) {
        self::log('WARNING', $message, $context);
    }
    
    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context);
    }
}

/**
 * Utility Functions
 */
class Utils {
    /**
     * Generate unique transaction ID
     */
    public static function generateTransactionId() {
        return 'LYCA_' . time() . '_' . uniqid();
    }
    
    /**
     * Format phone number to standard format
     */
    public static function formatPhoneNumber($phone) {
        // Remove all non-digit characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Handle Uganda numbers
        if (strlen($phone) === 9 && substr($phone, 0, 1) === '7') {
            $phone = '256' . $phone;
        } elseif (strlen($phone) === 10 && substr($phone, 0, 2) === '07') {
            $phone = '256' . substr($phone, 1);
        } elseif (strlen($phone) === 13 && substr($phone, 0, 4) === '2567') {
            // Already in correct format
        } elseif (strlen($phone) === 12 && substr($phone, 0, 3) === '256') {
            // Missing a digit, might be wrong
        }
        
        return $phone;
    }
    
    /**
     * Format currency amount
     */
    public static function formatCurrency($amount) {
        return 'UGX ' . number_format($amount, 0);
    }
    
    /**
     * Generate secure random string
     */
    public static function generateSecureToken($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Sanitize user input
     */
    public static function sanitizeInput($input) {
        return trim(strip_tags($input));
    }
    
    /**
     * Check if phone number is valid Uganda number
     */
    public static function isValidUgandaNumber($phone) {
        $phone = self::formatPhoneNumber($phone);
        return preg_match('/^2567[0-9]{8}$/', $phone);
    }
}

// Initialize autoloading and error handling
spl_autoload_register(function ($className) {
    $file = __DIR__ . '/' . str_replace('\\', '/', $className) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Set error handling
set_error_handler(function ($severity, $message, $file, $line) {
    Logger::error("PHP Error: $message in $file:$line");
});

set_exception_handler(function ($exception) {
    Logger::error("Uncaught Exception: " . $exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
});

// Set timezone
date_default_timezone_set('Africa/Kampala');

// Define the application constant
define('LYCAPAY_BOT', true);

?>