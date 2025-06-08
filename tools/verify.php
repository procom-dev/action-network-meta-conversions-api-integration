<?php
/**
 * Verify.php - Verifies Meta Pixel ID and Access Token
 * 
 * Used by the setup wizard to validate credentials before generating URLs
 */

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Include required files
require_once __DIR__ . '/../includes/functions.php';

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

/**
 * Send JSON response
 */
function sendJsonResponse($success, $message = '', $data = []) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    
    echo json_encode($response);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendJsonResponse(false, 'Method not allowed');
}

// Get input
$rawInput = file_get_contents('php://input');
quickLog('Verify request received', 'DEBUG', ['input_size' => strlen($rawInput)]);

$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    quickLog('JSON decode error: ' . json_last_error_msg(), 'ERROR');
    http_response_code(400);
    sendJsonResponse(false, 'Invalid JSON input');
}

// Extract and validate inputs
$pixelId = trim($input['pixelId'] ?? '');
$accessToken = trim($input['accessToken'] ?? '');

quickLog('Verification attempt', 'INFO', [
    'pixel_id' => $pixelId,
    'token_length' => strlen($accessToken),
    'token_start' => substr($accessToken, 0, 10)
]);

// Basic validation
if (empty($pixelId) || empty($accessToken)) {
    quickLog('Missing credentials', 'ERROR');
    sendJsonResponse(false, 'Missing Pixel ID or Access Token');
}

// Validate format
if (!isValidPixelId($pixelId)) {
    quickLog('Invalid Pixel ID format: ' . $pixelId, 'ERROR');
    sendJsonResponse(false, 'Invalid Pixel ID format. It should be a 10-20 digit number.');
}

if (!isValidAccessToken($accessToken)) {
    quickLog('Invalid Access Token format', 'ERROR', ['token_start' => substr($accessToken, 0, 10)]);
    sendJsonResponse(false, 'Invalid Access Token format. It should start with "EAA" and be longer than 20 characters.');
}

try {
    // Skip pixel info request and go directly to test event
    // This is more reliable as it tests the actual functionality we need
    
    quickLog('Sending test event to verify permissions', 'DEBUG');
    
    // Test sending a dummy event to verify write permissions
    $testEventData = [
        'event_name' => 'PageView',
        'event_time' => time(),
        'action_source' => 'website',
        'user_data' => [
            'client_ip_address' => '127.0.0.1',
            'client_user_agent' => 'Mozilla/5.0 (Setup Verification Test)'
        ],
        'custom_data' => [
            'source' => 'action_network_setup_verification',
            'test_mode' => true
        ]
    ];
    
    // Send test event
    $result = sendToMeta($pixelId, $accessToken, $testEventData);
    
    if (!$result['success']) {
        $errorMsg = $result['error'] ?? 'Unknown error';
        $httpCode = $result['http_code'] ?? 0;
        
        quickLog('Test event failed', 'ERROR', [
            'error' => $errorMsg,
            'http_code' => $httpCode,
            'response' => $result['response'] ?? ''
        ]);
        
        // Handle specific error cases with helpful messages
        if ($httpCode === 400) {
            if (stripos($errorMsg, 'Missing Permission') !== false || stripos($errorMsg, '#100') !== false) {
                sendJsonResponse(false, 'Access Token does not have permission to access this Pixel. Please ensure:

1. The Access Token was generated in the same Business Manager as the Pixel
2. Your user account has admin access to both the Pixel and Business Manager
3. The token has "ads_management" permissions
4. You are using a System User token (recommended) or User token with proper permissions

To fix this:
- Go to Meta Business Settings → System Users → Create new System User
- Assign "Admin" role to the System User
- Add the Pixel asset to the System User
- Generate a new token with "ads_management" permission');
            } elseif (stripos($errorMsg, 'does not exist') !== false || stripos($errorMsg, 'Invalid parameter') !== false) {
                sendJsonResponse(false, 'Pixel ID "' . $pixelId . '" does not exist or is not accessible with this Access Token. Please verify:

1. The Pixel ID is correct (find it in Events Manager → Data Sources)
2. The Access Token belongs to the same Business Manager as the Pixel
3. You have access to this Pixel in your Meta Business account');
            } elseif (stripos($errorMsg, 'Invalid OAuth') !== false || stripos($errorMsg, 'access token') !== false) {
                sendJsonResponse(false, 'Access Token is invalid or expired. Please:

1. Generate a new Access Token in Meta Events Manager
2. Go to Events Manager → Settings → Conversions API
3. Click "Generate Access Token"
4. Make sure to copy the complete token (starts with EAA)');
            }
        } elseif ($httpCode === 401 || $httpCode === 403) {
            sendJsonResponse(false, 'Access denied. The Access Token does not have sufficient permissions. Please:

1. Ensure you have Admin access to the Meta Business Account
2. Generate the token from Events Manager → Settings → Conversions API
3. Use a System User token with "ads_management" permissions (recommended)');
        }
        
        // Generic error message
        sendJsonResponse(false, 'Unable to connect to Meta Conversions API: ' . $errorMsg . ' (HTTP ' . $httpCode . ')

Please verify:
1. Pixel ID is correct and accessible
2. Access Token has proper permissions
3. Token was generated from the correct Business Manager account');
    }
    
    // Try to get pixel name for better UX (optional, won't fail if this doesn't work)
    $pixelName = 'Meta Pixel';
    $pixelInfoUrl = META_API_BASE_URL . "/{$pixelId}?fields=name&access_token={$accessToken}";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $pixelInfoUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ActionNetwork-MetaConversions/1.0'
        ]
    ]);
    
    $pixelResponse = curl_exec($ch);
    $pixelHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($pixelHttpCode === 200) {
        $pixelInfo = json_decode($pixelResponse, true);
        if (isset($pixelInfo['name'])) {
            $pixelName = $pixelInfo['name'];
        }
    }
    
    // Success! Log it
    quickLog('Credentials verified successfully', 'INFO', [
        'pixel_id' => $pixelId,
        'pixel_name' => $pixelName,
        'events_received' => $result['events_received'] ?? 0
    ]);
    
    // Return success with pixel info
    sendJsonResponse(true, 'Credentials verified successfully', [
        'pixel_name' => $pixelName,
        'pixel_id' => $pixelId,
        'events_received' => $result['events_received'] ?? 0
    ]);
    
} catch (Exception $e) {
    quickLog('Exception during verification', 'ERROR', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    sendJsonResponse(false, 'An error occurred during verification. Please try again.');
}
?>