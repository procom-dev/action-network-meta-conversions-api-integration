<?php
/**
 * check_script_test.php - Checks if a browser script test was received
 * 
 * Used by the setup wizard to verify JavaScript tracking is working
 */

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Include required files
require_once __DIR__ . '/functions.php';

/**
 * Check if a test script event was received for a specific pixel ID
 * Looks in the logs for recent JavaScript API calls
 */
function checkForScriptTest($pixelId) {
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
    
    // Check last 300 lines for script test within last 2 minutes (very recent)
    $recentLines = array_slice($lines, -300);
    $twoMinutesAgo = time() - (2 * 60);
    
    $foundPageView = false;
    $foundApiCall = false;
    $timestamps = [];
    
    foreach (array_reverse($recentLines) as $line) {
        // Look ONLY for API requests with test@test.com email (the specific test pattern)
        if (strpos($line, 'API request received for pixel: ' . $pixelId) !== false) {
            // Must contain test@test.com to be considered a valid test
            if (strpos($line, 'test@test.com') !== false || strpos($line, 'is_test_email') !== false) {
                // Extract timestamp
                if (preg_match('/\[([^\]]+)\]/', $line, $matches)) {
                    $logTime = strtotime($matches[1]);
                    if ($logTime >= $twoMinutesAgo) {
                        $foundApiCall = true;
                        $timestamps[] = $matches[1];
                        
                        // Check if it's a PageView or CompleteRegistration event
                        if (strpos($line, 'PageView') !== false || strpos($line, 'javascript_pixel_enhanced') !== false) {
                            $foundPageView = true;
                        }
                    }
                }
            }
        }
        
        // Also look for successful Meta API calls with test@test.com
        if (strpos($line, 'Successfully sent event to Meta') !== false && strpos($line, $pixelId) !== false) {
            // Must contain test@test.com to be valid
            if (strpos($line, 'test@test.com') !== false || strpos($line, 'is_test_email') !== false) {
                if (preg_match('/\[([^\]]+)\]/', $line, $matches)) {
                    $logTime = strtotime($matches[1]);
                    if ($logTime >= $twoMinutesAgo) {
                        $foundApiCall = true;
                        $timestamps[] = $matches[1];
                    }
                }
            }
        }
    }
    
    if ($foundApiCall) {
        $message = 'JavaScript tracker is working! ';
        if ($foundPageView) {
            $message .= 'PageView events detected.';
        } else {
            $message .= 'API calls detected.';
        }
        
        return [
            'found' => true,
            'message' => $message,
            'timestamp' => $timestamps[0] ?? null,
            'details' => 'Script successfully sent data to Conversions API',
            'events_found' => count($timestamps)
        ];
    }
    
    return [
        'found' => false,
        'message' => 'No test script activity found. Please submit a form with email "test@test.com" to test the JavaScript tracker.',
        'debug' => [
            'lines_checked' => count($recentLines),
            'search_pattern' => 'API request with test@test.com for pixel: ' . $pixelId,
            'search_window' => '2 minutes',
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
    $result = checkForScriptTest($pixelId);
    
    echo json_encode([
        'success' => true,
        'test_received' => $result['found'],
        'message' => $result['message'],
        'timestamp' => $result['timestamp'] ?? null,
        'details' => $result['details'] ?? null,
        'events_found' => $result['events_found'] ?? 0
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error checking for script test: ' . $e->getMessage()
    ]);
}
?>