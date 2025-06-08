<?php
/**
 * API.php - Endpoint for receiving browser-side tracking data
 * 
 * Receives data from tracker.js and forwards it to Meta Conversions API
 * with proper formatting and hashing
 */

// Set CORS headers FIRST before any other output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
header('Access-Control-Max-Age: 86400');
header('Access-Control-Allow-Credentials: false');
header('Content-Type: application/json');

// Ensure headers are sent immediately
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/debug_meta_payload.php';
require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/helpers.php';

/**
 * Send JSON response and exit
 */
function sendResponse($success, $message = '', $data = []) {
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => time()
    ];
    
    if (!empty($data)) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendResponse(false, 'Method not allowed');
}

// Get and validate input
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    http_response_code(400);
    sendResponse(false, 'Empty request body');
}

// Parse JSON input
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    sendResponse(false, 'Invalid JSON: ' . json_last_error_msg());
}

// Validate required fields
if (empty($input['hash']) || empty($input['data'])) {
    http_response_code(400);
    sendResponse(false, 'Missing required fields');
}

// Decrypt hash to get configuration
$config = Crypto::decrypt($input['hash']);
if (!$config) {
    http_response_code(401);
    sendResponse(false, 'Invalid or expired configuration');
}

// Extract configuration
$pixelId = $config['pixel_id'];
$accessToken = $config['access_token'];
// Event type is now auto-detected or passed from JavaScript

// Extract data from input
$data = $input['data'];
$eventType = $data['event_type'] ?? 'CompleteRegistration'; // Default to CompleteRegistration if not specified
$eventId = $data['event_id'] ?? null;
$eventTime = $data['event_time'] ?? time();
$formData = $data['form_data'] ?? [];
$browserData = $data['browser_data'] ?? [];
$fbData = $data['fb_data'] ?? [];
$pageData = $data['page_data'] ?? [];

// Log API request (without sensitive data)
$logContext = [
    'event_type' => $eventType,
    'has_email' => !empty($formData['email']),
    'has_fbclid' => !empty($fbData['fbclid']),
    'source' => $data['source'] ?? 'unknown'
];

// Add test email detection for debugging and setup wizard
if (!empty($formData['email']) && strtolower(trim($formData['email'])) === 'test@test.com') {
    $logContext['is_test_email'] = true;
    $logContext['test_email_detected'] = 'test@test.com';
    
    // Log script test for setup wizard verification
    logScriptTest($pixelId);
}

quickLog('API request received for pixel: ' . $pixelId, 'INFO', $logContext);

// Build user data for Meta
$userData = [];

// Hash personal data with type-specific normalization
if (!empty($formData['email'])) {
    // 🎯 NEW: Generate event ID with fbclid priority if not provided (matches webhook logic)
    if (empty($eventId)) {
        $fbclid = $fbData['fbclid'] ?? null;
        $eventId = generateEventIdWithFbclid($formData['email'], $fbclid, $eventTime);
        
        if (!empty($fbclid)) {
            quickLog('🆔 Generated fbclid-based event_id for perfect pairing (API)', 'INFO', [
                'fbclid_length' => strlen($fbclid),
                'event_id_preview' => substr($eventId ?? '', 0, 12) . '***'
            ]);
        }
    }
    
    
    $userData['em'] = hashData($formData['email'], 'email');
}

if (!empty($formData['phone'])) {
    $processedPhone = processPhoneNumber($formData['phone']);
    if ($processedPhone) {
        $userData['ph'] = hashData($processedPhone, 'phone');
    }
}

if (!empty($formData['first_name'])) {
    $userData['fn'] = hashData($formData['first_name'], 'name');
}

if (!empty($formData['last_name'])) {
    $userData['ln'] = hashData($formData['last_name'], 'name');
}

if (!empty($formData['city'])) {
    $userData['ct'] = hashData($formData['city'], 'city');
}

if (!empty($formData['state'])) {
    $userData['st'] = hashData($formData['state'], 'state');
}

if (!empty($formData['zip'])) {
    $userData['zp'] = hashData($formData['zip'], 'zip');
}

if (!empty($formData['country'])) {
    $userData['country'] = hashData($formData['country'], 'country');
}

// Add external_id for improved Event Match Quality (high priority per Meta docs)
if (!empty($formData['email'])) {
    // Use hashed email as external_id for consistent cross-platform tracking
    $userData['external_id'] = hashData($formData['email'], 'email');
}

// Add browser data
if (!empty($browserData['user_agent'])) {
    $userData['client_user_agent'] = $browserData['user_agent'];
}

// Get client IP (considering the request comes from user's browser)
$clientIp = getClientIp();
if ($clientIp) {
    $userData['client_ip_address'] = $clientIp;
}

