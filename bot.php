<?php
/**
 * LycaPay WhatsApp Bot - Complete Bot Logic
 * Handles all bot interactions and business logic
 */

require_once 'config.php';
require_once 'lyca_api.php';

class LycaPayBot {
    private $config;
    private $db;
    private $user;
    private $session;
    
    public function __construct() {
        $this->config = Config::getInstance();
        $this->db = $this->config->getDatabase();
    }
    
    /**
     * Process incoming WhatsApp message
     */
    public function processMessage($from, $message, $messageId = null) {
        try {
            Logger::info("Processing message from $from: $message");
            
            // Log incoming message
            $this->logMessage($from, 'incoming', $message);
            
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
                $this->logMessage($from, 'outgoing', $response);
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
     * Show available bundles
     */
    private function showBundles() {
        try {
            $lycaApi = new LycaMobileAPI();
            $plans = $lycaApi->getFloatEnabledPlans();
            
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
                    $response .= ($index + 1) . "️⃣ " . $number['subscription_id'] . 
                                " (" . ($number['subscriber_name'] ?: 'Unknown') . ")\n";
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
    
    /**
     * Handle saved number selection for bundle purchase
     */
    private function handleSavedNumberSelection($input, $sessionData) {
        if (strtolower($input) === 'cancel') {
            $this->clearUserSession($this->user['id']);
            return "❌ Operation cancelled. Send 'menu' to start over.";
        }
        
        if (strtolower($input) === 'new') {
            $this->updateSession($this->user['id'], 'awaiting_number', 'bundle_purchase', [
                'selected_plan' => $sessionData['selected_plan']
            ]);
            
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
            return $this->confirmBundlePurchase($sessionData['selected_plan'], $selectedNumber['subscription_id']);
        }
        
        return "❌ Invalid selection. Please choose a valid number or enter 'new' for a different number.";
    }
    
    /**
     * Handle number input
     */
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
    
    /**
     * Confirm bundle purchase
     */
    private function confirmBundlePurchase($plan, $phoneNumber) {
        try {
            // Get subscriber info
            $lycaApi = new LycaMobileAPI();
            $subscriberInfo = $lycaApi->getSubscriptionInfo($phoneNumber);
            
            $subscriberName = '';
            if ($subscriberInfo && isset($subscriberInfo['subscriberName'])) {
                $subscriberName = $subscriberInfo['subscriberName'] . ' ' . ($subscriberInfo['subscriberSurname'] ?? '');
                
                // Save customer number for future use
                $this->saveCustomerNumber($this->user['id'], $phoneNumber, trim($subscriberName));
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
            
            // Check user balance (if implemented)
            $walletBalance = $this->getUserWalletBalance($this->user['id']);
            if ($walletBalance !== null) {
                $response .= "💳 *Your Balance:* " . Utils::formatCurrency($walletBalance) . "\n\n";
                
                if ($walletBalance < $plan['serviceBundlePrice']) {
                    $response .= "❌ *Insufficient balance!*\n";
                    $response .= "Please top up your wallet or contact support.\n\n";
                    $response .= "Send 'support' for help or 'menu' to go back.";
                    
                    $this->clearUserSession($this->user['id']);
                    return $response;
                }
            }
            
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
    
    /**
     * Handle purchase confirmation
     */
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
    
    /**
     * Process bundle purchase
     */
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
            
            if ($result && $result['responseCode'] == 1) {
                // Success
                $this->updateTransactionStatus($transactionId, 'completed', $result);
                
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
                $errorMsg = $this->getErrorMessage($result['responseCode'] ?? -10017);
                $this->updateTransactionStatus($transactionId, 'failed', $result);
                
                $response = "❌ *Purchase Failed*\n\n";
                $response .= "🔢 *Transaction ID:* " . $transactionId . "\n";
                $response .= "📄 *Reason:* " . $errorMsg . "\n\n";
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
                $response .= ($index + 1) . "️⃣ " . $number['subscription_id'] . 
                            " (" . ($number['subscriber_name'] ?: 'Unknown') . ")\n";
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
                $subscriberName = $subscriberInfo['subscriberName'] . ' ' . ($subscriberInfo['subscriberSurname'] ?? '');
                
                // Save customer number for future use
                $this->saveCustomerNumber($this->user['id'], $phoneNumber, trim($subscriberName));
            }
            
            $response = "🔍 *Airtime Purchase Confirmation*\n\n";
            $response .= "💰 *Amount:* " . Utils::formatCurrency($amount) . "\n";
            $response .= "📞 *Number:* " . $phoneNumber . "\n";
            
            if ($subscriberName) {
                $response .= "👤 *Subscriber:* " . $subscriberName . "\n";
            }
            
            // Check user balance (if implemented)
            $walletBalance = $this->getUserWalletBalance($this->user['id']);
            if ($walletBalance !== null) {
                $response .= "\n💳 *Your Balance:* " . Utils::formatCurrency($walletBalance) . "\n";
                
                if ($walletBalance < $amount) {
                    $response .= "\n❌ *Insufficient balance!*\n";
                    $response .= "Please top up your wallet or contact support.\n\n";
                    $response .= "Send 'support' for help or 'menu' to go back.";
                    
                    $this->clearUserSession($this->user['id']);
                    return $response;
                }
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
            
            if ($result && $result['responseCode'] == 1) {
                // Success
                $this->updateTransactionStatus($transactionId, 'completed', $result);
                
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
                $errorMsg = $this->getErrorMessage($result['responseCode'] ?? -10017);
                $this->updateTransactionStatus($transactionId, 'failed', $result);
                
                $response = "❌ *Airtime Top-up Failed*\n\n";