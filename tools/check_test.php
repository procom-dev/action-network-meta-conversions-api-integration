<?php
/**
 * check_test.php - Checks if a test webhook was received
 * 
 * Simple file-based test verification without complex logs
 */

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

/**
 * Check if a test webhook was received for specific credentials (by hash)
 * Uses simple JSON file tracking
 */
function checkForTestWebhook($pixelId, $accessToken) {
    require_once __DIR__ . '/../includes/crypto.php';
    
    $testFile = __DIR__ . '/../test_webhooks.json';
    
    if (!file_exists($testFile)) {
        return [
            'found' => false,
            'message' => 'No test webhooks received yet. Click "Send Test" in Action Network.'
        ];
    }
    
    $content = @file_get_contents($testFile);
    if (!$content) {
        return [
            'found' => false,
            'message' => 'Could not read test file'
        ];
    }
    
    $tests = json_decode($content, true) ?: [];
    
    // Generate hash for these credentials (same as used in webhook URL)
    $hash = Crypto::encrypt($pixelId, $accessToken);
    
    if (isset($tests[$hash])) {
        $test = $tests[$hash];
        $secondsAgo = time() - $test['timestamp'];
        
        if ($secondsAgo < 60) { // Within last 1 minute
            $timeAgo = $secondsAgo < 60 ? 'just now' : floor($secondsAgo / 60) . ' minutes ago';
            
            return [
                'found' => true,
                'message' => 'Test webhook received successfully!',
                'timestamp' => date('Y-m-d H:i:s', $test['timestamp']),
                'time_ago' => $timeAgo,
                'details' => 'Webhook endpoint is working correctly'
            ];
        }
    }
    
    return [
        'found' => false,
        'message' => 'No recent test webhook found. Click "Send Test" in Action Network.'
    ];
}

// Get credentials from request
$pixelId = $_GET['pixel_id'] ?? '';
$accessToken = $_GET['access_token'] ?? '';

if (empty($pixelId) || empty($accessToken)) {
    echo json_encode([
        'success' => false,
        'error' => 'Pixel ID and Access Token required'
    ]);
    exit;
}

try {
    $result = checkForTestWebhook($pixelId, $accessToken);
    
    echo json_encode([
        'success' => true,
        'test_received' => $result['found'],
        'message' => $result['message'],
        'timestamp' => $result['timestamp'] ?? null,
        'details' => $result['details'] ?? null
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error checking for test webhook: ' . $e->getMessage()
    ]);
}
?>