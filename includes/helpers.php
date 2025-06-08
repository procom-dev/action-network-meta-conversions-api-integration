<?php
/**
 * Helper functions
 */

/**
 * Find value recursively in array
 */
function findValueRecursive($array, $searchKey) {
    foreach ($array as $key => $value) {
        if (strtolower($key) === $searchKey && is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $result = findValueRecursive($value, $searchKey);
            if ($result !== null) {
                return $result;
            }
        }
    }
    return null;
}

/**
 * Clean old log files
 */
function cleanOldLogs($logDir, $daysToKeep) {
    $files = glob($logDir . '/*.log');
    $now = time();
    
    foreach ($files as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) >= 60 * 60 * 24 * $daysToKeep) {
                @unlink($file);
            }
        }
    }
}

/**
 * Set security headers
 */
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}