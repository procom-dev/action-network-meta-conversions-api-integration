<?php
/**
 * Webhook.php - Endpoint for receiving Action Network webhooks
 * 
 * Processes form submission webhooks and sends data to Meta Conversions API
 * Must respond quickly (under 500ms) to avoid Action Network timeouts
 */

// Set response headers immediately
header('Content-Type: text/plain');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Include required files
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/debug_meta_payload.php';
require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/helpers.php';

// Start output buffering to control response
ob_start();

/**
 * Quick response function
 */
function respondQuickly($code = 200, $message = 'OK') {
    http_response_code($code);
    echo $message;
    
    // Calculate content length
    $size = ob_get_length();
    header("Content-Length: $size");
    header('Connection: close');
    
    // Flush all output
    ob_end_flush();
    ob_flush();
    flush();
    
    // Continue processing in background if possible
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondQuickly(405, 'Method Not Allowed');
    exit;
}

// Get hash from URL parameter
$hash = $_GET['id'] ?? '';

// Validate hash
if (empty($hash) || !Crypto::isValidHashFormat($hash)) {
    quickLog('Webhook received with invalid hash format', 'ERROR');
    respondQuickly(400, 'Bad Request');
    exit;
}

// Decrypt configuration
$config = Crypto::decrypt($hash);
if (!$config) {
    quickLog('Webhook received with invalid hash', 'ERROR');
    respondQuickly(401, 'Unauthorized');
    exit;
}

// Extract configuration
$pixelId = $config['pixel_id'];
$accessToken = $config['access_token'];
// Event type is now auto-detected based on Action Network webhook content

// Get raw input
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    respondQuickly(400, 'Empty Body');
    exit;
}

// Parse JSON
$webhookData = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($webhookData)) {
    quickLog('Invalid JSON in webhook', 'ERROR', ['error' => json_last_error_msg()]);
    respondQuickly(400, 'Invalid JSON');
    exit;
}

// Check if this is a test webhook
$isTest = isTestWebhook($webhookData);

// Log webhook receipt (without sensitive data)
quickLog('Webhook received', $isTest ? 'DEBUG' : 'INFO', [
    'pixel_id' => $pixelId,
    'is_test' => $isTest,
    'auto_detect' => 'event_type_will_be_detected'
]);

// Log complete webhook payload to dedicated webhooks.log file
$webhookLogData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'pixel_id' => $pixelId,
    'is_test' => $isTest,
    'auto_detect' => 'event_type_will_be_detected',
    'headers' => getAllHeaders(),
    'raw_payload' => $rawInput,
    'parsed_payload' => $webhookData,
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'query_string' => $_SERVER['QUERY_STRING'] ?? ''
];