// Add Facebook data (these should not be hashed)
if (!empty($fbData['fbp'])) {
    $userData['fbp'] = $fbData['fbp'];
    quickLog('Added Facebook Browser ID (fbp)', 'DEBUG', ['fbp_length' => strlen($fbData['fbp'])]);
}

if (!empty($fbData['fbc'])) {
    $userData['fbc'] = $fbData['fbc'];
    quickLog('Added Facebook Click ID (fbc)', 'DEBUG', ['fbc_length' => strlen($fbData['fbc'])]);
} elseif (!empty($fbData['fbclid'])) {
    // Construct fbc cookie if we have fbclid but no fbc
    $userData['fbc'] = 'fb.1.' . (time() * 1000) . '.' . $fbData['fbclid'];
    quickLog('Constructed fbc from fbclid', 'DEBUG', ['fbclid' => substr($fbData['fbclid'], 0, 10) . '***']);
}

// Log Facebook data quality for debugging
if (empty($fbData['fbp']) && empty($fbData['fbc']) && empty($fbData['fbclid'])) {
    quickLog('No Facebook browser identifiers found - this may impact match quality', 'WARNING');
}

// Build event data (following Meta Conversions API v23.0 specification)
$eventData = [
    'event_name' => $eventType,
    'event_time' => $eventTime,
    'event_source_url' => $pageData['url'] ?? '',
    'action_source' => 'website',
    'user_data' => $userData
];

// Note: client_user_agent and client_ip_address are already in user_data
// Meta v23.0 requires them ONLY in user_data, not as top-level fields

// Add event ID for deduplication
if (!empty($eventId)) {
    $eventData['event_id'] = $eventId;
}

// Only add value/currency for events with real monetary value
if ($eventType === 'Donate') {
    // Use value from JavaScript payload if available, otherwise default for donations
    $eventData['value'] = $data['value'] ?? 1.00; // Must be at event level, not in custom_data
    $eventData['currency'] = $data['currency'] ?? 'EUR'; // Must be at event level, not in custom_data
}

// Add custom data
$customData = [];

// Add order_id for better tracking (recommended for all conversion events)
if (in_array($eventType, ['CompleteRegistration', 'Lead', 'Subscribe', 'Contact', 'SubmitApplication', 'Donate'])) {
    if (!empty($eventId)) {
        $customData['order_id'] = $eventId; // Use event_id as order_id for uniqueness
    }
}

// Add content data for better optimization
if (!empty($formData)) {
    $customData['content_category'] = 'form_submission';
    $customData['content_name'] = $eventType . '_form';
    $customData['content_type'] = 'product'; // Standard value for conversions
}

// Add more recommended custom_data parameters per Meta documentation
if (!empty($pageData['url'])) {
    // Extract domain for content_ids (useful for tracking)
    $domain = parse_url($pageData['url'], PHP_URL_HOST);
    if ($domain) {
        $customData['content_ids'] = [$domain . '_' . $eventType];
    }
}

// Add page data as custom data
if (!empty($pageData['referrer'])) {
    $customData['referrer'] = $pageData['referrer'];
}

if (!empty($pageData['title'])) {
    $customData['page_title'] = $pageData['title'];
}

// Add any additional form fields as custom data
$standardFields = ['email', 'first_name', 'last_name', 'phone', 'city', 'state', 'zip', 'country'];
foreach ($formData as $key => $value) {
    if (!in_array($key, $standardFields) && !empty($value)) {
        $customData['form_' . $key] = $value;
    }
}

// Add tracking source
$customData['tracking_source'] = 'action_network_javascript';
$customData['integration_version'] = '1.0';

// Only add custom_data if not empty
if (!empty($customData)) {
    $eventData['custom_data'] = $customData;
}

// Send to Meta with enhanced debugging
try {
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
        quickLog('Successfully sent event to Meta', 'INFO', [
            'pixel_id' => $pixelId,
            'event_type' => $eventType,
            'event_id' => $eventId,
            'events_received' => $result['events_received'] ?? 0
        ]);
        
        sendResponse(true, 'Event sent successfully', [
            'event_id' => $eventId,
            'events_received' => $result['events_received'] ?? 0
        ]);
    } else {
        $errorMessage = $result['error'] ?? 'Unknown error';
        
        quickLog('Failed to send event to Meta', 'ERROR', [
            'pixel_id' => $pixelId,
            'error' => $errorMessage,
            'http_code' => $result['http_code'] ?? 0
        ]);
        
        // Don't expose internal errors to client
        sendResponse(false, 'Failed to process event');
    }
    
} catch (Exception $e) {
    quickLog('Exception in API processing', 'ERROR', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendResponse(false, 'Internal server error');
}
?>