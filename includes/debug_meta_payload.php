<?php
/**
 * debug_meta_payload.php - Meta Conversions API Payload Debugger
 * 
 * This script helps debug what we're sending to Meta Conversions API
 * and can help identify why match scores are low.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crypto.php';

/**
 * Enhanced sendToMeta function with detailed payload logging
 */
function sendToMetaWithDebug($pixelId, $accessToken, $eventData, $debugMode = true) {
    $url = META_API_BASE_URL . "/{$pixelId}/events";
    
    // Ensure event data is in array format for batch sending
    if (!isset($eventData[0])) {
        $eventData = [$eventData];
    }
    
    // Validate and prepare event data
    foreach ($eventData as $index => &$event) {
        if (empty($event['event_name'])) {
            logPayloadDebug('ERROR', "Event {$index}: Missing required event_name");
            return ['success' => false, 'error' => 'Missing event_name'];
        }
        
        if (empty($event['event_time'])) {
            $event['event_time'] = time();
        }
        
        if (empty($event['action_source'])) {
            $event['action_source'] = 'website';
        }
        
        if (!isset($event['user_data'])) {
            $event['user_data'] = [];
        }
        
        // Log event structure analysis
        if ($debugMode) {
            analyzeEventStructure($event, $index);
        }
    }
    
    // Build payload
    $payload = [
        'data' => $eventData,
        'access_token' => $accessToken
    ];
    
    // Add test event handling
    if (isset($eventData[0]['test_event']) && $eventData[0]['test_event'] === true) {
        $payload['test_event_code'] = 'TEST' . time();
        unset($eventData[0]['test_event']);
        $payload['data'] = $eventData;
        logPayloadDebug('INFO', 'Test event detected, added test_event_code');
    }
    
    $jsonPayload = json_encode($payload, JSON_PRETTY_PRINT);
    
    // Log complete payload (sanitized)
    if ($debugMode) {
        logFullPayload($payload, $pixelId);
    }
    
    // Initialize cURL with debugging
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: ActionNetwork-MetaConversions/1.0-Debug',
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
        CURLOPT_VERBOSE => false,
        CURLOPT_HEADER => true,
        CURLINFO_HEADER_OUT => true
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    
    // Parse headers and body
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);
    
    curl_close($ch);
    
    // Handle gzip compressed responses
    if (strpos($responseBody, "\x1f\x8b") === 0) {
        $responseBody = gzdecode($responseBody);
    }
    
    // Log detailed response
    if ($debugMode) {
        logResponseDebug($httpCode, $responseHeaders, $responseBody, $curlInfo, $curlError);
    }
    
    // Build result
    $result = [
        'success' => false,
        'response' => $responseBody,
        'headers' => $responseHeaders,
        'http_code' => $httpCode,
        'error' => null,
        'curl_info' => $curlInfo
    ];
    
    if (!empty($curlError)) {
        $result['error'] = 'cURL Error: ' . $curlError;
        logPayloadDebug('ERROR', 'cURL Error: ' . $curlError);
        return $result;
    }
    
    // Parse and analyze response
    $responseData = json_decode($responseBody, true);
    
    if ($httpCode === 200) {
        $result['success'] = true;
        $result['events_received'] = $responseData['events_received'] ?? count($eventData);
        
        // Analyze match quality if available
        if ($debugMode && isset($responseData['messages'])) {
            analyzeMatchQuality($responseData['messages']);
        }
        
        if (empty($responseData['messages'])) {
            $result['events_received'] = count($eventData);
        }
    } else {
        if (isset($responseData['error']['message'])) {
            $result['error'] = $responseData['error']['message'];
        } else {
            $result['error'] = 'HTTP Error ' . $httpCode;
        }
        
        logPayloadDebug('ERROR', 'API Error', [
            'http_code' => $httpCode,
            'error_response' => $responseBody,
            'pixel_id' => $pixelId
        ]);
    }
    
    return $result;
}

/**
 * Analyzes event structure and logs potential issues
 */
