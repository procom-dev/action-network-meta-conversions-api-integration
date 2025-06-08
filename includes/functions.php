<?php
/**
 * Functions.php - Helper functions for Meta Conversions API Integration
 * 
 * Contains all utility functions for hashing, API calls, logging, and data processing
 */

require_once __DIR__ . '/crypto.php';

/**
 * Meta API Configuration
 */
define('META_API_VERSION', 'v23.0');
define('META_API_BASE_URL', 'https://graph.facebook.com/' . META_API_VERSION);

/**
 * Hashes data for Meta API (SHA256)
 * Meta requires certain user data to be hashed before sending
 * 
 * @param string $data Raw data to hash
 * @return string|null SHA256 hashed and normalized data
 */
function hashData($data, $type = 'default') {
    if (empty($data)) {
        return null;
    }
    
    // Type-specific normalization per Meta documentation
    switch ($type) {
        case 'email':
            $normalized = strtolower(trim($data));
            break;
        case 'phone':
            // Remove all non-numeric characters, then lowercase and trim
            $normalized = strtolower(trim(preg_replace('/[^0-9]/', '', $data)));
            break;
        case 'name':
            // Remove extra spaces, lowercase and trim
            $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $data)));
            break;
        case 'city':
        case 'state':
        case 'country':
            // Remove extra spaces, lowercase and trim, remove special chars
            $normalized = strtolower(trim(preg_replace('/[^a-zA-Z\s]/', '', $data)));
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            break;
        case 'zip':
            // Remove spaces and convert to lowercase
            $normalized = strtolower(str_replace(' ', '', trim($data)));
            break;
        default:
            // Default normalization: lowercase and trim
            $normalized = strtolower(trim($data));
            break;
    }
    
    // Hash with SHA256
    return hash('sha256', $normalized);
}

/**
 * Sends event data to Meta Conversions API
 * 
 * @param string $pixelId Meta Pixel ID
 * @param string $accessToken Access token for API
 * @param array $eventData Event data array
 * @return array Response array with 'success', 'response', 'http_code', 'error'
 */
function sendToMeta($pixelId, $accessToken, $eventData) {
    $url = META_API_BASE_URL . "/{$pixelId}/events";
    
    // Ensure event data is in array format for batch sending
    if (!isset($eventData[0])) {
        $eventData = [$eventData];
    }
    
    // Validate critical parameters per Meta v23.0 requirements
    foreach ($eventData as &$event) {
        // Ensure required fields exist
        if (empty($event['event_name'])) {
            quickLog('Missing required event_name', 'ERROR');
            return ['success' => false, 'error' => 'Missing event_name'];
        }
        
        if (empty($event['event_time'])) {
            $event['event_time'] = time();
        }
        
        if (empty($event['action_source'])) {
            $event['action_source'] = 'website';
        }
        
        // Ensure user_data exists
        if (!isset($event['user_data'])) {
            $event['user_data'] = [];
        }
    }
    
    // Build payload
    $payload = [
        'data' => $eventData,
        'access_token' => $accessToken
    ];
    
    // Add test_event_code if this is a test event
    if (isset($eventData[0]['test_event']) && $eventData[0]['test_event'] === true) {
        $payload['test_event_code'] = 'TEST' . time();
        // Remove test_event flag from actual event data
        unset($eventData[0]['test_event']);
        $payload['data'] = $eventData;
    }
    
    $jsonPayload = json_encode($payload);
    
    // Log what we're sending (without access token)
    quickLog('Sending to Meta API', 'DEBUG', [
        'url' => $url,
        'pixel_id' => $pixelId,
        'event_count' => count($eventData),
        'payload_size' => strlen($jsonPayload)
    ]);
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: ActionNetwork-MetaConversions/1.0',
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_VERBOSE => false
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);
    
    // Handle gzip compressed responses
    if (strpos($response, "\x1f\x8b") === 0) {
        $response = gzdecode($response);
    }
    
    // Log response details
    quickLog('Meta API response', 'DEBUG', [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'response_size' => strlen($response),
        'total_time' => $curlInfo['total_time'] ?? 0
    ]);
    
    // Build result array
    $result = [
        'success' => false,
        'response' => $response,
        'http_code' => $httpCode,
        'error' => null
    ];
    
    // Handle cURL errors
    if (!empty($curlError)) {
        $result['error'] = 'cURL Error: ' . $curlError;
        quickLog('Meta API cURL error: ' . $curlError, 'ERROR');
        return $result;
    }
    
    // Parse response
    $responseData = json_decode($response, true);
    
    // Check for success
    if ($httpCode === 200) {
        if (isset($responseData['events_received'])) {
            $result['success'] = true;
            $result['events_received'] = $responseData['events_received'];
            
            // Log Event Match Quality data if available (Meta v23.0 feature)
            if (isset($responseData['messages'])) {
                foreach ($responseData['messages'] as $message) {
                    if (isset($message['message'])) {
                        quickLog('Meta API message: ' . $message['message'], 'INFO');
                    }
                }
            }
        } elseif (isset($responseData['messages']) && empty($responseData['messages'])) {
            // Empty messages array also indicates success
            $result['success'] = true;
            $result['events_received'] = count($eventData);
        }
    } else {
        // Parse error message
        if (isset($responseData['error']['message'])) {
            $result['error'] = $responseData['error']['message'];
        } else {
            $result['error'] = 'HTTP Error ' . $httpCode;
        }
        
        // Log the full error response
        quickLog('Meta API error', 'ERROR', [
            'http_code' => $httpCode,
            'error_response' => $response,
            'pixel_id' => $pixelId
        ]);
    }
    
    return $result;
}

