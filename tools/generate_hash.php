<?php
/**
 * Generate_hash.php - Generates encrypted hash for URLs
 * 
 * Used by setup wizard to create secure webhook and tracker URLs
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/crypto.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Extract data
$pixelId = $input['pixelId'] ?? '';
$accessToken = $input['accessToken'] ?? '';
// Event type no longer needed - auto-detected by system

// Validate
if (empty($pixelId) || empty($accessToken)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    // Generate hash (only pixel_id + access_token)
    $hash = Crypto::encrypt($pixelId, $accessToken);
    
    if ($hash) {
        echo json_encode([
            'success' => true,
            'hash' => $hash
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to generate hash'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Encryption error: ' . $e->getMessage()
    ]);
}
?>