function analyzeEventStructure($event, $index) {
    $issues = [];
    $strengths = [];
    
    // Check required fields
    $requiredFields = ['event_name', 'event_time', 'action_source'];
    foreach ($requiredFields as $field) {
        if (empty($event[$field])) {
            $issues[] = "Missing required field: {$field}";
        } else {
            $strengths[] = "Has required field: {$field}";
        }
    }
    
    // Analyze user_data for match quality
    $userData = $event['user_data'] ?? [];
    $userDataFields = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id'];
    $userDataCount = 0;
    
    foreach ($userDataFields as $field) {
        if (!empty($userData[$field])) {
            $userDataCount++;
            
            // Check if data looks hashed
            if (in_array($field, ['em', 'ph', 'fn', 'ln']) && strlen($userData[$field]) === 64 && ctype_xdigit($userData[$field])) {
                $strengths[] = "User data field '{$field}' is properly hashed (SHA256)";
            } elseif (in_array($field, ['em', 'ph', 'fn', 'ln'])) {
                $issues[] = "User data field '{$field}' may not be hashed or normalized properly";
            }
        }
    }
    
    if ($userDataCount === 0) {
        $issues[] = "No user_data fields provided - this will severely impact match quality";
    } elseif ($userDataCount < 3) {
        $issues[] = "Only {$userDataCount} user_data fields provided - consider adding more for better match quality";
    } else {
        $strengths[] = "Good user_data coverage: {$userDataCount} fields provided";
    }
    
    // Check for client-side data
    if (!empty($userData['fbc']) || !empty($userData['fbp'])) {
        $strengths[] = "Has Facebook browser identifier(s) for better attribution";
    } else {
        $issues[] = "Missing Facebook browser identifiers (fbc/fbp) - consider integrating JavaScript tracker";
    }
    
    // Check event_id for deduplication
    if (!empty($event['event_id'])) {
        $strengths[] = "Has event_id for deduplication";
    } else {
        $issues[] = "Missing event_id - may cause duplicate events";
    }
    
    // Log analysis
    logPayloadDebug('DEBUG', "Event {$index} Structure Analysis", [
        'event_name' => $event['event_name'] ?? 'MISSING',
        'user_data_fields' => $userDataCount,
        'issues' => $issues,
        'strengths' => $strengths
    ]);
}

/**
 * Logs the complete payload in a readable format (sanitized)
 */
function logFullPayload($payload, $pixelId) {
    // Create sanitized version for logging
    $sanitizedPayload = $payload;
    
    // Mask access token
    if (isset($sanitizedPayload['access_token'])) {
        $token = $sanitizedPayload['access_token'];
        $sanitizedPayload['access_token'] = substr($token, 0, 10) . '***' . substr($token, -5);
    }
    
    // Mask any raw PII in user_data (though it should be hashed)
    foreach ($sanitizedPayload['data'] as &$event) {
        if (isset($event['user_data'])) {
            foreach ($event['user_data'] as $key => &$value) {
                if (strlen($value) !== 64 || !ctype_xdigit($value)) {
                    // If not a SHA256 hash, mask it
                    if (strlen($value) > 4) {
                        $value = substr($value, 0, 2) . '***' . substr($value, -2);
                    }
                }
            }
        }
    }
    
    logPayloadDebug('DEBUG', 'Complete Payload Being Sent', [
        'pixel_id' => $pixelId,
        'api_url' => META_API_BASE_URL . "/{$pixelId}/events",
        'payload' => $sanitizedPayload,
        'payload_size_bytes' => strlen(json_encode($payload))
    ]);
}

/**
 * Logs detailed response information
 */
