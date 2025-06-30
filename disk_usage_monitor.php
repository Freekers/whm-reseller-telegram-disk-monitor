<?php
/**
 * WHM Reseller Disk Usage Monitor with Telegram Notifications
 */

// Configuration
class Config {
    const WHM_HOST = 'yourwebhost.com';  // Replace with your WHM URL
    const WHM_USER = 'yourusername'; // Replace with your WHM user
    const WHM_API_TOKEN = 'ABCDEFGHJKLMNOPQRSTUVWXYZ'; // Replace with your WHM API Token
    const WHM_PORT = '2087'; // Default WHM port

    const DEFAULT_THRESHOLD = 90; // Disk usage percentage to trigger alerts
    const LOG_FILE = 'disk_usage_monitor.log'; // Log filename
    const TIMEZONE = 'Europe/Amsterdam'; // Timezone for timestamps
    
    // Telegram Configuration
    const TELEGRAM_BOT_TOKEN = '123456789:ABCDEFGH-123456789'; // Replace with your bot token
    const TELEGRAM_CHAT_ID = '123456789';     // Replace with your channel/chat ID
    
}

class WHMDiskMonitor {
    private $threshold;
    private $logFile;
    private $timezone;
    
    public function __construct() {
        $this->threshold = Config::DEFAULT_THRESHOLD;
        $this->logFile = Config::LOG_FILE;
        $this->timezone = Config::TIMEZONE;
        date_default_timezone_set($this->timezone);
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s T');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }
    
    /**
     * Make WHM API call with enhanced error handling
     */
    private function makeApiCall($endpoint, $params = []) {
        $url = 'https://' . Config::WHM_HOST . ':' . Config::WHM_PORT . '/json-api/' . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $this->log("Making API call to: $endpoint");
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_VERBOSE => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: whm ' . Config::WHM_USER . ':' . Config::WHM_API_TOKEN,
                'User-Agent: WHM-Disk-Monitor/1.0'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Enhanced error logging
        if ($error) {
            $this->log("cURL error: $error");
            throw new Exception("cURL error: $error");
        }
        
        $this->log("HTTP Response Code: $httpCode");
        
        if ($httpCode !== 200) {
            $this->log("HTTP Error Response: " . substr($response, 0, 500));
            
            // Try to parse error response
            $errorData = json_decode($response, true);
            if ($errorData && isset($errorData['errors'])) {
                $errorMsg = implode(', ', $errorData['errors']);
                throw new Exception("API Error: $errorMsg (HTTP $httpCode)");
            } else {
                throw new Exception("HTTP error: $httpCode - Response: " . substr($response, 0, 200));
            }
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON Response: " . substr($response, 0, 500));
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Send message via Telegram Bot API
     */
    private function sendTelegramMessage($message) {
        $url = "https://api.telegram.org/bot" . Config::TELEGRAM_BOT_TOKEN . "/sendMessage";
        
        $data = [
            'chat_id' => Config::TELEGRAM_CHAT_ID,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->log("Telegram cURL error: $error");
            return false;
        }
        
        if ($httpCode !== 200) {
            $this->log("Telegram API error: HTTP $httpCode - $response");
            return false;
        }
        
        $result = json_decode($response, true);
        if (!$result || !$result['ok']) {
            $this->log("Telegram API failed: " . ($result['description'] ?? 'Unknown error'));
            return false;
        }
        
        return true;
    }
    
    /**
     * Test API connection
     */
    public function testConnection() {
        $this->log("Testing API connection...");
        
        try {
            // Try a simple API call first
            $data = $this->makeApiCall('version');
            
            if (isset($data['version'])) {
                $this->log("API connection successful. WHM Version: " . $data['version']);
                return true;
            } else {
                $this->log("Unexpected response format from version API");
                return false;
            }
            
        } catch (Exception $e) {
            $this->log("API connection test failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Test Telegram connection
     */
    public function testTelegram() {
        $this->log("Testing Telegram connection...");
        
        $testMessage = "🔧 <b>WHM Disk Monitor Test</b>\n\n";
        $testMessage .= "This is a test message from your WHM Disk Usage Monitor.\n";
        $testMessage .= "If you receive this, Telegram notifications are working correctly!\n\n";
        $testMessage .= "📅 Test time: " . date('Y-m-d H:i:s');
        
        if ($this->sendTelegramMessage($testMessage)) {
            $this->log("Telegram test message sent successfully");
            return true;
        } else {
            $this->log("Failed to send Telegram test message");
            return false;
        }
    }
    
    private function getAccounts() {
        try {
            $data = $this->makeApiCall('listaccts');
            
            // Check for different possible response structures
            $accounts_data = null;
            if (isset($data['data']['acct'])) {
                $accounts_data = $data['data']['acct'];
            } elseif (isset($data['acct'])) {
                $accounts_data = $data['acct'];
            } else {
                $this->log("API Response structure: " . json_encode(array_keys($data)));
                $this->log("Full response sample: " . json_encode(array_slice($data, 0, 3, true)));
                throw new Exception("Invalid API response format - no account data found");
            }
            
            $accounts = [];
            $totalAccounts = count($accounts_data);
            $this->log("Found $totalAccounts total accounts on server");
            
            foreach ($accounts_data as $account) {
                $this->log("Account: {$account['user']}, Owner: {$account['owner']}");
                
                // Only include accounts owned by this reseller
                if (isset($account['owner']) && $account['owner'] === Config::WHM_USER) {
                    $accounts[] = $account['user'];
                }
            }
            
            $this->log("Found " . count($accounts) . " accounts owned by reseller: " . Config::WHM_USER);
            return $accounts;
            
        } catch (Exception $e) {
            $this->log("Error fetching accounts: " . $e->getMessage());
            return [];
        }
    }
    
    private function getDiskUsage($account) {
        try {
            $data = $this->makeApiCall('accountsummary', ['user' => $account]);
            
            // Check for different possible response structures
            $acct_data = null;
            if (isset($data['data']['acct'][0])) {
                $acct_data = $data['data']['acct'][0];
            } elseif (isset($data['acct'][0])) {
                $acct_data = $data['acct'][0];
            } elseif (isset($data['data']['acct'])) {
                $acct_data = $data['data']['acct'];
            } elseif (isset($data['acct'])) {
                $acct_data = $data['acct'];
            } else {
                $this->log("Account summary response structure for $account: " . json_encode(array_keys($data)));
                if (isset($data['data'])) {
                    $this->log("Data section keys: " . json_encode(array_keys($data['data'])));
                }
                throw new Exception("Account data not found - unexpected response structure");
            }
            
            $diskUsed = floatval($acct_data['diskused'] ?? 0);
            $diskLimit = floatval($acct_data['disklimit'] ?? 0);
            
            if ($diskLimit <= 0) {
                $this->log("Account $account has unlimited or zero disk quota");
                return null;
            }
            
            $percentage = ($diskUsed / $diskLimit) * 100;
            
            return [
                'used' => $diskUsed,
                'limit' => $diskLimit,
                'percentage' => $percentage
            ];
            
        } catch (Exception $e) {
            $this->log("Error fetching usage for $account: " . $e->getMessage());
            return null;
        }
    }
    
    private function sendConsolidatedTelegramAlert($alertData, $totalChecked = 0) {
        if (empty($alertData)) {
            return true;
        }
        
        // Set timezone to Europe/Amsterdam
        
        $message = "🚨 <b>cPanel High Disk Usage Alert</b> 🚨\n\n";
        $message .= "⚠️ <b>" . count($alertData) . " account(s) exceeding threshold of {$this->threshold}%</b>\n\n";
        
        foreach ($alertData as $alert) {
            $account = $alert['account'];
            $usageData = $alert['usage'];
            
            $message .= "📊 <b>Account:</b> <code>$account</code>\n";
            $message .= "💾 <b>Usage:</b> " . number_format($usageData['used'], 1) . "MB / " . number_format($usageData['limit'], 1) . "MB\n";
            $message .= "📈 <b>Percentage:</b> " . number_format($usageData['percentage'], 1) . "%\n";
            
            // Add visual progress bar
            $percentage = $usageData['percentage'];
            $barLength = 10;
            $filledLength = round(($percentage / 100) * $barLength);
            $bar = str_repeat('🟥', $filledLength) . str_repeat('⬜', $barLength - $filledLength);
            $message .= "📊 $bar " . number_format($percentage, 1) . "%\n\n";
        }
        
        // Add monitoring summary to the alert message
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "📋 <b>Monitoring Summary</b>\n\n";
        $message .= "✅ <b>Accounts checked:</b> $totalChecked\n";
        $message .= "🚨 <b>Accounts over threshold:</b> " . count($alertData) . "\n";
        $message .= "🕐 <b>Completed:</b> " . date('Y-m-d H:i:s T');
      
        $success = $this->sendTelegramMessage($message);
        
        if ($success) {
            $this->log("Consolidated Telegram alert sent for " . count($alertData) . " accounts");
        } else {
            $this->log("Failed to send consolidated Telegram alert");
        }
        
        return $success;
    }
    
    public function monitor() {
        
        $this->log("Starting disk usage monitoring");
        $this->log("Threshold: {$this->threshold}%");
        $this->log("Timezone: {$this->timezone}");
        
        // Test connection first
        if (!$this->testConnection()) {
            $this->log("API connection test failed. Please check your credentials.");
            return false;
        }
        
        $accounts = $this->getAccounts();
        
        if (empty($accounts)) {
            $this->log("No accounts found or API error");
            return false;
        }
        
        $alertData = [];
        $accountsChecked = 0;
        
        foreach ($accounts as $account) {
            $this->log("Checking account: $account");
            
            $usageData = $this->getDiskUsage($account);
            
            if ($usageData !== null) {
                $accountsChecked++;
                $percentage = number_format($usageData['percentage'], 1);
                $used = number_format($usageData['used'], 1);
                $limit = number_format($usageData['limit'], 1);
                
                $this->log("  Usage: {$used}MB / {$limit}MB ({$percentage}%)");
                
                if ($usageData['percentage'] >= $this->threshold) {
                    $this->log("  WARNING: Usage exceeds threshold!");
                    $alertData[] = [
                        'account' => $account,
                        'usage' => $usageData
                    ];
                } else {
                    $this->log("  OK: Usage within limits");
                }
            } else {
                $this->log("  ERROR: Could not retrieve usage data");
            }
            
            sleep(1);
        }
        
        // Send consolidated alert with monitoring summary if there are any alerts
        $alertsSent = 0;
        if (!empty($alertData)) {
            if ($this->sendConsolidatedTelegramAlert($alertData, $accountsChecked)) {
                $alertsSent = 1; // One consolidated message sent
            }
        } else {
            // Send summary message even if no alerts (optional - you can remove this if you only want alerts)
            $summaryMessage = "📋 <b>Monitoring Summary</b>\n\n";
            $summaryMessage .= "✅ <b>Accounts checked:</b> $accountsChecked\n";
            $summaryMessage .= "🚨 <b>Accounts over threshold:</b> 0\n";
            $summaryMessage .= "🕐 <b>Completed:</b> " . date('Y-m-d H:i:s T');
            
            $this->sendTelegramMessage($summaryMessage);
        }
        
        $this->log("Monitoring completed");
        $this->log("Accounts checked: $accountsChecked");
        $this->log("Accounts over threshold: " . count($alertData));
        $this->log("Alert messages sent: $alertsSent");
        
        return true;
    }
}

// Command line interface
if (php_sapi_name() === 'cli') {
    $options = getopt('hdt', ['help', 'debug', 'test-telegram']);
    
    if (isset($options['h']) || isset($options['help'])) {
        echo "Usage: php disk_usage_monitor.php [OPTIONS]\n";
        echo "Options:\n";
        echo "  --test-telegram          Send a test message to Telegram\n";
        echo "  -d, --debug              Enable debug mode\n";
        echo "  -h, --help               Show this help message\n";
        exit(0);
    }
    
    // Check configuration - cleaned up validation
    if (Config::TELEGRAM_BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE' ||
        Config::TELEGRAM_CHAT_ID === 'YOUR_CHAT_ID_HERE') {
        echo "Error: Please configure the script with your actual Telegram credentials\n";
        echo "Edit the TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID constants in the Config class\n";
        exit(1);
    }
    
    $monitor = new WHMDiskMonitor();
    
    // Test Telegram if requested
    if (isset($options['test-telegram'])) {
        echo "Testing Telegram connection...\n";
        $success = $monitor->testTelegram();
        exit($success ? 0 : 1);
    }
    
    $success = $monitor->monitor();
    
    exit($success ? 0 : 1);
}
?>