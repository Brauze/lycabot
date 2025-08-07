<?php
/**
 * LycaPay WhatsApp Bot - Complete Bot Logic
 * Handles all bot interactions and business logic
 */

require_once 'config.php';
require_once 'lyca_api.php';
require_once 'vendor/autoload.php';

use Twilio\Rest\Client;

class LycaPayBot {
    private $config;
    private $db;
    private $user;
    private $session;
    private $twilio;
    
    public function __construct() {
        $this->config = Config::getInstance();
        $this->db = $this->config->getDatabase();
        $this->twilio = new Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
    }
    
    /**
     * Process incoming WhatsApp message
     */
    public function processMessage($from, $message, $messageId = null) {
        try {
            $startTime = microtime(true);
            Logger::info("Processing message from $from: $message");
            
            // Clean phone number
            $from = $this->cleanPhoneNumber($from);
            
            // Log incoming message
            $this->logMessage($from, 'incoming', $message, null, $messageId);
            
            // Get or create user
            $this->user = $this->getOrCreateUser($from);
            
            // Get user session
            $this->session = $this->getUserSession($this->user['id']);
            
            // Clean and normalize message
            $message = Utils::sanitizeInput($message);
            $command = strtolower(trim($message));
            
            // Handle commands
            $response = $this->handleCommand($command, $message);
            
            // Send response
            if ($response) {
                $this->sendMessage($from, $response);
                $processingTime = round((microtime(true) - $startTime) * 1000);
                $this->logMessage($from, 'outgoing', $response, $processingTime);
            }
            
            // Update user activity
            $this->updateUserActivity($this->user['id']);
            
        } catch (Exception $e) {
            Logger::error("Error processing message: " . $e->getMessage(), [
                'from' => $from,
                'message' => $message
            ]);
            $this->sendMessage($from, "Sorry, I encountered an error. Please try again later or contact support.");
        }
    }
    
    /**
     * Send WhatsApp message via Twilio
     */
    private function sendMessage($to, $message) {
        try {
            $this->twilio->messages->create(
                'whatsapp:' . $to,
                [
                    'from' => TWILIO_WHATSAPP_NUMBER,
                    'body' => $message
                ]
            );
            Logger::info("Message sent successfully", ['to' => $to]);
        } catch (Exception $e) {
            Logger::error("Failed to send message: " . $e->getMessage(), ['to' => $to]);
            throw $e;
        }
    }
    
    /**
     * Get or create user
     */
    private function getOrCreateUser($phoneNumber) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE phone_number = ?");
            $stmt->execute([$phoneNumber]);
            $user = $stmt->fetch();
            
