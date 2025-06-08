<?php
/**
 * Configuration and Security Functions
 * 
 * This file contains all configuration constants and security-related functions
 * for encrypting and decrypting sensitive data in URLs.
 */

// Security configuration
define('ENCRYPTION_KEY', 'wh3KJZs78rrB8ATtMvWdBoIPtlEHoBXn'); // IMPORTANT: Change this to a random 32-character string
define('HASH_SALT', 'vHMKkv6kFhw0IGsNBWLO7ElDOwjFuPQi'); // IMPORTANT: Change this to a random string

/**
 * Encrypts sensitive data (pixel_id, access_token, event_type) into a secure token
 * 
 * @param string $pixelId Meta Pixel ID
 * @param string $accessToken Meta Conversions API access token
 * @param string $eventType Event type (default: CompleteRegistration)
 * @return string Encrypted and base64url encoded token
 */
function encryptData($pixelId, $accessToken, $eventType = 'CompleteRegistration') {
    // Create a data array
    $data = [
        'pixel_id' => $pixelId,
        'access_token' => $accessToken,
        'event_type' => $eventType,
        'timestamp' => time(), // Add timestamp for additional security
        'hash' => '' // Will be filled below
    ];
    
    // Create a hash of the data for integrity verification
    $dataString = $pixelId . '|' . $accessToken . '|' . $eventType . '|' . $data['timestamp'];
    $data['hash'] = hash_hmac('sha256', $dataString, HASH_SALT);
    
    // Convert to JSON
    $jsonData = json_encode($data);
    
    // Encrypt using AES-256-CBC
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($jsonData, 'aes-256-cbc', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    
    // Combine IV and encrypted data
    $combined = $iv . $encrypted;
    
    // Base64url encode for safe URL usage
    return rtrim(strtr(base64_encode($combined), '+/', '-_'), '=');
}

/**
 * Decrypts a secure token back to its original data
 * 
 * @param string $token Encrypted token from URL
 * @return array|false Decrypted data array or false on failure
 */
function decryptData($token) {
    // Base64url decode
    $combined = base64_decode(strtr($token, '-_', '+/'));
    
    if ($combined === false) {
        error_log('Failed to decode token');
        return false;
    }
    
    // Extract IV and encrypted data
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($combined, 0, $ivLength);
    $encrypted = substr($combined, $ivLength);
    
    // Decrypt
    $jsonData = openssl_decrypt($encrypted, 'aes-256-cbc', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    
    if ($jsonData === false) {
        error_log('Failed to decrypt token');
        return false;
    }
    
    // Parse JSON
    $data = json_decode($jsonData, true);
    
    if ($data === null) {
        error_log('Failed to parse decrypted JSON');
        return false;
    }
    
    // Verify hash integrity
    $dataString = $data['pixel_id'] . '|' . $data['access_token'] . '|' . $data['event_type'] . '|' . $data['timestamp'];
    $expectedHash = hash_hmac('sha256', $dataString, HASH_SALT);
    
    if (!hash_equals($expectedHash, $data['hash'])) {
        error_log('Hash verification failed - data may be tampered');
        return false;
    }
    
    // Optional: Check timestamp to prevent old tokens (e.g., max 30 days old)
    if (time() - $data['timestamp'] > 30 * 24 * 60 * 60) {
        error_log('Token is too old');
        return false;
    }
    
    return $data;
}

/**
 * Generates a consistent event ID for deduplication
 * 
 * @param string $email User email address
 * @param int $timestamp Event timestamp
 * @return string SHA256 hash to use as event_id
 */
function generateEventId($email, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    
    // Round timestamp to nearest 60 seconds for deduplication window
    $roundedTime = floor($timestamp / 60) * 60;
    
    // Create event ID from email + rounded timestamp
    $eventString = strtolower(trim($email)) . '_' . $roundedTime;
    
    return hash('sha256', $eventString);
}

/**
 * Get domain URL for generating absolute URLs
 * 
 * @return string Domain URL with protocol
 */
function getDomainUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $domain;
}