<?php
/**
 * LycaPay WhatsApp Bot - Fixed for TwiML Response Version
 * Handles all bot interactions and business logic
 */

require_once 'config.php';
require_once 'lyca_api.php';

Logger::info("Logger test message", ['file' => __FILE__, 'time' => date('Y-m-d H:i:s')]);

class LycaPayBot {
    private $pdo;
    private $currentUser;
    private $userState;
    
    public function __construct() {
        $this->debugLog("LycaPay Bot constructor called");
        
        try {
            $this->pdo = Config::getInstance()->getDatabase();
            $this->debugLog("Database connection established");
        } catch (Exception $e) {
            $this->debugLog("Database connection failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
        
        $this->userState = [];
        $this->debugLog("LycaPay Bot constructor completed successfully");
    }
    
    private function debugLog($message, $level = 'INFO') {
        $logEntry = date('Y-m-d H:i:s') . " - [$level] [LYCABOT] " . $message . PHP_EOL;
        file_put_contents('lycapay_debug_log.txt', $logEntry, FILE_APPEND);
    }
    
    /**
     * Process incoming WhatsApp message and return TwiML response
     */
    public function handleIncomingMessage($from, $body) {
        $this->debugLog("handleIncomingMessage called - From: $from, Body: $body");
        
        try {
            // Format phone number
            $phone = $this->formatPhoneNumber($from);
            $this->debugLog("Formatted phone: $phone");
            
            // Get or create user
            $this->currentUser = $this->getOrCreateUser($phone);
            $this->debugLog("User retrieved/created - ID: " . ($this->currentUser['id'] ?? 'null'));
            
            // Load user state
            $this->loadUserState($phone);
            $this->debugLog("User state loaded");
            
            // Process message and get response
            $response = $this->processMessage($body);
            $this->debugLog("Message processed - Response: " . substr($response, 0, 100) . "...");
            
            // Log messages
            $this->logMessage($phone, 'incoming', $body);
            $this->logMessage($phone, 'outgoing', $response);
            $this->debugLog("Messages logged");
            
            // Return the response message (webhook.php will handle TwiML)
            return $response;
            
        } catch (Exception $e) {
            $this->debugLog("Error in handleIncomingMessage: " . $e->getMessage(), 'ERROR');
            $this->debugLog("Stack trace: " . $e->getTraceAsString(), 'ERROR');
            
            // Return error message
            return $this->getErrorMessage();
        }
    }
    
    private function formatPhoneNumber($phone) {
        // Remove whatsapp: prefix if present
        $phone = str_replace('whatsapp:', '', $phone);
        
        // Add + if not present
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }
        
        return $phone;
    }
    
    private function processMessage($message) {
        $this->debugLog("Processing message: $message");
        
        $originalMessage = trim($message);
        $command = strtolower(trim($message));
        
        // Handle state-based flows
        if (isset($this->userState['state'])) {
            $this->debugLog("User in state: " . $this->userState['state']);
            return $this->handleStateBasedInput($command, $originalMessage);
        }
        
        // Handle main commands
        $this->debugLog("Processing main command: $command");
        
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
                $this->clearUserState();
                return "❌ Operation cancelled. Send 'menu' to start over.";
                
            default:
                // Check if it's a phone number
                if ($this->isValidUgandaNumber($originalMessage)) {
                    return $this->handleDirectNumber($originalMessage);
                }
                
                return $this->getUnknownCommandResponse();
        }
    }
    
    private function handleStateBasedInput($command, $originalMessage) {
        $state = $this->userState['state'];
        $data = $this->userState['data'] ?? [];
        
        switch ($state) {
            case 'selecting_bundle':
                return $this->handleBundleSelection($originalMessage, $data);
                
            case 'awaiting_number':
                return $this->handleNumberInput($originalMessage, $data);
                
            case 'selecting_saved_number':
                return $this->handleSavedNumberSelection($originalMessage, $data);
                
            case 'confirming_purchase':
                return $this->handlePurchaseConfirmation($originalMessage, $data);
                
            case 'entering_amount':
                return $this->handleAmountInput($originalMessage, $data);
                
            case 'confirming_airtime':
                return $this->handleAirtimeConfirmation($originalMessage, $data);
                
            case 'number_selected':
                return $this->handleNumberSelectedAction($originalMessage, $data);
                
            case 'selecting_bundle_for_number':
                return $this->handleBundleSelectionForNumber($originalMessage, $data);
                
            case 'entering_amount_for_number':
                return $this->handleAmountInputForNumber($originalMessage, $data);
                
            default:
                $this->clearUserState();
                return "❌ Something went wrong. Please start over by sending 'menu'.";
        }
    }
    
