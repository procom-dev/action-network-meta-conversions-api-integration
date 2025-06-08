<?php
/**
 * Crypto.php - Encryption/Decryption functions for Meta Conversions API Integration
 * 
 * This file handles all cryptographic operations to securely store and transmit
 * sensitive data (Pixel ID, Access Token, Event Type) without exposing them.
 */

class Crypto {
    /**
     * Secret key for encryption - CHANGE THIS IN PRODUCTION!
     * Should be 32 bytes (256 bits) for AES-256
     * Generate with: bin2hex(random_bytes(32))
     */
    private const SECRET_KEY = 'a3f8bfc1d2e3f4a5b62c9d4e2a3b4c5d6e7f8a9b05f6a7b8c9d0e1c7d8e9f0a1';
    
    /**
     * Algorithm for encryption
     */
    public const CIPHER_METHOD = 'aes-256-cbc';
    
    /**
     * Separator for data components
     */
    private const DATA_SEPARATOR = '|';
    
    /**
     * Encrypts data and returns a URL-safe hash
     * 
     * @param string $pixelId Meta Pixel ID
     * @param string $accessToken Meta Access Token
     * @return string URL-safe encrypted hash
     */
    public static function encrypt($pixelId, $accessToken) {
        try {
            // Validate inputs
            if (empty($pixelId) || empty($accessToken)) {
                throw new Exception('Pixel ID and Access Token are required');
            }
            
            // Create data string with timestamp for added security (event type auto-detected)
            $timestamp = time();
            $dataString = implode(self::DATA_SEPARATOR, [
                $pixelId,
                $accessToken,
                $timestamp
            ]);
            
            // Get key as binary
            $key = hex2bin(self::SECRET_KEY);
            
            // Generate random IV
            $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $iv = openssl_random_pseudo_bytes($ivLength);
            
            // Encrypt data
            $encrypted = openssl_encrypt(
                $dataString, 
                self::CIPHER_METHOD, 
                $key, 
                OPENSSL_RAW_DATA, 
                $iv
            );
            
            if ($encrypted === false) {
                throw new Exception('Encryption failed');
            }
            
            // Combine IV and encrypted data
            $combined = $iv . $encrypted;
            
            // Add HMAC for integrity verification
            $hmac = hash_hmac('sha256', $combined, $key, true);
            $final = $hmac . $combined;
            
            // Encode to URL-safe base64
            $encoded = rtrim(strtr(base64_encode($final), '+/', '-_'), '=');
            
            return $encoded;
            
        } catch (Exception $e) {
            error_log('Encryption error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Decrypts a hash and returns the original data
     * 
     * @param string $hash URL-safe encrypted hash
     * @return array|false Array with pixelId, accessToken, eventType or false on failure
     */
    public static function decrypt($hash) {
        try {
            // Validate input
            if (empty($hash)) {
                throw new Exception('Empty hash provided');
            }
            
            // Decode from URL-safe base64
            $decoded = base64_decode(strtr($hash, '-_', '+/'));
            if ($decoded === false) {
                throw new Exception('Invalid hash format');
            }
            
            // Get key as binary
            $key = hex2bin(self::SECRET_KEY);
            
            // Extract HMAC (first 32 bytes)
            $hmacProvided = substr($decoded, 0, 32);
            $combined = substr($decoded, 32);
            
            // Verify HMAC
            $hmacCalculated = hash_hmac('sha256', $combined, $key, true);
            if (!hash_equals($hmacProvided, $hmacCalculated)) {
                throw new Exception('HMAC verification failed - data may be tampered');
            }
            
            // Extract IV and encrypted data
            $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $iv = substr($combined, 0, $ivLength);
            $encrypted = substr($combined, $ivLength);
            
            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted, 
                self::CIPHER_METHOD, 
                $key, 
                OPENSSL_RAW_DATA, 
                $iv
            );
            
            if ($decrypted === false) {
                throw new Exception('Decryption failed');
            }
            
            // Parse decrypted data (now only pixel_id, access_token, timestamp)
            $parts = explode(self::DATA_SEPARATOR, $decrypted);
            if (count($parts) !== 3) {
                throw new Exception('Invalid decrypted data format');
            }
            
            list($pixelId, $accessToken, $timestamp) = $parts;
            
            // Optional: Check timestamp validity (e.g., not older than 1 year)
            $maxAge = 365 * 24 * 60 * 60; // 1 year in seconds
            if ((time() - $timestamp) > $maxAge) {
                throw new Exception('Hash has expired');
            }
            
            return [
                'pixel_id' => $pixelId,
                'access_token' => $accessToken,
                'timestamp' => $timestamp
            ];
            
        } catch (Exception $e) {
            error_log('Decryption error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generates a secure random key (for initial setup)
     * 
     * @return string Hex-encoded 32-byte key
     */
    public static function generateKey() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Quick validation of hash format without decrypting
     * 
     * @param string $hash
     * @return bool
     */
    public static function isValidHashFormat($hash) {
        // Check if it's a valid base64url string
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $hash)) {
            return false;
        }
        
        // Decode and check minimum length (HMAC + IV + min data)
        $decoded = base64_decode(strtr($hash, '-_', '+/'));
        if ($decoded === false || strlen($decoded) < 64) { // 32 (HMAC) + 16 (IV) + 16 (min data)
            return false;
        }
        
        return true;
    }
    
    /**
     * Creates a test hash for wizard testing
     * 
     * @param string $pixelId
     * @param string $accessToken
     * @return string
     */
    public static function createTestHash($pixelId, $accessToken) {
        return self::encrypt($pixelId, $accessToken, 'Test');
    }
    
    /**
     * Extracts just the pixel ID from a hash (for logging without exposing token)
     * 
     * @param string $hash
     * @return string|false
     */
    public static function getPixelIdFromHash($hash) {
        $data = self::decrypt($hash);
        return $data ? $data['pixel_id'] : false;
    }
    
    /**
     * Get cipher method (public method for external access)
     * 
     * @return string
     */
    public static function getCipherMethod() {
        return self::CIPHER_METHOD;
    }
}

/**
 * Helper function for quick encryption
 */
function encryptData($pixelId, $accessToken, $eventType = 'CompleteRegistration') {
    return Crypto::encrypt($pixelId, $accessToken, $eventType);
}

/**
 * Helper function for quick decryption
 */
function decryptData($hash) {
    return Crypto::decrypt($hash);
}

/**
 * Initialize crypto system (run once on setup)
 */
function initCrypto() {
    // Check if OpenSSL is available
    if (!function_exists('openssl_encrypt')) {
        die('OpenSSL extension is required but not installed');
    }
    
    // Verify cipher method is supported
    if (!in_array(Crypto::CIPHER_METHOD, openssl_get_cipher_methods())) {
        die('Cipher method ' . Crypto::CIPHER_METHOD . ' is not supported');
    }
    
    return true;
}

// Example usage (comment out in production):
/*
$pixelId = '123456789012345';
$accessToken = 'EAAxxxxxxxxxxxxxxxx';
$hash = Crypto::encrypt($pixelId, $accessToken);
echo "Encrypted hash: " . $hash . "\n";

$decrypted = Crypto::decrypt($hash);
print_r($decrypted);
*/
?>