$webhookLogFile = __DIR__ . '/logs/webhooks.log';
$logEntry = "[" . date('Y-m-d H:i:s') . "] WEBHOOK RECEIVED\n" . 
           json_encode($webhookLogData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . 
           "\n" . str_repeat('=', 80) . "\n\n";

@file_put_contents($webhookLogFile, $logEntry, FILE_APPEND | LOCK_EX);

// Debug: Log webhook structure for analysis
if (!$isTest) {
    quickLog('DEBUG: Full webhook structure analysis', 'DEBUG', [
        'top_level_keys' => array_keys($webhookData[0] ?? []),
        'has_osdi_signature' => isset($webhookData[0]['osdi:signature']),
        'has_osdi_submission' => isset($webhookData[0]['osdi:submission']),
        'has_osdi_outreach' => isset($webhookData[0]['osdi:outreach']),
        'has_osdi_donation' => isset($webhookData[0]['osdi:donation']),
        'has_osdi_attendance' => isset($webhookData[0]['osdi:attendance'])
    ]);
}

// If test webhook, log it BEFORE responding to ensure it's written
if ($isTest) {
    logTestWebhook($pixelId, $accessToken);
    quickLog('Test webhook processed for pixel: ' . $pixelId, 'INFO');
}

// RESPOND IMMEDIATELY to meet 500ms requirement
respondQuickly(200, 'OK');

// === BACKGROUND PROCESSING STARTS HERE ===

// If test webhook, we already logged it, so just exit
if ($isTest) {
    exit;
}

// Process real webhook data
try {
    // Action Network sends an array with one object
    if (!isset($webhookData[0])) {
        throw new Exception('Invalid webhook format - expected array');
    }
    
    $actionData = $webhookData[0];
    
    // Action Network can send different types: signature, submission, outreach, donation, attendance
    $actionTypes = ['osdi:signature', 'osdi:submission', 'osdi:outreach', 'osdi:donation', 'osdi:attendance'];
    $actionRecord = null;
    $actionType = null;
    
    foreach ($actionTypes as $type) {
        if (isset($actionData[$type])) {
            $actionRecord = $actionData[$type];
            $actionType = $type;
            break;
        }
    }
    
    if (!$actionRecord) {
        throw new Exception('No recognized action type found in webhook');
    }
    
    quickLog('Action Network webhook type detected: ' . $actionType, 'DEBUG');
    
    // Auto-detect Meta event type based on Action Network action type
    if ($actionType === 'osdi:donation') {
        $eventType = 'Donate';
        quickLog('Donation detected - mapped to Donate event', 'INFO');
    } else {
        $eventType = 'CompleteRegistration';
        quickLog('Non-donation action detected - mapped to CompleteRegistration event', 'INFO', ['action_type' => $actionType]);
    }
    
    $person = $actionRecord['person'] ?? [];
    
    // Extract person data
    $email = $person['email_addresses'][0]['address'] ?? null;
    $phone = $person['phone_numbers'][0]['number'] ?? null;
    $firstName = $person['given_name'] ?? null;
    $lastName = $person['family_name'] ?? null;
    
    // Debug: Log person structure if email is missing
    if (empty($email)) {
        quickLog('DEBUG: Webhook person structure analysis', 'DEBUG', [
            'has_person' => !empty($person),
            'has_email_addresses' => isset($person['email_addresses']),
            'email_addresses_count' => isset($person['email_addresses']) ? count($person['email_addresses']) : 0,
            'email_addresses_structure' => $person['email_addresses'] ?? 'missing',
            'person_keys' => array_keys($person)
        ]);
    }
    
    // Extract fbclid from form submission (server-side tracking!)
    $fbclid = null;
    
    // Method 1: Check if fbclid is in person custom fields
    if (isset($person['custom_fields']) && is_array($person['custom_fields'])) {
        foreach ($person['custom_fields'] as $field => $value) {
            if (strtolower($field) === 'fbclid' || strpos(strtolower($field), 'fbclid') !== false) {
                $fbclid = $value;
                break;
            }
        }
    }
    
    // Method 2: Check if fbclid is in action record custom fields
    if (!$fbclid && isset($actionRecord['custom_fields']) && is_array($actionRecord['custom_fields'])) {
        foreach ($actionRecord['custom_fields'] as $field => $value) {
            if (strtolower($field) === 'fbclid' || strpos(strtolower($field), 'fbclid') !== false) {
                $fbclid = $value;
                break;
            }
        }
    }
    
    // Method 3: Check action network specific answer fields (for forms)
    if (!$fbclid && isset($actionRecord['answers']) && is_array($actionRecord['answers'])) {
        foreach ($actionRecord['answers'] as $answer) {
            if (isset($answer['key']) && (strtolower($answer['key']) === 'fbclid' || strpos(strtolower($answer['key']), 'fbclid') !== false)) {
                $fbclid = $answer['value'] ?? null;
                break;
            }
        }
    }
    
    // Method 4: Check top-level action data for any fbclid field
    if (!$fbclid) {
        foreach ($actionRecord as $key => $value) {
            if (strtolower($key) === 'fbclid' || strpos(strtolower($key), 'fbclid') !== false) {
                $fbclid = is_string($value) ? $value : null;
                break;
            }
        }
    }
    
    // Validate fbclid format (should be alphanumeric with underscores/dots)
    if ($fbclid && !preg_match('/^[a-zA-Z0-9._-]+$/', $fbclid)) {
        quickLog('Invalid fbclid format detected, ignoring', 'WARNING', ['fbclid' => substr($fbclid, 0, 20) . '***']);
        $fbclid = null;
    }
    
    if ($fbclid) {
        quickLog('🎉 Server-side fbclid detected from Action Network form!', 'INFO', [
            'fbclid_length' => strlen($fbclid),
            'fbclid_preview' => substr($fbclid, 0, 15) . '***'
        ]);
    }
    
    // Extract address
    $address = $person['postal_addresses'][0] ?? [];
    $city = $address['locality'] ?? null;
    $state = $address['region'] ?? null;
    $zipCode = $address['postal_code'] ?? null;
    $country = $address['country'] ?? null;
    
    // Extract identifiers
    $externalId = null;
    if (isset($actionRecord['_links']['osdi:person']['href'])) {
        // Extract ID from URL like: https://actionnetwork.org/api/v2/people/12345-6789-abcd
        $personUrl = $actionRecord['_links']['osdi:person']['href'];
        if (preg_match('/\/([a-f0-9\-]+)$/', $personUrl, $matches)) {
            $externalId = $matches[1];
        }
    }
    
    // Get event timestamp
    $eventTime = isset($actionRecord['created_date']) ? strtotime($actionRecord['created_date']) : time();
    
    // 🎯 NEW: Generate event ID with fbclid priority for perfect pairing (same logic as JavaScript)
    $eventId = generateEventIdWithFbclid($email, $fbclid, $eventTime);
    
    if ($eventId) {
        if (!empty($fbclid)) {
            quickLog('🆔 Generated fbclid-based event_id for perfect pairing', 'INFO', [
                'fbclid_length' => strlen($fbclid),
                'event_id_preview' => substr($eventId, 0, 12) . '***',
                'method' => 'fbclid_priority'
            ]);
        } else {
            quickLog('🆔 Generated email+timestamp event_id', 'DEBUG', [
                'event_id_preview' => substr($eventId, 0, 12) . '***',
                'method' => 'email_timestamp'
            ]);
        }
    } elseif (!empty($externalId)) {
        // Use Action Network person ID as fallback for deduplication
        $eventId = generateAlternativeEventId($externalId, $eventTime, 'external_id');
        quickLog('Generated alternative event_id using external_id', 'DEBUG', [
            'external_id' => substr($externalId, 0, 8) . '***',
            'event_id' => substr($eventId, 0, 12) . '***'
        ]);
    } elseif (!empty($phone)) {
        // Use phone as last resort for deduplication
        $eventId = generateAlternativeEventId($phone, $eventTime, 'phone');
        quickLog('Generated alternative event_id using phone', 'DEBUG', [
            'phone_hash' => substr(hash('sha256', $phone), 0, 8) . '***',
            'event_id' => substr($eventId, 0, 12) . '***'
        ]);
    }
    
    
    // Build user data for Meta
    $userData = [];
    
    // Hash personal data with type-specific normalization
    if ($email) $userData['em'] = hashData($email, 'email');
    
    if ($phone) {
        $processedPhone = processPhoneNumber($phone);
        if ($processedPhone) {
            $userData['ph'] = hashData($processedPhone, 'phone');
        }
    }
    
    if ($firstName) $userData['fn'] = hashData($firstName, 'name');
    if ($lastName) $userData['ln'] = hashData($lastName, 'name');
    if ($city) $userData['ct'] = hashData($city, 'city');
    if ($state) $userData['st'] = hashData($state, 'state');
    if ($zipCode) $userData['zp'] = hashData($zipCode, 'zip');
    if ($country) $userData['country'] = hashData($country, 'country');
    
    // Add external_id for improved Event Match Quality
    if ($externalId) {
        $userData['external_id'] = $externalId; // Action Network person ID
    } elseif ($email) {
        // Fallback: use hashed email as external_id for consistent tracking
        $userData['external_id'] = hashData($email, 'email');
    }
    
    // Server events don't have browser data, but we can add server IP
    $userData['client_ip_address'] = getClientIp();
    $userData['client_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Try to get Facebook browser identifiers from headers (if passed by JavaScript)
    $headers = getAllHeaders();
    if (!empty($headers['X-FB-FBC'])) {
        $userData['fbc'] = $headers['X-FB-FBC'];
    }
    if (!empty($headers['X-FB-FBP'])) {
        $userData['fbp'] = $headers['X-FB-FBP'];
    }
    
    // 🚀 SERVER-SIDE FBCLID SUPPORT - Convert Action Network fbclid to Meta fbc format
    if ($fbclid && empty($userData['fbc'])) {
        // Construct fbc cookie format: fb.1.timestamp.fbclid
        // Version 1 (subdomains), current timestamp in milliseconds
        $currentTimestamp = time() * 1000; // Convert to milliseconds
        $userData['fbc'] = "fb.1.{$currentTimestamp}.{$fbclid}";
        
        quickLog('🎯 Server-side fbc constructed from Action Network fbclid', 'INFO', [
            'fbc_length' => strlen($userData['fbc']),
            'fbc_preview' => substr($userData['fbc'], 0, 20) . '***',
            'source' => 'action_network_form_field'
        ]);
    }
    if (!empty($headers['X-FB-FBCLID'])) {
        // Construct fbc from fbclid if we don't have fbc
        if (empty($userData['fbc'])) {
            $userData['fbc'] = 'fb.1.' . (time() * 1000) . '.' . $headers['X-FB-FBCLID'];
        }
    }
    
    // Build event data (following Meta Conversions API v23.0 specification)
    $eventData = [
        'event_name' => $eventType,
        'event_time' => $eventTime,
        'event_id' => $eventId, // For deduplication with browser event
        'action_source' => 'website',
        'user_data' => $userData
    ];
    
    // Note: client_user_agent is already in user_data (line 243)
    // Meta v23.0 requires it ONLY in user_data, not as top-level field
    
    // Add event source URL (construct from Action Network data if available)
    if (isset($actionRecord['action_network:referrer_data']['website'])) {
        $eventData['event_source_url'] = $actionRecord['action_network:referrer_data']['website'];
    }
    
    // Only add value/currency for events with real monetary value
    if ($eventType === 'Donate') {
        // For donations, try to extract actual donation amount
        $donationAmount = 1.00; // Default value
        $donationCurrency = 'EUR'; // Default currency
        
        // Extract amount from Action Network donation data (based on real webhook structure)
        if (isset($actionRecord['amount']) && is_numeric($actionRecord['amount'])) {
            $donationAmount = floatval($actionRecord['amount']);
            quickLog('Donation amount extracted', 'INFO', ['amount' => $actionRecord['amount']]);
        } elseif (isset($actionRecord['recipients'][0]['amount']) && is_numeric($actionRecord['recipients'][0]['amount'])) {
            // Fallback: get amount from recipients array
            $donationAmount = floatval($actionRecord['recipients'][0]['amount']);
            quickLog('Donation amount found in recipients array', 'DEBUG', ['amount' => $actionRecord['recipients'][0]['amount']]);
        } else {
            quickLog('No donation amount found - using default 1.00', 'WARNING', [
                'available_fields' => array_keys($actionRecord),
                'amount_exists' => isset($actionRecord['amount']),
                'recipients_exists' => isset($actionRecord['recipients'])
            ]);
        }
        
        // Extract currency from Action Network donation data
        if (isset($actionRecord['currency'])) {
            $donationCurrency = strtoupper($actionRecord['currency']);
            quickLog('Currency extracted', 'INFO', ['currency' => $actionRecord['currency']]);
        } else {
            quickLog('No currency found - using default EUR', 'DEBUG');
        }
        
        $eventData['value'] = $donationAmount;
        $eventData['currency'] = $donationCurrency;
        
        quickLog('Donation amount extracted', 'INFO', [
            'amount' => $donationAmount,
            'currency' => $donationCurrency
        ]);
    }
    
    // Build custom data
    $customData = [];
    
    // Add order_id for better tracking (recommended for all conversion events)
    if (in_array($eventType, ['CompleteRegistration', 'Lead', 'Subscribe', 'Contact', 'SubmitApplication', 'Donate'])) {
        if (!empty($eventId)) {
            $customData['order_id'] = $eventId;
        } elseif (!empty($externalId)) {
            $customData['order_id'] = 'an_' . $externalId; // Action Network submission
        }
    }
    
    // Add content data for better optimization
    $customData['content_category'] = 'form_submission';
    $customData['content_name'] = $eventType . '_action_network';
    
    // Add Action Network specific data
    if (isset($actionRecord['identifiers'][0])) {
        $customData['action_network_id'] = $actionRecord['identifiers'][0];
    }
    
    // Add submission details
    if (isset($actionRecord['submission_id'])) {
        $customData['submission_id'] = $actionRecord['submission_id'];
    }
    
    // Add referrer data if available
    if (isset($actionRecord['action_network:referrer_data'])) {
        $referrerData = $actionRecord['action_network:referrer_data'];
        if (isset($referrerData['source'])) {
            $customData['referrer_source'] = $referrerData['source'];
        }
        if (isset($referrerData['referrer'])) {
            $customData['referrer_url'] = $referrerData['referrer'];
        }
        if (isset($referrerData['website'])) {
            $customData['referrer_website'] = $referrerData['website'];
        }
    }
    
    // Add tags if present
    if (isset($actionRecord['add_tags']) && is_array($actionRecord['add_tags'])) {
        $customData['tags'] = implode(',', $actionRecord['add_tags']);
    }
    
    // Add custom fields
    if (isset($person['custom_fields']) && is_array($person['custom_fields'])) {
        foreach ($person['custom_fields'] as $key => $value) {
            // Limit custom field name length and sanitize
            $cleanKey = substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $key), 0, 50);
            $customData['custom_' . $cleanKey] = is_array($value) ? json_encode($value) : $value;
        }
    }
    
    // Add tracking info
    $customData['tracking_source'] = 'action_network_webhook';
    $customData['integration_version'] = '1.0';
    
    // Only add custom_data if not empty
    if (!empty($customData)) {
        $eventData['custom_data'] = $customData;
    }
    
    // Log what we're about to send (without sensitive data)
    quickLog('Sending event to Meta', 'DEBUG', [
        'pixel_id' => $pixelId,
        'event_type' => $eventType,
        'event_id' => $eventId,
        'has_email' => !empty($email),
        'has_external_id' => !empty($externalId)
    ]);
    
    // Send to Meta with enhanced debugging
    $result = sendToMetaWithDebug($pixelId, $accessToken, $eventData, true);
    
    // Also send with original function for comparison (if debugging)
    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        $originalResult = sendToMeta($pixelId, $accessToken, $eventData);
        quickLog('Original sendToMeta result', 'DEBUG', [
            'success' => $originalResult['success'],
            'events_received' => $originalResult['events_received'] ?? 0,
            'error' => $originalResult['error'] ?? null
        ]);
    }
    
    if ($result['success']) {
        quickLog('Successfully sent webhook event to Meta', 'INFO', [
            'pixel_id' => $pixelId,
            'event_type' => $eventType,
            'event_id' => $eventId,
            'email' => $email ? 'present' : 'none',
            'events_received' => $result['events_received'] ?? 0,
            'source' => 'webhook_conversions_api',
            'server_fbclid' => $fbclid ? 'detected' : 'none',
            'has_fbc' => !empty($userData['fbc']) ? 'yes' : 'no'
        ]);
    } else {
        quickLog('Failed to send webhook event to Meta', 'ERROR', [
            'pixel_id' => $pixelId,
            'error' => $result['error'] ?? 'Unknown error',
            'http_code' => $result['http_code'] ?? 0,
            'response' => $result['response'] ?? ''
        ]);
    }
    
} catch (Exception $e) {
    quickLog('Exception processing webhook', 'ERROR', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

// Save webhook data for debugging (optional, can be disabled in production)
if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
    $debugFile = __DIR__ . '/logs/webhooks_debug.json';
    $debugData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'pixel_id' => $pixelId,
        'is_test' => $isTest,
        'headers' => getAllHeaders(),
        'raw_body' => $rawInput,
        'parsed_body' => $webhookData
    ];
    @file_put_contents($debugFile, json_encode($debugData, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND | LOCK_EX);
}
?>