<?php
/**
 * check_test.php - Checks if a test webhook was received
 * 
 * Used by the setup wizard to verify webhook configuration is working
 */

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Include required files
require_once __DIR__ . '/functions.php';

/**
 * Check if a test webhook was received for a specific pixel ID
 * Looks in the logs for recent test entries
 */
function checkForTestWebhook($pixelId) {
    $logFile = __DIR__ . '/logs/app.log';
    
    if (!file_exists($logFile)) {
        return [
            'found' => false,
            'message' => 'No log file found'
        ];
    }
    
    // Read last 1000 lines to look for recent test
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [
            'found' => false,
            'message' => 'Could not read log file'
        ];
    }
    
    // Check last 100 lines for test webhook within last 3 minutes (very recent)
    $recentLines = array_slice($lines, -100);
    $threeMinutesAgo = time() - (3 * 60);
    
    $foundTests = [];
    
    foreach (array_reverse($recentLines) as $line) {
        // Look ONLY for the specific test webhook log message
        if (strpos($line, 'Test webhook processed for pixel: ' . $pixelId) !== false) {
            // Extract timestamp
            if (preg_match('/\[([^\]]+)\]/', $line, $matches)) {
                $logTime = strtotime($matches[1]);
                if ($logTime >= $threeMinutesAgo) {
                    $foundTests[] = [
                        'timestamp' => $matches[1],
                        'log_time' => $logTime,
                        'line' => $line
                    ];
                }
            }
        }
    }
    
    if (!empty($foundTests)) {
        // Return the most recent test
        $mostRecent = $foundTests[0];
        return [
            'found' => true,
            'message' => 'Test webhook received successfully!',
            'timestamp' => $mostRecent['timestamp'],
            'details' => 'Webhook endpoint is working correctly',
            'debug' => [
                'tests_found' => count($foundTests),
                'search_window' => '3 minutes',
                'current_time' => date('Y-m-d H:i:s')
            ]
        ];
    }
    
    return [
        'found' => false,
        'message' => 'No recent test webhook found. Make sure you clicked "Send Test" in Action Network webhook settings.',
        'debug' => [
            'lines_checked' => count($recentLines),
            'search_pattern' => 'Test webhook processed for pixel: ' . $pixelId,
            'search_window' => '3 minutes',
            'current_time' => date('Y-m-d H:i:s')
        ]
    ];
}

// Get pixel ID from request
$pixelId = $_GET['pixel_id'] ?? '';

if (empty($pixelId)) {
    echo json_encode([
        'success' => false,
        'error' => 'Pixel ID required'
    ]);
    exit;
}

try {
    $result = checkForTestWebhook($pixelId);
    
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