/**
 * Quick logging function for debugging and monitoring
 * 
 * @param string $message Log message
 * @param string $level Log level (INFO, ERROR, WARNING, DEBUG)
 * @param array $context Additional context data
 */
function quickLog($message, $level = 'INFO', $context = []) {
    // Ensure logs directory exists
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    // Rotate logs daily
    $date = date('Y-m-d');
    $logFile = $logDir . '/app-' . $date . '.log';
    
    // Clean old logs (older than 30 days)
    require_once __DIR__ . '/helpers.php';
    cleanOldLogs($logDir, 30);
    
    // Format timestamp
    $timestamp = date('Y-m-d H:i:s');
    
    // Create structured log entry
    $logEntry = [
        'timestamp' => $timestamp,
        'level' => $level,
        'message' => $message,
        'context' => $context
    ];
    
    // Format as JSON for better parsing
    $jsonEntry = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    
    // Write to file
    @file_put_contents($logFile, $jsonEntry, FILE_APPEND | LOCK_EX);
    
    // Also write to level-specific files
    if ($level === 'ERROR') {
        $errorFile = $logDir . '/error-' . $date . '.log';
        @file_put_contents($errorFile, $jsonEntry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Generates event ID for deduplication
 * Uses email + timestamp rounded to nearest minute
 * 
 * @param string $email User email
 * @param int $timestamp Event timestamp (optional)
 * @return string Event ID hash
 */
function generateEventId($email, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    
    // Round timestamp to nearest 30 minutes (1800 seconds) for deduplication window
    // This allows for webhook delays of up to 30 minutes while still deduplicating
    $roundedTime = floor($timestamp / 1800) * 1800;
    
    // Normalize email
    $normalizedEmail = strtolower(trim($email));
    
    // Generate hash
    return hash('sha256', $normalizedEmail . '_' . $roundedTime);
}

/**
 * Generates event ID with fbclid priority for perfect pairing
 * Same algorithm as JavaScript version for webhook/browser event deduplication
 * 
 * @param string $email User email (optional)
 * @param string $fbclid Facebook click ID from Action Network form (optional)
 * @param int $timestamp Event timestamp (optional)
 * @return string Event ID hash
 */
function generateEventIdWithFbclid($email = null, $fbclid = null, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    
    // Method 1: fbclid-based (most reliable for pairing)
    if (!empty($fbclid)) {
        $emailPart = !empty($email) ? strtolower(trim($email)) : 'no_email';
        $input = $fbclid . '_' . $emailPart;
        return hash('sha256', $input);
    }
    
    // Method 2: email + timestamp (fallback for non-Facebook traffic)
    if (!empty($email)) {
        // Round timestamp to nearest 30 minutes (1800 seconds) for deduplication window
        $roundedTime = floor($timestamp / 1800) * 1800;
        $normalizedEmail = strtolower(trim($email));
        $input = $normalizedEmail . '_' . $roundedTime;
        return hash('sha256', $input);
    }
    
    return null;
}

/**
 * Generates consistent event ID using alternative identifiers when email is not available
 * 
 * @param string $identifier Alternative identifier (external_id, phone, etc.)
 * @param int $timestamp Event timestamp (optional)
 * @param string $type Type of identifier ('external_id', 'phone', etc.)
 * @return string Event ID hash
 */
function generateAlternativeEventId($identifier, $timestamp = null, $type = 'external_id') {
    if (empty($identifier)) {
        return null;
    }
    
    if ($timestamp === null) {
        $timestamp = time();
    }
    
    // Round timestamp to nearest 15 minutes (900 seconds) for deduplication window
    // This allows for webhook delays of up to 15 minutes while still deduplicating
    $roundedTime = floor($timestamp / 900) * 900;
    
    // Normalize identifier based on type
    if ($type === 'phone') {
        $normalizedId = processPhoneNumber($identifier);
    } else {
        $normalizedId = strtolower(trim($identifier));
    }
    
    // Generate hash with type prefix to avoid collisions
    return hash('sha256', $type . '_' . $normalizedId . '_' . $roundedTime);
}

/**
 * Processes phone numbers for Meta API
 * Handles Spanish numbers and international formats
 * 
 * @param string $phone Raw phone number
 * @param string $defaultCountryCode Default country code (e.g., '34' for Spain)
 * @return string|null Processed phone number
 */
function processPhoneNumber($phone, $defaultCountryCode = '34') {
    if (empty($phone)) {
        return null;
    }
    
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Remove leading zeros
    $phone = ltrim($phone, '0');
    
    // Handle Spanish mobile numbers (9 digits starting with 6 or 7)
    if (strlen($phone) === 9 && in_array(substr($phone, 0, 1), ['6', '7'])) {
        $phone = $defaultCountryCode . $phone;
    }
    
    // Validate minimum length
    if (strlen($phone) < 10) {
        return null;
    }
    
    return $phone;
}

/**
 * Detects if webhook data is from Action Network test
 * 
 * @param array $webhookData Raw webhook data
 * @return bool True if test webhook
 */
function isTestWebhook($webhookData) {
    if (!is_array($webhookData) || empty($webhookData)) {
        return false;
    }
    
    // Get first item data
    $firstItem = $webhookData[0] ?? [];
    $signature = $firstItem['osdi:signature'] ?? [];
    $person = $signature['person'] ?? [];
    
    // Test patterns used by Action Network
    $testPatterns = [
        'emails' => ['jsmith@mail.com', 'test@example.com', 'demo@example.com'],
        'names' => ['john smith', 'jane doe', 'test user', 'demo user'],
        'phones' => ['11234567890', '1234567890', '5555555555'],
        'ids' => ['d6bdf50e-c3a4-4981-a948-3d8c086066d7'] // Known test UUID
    ];
    
    // Check email
    $email = $person['email_addresses'][0]['address'] ?? '';
    if (in_array(strtolower($email), $testPatterns['emails'])) {
        return true;
    }
    
    // Check name
    $fullName = strtolower(trim(
        ($person['given_name'] ?? '') . ' ' . ($person['family_name'] ?? '')
    ));
    if (in_array($fullName, $testPatterns['names'])) {
        return true;
    }
    
    // Check phone
    $phone = $person['phone_numbers'][0]['number'] ?? '';
    if (in_array($phone, $testPatterns['phones'])) {
        return true;
    }
    
    // Check identifier
    $identifier = $signature['identifiers'][0] ?? '';
    foreach ($testPatterns['ids'] as $testId) {
        if (strpos($identifier, $testId) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Logs test webhook for wizard verification
 * 
 * @param string $pixelId
 * @param string $accessToken
 */
function logTestWebhook($pixelId, $accessToken) {
    // Simple file-based test tracking - use hash as key, not pixel ID
    $testFile = __DIR__ . '/../test_webhooks.json';
    
    // Generate hash for this pixel/token combination
    $hash = Crypto::encrypt($pixelId, $accessToken);
    
    // Read existing tests
    $tests = [];
    if (file_exists($testFile)) {
        $content = @file_get_contents($testFile);
        if ($content) {
            $tests = json_decode($content, true) ?: [];
        }
    }
    
    // Create unique key using the hash (same as webhook URL)
    $testKey = $hash;
    $tests[$testKey] = [
        'timestamp' => time(),
        'pixel_id' => $pixelId,
        'hash' => $hash,
        'time_ago' => 'just now'
    ];
    
    // Clean old tests (older than 1 minute)
    $tests = array_filter($tests, function($test) {
        return (time() - $test['timestamp']) < 60;
    });
    
    // Save
    @file_put_contents($testFile, json_encode($tests), LOCK_EX);
}

/**
 * Logs script test for wizard verification
 * 
 * @param string $pixelId
 */
function logScriptTest($pixelId) {
    // Simple file-based script test tracking
    $testFile = __DIR__ . '/../script_tests.json';
    
    // Read existing tests
    $tests = [];
    if (file_exists($testFile)) {
        $content = @file_get_contents($testFile);
        if ($content) {
            $tests = json_decode($content, true) ?: [];
        }
    }
    
    // Create unique key for this pixel
    $testKey = $pixelId;
    $tests[$testKey] = [
        'timestamp' => time(),
        'pixel_id' => $pixelId,
        'events_count' => isset($tests[$testKey]) ? ($tests[$testKey]['events_count'] ?? 1) + 1 : 1
    ];
    
    // Clean old tests (older than 1 minute)
    $tests = array_filter($tests, function($test) {
        return (time() - $test['timestamp']) < 60;
    });
    
    // Save
    @file_put_contents($testFile, json_encode($tests), LOCK_EX);
}


/**
 * Gets all headers (compatibility function)
 * 
 * @return array
 */
if (!function_exists('getAllHeaders')) {
    function getAllHeaders() {
        $headers = [];
        
        // Try getallheaders() first
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        
        // Fallback: Parse from $_SERVER
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $header = str_replace('_', '-', substr($key, 5));
                $header = str_replace(' ', '-', ucwords(strtolower($header), '-'));
                $headers[$header] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $header = str_replace('_', '-', $key);
                $header = str_replace(' ', '-', ucwords(strtolower($header), '-'));
                $headers[$header] = $value;
            }
        }
        
        return $headers;
    }
}

/**
 * Validates Meta Pixel ID format (more flexible)
 * 
 * @param string $pixelId
 * @return bool
 */
function isValidPixelId($pixelId) {
    // More flexible validation - just check if it's numeric and has reasonable length
    return preg_match('/^\d{10,20}$/', $pixelId);
}

/**
 * Validates Meta Access Token format (more flexible)
 * 
 * @param string $accessToken
 * @return bool
 */
function isValidAccessToken($accessToken) {
    // More flexible validation - allow underscores, hyphens, and other chars
    return preg_match('/^EAA[a-zA-Z0-9_-]+$/', $accessToken) && strlen($accessToken) > 20;
}

/**
 * Gets client IP address (considers proxies)
 * 
 * @return string
 */
function getClientIp() {
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            // Handle comma-separated IPs
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
            // Validate IP
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Extracts Facebook Click ID from URL or cookies
 * 
 * @param string $url Current URL
 * @param array $cookies Cookie array
 * @return string|null
 */
function extractFbclid($url = '', $cookies = []) {
    // Try to get from URL first
    if (!empty($url)) {
        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $params);
            if (!empty($params['fbclid'])) {
                return $params['fbclid'];
            }
        }
    }
    
    // Try from cookies (fbc cookie contains fbclid)
    if (!empty($cookies['_fbc'])) {
        // fbc format: fb.1.timestamp.fbclid
        $parts = explode('.', $cookies['_fbc']);
        if (count($parts) >= 4) {
            return $parts[3];
        }
    }
    
    return null;
}
?>