    /**
     * Get or create user
     */
    private function getOrCreateUser($phoneNumber) {
        $this->debugLog("Getting or creating user for phone: $phoneNumber");
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE phone_number = ?");
            $stmt->execute([$phoneNumber]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $this->debugLog("User not found, creating new user");
                $stmt = $this->pdo->prepare("INSERT INTO users (phone_number, registration_date, last_activity, status) VALUES (?, NOW(), NOW(), 'active')");
                $stmt->execute([$phoneNumber]);
                $userId = $this->pdo->lastInsertId();
                
                $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $this->debugLog("New user created with ID: $userId");
            } else {
                $this->debugLog("Existing user found with ID: " . $user['id']);
                
                // Update last activity
                $stmt = $this->pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
            }
            
            return $user;
            
        } catch (Exception $e) {
            $this->debugLog("Error in getOrCreateUser: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Get welcome message
     */
    private function getWelcomeMessage() {
        $firstName = !empty($this->currentUser['first_name']) ? " " . $this->currentUser['first_name'] : "";
        
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
                       "💰 Available: " . $this->formatCurrency($balance['walletBalance']) . "\n" .
                       "📅 Last Updated: " . date('Y-m-d H:i:s') . "\n\n" .
                       "Send 'menu' to continue 🏠";
            } else {
                return "❌ Could not retrieve balance at this time. Please try again later.";
            }
        } catch (Exception $e) {
            $this->debugLog("Error checking balance: " . $e->getMessage(), 'ERROR');
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
                $response .= "   💰 " . $this->formatCurrency($plan['serviceBundlePrice']) . "\n";
                $response .= "   📄 " . $plan['serviceBundleDescription'] . "\n\n";
            }
            
            $response .= "📝 *How to Purchase:*\n";
            $response .= "Select bundle number (1-" . count($plans) . ")\n\n";
            $response .= "💡 *Tip:* You can also send a phone number first to pre-select the recipient\n\n";
            $response .= "Send 'menu' to go back 🔙";
            
            // Store bundles in state
            $this->updateUserState('selecting_bundle', [
                'plans' => $plans
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->debugLog("Error fetching bundles: " . $e->getMessage(), 'ERROR');
            return "❌ Could not load bundles. Please try again later or contact support.";
        }
    }
    
    /**
     * Handle bundle selection
     */
    private function handleBundleSelection($input, $data) {
        if (strtolower($input) === 'menu' || strtolower($input) === 'cancel') {
            $this->clearUserState();
            return $this->getMainMenu();
        }
        
        $plans = $data['plans'] ?? [];
        $selectedNumber = (int)$input - 1;
        
        if ($selectedNumber >= 0 && $selectedNumber < count($plans)) {
            $selectedPlan = $plans[$selectedNumber];
            
            // Check if user has saved numbers
            $customerNumbers = $this->getCustomerNumbers($this->currentUser['id']);
            
            if (empty($customerNumbers)) {
                // Ask for number
                $this->updateUserState('awaiting_number', [
                    'selected_plan' => $selectedPlan,
                    'action' => 'bundle_purchase'
                ]);
                
                return "📱 *Bundle Selected:* " . $selectedPlan['serviceBundleName'] . "\n" .
                       "💰 *Price:* " . $this->formatCurrency($selectedPlan['serviceBundlePrice']) . "\n\n" .
                       "Please enter the Uganda mobile number to recharge:\n\n" .
                       "📝 *Format Examples:*\n" .
                       "• 0772123456\n" .
                       "• 256772123456\n" .
                       "• +256772123456\n\n" .
                       "Send 'cancel' to abort 🚫";
            } else {
                // Show saved numbers
                $response = "📱 *Bundle Selected:* " . $selectedPlan['serviceBundleName'] . "\n" .
                           "💰 *Price:* " . $this->formatCurrency($selectedPlan['serviceBundlePrice']) . "\n\n" .
                           "Select recipient number:\n\n";
                
                foreach ($customerNumbers as $index => $number) {
                    $name = trim(($number['subscriber_name'] ?? '') . ' ' . ($number['subscriber_surname'] ?? ''));
                    $response .= ($index + 1) . "️⃣ " . $number['subscription_id'] . 
                                " (" . ($name ?: 'Unknown') . ")\n";
                }
                
                $response .= "\n🆕 Enter 'new' for different number\n";
                $response .= "Send 'cancel' to abort 🚫";
                
                $this->updateUserState('selecting_saved_number', [
                    'selected_plan' => $selectedPlan,
                    'saved_numbers' => $customerNumbers,
                    'action' => 'bundle_purchase'
                ]);
                
                return $response;
            }
        } else {
            return "❌ Invalid selection. Please choose a number between 1 and " . count($plans) . "\n\nSend 'menu' to start over.";
        }
    }
    
    /**
     * Handle bundle selection for specific number
     */
    private function handleBundleSelectionForNumber($input, $data) {
        if (strtolower($input) === 'menu' || strtolower($input) === 'cancel') {
            $this->clearUserState();
            return $this->getMainMenu();
        }
        
        $plans = $data['plans'] ?? [];
        $selectedNumber = (int)$input - 1;
        $phoneNumber = $data['selected_number'];
        $subscriberName = $data['subscriber_name'] ?? '';
        
        if ($selectedNumber >= 0 && $selectedNumber < count($plans)) {
            $selectedPlan = $plans[$selectedNumber];
            return $this->confirmBundlePurchase($selectedPlan, $phoneNumber, $subscriberName);
        } else {
            return "❌ Invalid selection. Please choose a number between 1 and " . count($plans) . "\n\nSend 'cancel' to go back.";
        }
    }
    
    /**
     * Handle number input
     */
    private function handleNumberInput($input, $data) {
        if (strtolower($input) === 'cancel') {
            $this->clearUserState();
            return "❌ Operation cancelled. Send 'menu' to start over.";
        }
        
        $phoneNumber = $this->formatPhoneNumber($input);
        
        if (!$this->isValidUgandaNumber($phoneNumber)) {
            return "❌ Invalid Uganda mobile number format.\n\n" .
                   "📝 *Please use one of these formats:*\n" .
                   "• 0772123456\n" .
                   "• 256772123456\n" .
                   "• +256772123456\n\n" .
                   "Try again or send 'cancel' to abort.";
        }
        
        $action = $data['action'] ?? '';
        
        if ($action === 'bundle_purchase') {
            return $this->confirmBundlePurchase($data['selected_plan'], $phoneNumber);
        } elseif ($action === 'airtime_purchase') {
            return $this->confirmAirtimePurchase($data['amount'], $phoneNumber);
        }
        
        return "❌ Something went wrong. Please start over by sending 'menu'.";
    }
    
    /**
     * Confirm bundle purchase
     */
    private function confirmBundlePurchase($plan, $phoneNumber, $subscriberName = '') {
        try {
            // Get subscriber info if not provided
            if (!$subscriberName) {
                $lycaApi = new LycaMobileAPI();
                $subscriberInfo = $lycaApi->getSubscriptionInfo($phoneNumber);
                
                if ($subscriberInfo && isset($subscriberInfo['subscriberName'])) {
                    $subscriberName = trim($subscriberInfo['subscriberName'] . ' ' . ($subscriberInfo['subscriberSurname'] ?? ''));
                }
            }
            
            // Save customer number for future use
            if ($subscriberName) {
                $this->saveCustomerNumber($this->currentUser['id'], $phoneNumber, $subscriberName);
            }
            
            $response = "🔍 *Purchase Confirmation*\n\n";
            $response .= "📱 *Bundle:* " . $plan['serviceBundleName'] . "\n";
            $response .= "💰 *Price:* " . $this->formatCurrency($plan['serviceBundlePrice']) . "\n";
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
            
            $this->updateUserState('confirming_purchase', [
                'plan' => $plan,
                'phone_number' => $phoneNumber,
                'subscriber_name' => $subscriberName,
                'action' => 'bundle_purchase'
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->debugLog("Error confirming bundle purchase: " . $e->getMessage(), 'ERROR');
            return "❌ Could not verify subscriber info. Please try again or contact support.\n\nSend 'menu' to start over.";
        }
    }
    
    /**
     * Handle purchase confirmation
     */
    private function handlePurchaseConfirmation($input, $data) {
        $input = strtolower(trim($input));
        
        if ($input === 'cancel' || $input === '2' || $input === 'no') {
            $this->clearUserState();
            return "❌ Purchase cancelled. Send 'menu' to start over.";
        }
        
        if ($input === '1' || $input === 'yes') {
            if ($data['action'] === 'bundle_purchase') {
                return $this->processBundlePurchase($data);
            } elseif ($data['action'] === 'airtime_purchase') {
                return $this->processAirtimePurchase($data);
            }
        }
        
        return "❓ Please send:\n1️⃣ *YES* to confirm\n2️⃣ *NO* to cancel\n\nOr send 'cancel' to abort.";
    }
    
    /**
     * Process bundle purchase
     */
    private function processBundlePurchase($data) {
        try {
            $plan = $data['plan'];
            $phoneNumber = $data['phone_number'];
            $subscriberName = $data['subscriber_name'] ?? '';
            
            // Generate transaction ID
            $transactionId = $this->generateTransactionId();
            
            // Create transaction record
            $this->createTransaction($this->currentUser['id'], 'bundle', $plan['serviceBundlePrice'], $phoneNumber, $transactionId, [
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
                $response .= "💰 *Amount:* " . $this->formatCurrency($plan['serviceBundlePrice']) . "\n";
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
                $response .= "📄 *Reason:* " . $this->getErrorMessage($errorCode) . "\n\n";
                $response .= "💡 You can try again or contact support if the issue persists.\n\n";
                $response .= "Send 'menu' to try again or 'support' for help.";
            }
            
            $this->clearUserState();
            return $response;
            
        } catch (Exception $e) {
            $this->debugLog("Error processing bundle purchase: " . $e->getMessage(), 'ERROR');
            
            if (isset($transactionId)) {
                $this->updateTransactionStatus($transactionId, 'failed', ['error' => $e->getMessage()]);
            }
            
            $this->clearUserState();
            return "❌ Purchase failed due to a technical error. Please try again later or contact support.\n\nSend 'menu' to start over.";
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
        
        $this->updateUserState('entering_amount', [
            'action' => 'airtime_purchase'
        ]);
        
        return $response;
    }
    
    /**
     * Handle amount input for airtime
     */
    private function handleAmountInput($input, $data) {
        if (strtolower($input) === 'cancel') {
            $this->clearUserState();
            return "❌ Operation cancelled. Send 'menu' to start over.";
        }
        
        // Extract numeric value
        $amount = (int)preg_replace('/[^0-9]/', '', $input);
        
        if ($amount < 500) { // Min amount
            return "❌ Minimum airtime amount is UGX 500\n\nPlease enter a valid amount or send 'cancel' to abort.";
        }
        
        if ($amount > 100000) { // Max 100k for safety
            return "❌ Maximum airtime amount is UGX 100,000 per transaction.\n\nPlease enter a valid amount or send 'cancel' to abort.";
        }
        
        // Check if user has saved numbers
        $customerNumbers = $this->getCustomerNumbers($this->currentUser['id']);
        
        if (empty($customerNumbers)) {
            $this->updateUserState('awaiting_number', [
                'amount' => $amount,
                'action' => 'airtime_purchase'
            ]);
            
            return "💰 *Amount:* " . $this->formatCurrency($amount) . "\n\n" .
                   "📱 Please enter the Uganda mobile number to recharge:\n\n" .
                   "📝 *Format Examples:*\n" .
                   "• 0772123456\n" .
                   "• 256772123456\n" .
                   "• +256772123456\n\n" .
                   "Send 'cancel' to abort 🚫";
        } else {
            // Show saved numbers
            $response = "💰 *Amount:* " . $this->formatCurrency($amount) . "\n\n";
            $response .= "Select recipient number:\n\n";
            
            foreach ($customerNumbers as $index => $number) {
                $name = trim(($number['subscriber_name'] ?? '') . ' ' . ($number['subscriber_surname'] ?? ''));
                $response .= ($index + 1) . "️⃣ " . $number['subscription_id'] . 
                            " (" . ($name ?: 'Unknown') . ")\n";
            }
            
            $response .= "\n🆕 Enter 'new' for different number\n";
            $response .= "Send 'cancel' to abort 🚫";
            
            $this->updateUserState('selecting_saved_number', [
                'amount' => $amount,
                'saved_numbers' => $customerNumbers,
                'action' => 'airtime_purchase'
            ]);
            
            return $response;
        }
    }
    
    /**
     * Handle amount input for specific number
     */
    private function handleAmountInputForNumber($input, $data) {
        if (strtolower($input) === 'cancel') {
            $this->clearUserState();
            return "❌ Operation cancelled. Send 'menu' to start over.";
        }
        
        // Extract numeric value
        $amount = (int)preg_replace('/[^0-9]/', '', $input);
        
        if ($amount < 500) { // Min amount
            return "❌ Minimum airtime amount is UGX 500\n\nPlease enter a valid amount or send 'cancel' to abort.";
        }
        
        if ($amount > 100000) { // Max 100k for safety
            return "❌ Maximum airtime amount is UGX 100,000 per transaction.\n\nPlease enter a valid amount or send 'cancel' to abort.";
        }
        
        $phoneNumber = $data['selected_number'];
        $subscriberName = $data['subscriber_name'] ?? '';
        
        return $this->confirmAirtimePurchase($amount, $phoneNumber, $subscriberName);
    }
    
    /**
     * Show transaction history
     */
    private function showTransactionHistory() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM transactions 
                WHERE user_id = ? 
                ORDER BY created_date DESC 
                LIMIT 10
            ");
            $stmt->execute([$this->currentUser['id']]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
                
                $response .= "   💰 " . $this->formatCurrency($transaction['amount']) . "\n";
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
            $this->debugLog("Error getting transaction history: " . $e->getMessage(), 'ERROR');
            return "❌ Could not load transaction history. Please try again later.\n\nSend 'menu' to go back.";
        }
    }
    
    /**
     * Show user profile
     */
    private function showProfile() {
        try {
            // Get transaction statistics
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as total_spent,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_transactions
                FROM transactions 
                WHERE user_id = ?
            ");
            $stmt->execute([$this->currentUser['id']]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $response = "👤 *Your Profile*\n\n";
            $response .= "📞 *Phone:* " . $this->currentUser['phone_number'] . "\n";
            
            if ($this->currentUser['first_name'] || $this->currentUser['last_name']) {
                $name = trim(($this->currentUser['first_name'] ?? '') . ' ' . ($this->currentUser['last_name'] ?? ''));
                $response .= "🏷️ *Name:* " . $name . "\n";
            }
            
            $response .= "📅 *Member Since:* " . date('M d, Y', strtotime($this->currentUser['registration_date'])) . "\n";
            $response .= "🕐 *Last Active:* " . date('M d, Y H:i', strtotime($this->currentUser['last_activity'])) . "\n\n";
            
            $response .= "📊 *Your Statistics:*\n";
            $response .= "• Total Transactions: " . ($stats['total_transactions'] ?? 0) . "\n";
            $response .= "• Successful: " . ($stats['successful_transactions'] ?? 0) . "\n";
            $response .= "• Total Spent: " . $this->formatCurrency($stats['total_spent'] ?? 0) . "\n\n";
            
            // Get saved numbers count
            $savedNumbers = $this->getCustomerNumbers($this->currentUser['id']);
            $response .= "📱 *Saved Numbers:* " . count($savedNumbers) . "\n\n";
            
            $response .= "Need to update your profile? Contact support.\n\n";
            $response .= "Send 'menu' to go back 🏠";
            
            return $response;
            
        } catch (Exception $e) {
            $this->debugLog("Error getting user profile: " . $e->getMessage(), 'ERROR');
            return "❌ Could not load profile. Please try again later.\n\nSend 'menu' to go back.";
        }
    }
    
    /**
     * Get support information
     */
    private function getSupport() {
        $supportPhone = defined('ADMIN_PHONE') ? ADMIN_PHONE : '+256123456789';
        $supportEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'support@lycapay.com';
        
        $response = "🆘 *LycaPay Support*\n\n";
        $response .= "Need help? We're here for you!\n\n";
        $response .= "📞 *Phone:* " . $supportPhone . "\n";
        $response .= "📧 *Email:* " . $supportEmail . "\n\n";
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
            $formattedNumber = $this->formatPhoneNumber($phoneNumber);
            
            // Get subscriber info
            $lycaApi = new LycaMobileAPI();
            $subscriberInfo = $lycaApi->getSubscriptionInfo($formattedNumber);
            
            if ($subscriberInfo && isset($subscriberInfo['subscriberName'])) {
                $subscriberName = trim($subscriberInfo['subscriberName'] . ' ' . ($subscriberInfo['subscriberSurname'] ?? ''));
                
                // Save customer number for future use
                $this->saveCustomerNumber($this->currentUser['id'], $formattedNumber, $subscriberName);
                
                $response = "📱 *Subscriber Information*\n\n";
                $response .= "📞 *Number:* " . $formattedNumber . "\n";
                $response .= "👤 *Name:* " . $subscriberName . "\n";
                $response .= "📊 *Status:* " . ($subscriberInfo['status'] ?? 'Active') . "\n\n";
                $response .= "What would you like to do?\n\n";
                $response .= "1️⃣ Buy Data Bundle\n";
                $response .= "2️⃣ Buy Airtime\n";
                $response .= "3️⃣ Back to Menu\n\n";
                $response .= "Send your choice (1, 2, or 3)";
                
                $this->updateUserState('number_selected', [
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
            $this->debugLog("Error handling direct number: " . $e->getMessage(), 'ERROR');
            return "❌ Could not check subscriber information. Please try again or contact support.\n\nSend 'menu' to go back.";
        }
    }
    
    /**
     * Handle number selected action
     */
    private function handleNumberSelectedAction($input, $data) {
        $input = strtolower(trim($input));
        
        if ($input === 'cancel' || $input === '3') {
            $this->clearUserState();
            return $this->getMainMenu();
        }
        
        $selectedNumber = $data['selected_number'];
        $subscriberName = $data['subscriber_name'] ?? '';
        
        if ($input === '1') {
            // Buy Data Bundle
            return $this->showBundlesForNumber($selectedNumber, $subscriberName);
        } elseif ($input === '2') {
            // Buy Airtime
            return $this->showAirtimeForNumber($selectedNumber, $subscriberName);
        }
        
        return "❓ Please send:\n1️⃣ Buy Data Bundle\n2️⃣ Buy Airtime\n3️⃣ Back to Menu";
    }
    
    /**
     * Show bundles for specific number
     */
    private function showBundlesForNumber($phoneNumber, $subscriberName) {
        try {
            $lycaApi = new LycaMobileAPI();
            $plans = $lycaApi->getEbalanceSupportedPlans();
            
            if (empty($plans)) {
                return "❌ Sorry, no data bundles are available at the moment. Please try again later.";
            }
            
            $response = "📱 *Data Bundles for:*\n";
            $response .= "📞 " . $phoneNumber . "\n";
            if ($subscriberName) {
                $response .= "👤 " . $subscriberName . "\n";
            }
            $response .= "\n";
            
            foreach ($plans as $index => $plan) {
                $response .= ($index + 1) . "️⃣ *" . $plan['serviceBundleName'] . "*\n";
                $response .= "   💰 " . $this->formatCurrency($plan['serviceBundlePrice']) . "\n";
                $response .= "   📄 " . $plan['serviceBundleDescription'] . "\n\n";
            }
            
            $response .= "📝 Select bundle number (1-" . count($plans) . ")\n";
            $response .= "Send 'cancel' to go back 🔙";
            
            $this->updateUserState('selecting_bundle_for_number', [
                'plans' => $plans,
                'selected_number' => $phoneNumber,
                'subscriber_name' => $subscriberName
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->debugLog("Error fetching bundles for number: " . $e->getMessage(), 'ERROR');
            return "❌ Could not load bundles. Please try again later or contact support.";
        }
    }
    
    /**
     * Show airtime for specific number
     */
    private function showAirtimeForNumber($phoneNumber, $subscriberName) {
        $response = "💰 *Airtime Top-up for:*\n";
        $response .= "📞 " . $phoneNumber . "\n";
        if ($subscriberName) {
            $response .= "👤 " . $subscriberName . "\n";
        }
        $response .= "\n";
        
        $response .= "💡 *Popular amounts:*\n";
        $response .= "• UGX 1,000\n";
        $response .= "• UGX 2,000\n";
        $response .= "• UGX 5,000\n";
        $response .= "• UGX 10,000\n\n";
        
        $response .= "💵 Please enter the amount you want to top up (Min: UGX 500):";
        
        $this->updateUserState('entering_amount_for_number', [
            'selected_number' => $phoneNumber,
            'subscriber_name' => $subscriberName
        ]);
        
        return $response;
    }
    
    /**
     * Handle saved number selection
     */
    private function handleSavedNumberSelection($input, $data) {
        if (strtolower($input) === 'cancel') {
            $this->clearUserState();
            return "❌ Operation cancelled. Send 'menu' to start over.";
        }
        
        if (strtolower($input) === 'new') {
            if (isset($data['selected_plan'])) {
                $this->updateUserState('awaiting_number', [
                    'selected_plan' => $data['selected_plan'],
                    'action' => 'bundle_purchase'
                ]);
            } elseif (isset($data['amount'])) {
                $this->updateUserState('awaiting_number', [
                    'amount' => $data['amount'],
                    'action' => 'airtime_purchase'
                ]);
            }
            
            return "📱 Please enter the new Uganda mobile number:\n\n" .
                   "📝 *Format Examples:*\n" .
                   "• 0772123456\n" .
                   "• 256772123456\n" .
                   "• +256772123456";
        }
        
        $savedNumbers = $data['saved_numbers'] ?? [];
        $selectedIndex = (int)$input - 1;
        
        if ($selectedIndex >= 0 && $selectedIndex < count($savedNumbers)) {
            $selectedNumber = $savedNumbers[$selectedIndex];
            
            if (isset($data['selected_plan'])) {
                return $this->confirmBundlePurchase($data['selected_plan'], $selectedNumber['subscription_id']);
            } elseif (isset($data['amount'])) {
                return $this->confirmAirtimePurchase($data['amount'], $selectedNumber['subscription_id']);
            }
        }
        
        return "❌ Invalid selection. Please choose a valid number or enter 'new' for a different number.";
    }
    
    /**
     * Confirm airtime purchase
     */
    private function confirmAirtimePurchase($amount, $phoneNumber, $subscriberName = '') {
        try {
            // Get subscriber info if not provided
            if (!$subscriberName) {
                $lycaApi = new LycaMobileAPI();
                $subscriberInfo = $lycaApi->getSubscriptionInfo($phoneNumber);
                
                if ($subscriberInfo && isset($subscriberInfo['subscriberName'])) {
                    $subscriberName = trim($subscriberInfo['subscriberName'] . ' ' . ($subscriberInfo['subscriberSurname'] ?? ''));
                }
            }
            
            // Save customer number for future use
            if ($subscriberName) {
                $this->saveCustomerNumber($this->currentUser['id'], $phoneNumber, $subscriberName);
            }
            
            $response = "🔍 *Airtime Purchase Confirmation*\n\n";
            $response .= "💰 *Amount:* " . $this->formatCurrency($amount) . "\n";
            $response .= "📞 *Number:* " . $phoneNumber . "\n";
            
            if ($subscriberName) {
                $response .= "👤 *Subscriber:* " . $subscriberName . "\n";
            }
            
            $response .= "\n✅ Confirm airtime top-up?\n\n";
            $response .= "1️⃣ *YES* - Proceed with purchase\n";
            $response .= "2️⃣ *NO* - Cancel and go back\n\n";
            $response .= "Send your choice (1 or 2)";
            
            $this->updateUserState('confirming_purchase', [
                'amount' => $amount,
                'phone_number' => $phoneNumber,
                'subscriber_name' => $subscriberName,
                'action' => 'airtime_purchase'
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->debugLog("Error confirming airtime purchase: " . $e->getMessage(), 'ERROR');
            return "❌ Could not verify subscriber info. Please try again or contact support.\n\nSend 'menu' to start over.";
        }
    }
    
    /**
     * Handle airtime confirmation
     */
    private function handleAirtimeConfirmation($input, $data) {
        $input = strtolower(trim($input));
        
        if ($input === 'cancel' || $input === '2' || $input === 'no') {
            $this->clearUserState();
            return "❌ Purchase cancelled. Send 'menu' to start over.";
        }
        
        if ($input === '1' || $input === 'yes') {
            return $this->processAirtimePurchase($data);
        }
        
        return "❓ Please send:\n1️⃣ *YES* to confirm\n2️⃣ *NO* to cancel\n\nOr send 'cancel' to abort.";
    }
    
    /**
     * Process airtime purchase
     */
    private function processAirtimePurchase($data) {
        try {
            $amount = $data['amount'];
            $phoneNumber = $data['phone_number'];
            $subscriberName = $data['subscriber_name'] ?? '';
            
            // Generate transaction ID
            $transactionId = $this->generateTransactionId();
            
            // Create transaction record
            $this->createTransaction($this->currentUser['id'], 'airtime', $amount, $phoneNumber, $transactionId, [
                'subscriber_name' => $subscriberName
            ]);
            
            // Call Lyca API
            $lycaApi = new LycaMobileAPI();
            $result = $lycaApi->purchaseAirtime($phoneNumber, $amount, $transactionId);
            
            if ($result && isset($result['status']) && $result['status'] === 'SUCCESS') {
                // Success
                $this->updateTransactionStatus($transactionId, 'success', $result);
                
                $response = "✅ *Airtime Top-up Successful!* 🎉\n\n";
                $response .= "💰 *Amount:* " . $this->formatCurrency($amount) . "\n";
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
                $response .= "📄 *Reason:* " . $this->getErrorMessage($errorCode) . "\n\n";
                $response .= "💡 You can try again or contact support if the issue persists.\n\n";
                $response .= "Send 'menu' to try again or 'support' for help.";
            }
            
            $this->clearUserState();
            return $response;
            
        } catch (Exception $e) {
            $this->debugLog("Error processing airtime purchase: " . $e->getMessage(), 'ERROR');
            
            if (isset($transactionId)) {
                $this->updateTransactionStatus($transactionId, 'failed', ['error' => $e->getMessage()]);
            }
            
            $this->clearUserState();
            return "❌ Airtime top-up failed due to a technical error. Please try again later or contact support.\n\nSend 'menu' to start over.";
        }
    }
    
    // Utility methods
    
    /**
     * Get customer saved numbers
     */
    private function getCustomerNumbers($userId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT subscription_id, subscriber_name, subscriber_surname 
                FROM customer_subscriptions 
                WHERE user_id = ? AND status = 'active'
                ORDER BY is_primary DESC, created_date DESC
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->debugLog("Error getting customer numbers: " . $e->getMessage(), 'ERROR');
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
            
            $stmt = $this->pdo->prepare("
                INSERT INTO customer_subscriptions (user_id, subscription_id, subscriber_name, subscriber_surname, created_date, status)
                VALUES (?, ?, ?, ?, NOW(), 'active')
                ON DUPLICATE KEY UPDATE 
                subscriber_name = VALUES(subscriber_name), 
                subscriber_surname = VALUES(subscriber_surname),
                status = 'active'
            ");
            $stmt->execute([$userId, $subscriptionId, $firstName, $lastName]);
        } catch (Exception $e) {
            $this->debugLog("Error saving customer number: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Create transaction record
     */
    private function createTransaction($userId, $type, $amount, $subscriptionId, $transactionId, $metadata = []) {
        try {
            $bundleToken = $metadata['bundle_token'] ?? null;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO transactions (
                    transaction_id, user_id, subscription_id, transaction_type, 
                    service_bundle_token, amount, status, created_date
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            
            $stmt->execute([
                $transactionId, $userId, $subscriptionId, $type, $bundleToken, $amount
            ]);
            
        } catch (Exception $e) {
            $this->debugLog("Error creating transaction: " . $e->getMessage(), 'ERROR');
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
            
            $stmt = $this->pdo->prepare("
                UPDATE transactions 
                SET status = ?, lyca_transaction_id = ?, error_code = ?, error_message = ?, completed_date = ?
                WHERE transaction_id = ?
            ");
            
            $stmt->execute([$status, $lycaTransactionId, $errorCode, $errorMessage, $completedDate, $transactionId]);
            
        } catch (Exception $e) {
            $this->debugLog("Error updating transaction status: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Generate transaction ID
     */
    private function generateTransactionId() {
        return 'LP' . date('Ymd') . strtoupper(substr(uniqid(), -8));
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
     * Format currency
     */
    private function formatCurrency($amount) {
        return 'UGX ' . number_format($amount, 0);
    }
    
    /**
     * Validate Uganda phone number
     */
    private function isValidUgandaNumber($phone) {
        // Remove all non-numeric characters except +
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Remove leading + if present
        $cleanPhone = ltrim($cleanPhone, '+');
        
        // Check various Uganda number formats
        $patterns = [
            '/^256[7-9][0-9]{8}$/',     // +256XXXXXXXXX
            '/^0[7-9][0-9]{8}$/',       // 07XXXXXXXX
            '/^[7-9][0-9]{8}$/'         // 7XXXXXXXX
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $cleanPhone)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get error message for error code
     */
    private function getErrorMessage($errorCode = null) {
        if (!$errorCode) {
            return "Sorry, I'm experiencing technical difficulties. Please try again in a moment or contact support if the issue persists.";
        }
        
        $errorMessages = [
            'INSUFFICIENT_BALANCE' => 'Insufficient wallet balance',
            'INVALID_NUMBER' => 'Invalid phone number',
            'NETWORK_ERROR' => 'Network error, please try again',
            'SERVICE_UNAVAILABLE' => 'Service temporarily unavailable',
            'DUPLICATE_TRANSACTION' => 'Duplicate transaction detected',
            'EXPIRED_BUNDLE' => 'Bundle has expired',
            'INVALID_BUNDLE' => 'Invalid bundle selected'
        ];
        
        return $errorMessages[$errorCode] ?? 'An unexpected error occurred';
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
    
    // State management methods
    
    /**
     * Update user state
     */
    private function updateUserState($state, $data = []) {
        $this->userState = [
            'state' => $state,
            'data' => $data
        ];
        $this->saveUserState();
    }
    
    /**
     * Clear user state
     */
    private function clearUserState() {
        $this->userState = [];
        $this->saveUserState();
    }
    
    /**
     * Load user state from file
     */
    private function loadUserState($phone) {
        $stateFile = "lycapay_user_states/" . md5($phone) . ".json";
        if (file_exists($stateFile)) {
            $this->userState = json_decode(file_get_contents($stateFile), true) ?? [];
            $this->debugLog("User state loaded from file");
        } else {
            $this->debugLog("No existing user state file found");
        }
    }
    
    /**
     * Save user state to file
     */
    private function saveUserState() {
        if (!isset($this->currentUser['phone_number'])) {
            $this->debugLog("Cannot save user state - no phone number available", 'ERROR');
            return;
        }
        
        $stateDir = "lycapay_user_states";
        if (!file_exists($stateDir)) {
            mkdir($stateDir, 0755, true);
        }
        
        $stateFile = $stateDir . "/" . md5($this->currentUser['phone_number']) . ".json";
        file_put_contents($stateFile, json_encode($this->userState));
        $this->debugLog("User state saved to file");
    }
    
    /**
     * Log message to database
     */
    private function logMessage($phone, $type, $message) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO message_logs (user_id, phone_number, message_type, message_content, created_date) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->currentUser['id'] ?? null,
                $phone,
                $type,
                $message
            ]);
            
            $this->debugLog("Message logged successfully");
            
        } catch (Exception $e) {
            $this->debugLog("Error logging message: " . $e->getMessage(), 'ERROR');
        }
    }
}

?>