function logResponseDebug($httpCode, $headers, $body, $curlInfo, $curlError) {
    $responseData = json_decode($body, true);
    
    logPayloadDebug('DEBUG', 'Meta API Response Details', [
        'http_code' => $httpCode,
        'response_time_ms' => round(($curlInfo['total_time'] ?? 0) * 1000),
        'response_size_bytes' => strlen($body),
        'curl_error' => $curlError ?: null,
        'content_type' => $curlInfo['content_type'] ?? null
    ]);
    
    if ($responseData) {
        logPayloadDebug('DEBUG', 'Parsed Response Data', $responseData);
    } else {
        logPayloadDebug('WARNING', 'Could not parse response as JSON', ['raw_response' => $body]);
    }
    
    // Log response headers for debugging
    $headerLines = explode("\n", $headers);
    $parsedHeaders = [];
    foreach ($headerLines as $line) {
        if (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $parsedHeaders[trim($key)] = trim($value);
        }
    }
    
    if (!empty($parsedHeaders)) {
        logPayloadDebug('DEBUG', 'Response Headers', $parsedHeaders);
    }
}

/**
 * Analyzes Meta's match quality messages
 */
function analyzeMatchQuality($messages) {
    foreach ($messages as $message) {
        if (isset($message['message'])) {
            $msg = $message['message'];
            $level = 'INFO';
            
            // Categorize message types
            if (stripos($msg, 'warning') !== false || stripos($msg, 'low') !== false) {
                $level = 'WARNING';
            } elseif (stripos($msg, 'error') !== false) {
                $level = 'ERROR';
            }
            
            logPayloadDebug($level, 'Meta Match Quality Message', [
                'message' => $msg,
                'full_message_data' => $message
            ]);
            
            // Provide specific advice based on message content
            if (stripos($msg, 'match quality') !== false) {
                logPayloadDebug('INFO', 'Match Quality Advice', [
                    'suggestion' => 'Low match quality detected. Consider:',
                    'tips' => [
                        'Ensure all PII is properly hashed with SHA256',
                        'Include more user data fields (email, phone, name, location)',
                        'Add Facebook browser identifiers (fbc, fbp) via JavaScript',
                        'Verify data normalization (lowercase, trim, etc.)',
                        'Check that phone numbers include country codes'
                    ]
                ]);
            }
        }
    }
}

/**
 * Dedicated debug logging function
 */
function logPayloadDebug($level, $message, $context = []) {
    $logFile = dirname(__DIR__) . '/logs/meta_payload_debug.log';
    
    // Ensure directory exists
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}";
    
    if (!empty($context)) {
        $logEntry .= "\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    $logEntry .= "\n" . str_repeat('-', 80) . "\n";
    
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Test function to analyze a sample payload
 */
function testPayloadAnalysis() {
    logPayloadDebug('INFO', 'Starting Payload Analysis Test');
    
    // Sample event data to test
    $sampleEvent = [
        'event_name' => 'Lead',
        'event_time' => time(),
        'action_source' => 'website',
        'event_id' => generateEventId('test@example.com'),
        'user_data' => [
            'em' => hashData('test@example.com', 'email'),
            'ph' => hashData(processPhoneNumber('666123456'), 'phone'),
            'fn' => hashData('John', 'name'),
            'ln' => hashData('Doe', 'name'),
            'ct' => hashData('Madrid', 'city'),
            'country' => hashData('es', 'country')
        ],
        'custom_data' => [
            'source' => 'action_network_test'
        ]
    ];
    
    // Analyze without sending
    analyzeEventStructure($sampleEvent, 0);
    
    logPayloadDebug('INFO', 'Test Analysis Complete - Check logs for insights');
    
    return $sampleEvent;
}

// If called directly, run test analysis
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    echo "Meta Payload Debug Tool\n";
    echo "======================\n\n";
    
    echo "Running test analysis...\n";
    $testEvent = testPayloadAnalysis();
    
    echo "Test event generated:\n";
    echo json_encode($testEvent, JSON_PRETTY_PRINT) . "\n\n";
    
    echo "Check logs/meta_payload_debug.log for detailed analysis.\n\n";
    
    // Show recent debug log entries
    $debugLog = __DIR__ . '/logs/meta_payload_debug.log';
    if (file_exists($debugLog)) {
        echo "Recent debug log entries:\n";
        echo "------------------------\n";
        $lines = file($debugLog);
        $recentLines = array_slice($lines, -50); // Last 50 lines
        echo implode('', $recentLines);
    }
}

?>