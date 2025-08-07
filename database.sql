-- LycaPay WhatsApp Bot Database Schema
-- Create database
CREATE DATABASE lycapay_bot;
USE lycapay_bot;

-- Users table to store WhatsApp bot users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone_number VARCHAR(20) UNIQUE NOT NULL,
    whatsapp_name VARCHAR(100),
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('active', 'blocked', 'suspended') DEFAULT 'active',
    preferred_language VARCHAR(10) DEFAULT 'en',
    user_type ENUM('customer', 'reseller') DEFAULT 'customer',
    INDEX idx_phone (phone_number),
    INDEX idx_status (status)
);

-- Reseller accounts for authorized dealers
CREATE TABLE resellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    reseller_code VARCHAR(50) UNIQUE NOT NULL,
    api_key VARCHAR(255) NOT NULL,
    wallet_balance DECIMAL(10,2) DEFAULT 0.00,
    commission_rate DECIMAL(5,2) DEFAULT 5.00, -- percentage
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_reseller_code (reseller_code),
    INDEX idx_status (status)
);

-- Customer subscriptions/mobile numbers
CREATE TABLE customer_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subscription_id VARCHAR(20) NOT NULL, -- Mobile number
    subscriber_name VARCHAR(100),
    subscriber_surname VARCHAR(100),
    is_primary BOOLEAN DEFAULT FALSE,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_subscription (user_id, subscription_id),
    INDEX idx_subscription_id (subscription_id)
);

-- Available service bundles/plans
CREATE TABLE service_bundles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_bundle_token VARCHAR(100) UNIQUE NOT NULL,
    service_bundle_name VARCHAR(200) NOT NULL,
    service_bundle_description TEXT,
    service_bundle_price DECIMAL(10,2) NOT NULL,
    recharge_type VARCHAR(50) DEFAULT 'FloatBundle',
    category ENUM('data', 'voice', 'sms', 'combo') DEFAULT 'data',
    validity_days INT DEFAULT 30,
    is_active BOOLEAN DEFAULT TRUE,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_token (service_bundle_token),
    INDEX idx_active (is_active),
    INDEX idx_category (category)
);

-- Transaction history
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(100) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    reseller_id INT NULL,
    subscription_id VARCHAR(20) NOT NULL,
    transaction_type ENUM('bundle', 'airtime') NOT NULL,
    service_bundle_token VARCHAR(100) NULL,
    amount DECIMAL(10,2) NOT NULL,
    commission_amount DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('pending', 'success', 'failed', 'cancelled') DEFAULT 'pending',
    lyca_transaction_id VARCHAR(100) NULL,
    error_code VARCHAR(20) NULL,
    error_message TEXT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_date TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    FOREIGN KEY (service_bundle_token) REFERENCES service_bundles(service_bundle_token),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_subscription_id (subscription_id),
    INDEX idx_created_date (created_date)
);

-- Bot conversations and user states
CREATE TABLE bot_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_state VARCHAR(50) DEFAULT 'idle',
    current_action VARCHAR(100) NULL,
    session_data JSON NULL,
    last_message_id VARCHAR(100) NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_session (user_id),
    INDEX idx_session_state (session_state),
    INDEX idx_expires_at (expires_at)
);

-- Bot configuration settings
CREATE TABLE bot_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT NULL,
    is_system BOOLEAN DEFAULT FALSE,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
);

-- Message logs for debugging and analytics
CREATE TABLE message_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    phone_number VARCHAR(20) NOT NULL,
    message_type ENUM('incoming', 'outgoing') NOT NULL,
    message_content TEXT NOT NULL,
    webhook_data JSON NULL,
    response_data JSON NULL,
    processing_time_ms INT DEFAULT 0,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_phone_number (phone_number),
    INDEX idx_message_type (message_type),
    INDEX idx_created_date (created_date)
);

-- Admin users for dashboard access
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('super_admin', 'admin', 'support') DEFAULT 'support',
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_status (status)
);

-- System notifications and alerts
CREATE TABLE system_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_type ENUM('error', 'warning', 'info', 'success') NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    admin_user_id INT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_type (notification_type),
    INDEX idx_read (is_read),
    INDEX idx_created_date (created_date)
);

-- Insert default bot settings
INSERT INTO bot_settings (setting_key, setting_value, setting_type, description, is_system) VALUES
('api_base_url_test', 'http://10.20.15.12/recharge', 'string', 'Test API base URL', TRUE),
('api_base_url_production', 'http://10.20.15.39:8080/reseller', 'string', 'Production API base URL', TRUE),
('api_environment', 'test', 'string', 'Current API environment (test/production)', TRUE),
('default_api_key', '', 'string', 'Default API key for Lyca Mobile API', TRUE),
('bot_welcome_message', 'Welcome to LycaPay! 🎉\n\nI can help you:\n📱 Buy data bundles\n💰 Top up airtime\n📊 Check your balance\n\nSend *menu* to get started!', 'string', 'Bot welcome message', FALSE),
('max_recharge_per_hour', '5', 'number', 'Maximum recharges per number per hour', FALSE),
('session_timeout_minutes', '30', 'number', 'Session timeout in minutes', FALSE),
('enable_reseller_mode', 'true', 'boolean', 'Enable reseller functionality', FALSE),
('minimum_airtime_amount', '500', 'number', 'Minimum airtime recharge amount in UGX', FALSE),
('support_phone_number', '+256700000000', 'string', 'Support phone number', FALSE);

-- Create indexes for better performance
CREATE INDEX idx_transactions_date_status ON transactions(created_date, status);
CREATE INDEX idx_users_activity ON users(last_activity, status);
CREATE INDEX idx_message_logs_date_user ON message_logs(created_date, user_id);

-- Create a view for transaction analytics
CREATE VIEW transaction_analytics AS
SELECT 
    DATE(created_date) as transaction_date,
    transaction_type,
    status,
    COUNT(*) as transaction_count,
    SUM(amount) as total_amount,
    SUM(commission_amount) as total_commission,
    AVG(amount) as avg_amount
FROM transactions 
GROUP BY DATE(created_date), transaction_type, status;

-- Create a view for user statistics
CREATE VIEW user_statistics AS
SELECT 
    DATE(registration_date) as registration_date,
    user_type,
    status,
    COUNT(*) as user_count
FROM users 
GROUP BY DATE(registration_date), user_type, status;
