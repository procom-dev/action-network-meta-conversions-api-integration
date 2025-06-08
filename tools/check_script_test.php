<?php
/**
 * check_script_test.php - Checks if a browser script test was received
 * 
 * Simple file-based script test verification
 */

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

/**
 * Check if a test script event was received for a specific pixel ID
 * Uses simple JSON file tracking
 */
function checkForScriptTest($pixelId) {
    $testFile = __DIR__ . '/../script_tests.json';
    
    if (!file_exists($testFile)) {
        return [
            'found' => false,
            'message' => 'No script tests received yet. Submit a form with email "test@test.com".'
        ];
    }
    
    $content = @file_get_contents($testFile);
    if (!$content) {
        return [
            'found' => false,
            'message' => 'Could not read script test file'
        ];
    }
    
    $tests = json_decode($content, true) ?: [];
    
    if (isset($tests[$pixelId])) {
        $test = $tests[$pixelId];
        $secondsAgo = time() - $test['timestamp'];
        
        if ($secondsAgo < 60) { // Within last 1 minute
            $timeAgo = $secondsAgo < 60 ? 'just now' : floor($secondsAgo / 60) . ' minutes ago';
            
            return [
                'found' => true,
                'message' => 'Script test received successfully! JavaScript tracking is working.',
                'timestamp' => date('Y-m-d H:i:s', $test['timestamp']),
                'time_ago' => $timeAgo,
                'details' => 'Browser tracking script is working correctly',
                'events_found' => $test['events_count'] ?? 1
            ];
        }
    }
    
    return [
        'found' => false,
        'message' => 'No recent script test found. Submit a form with email "test@test.com".'
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