            if (!$user) {
                // Create new user
                $stmt = $this->db->prepare("
                    INSERT INTO users (phone_number, registration_date, last_activity, status) 
                    VALUES (?, NOW(), NOW(), 'active')
                ");
                $stmt->execute([$phoneNumber]);
                
                $userId = $this->db->lastInsertId();
                
                // Get the created user
                $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                Logger::info("New user created", ['phone' => $phoneNumber, 'id' => $userId]);
            }
            
            return $user;
        } catch (Exception $e) {
            Logger::error("Error getting/creating user: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get user session
     */
    private function getUserSession($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM bot_sessions 
                WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $stmt->execute([$userId]);
            $session = $stmt->fetch();
            
            if (!$session) {
                // Create new session
                $expiresAt = date('Y-m-d H:i:s', time() + SESSION_TIMEOUT);
                $stmt = $this->db->prepare("
                    INSERT INTO bot_sessions (user_id, session_state, expires_at, created_date, updated_date) 
                    VALUES (?, 'idle', ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                    session_state = 'idle', expires_at = ?, updated_date = NOW()
                ");
                $stmt->execute([$userId, $expiresAt, $expiresAt]);
                
                // Get the created session
                $stmt = $this->db->prepare("SELECT * FROM bot_sessions WHERE user_id = ?");
                $stmt->execute([$userId]);
                $session = $stmt->fetch();
            }
            
            return $session;
        } catch (Exception $e) {
            Logger::error("Error getting user session: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update user session
     */
    private function updateSession($userId, $state, $action = null, $data = []) {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + SESSION_TIMEOUT);
            $sessionData = empty($data) ? null : json_encode($data);
            
            $stmt = $this->db->prepare("
                UPDATE bot_sessions 
                SET session_state = ?, current_action = ?, session_data = ?, expires_at = ?, updated_date = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([$state, $action, $sessionData, $expiresAt, $userId]);
            
            // Update local session
            $this->session['session_state'] = $state;
            $this->session['current_action'] = $action;
            $this->session['session_data'] = $sessionData;
            
        } catch (Exception $e) {
            Logger::error("Error updating session: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Clear user session
     */
    private function clearUserSession($userId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE bot_sessions 
                SET session_state = 'idle', current_action = NULL, session_data = NULL, updated_date = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            Logger::error("Error clearing session: " . $e->getMessage());
        }
    }
    
    /**
     * Log message
     */
    private function logMessage($phoneNumber, $type, $content, $processingTime = null, $webhookData = null) {
        try {
            $userId = $this->user['id'] ?? null;
            $webhookJson = $webhookData ? json_encode($webhookData) : null;
            
            $stmt = $this->db->prepare("
                INSERT INTO message_logs (user_id, phone_number, message_type, message_content, webhook_data, processing_time_ms, created_date)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $phoneNumber, $type, $content, $webhookJson, $processingTime]);
        } catch (Exception $e) {
            Logger::error("Error logging message: " . $e->getMessage());
        }
    }
    
    /**
     * Update user activity
     */
    private function updateUserActivity($userId) {
        try {
            $stmt = $this->db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            Logger::error("Error updating user activity: " . $e->getMessage());
        }
    }
    
    /**
     * Clean phone number
     */
    private function cleanPhoneNumber($phone) {
        // Remove 'whatsapp:' prefix and other prefixes
        $phone = str_replace(['whatsapp:', '+'], '', $phone);
        return Utils::formatPhoneNumber($phone);
    }
    
    /**
     * Handle different commands and user inputs
     */
    private function handleCommand($command, $originalMessage) {
        // Check session state
        $state = $this->session['session_state'] ?? 'idle';
        $action = $this->session['current_action'] ?? null;
        $data = json_decode($this->session['session_data'] ?? '{}', true);
        
        // Handle states
        switch ($state) {
            case 'awaiting_number':
                return $this->handleNumberInput($originalMessage, $data);
                
            case 'selecting_bundle':
                return $this->handleBundleSelection($originalMessage, $data);
                
            case 'confirming_purchase':
                return $this->handlePurchaseConfirmation($originalMessage, $data);
                
            case 'entering_amount':
                return $this->handleAmountInput($originalMessage, $data);
                
            case 'confirming_airtime':
                return $this->handleAirtimeConfirmation($originalMessage, $data);
                
            case 'selecting_saved_number':
                return $this->handleSavedNumberSelection($originalMessage, $data);
                
            case 'idle':
            default:
                return $this->handleIdleCommands($command, $originalMessage);
        }
    }
    
    /**
     * Handle commands when user is in idle state
     */
    private function handleIdleCommands($command, $originalMessage) {
        switch ($command) {
            case 'start':
            case 'hello':
            case 'hi':
                return $this->getWelcomeMessage();
                
            case 'menu':
            case 'help':
                return $this->getMainMenu();
                
            case 'balance':
                return $this->checkBalance();
                
            case 'bundles':
            case '1':
                return $this->showBundles();
                
            case 'airtime':
            case '2':
                return $this->showAirtimeOptions();
                
            case 'history':
            case '3':
                return $this->showTransactionHistory();
                
            case 'support':
            case '4':
                return $this->getSupport();
                
            case 'profile':
            case '5':
                return $this->showProfile();
                
            case 'cancel':
            case 'stop':
                $this->clearUserSession($this->user['id']);
                return "❌ Operation cancelled. Send 'menu' to start over.";
                
            default:
                // Check if it's a phone number
                if (Utils::isValidUgandaNumber($originalMessage)) {
                    return $this->handleDirectNumber($originalMessage);
                }
                
                return $this->getUnknownCommandResponse();
        }
    }
    
    /**
     * Get welcome message
     */
    private function getWelcomeMessage() {
        $firstName = $this->user['first_name'] ? " " . $this->user['first_name'] : "";
        
        $welcomeMsg = "🎉 *Welcome to LycaPay{$firstName}!*\n\n";
        $welcomeMsg .= "Your one-stop solution for:\n";
        $welcomeMsg .= "📱 Data Bundle Purchases\n";
        $welcomeMsg .= "💰 Airtime Top-ups\n";
        $welcomeMsg .= "📊 Balance & History Checking\n\n";
        $welcomeMsg .= "💡 *Quick Start:*\n";
        $welcomeMsg .= "• Send 'menu' to see all options\n";
        $welcomeMsg .= "• Send any Uganda number to check info\n";
        $welcomeMsg .= "• Send 'balance' to check your wallet\n\n";
        $welcomeMsg .= "Ready to get started? Send *menu* 🚀";
        
        return $welcomeMsg;
    }
    
    /**
     * Get main menu
     */
    private function getMainMenu() {
        return "🏠 *LycaPay Main Menu*\n\n" .
               "1️⃣ Buy Data Bundles\n" .
               "2️⃣ Buy Airtime\n" .
               "3️⃣ Transaction History\n" .
               "4️⃣ Support\n" .
               "5️⃣ My Profile\n\n" .
               "💡 *Quick Tips:*\n" .
               "• Send any Uganda number to check subscriber info\n" .
               "• Send 'balance' to check your wallet\n" .
               "• Send 'cancel' anytime to stop current operation\n\n" .
               "What would you like to do? 🤔";
    }
    
    /**
     * Check balance
     */
    private function checkBalance() {
        try {
            $lycaApi = new LycaMobileAPI();
            $balance = $lycaApi->getWalletBalance();
            
            if ($balance && isset($balance['walletBalance'])) {
                return "💳 *Your Wallet Balance*\n\n" .
                       "💰 Available: " . Utils::formatCurrency($balance['walletBalance']) . "\n" .
                       "📅 Last Updated: " . date('Y-m-d H:i:s') . "\n\n" .
                       "Send 'menu' to continue 🏠";
            } else {
                return "❌ Could not retrieve balance at this time. Please try again later.";
            }
        } catch (Exception $e) {
            Logger::error("Error checking balance: " . $e->getMessage());
            return "❌ Could not check balance. Please try again or contact support.";
        }
    }
    
    /**
     * Show available bundles
     */
    private function showBundles() {
        try {
            $lycaApi = new LycaMobileAPI();
            $plans = $lycaApi->getEbalanceSupportedPlans();
            
            if (empty($plans)) {
                return "❌ Sorry, no data bundles are available at the moment. Please try again later.";
            }
            
            $response = "📱 *Available Data Bundles*\n\n";
            
            foreach ($plans as $index => $plan) {
                $response .= ($index + 1) . "️⃣ *" . $plan['serviceBundleName'] . "*\n";
                $response .= "   💰 " . Utils::formatCurrency($plan['serviceBundlePrice']) . "\n";
                $response .= "   📄 " . $plan['serviceBundleDescription'] . "\n\n";
            }
            
            $response .= "📝 *How to Purchase:*\n";
            $response .= "Select bundle number (1-" . count($plans) . ")\n\n";
            $response .= "💡 *Tip:* You can also send a phone number first to pre-select the recipient\n\n";
            $response .= "Send 'menu' to go back 🔙";
            
            // Store bundles in session
            $this->updateSession($this->user['id'], 'selecting_bundle', 'bundle_selection', [
                'plans' => $plans
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            Logger::error("Error fetching bundles: " . $e->getMessage());
            return "❌ Could not load bundles. Please try again later or contact support.";
        }
    }
    
    /**
     * Get customer saved numbers
     */
    private function getCustomerNumbers($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT subscription_id, subscriber_name, subscriber_surname 
                FROM customer_subscriptions 
                WHERE user_id = ? AND status = 'active'
                ORDER BY is_primary DESC, created_date DESC
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            Logger::error("Error getting customer numbers: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Save customer number
     */
    private function saveCustomerNumber($userId, $subscriptionId, $subscriberName = '') {
        try {
            $names = explode(' ', trim($subscriberName), 2);
            $firstName = $names[0] ?? '';
            $lastName = $names[1] ?? '';
            
            $stmt = $this->db->prepare("
                INSERT INTO customer_subscriptions (user_id, subscription_id, subscriber_name, subscriber_surname, created_date)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                subscriber_name = VALUES(subscriber_name), 
                subscriber_surname = VALUES(subscriber_surname)
            ");
            $stmt->execute([$userId, $subscriptionId, $firstName, $lastName]);
        } catch (Exception $e) {
            Logger::error("Error saving customer number: " . $e->getMessage());
        }
    }
    
    /**
     * Handle bundle selection
     */
    private function handleBundleSelection($input, $sessionData) {
        if (strtolower($input) === 'menu') {
            $this->clearUserSession($this->user['id']);
            return $this->getMainMenu();
        }
        
        $plans = $sessionData['plans'] ?? [];
        $selectedNumber = (int)$input - 1;
        
        if ($selectedNumber >= 0 && $selectedNumber < count($plans)) {
            $selectedPlan = $plans[$selectedNumber];
            
            // Check if user has saved numbers
            $customerNumbers = $this->getCustomerNumbers($this->user['id']);
            
            if (empty($customerNumbers)) {
                // Ask for number
                $this->updateSession($this->user['id'], 'awaiting_number', 'bundle_purchase', [
                    'selected_plan' => $selectedPlan
                ]);
                
                return "📱 *Bundle Selected:* " . $selectedPlan['serviceBundleName'] . "\n" .
                       "💰 *Price:* " . Utils::formatCurrency($selectedPlan['serviceBundlePrice']) . "\n\n" .
                       "Please enter the Uganda mobile number to recharge:\n\n" .
                       "📝 *Format Examples:*\n" .
                       "• 0772123456\n" .
                       "• 256772123456\n" .
                       "• +256772123456\n\n" .
                       "Send 'cancel' to abort 🚫";
            } else {
                // Show saved numbers
                $response = "📱 *Bundle Selected:* " . $selectedPlan['serviceBundleName'] . "\n" .
                           "💰 *Price:* " . Utils::formatCurrency($selectedPlan['serviceBundlePrice']) . "\n\n" .
                           "Select recipient number:\n\n";
                
                foreach ($customerNumbers as $index => $number) {
                    $name = trim(($number['subscriber_name'] ?? '') . ' ' . ($number['subscriber_surname'] ?? ''));
                    $response .= ($index + 1) . "️⃣ " . $number['subscription_id'] . 
                                " (" . ($name ?: 'Unknown') . ")\n";
                }
                
                $response .= "\n🆕 Enter 'new' for different number\n";
                $response .= "Send 'cancel' to abort 🚫";
                
                $this->updateSession($this->user['id'], 'selecting_saved_number', 'bundle_purchase', [
                    'selected_plan' => $selectedPlan,
                    'saved_numbers' => $customerNumbers
                ]);
                
                return $response;
            }
        } else {
            return "❌ Invalid selection. Please choose a number between 1 and " . count($plans) . "\n\nSend 'menu' to start over.";
        }
    }
    
    // Continue with remaining methods...
    private function handleSavedNumberSelection($input, $sessionData) {
        if (strtolower($input) === 'cancel') {
            $this->clearUserSession($this->user['id']);
            return "❌ Operation cancelled. Send 'menu' to start over.";
        }
        
        if (strtolower($input) === 'new') {
            $this->updateSession($this->user['id'], 'awaiting_number', $sessionData['selected_plan'] ? 'bundle_purchase' : 'airtime_purchase', $sessionData);
            
            return "📱 Please enter the new Uganda mobile number:\n\n" .
                   "📝 *Format Examples:*\n" .
                   "• 0772123456\n" .
                   "• 256772123456\n" .
                   "• +256772123456";
        }
        
        $savedNumbers = $sessionData['saved_numbers'] ?? [];
        $selectedIndex = (int)$input - 1;
        
        if ($selectedIndex >= 0 && $selectedIndex < count($savedNumbers)) {
            $selectedNumber = $savedNumbers[$selectedIndex];
            
            if (isset($sessionData['selected_plan'])) {
                return $this->confirmBundlePurchase($sessionData['selected_plan'], $selectedNumber['subscription_id']);
            } elseif (isset($sessionData['amount'])) {
                return $this->confirmAirtimePurchase($sessionData['amount'], $selectedNumber['subscription_id']);
            }
        }
        
        return "❌ Invalid selection. Please choose a valid number or enter 'new' for a different number.";
    }
    
    private function handleNumberInput($input, $sessionData) {
        if (strtolower($input) === 'cancel') {
            $this->clearUserSession($this->user['id']);
            return "❌ Operation cancelled. Send 'menu' to start over.";
        }
        
        $phoneNumber = Utils::formatPhoneNumber($input);
        
        if (!Utils::isValidUgandaNumber($phoneNumber)) {
            return "❌ Invalid Uganda mobile number format.\n\n" .
                   "📝 *Please use one of these formats:*\n" .
                   "• 0772123456\n" .
                   "• 256772123456\n" .
                   "• +256772123456\n\n" .
                   "Try again or send 'cancel' to abort.";
        }
        
        // Check if it's for bundle or airtime
        $action = $this->session['current_action'] ?? '';
        
        if ($action === 'bundle_purchase') {
            return $this->confirmBundlePurchase($sessionData['selected_plan'], $phoneNumber);
        } elseif ($action === 'airtime_purchase') {
            return $this->confirmAirtimePurchase($sessionData['amount'], $phoneNumber);
        }
        
        return "❌ Something went wrong. Please start over by sending 'menu'.";
    }
    
    private function confirmBundlePurchase($plan, $phoneNumber) {
        try {
            // Get subscriber info
            $lycaApi = new LycaMobileAPI();
            $subscriberInfo = $lycaApi->getSubscriptionInfo($phoneNumber);
            
            $subscriberName = '';
            if ($subscriberInfo && isset($subscriberInfo['subscriberName'])) {
                $subscriberName = trim($subscriberInfo['subscriberName'] . ' ' . ($subscriberInfo['subscriberSurname'] ?? ''));
                
                // Save customer number for future use
                $this->saveCustomerNumber($this->user['id'], $phoneNumber, $subscriberName);
            }
            
            $response = "🔍 *Purchase Confirmation*\n\n";
            $response .= "📱 *Bundle:* " . $plan['serviceBundleName'] . "\n";
            $response .= "💰 *Price:* " . Utils::formatCurrency($plan['serviceBundlePrice']) . "\n";
            $response .= "📞 *Number:* " . $phoneNumber . "\n";
            
            if ($subscriberName) {
                $response .= "👤 *Subscriber:* " . $subscriberName . "\n";
            }
            
            $response .= "\n📋 *Bundle Details:*\n";
            $response .= $plan['serviceBundleDescription'] . "\n\n";
            
            $response .= "✅ Confirm purchase?\n\n";
            $response .= "1️⃣ *YES* - Proceed with purchase\n";
            $response .= "2️⃣ *NO* - Cancel and go back\n\n";
            $response .= "Send your choice (1 or 2)";
            
            $this->updateSession($this->user['id'], 'confirming_purchase', 'bundle_purchase', [
                'plan' => $plan,
                'phone_number' => $phoneNumber,
                'subscriber_name' => $subscriberName
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            Logger::error("Error confirming bundle purchase: " . $e->getMessage());
            return "❌ Could not verify subscriber info. Please try again or contact support.\n\nSend 'menu' to start over.";
        }
    }
    
    private function handlePurchaseConfirmation($input, $sessionData) {
        $input = strtolower(trim($input));
        
        if ($input === 'cancel' || $input === '2' || $input === 'no') {
            $this->clearUserSession($this->user['id']);
            return "❌ Purchase cancelled. Send 'menu' to start over.";
        }
        
        if ($input === '1' || $input === 'yes') {
            return $this->processBundlePurchase($sessionData);
        }
        
        return "❓ Please send:\n1️⃣ *YES* to confirm\n2️⃣ *NO* to cancel\n\nOr send 'cancel' to abort.";
    }
    
    private function processBundlePurchase($sessionData) {
        try {
            $plan = $sessionData['plan'];
            $phoneNumber = $sessionData['phone_number'];
            $subscriberName = $sessionData['subscriber_name'] ?? '';
            
            // Generate transaction ID
            $transactionId = Utils::generateTransactionId();
            
            // Create transaction record
            $this->createTransaction($this->user['id'], 'bundle', $plan['serviceBundlePrice'], $phoneNumber, $transactionId, [
                'bundle_name' => $plan['serviceBundleName'],
                'bundle_token' => $plan['serviceBundleToken'],
                'subscriber_name' => $subscriberName
            ]);
            
            // Call Lyca API
            $lycaApi = new LycaMobileAPI();
            $result = $lycaApi->purchaseBundle($phoneNumber, $plan['serviceBundleToken'], $transactionId);
            
            if ($result && isset($result['status']) && $result['status'] === 'SUCCESS') {
                // Success
                $this->updateTransactionStatus($transactionId, 'success', $result);
                
                $response = "✅ *Purchase Successful!* 🎉\n\n";
                $response .= "📱 *Bundle:* " . $plan['serviceBundleName'] . "\n";
                $response .= "💰 *Amount:* " . Utils::formatCurrency($plan['serviceBundlePrice']) . "\n";
                $response .= "📞 *Number:* " . $phoneNumber . "\n";
                
                if ($subscriberName) {
                    $response .= "👤 *Subscriber:* " . $subscriberName . "\n";
                }
                
                $response .= "🔢 *Transaction ID:* " . $transactionId . "\n";
                $response .= "📅 *Date:* " . date('Y-m-d H:i:s') . "\n\n";
                $response .= "🎯 The bundle has been successfully activated!\n\n";
                $response .= "Need anything else? Send 'menu' 🏠";
                
            } else {
                // Failed
                $errorCode = $result['responseCode'] ?? 'Unknown';
                $this->updateTransactionStatus($transactionId, 'failed', $result);
                
                $response = "❌ *Purchase Failed*\n\n";
                $response .= "🔢 *Transaction ID:* " . $transactionId . "\n";
                $response .= "📄 *Reason:* " . ErrorCodes::getMessage($errorCode) . "\n\n";
                $response .= "💡 You can try again or contact support if the issue persists.\n\n";
                $response .= "Send 'menu' to try again or 'support' for help.";
            }
            
            $this->clearUserSession($this->user['id']);
            return $response;
            
        } catch (Exception $e) {
            Logger::error("Error processing bundle purchase: " . $e->getMessage());
            
            if (isset($transactionId)) {
                $this->updateTransactionStatus($transactionId, 'failed', ['error' => $e->getMessage()]);
            }
            
            $this->clearUserSession($this->user['id']);
            return "❌ Purchase failed due to a technical error. Please try again later or contact support.\n\nSend 'menu' to start over.";
        }
    }
    
    /**
     * Create transaction record
     */
    private function createTransaction($userId, $type, $amount, $subscriptionId, $transactionId, $metadata = []) {
        try {
            $bundleToken = $metadata['bundle_token'] ?? null;
            
            $stmt = $this->db->prepare("
                INSERT INTO transactions (
                    transaction_id, user_id, subscription_id, transaction_type, 
                    service_bundle_token, amount, status, created_date
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            
            $stmt->execute([
                $transactionId, $userId, $subscriptionId, $type, $bundleToken, $amount
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error creating transaction: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update transaction status
     */
    private function updateTransactionStatus($transactionId, $status, $result = null) {
        try {
            $lycaTransactionId = $result['transactionId'] ?? null;
            $errorCode = $result['responseCode'] ?? null;
            $errorMessage = $result['responseMessage'] ?? null;
            $completedDate = ($status === 'success') ? date('Y-m-d H:i:s') : null;
            
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET status = ?, lyca_transaction_id = ?, error_code = ?, error_message = ?, completed_date = ?
                WHERE transaction_id = ?
            ");
            
            $stmt->execute([$status, $lycaTransactionId, $errorCode, $errorMessage, $completedDate, $transactionId]);
            
        } catch (Exception $e) {
            Logger::error("Error updating transaction status: " . $e->getMessage());
        }
    }
    
    /**
     * Show airtime options
     */
    private function showAirtimeOptions() {
        $response = "💰 *Airtime Top-up*\n\n";
        $response .= "🎯 *How it works:*\n";
        $response .= "1️⃣ Enter the amount (Min: UGX 500)\n";
        $response .= "2️⃣ Enter the phone number\n";
        $response .= "3️⃣ Confirm and purchase\n\n";
        
        $response .= "💡 *Popular amounts:*\n";
        $response .= "• UGX 1,000\n";
        $response .= "• UGX 2,000\n";
        $response .= "• UGX 5,000\n";
        $response .= "• UGX 10,000\n\n";
        
        $response .= "💵 Please enter the amount you want to top up:";
        
        $this->updateSession($this->user['id'], 'entering_amount', 'airtime_purchase', []);
        
        return $response;
    }
    
    /**
     * Handle amount input for airtime
     */
    private function handleAmountInput($input, $sessionData) {
        if (strtolower($input) === 'cancel') {
            $this->clearUserSession($this->user['id']);
            return "❌ Operation cancelled. Send 'menu' to start over.";
        }
        
        // Extract numeric value
        $amount = (int)preg_replace('/[^0-9]/', '', $input);
        
        if ($amount < MIN_AIRTIME_AMOUNT) {
            return "❌ Minimum airtime amount is " . Utils::formatCurrency(MIN_AIRTIME_AMOUNT) . "\n\nPlease enter a valid amount or send 'cancel' to abort.";
        }
        
        if ($amount > 100000) { // Max 100k for safety
            return "❌ Maximum airtime amount is UGX 100,000 per transaction.\n\nPlease enter a valid amount or send 'cancel' to abort.";
        }
        
        // Check if user has saved numbers
        $customerNumbers = $this->getCustomerNumbers($this->user['id']);
        
        if (empty($customerNumbers)) {
            $this->updateSession($this->user['id'], 'awaiting_number', 'airtime_purchase', [
                'amount' => $amount
            ]);
            
            return "💰 *Amount:* " . Utils::formatCurrency($amount) . "\n\n" .
                   "📱 Please enter the Uganda mobile number to recharge:\n\n" .
                   "📝 *Format Examples:*\n" .
                   "• 0772123456\n" .
                   "• 256772123456\n" .
                   "• +256772123456\n\n" .
                   "Send 'cancel' to abort 🚫";
        } else {
            // Show saved numbers
            $response = "💰 *Amount:* " . Utils::formatCurrency($amount) . "\n\n";
            $response .= "Select recipient number:\n\n";
            
            foreach ($customerNumbers as $index => $number) {
                $name = trim(($number['subscriber_name'] ?? '') . ' ' . ($number['subscriber_surname'] ?? ''));
                $response .= ($index + 1) . "️⃣ " . $number['subscription_id'] . 
                            " (" . ($name ?: 'Unknown') . ")\n";
            }
            
            $response .= "\n🆕 Enter 'new' for different number\n";
            $response .= "Send 'cancel' to abort 🚫";
            
            $this->updateSession($this->user['id'], 'selecting_saved_number', 'airtime_purchase', [
                'amount' => $amount,
                'saved_numbers' => $customerNumbers
            ]);
            
            return $response;
        }
    }
    
    /**
     * Confirm airtime purchase
     */
    private function confirmAirtimePurchase($amount, $phoneNumber) {
        try {
            // Get subscriber info
            $lycaApi = new LycaMobileAPI();
            $subscriberInfo = $lycaApi->getSubscriptionInfo($phoneNumber);
            
            $subscriberName = '';
            if ($subscriberInfo && isset($subscriberInfo['subscriberName'])) {
                $subscriberName = trim($subscriberInfo['subscriberName'] . ' ' . ($subscriberInfo['subscriberSurname'] ?? ''));
                
                // Save customer number for future use
                $this->saveCustomerNumber($this->user['id'], $phoneNumber, $subscriberName);
            }
            
            $response = "🔍 *Airtime Purchase Confirmation*\n\n";
            $response .= "💰 *Amount:* " . Utils::formatCurrency($amount) . "\n";
            $response .= "📞 *Number:* " . $phoneNumber . "\n";
            
            if ($subscriberName) {
                $response .= "👤 *Subscriber:* " . $subscriberName . "\n";
            }
            
            $response .= "\n✅ Confirm airtime top-up?\n\n";
            $response .= "1️⃣ *YES* - Proceed with purchase\n";
            $response .= "2️⃣ *NO* - Cancel and go back\n\n";
            $response .= "Send your choice (1 or 2)";
            
            $this->updateSession($this->user['id'], 'confirming_airtime', 'airtime_purchase', [
                'amount' => $amount,
                'phone_number' => $phoneNumber,
                'subscriber_name' => $subscriberName
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            Logger::error("Error confirming airtime purchase: " . $e->getMessage());
            return "❌ Could not verify subscriber info. Please try again or contact support.\n\nSend 'menu' to start over.";
        }
    }
    
    /**
     * Handle airtime confirmation
     */
    private function handleAirtimeConfirmation($input, $sessionData) {
        $input = strtolower(trim($input));
        
        if ($input === 'cancel' || $input === '2' || $input === 'no') {
            $this->clearUserSession($this->user['id']);
            return "❌ Purchase cancelled. Send 'menu' to start over.";
        }
        
        if ($input === '1' || $input === 'yes') {
            return $this->processAirtimePurchase($sessionData);
        }
        
        return "❓ Please send:\n1️⃣ *YES* to confirm\n2️⃣ *NO* to cancel\n\nOr send 'cancel' to abort.";
    }
    
    /**
     * Process airtime purchase
     */
    private function processAirtimePurchase($sessionData) {
        try {
            $amount = $sessionData['amount'];
            $phoneNumber = $sessionData['phone_number'];
            $subscriberName = $sessionData['subscriber_name'] ?? '';
            
            // Generate transaction ID
            $transactionId = Utils::generateTransactionId();
            
            // Create transaction record
            $this->createTransaction($this->user['id'], 'airtime', $amount, $phoneNumber, $transactionId, [
                'subscriber_name' => $subscriberName
            ]);
            
            // Call Lyca API
            $lycaApi = new LycaMobileAPI();
            $result = $lycaApi->purchaseAirtime($phoneNumber, $amount, $transactionId);
            
            if ($result && isset($result['status']) && $result['status'] === 'SUCCESS') {
                // Success
                $this->updateTransactionStatus($transactionId, 'success', $result);
                
                $response = "✅ *Airtime Top-up Successful!* 🎉\n\n";
                $response .= "💰 *Amount:* " . Utils::formatCurrency($amount) . "\n";
                $response .= "📞 *Number:* " . $phoneNumber . "\n";
                
                if ($subscriberName) {
                    $response .= "👤 *Subscriber:* " . $subscriberName . "\n";
                }
                
                $response .= "🔢 *Transaction ID:* " . $transactionId . "\n";
                $response .= "📅 *Date:* " . date('Y-m-d H:i:s') . "\n\n";
                $response .= "🎯 Airtime has been successfully credited!\n\n";
                $response .= "Need anything else? Send 'menu' 🏠";
                
            } else {
                // Failed
                $errorCode = $result['responseCode'] ?? 'Unknown';
                $this->updateTransactionStatus($transactionId, 'failed', $result);
                
                $response = "❌ *Airtime Top-up Failed*\n\n";
                $response .= "🔢 *Transaction ID:* " . $transactionId . "\n";
                $response .= "📄 *Reason:* " . ErrorCodes::getMessage($errorCode) . "\n\n";
                $response .= "💡 You can try again or contact support if the issue persists.\n\n";
                $response .= "Send 'menu' to try again or 'support' for help.";
            }
            
            $this->clearUserSession($this->user['id']);
            return $response;
            
        } catch (Exception $e) {
            Logger::error("Error processing airtime purchase: " . $e->getMessage());
            
            if (isset($transactionId)) {
                $this->updateTransactionStatus($transactionId, 'failed', ['error' => $e->getMessage()]);
            }
            
            $this->clearUserSession($this->user['id']);
            return "❌ Airtime top-up failed due to a technical error. Please try again later or contact support.\n\nSend 'menu' to start over.";
        }
    }
    
    /**
     * Show transaction history
     */
    private function showTransactionHistory() {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*, sb.service_bundle_name 
                FROM transactions t
                LEFT JOIN service_bundles sb ON t.service_bundle_token = sb.service_bundle_token
                WHERE t.user_id = ? 
                ORDER BY t.created_date DESC 
                LIMIT 10
            ");
            $stmt->execute([$this->user['id']]);
            $transactions = $stmt->fetchAll();
            
            if (empty($transactions)) {
                return "📊 *Transaction History*\n\n" .
                       "No transactions found yet.\n\n" .
                       "Start making purchases to see your history here!\n\n" .
                       "Send 'menu' to go back 🏠";
            }
            
            $response = "📊 *Your Transaction History*\n\n";
            
            foreach ($transactions as $transaction) {
                $statusIcon = $this->getStatusIcon($transaction['status']);
                $response .= "{$statusIcon} *" . ucfirst($transaction['transaction_type']) . "*\n";
                
                if ($transaction['transaction_type'] === 'bundle' && $transaction['service_bundle_name']) {
                    $response .= "   📱 " . $transaction['service_bundle_name'] . "\n";
                }
                
                $response .= "   💰 " . Utils::formatCurrency($transaction['amount']) . "\n";
                $response .= "   📞 " . $transaction['subscription_id'] . "\n";
                $response .= "   📅 " . date('M d, Y H:i', strtotime($transaction['created_date'])) . "\n";
                
                if ($transaction['status'] === 'failed' && $transaction['error_message']) {
                    $response .= "   ❌ " . $transaction['error_message'] . "\n";
                }
                
                $response .= "\n";
            }
            
            $response .= "💡 Showing last 10 transactions\n";
            $response .= "Send 'menu' to go back 🏠";
            
            return $response;
            
        } catch (Exception $e) {
            Logger::error("Error getting transaction history: " . $e->getMessage());
            return "❌ Could not load transaction history. Please try again later.\n\nSend 'menu' to go back.";
        }
    }
    
    /**
     * Get status icon for transaction
     */
    private function getStatusIcon($status) {
        switch ($status) {
            case 'success': return '✅';
            case 'failed': return '❌';
            case 'pending': return '⏳';
            case 'cancelled': return '🚫';
            default: return '❓';
        }
    }
    
    /**
     * Show user profile
     */
    private function showProfile() {
        try {
            // Get transaction statistics
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as total_spent,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_transactions
                FROM transactions 
                WHERE user_id = ?
            ");
            $stmt->execute([$this->user['id']]);
            $stats = $stmt->fetch();
            
            $response = "👤 *Your Profile*\n\n";
            $response .= "📞 *Phone:* " . $this->user['phone_number'] . "\n";
            
            if ($this->user['first_name'] || $this->user['last_name']) {
                $name = trim(($this->user['first_name'] ?? '') . ' ' . ($this->user['last_name'] ?? ''));
                $response .= "🏷️ *Name:* " . $name . "\n";
            }
            
            $response .= "📅 *Member Since:* " . date('M d, Y', strtotime($this->user['registration_date'])) . "\n";
            $response .= "🕐 *Last Active:* " . date('M d, Y H:i', strtotime($this->user['last_activity'])) . "\n\n";
            
            $response .= "📊 *Your Statistics:*\n";
            $response .= "• Total Transactions: " . ($stats['total_transactions'] ?? 0) . "\n";
            $response .= "• Successful: " . ($stats['successful_transactions'] ?? 0) . "\n";
            $response .= "• Total Spent: " . Utils::formatCurrency($stats['total_spent'] ?? 0) . "\n\n";
            
            // Get saved numbers count
            $savedNumbers = $this->getCustomerNumbers($this->user['id']);
            $response .= "📱 *Saved Numbers:* " . count($savedNumbers) . "\n\n";
            
            $response .= "Need to update your profile? Contact support.\n\n";
            $response .= "Send 'menu' to go back 🏠";
            
            return $response;
            
        } catch (Exception $e) {
            Logger::error("Error getting user profile: " . $e->getMessage());
            return "❌ Could not load profile. Please try again later.\n\nSend 'menu' to go back.";
        }
    }
    
    /**
     * Get support information
     */
    private function getSupport() {
        $supportPhone = $this->config->get('support_phone_number', ADMIN_PHONE);
        
        $response = "🆘 *LycaPay Support*\n\n";
        $response .= "Need help? We're here for you!\n\n";
        $response .= "📞 *Phone:* " . $supportPhone . "\n";
        $response .= "📧 *Email:* " . ADMIN_EMAIL . "\n\n";
        $response .= "🕒 *Support Hours:*\n";
        $response .= "Monday - Friday: 8:00 AM - 6:00 PM\n";
        $response .= "Saturday: 9:00 AM - 4:00 PM\n";
        $response .= "Sunday: Closed\n\n";
        $response .= "💡 *Common Issues:*\n";
        $response .= "• Transaction failed? We'll help you resolve it\n";
        $response .= "• Wrong number recharged? Contact us immediately\n";
        $response .= "• Balance questions? We can check for you\n\n";
        $response .= "When contacting support, please provide your transaction ID if available.\n\n";
        $response .= "Send 'menu' to go back 🏠";
        
        return $response;
    }
    
    /**
     * Handle direct number input
     */
    private function handleDirectNumber($phoneNumber) {
        try {
            $formattedNumber = Utils::formatPhoneNumber($phoneNumber);
            
            // Get subscriber info
            $lycaApi = new LycaMobileAPI();
            $subscriberInfo = $lycaApi->getSubscriptionInfo($formattedNumber);
            
            if ($subscriberInfo && isset($subscriberInfo['subscriberName'])) {
                $subscriberName = trim($subscriberInfo['subscriberName'] . ' ' . ($subscriberInfo['subscriberSurname'] ?? ''));
                
                // Save customer number for future use
                $this->saveCustomerNumber($this->user['id'], $formattedNumber, $subscriberName);
                
                $response = "📱 *Subscriber Information*\n\n";
                $response .= "📞 *Number:* " . $formattedNumber . "\n";
                $response .= "👤 *Name:* " . $subscriberName . "\n";
                $response .= "📊 *Status:* " . ($subscriberInfo['status'] ?? 'Active') . "\n\n";
                $response .= "What would you like to do?\n\n";
                $response .= "1️⃣ Buy Data Bundle\n";
                $response .= "2️⃣ Buy Airtime\n";
                $response .= "3️⃣ Back to Menu\n\n";
                $response .= "Send your choice (1, 2, or 3)";
                
                $this->updateSession($this->user['id'], 'number_selected', 'quick_action', [
                    'selected_number' => $formattedNumber,
                    'subscriber_name' => $subscriberName
                ]);
                
                return $response;
            } else {
                return "❓ Could not find subscriber information for " . $formattedNumber . "\n\n" .
                       "This number might be:\n" .
                       "• Not a Lycamobile number\n" .
                       "• Inactive or suspended\n" .
                       "• Invalid format\n\n" .
                       "Please check the number and try again, or send 'menu' to go back.";
            }
        } catch (Exception $e) {
            Logger::error("Error handling direct number: " . $e->getMessage());
            return "❌ Could not check subscriber information. Please try again or contact support.\n\nSend 'menu' to go back.";
        }
    }
    
    /**
     * Get unknown command response
     */
    private function getUnknownCommandResponse() {
        $responses = [
            "❓ I didn't understand that command.\n\nSend 'menu' to see available options.",
            "🤔 Not sure what you mean.\n\nTry sending 'help' or 'menu' to get started.",
            "❓ Invalid command.\n\nSend 'menu' to see what I can help you with.",
        ];
        
        return $responses[array_rand($responses)];
    }
}

?>
