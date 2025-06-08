<?php
/**
 * Crypto.php - Encryption/Decryption functions for Meta Conversions API Integration
 */

class Crypto {
    /**
     * Algorithm for encryption
     */
    public const CIPHER_METHOD = 'aes-256-cbc';
    
    /**
     * Separator for data components
     */
    private const DATA_SEPARATOR = '|';
    
    /**
     * Configuration cache
     */
    private static $config = null;
    private static $cache = [];
    
    /**
     * Get configuration
     */
    public static function getConfig() {
        if (self::$config === null) {
            $configFile = __DIR__ . '/../config/settings.local.php';
            if (!file_exists($configFile)) {
                $configFile = __DIR__ . '/../config/settings.php';
            }
            self::$config = require $configFile;
        }
        return self::$config;
    }
    
    /**
     * Get secret key from configuration
     */
    private static function getSecretKey() {
        $config = self::getConfig();
        $key = $config['encryption_key'] ?? null;
        
        if (!$key || $key === 'CHANGE_THIS_TO_RANDOM_32_BYTE_HEX') {
            throw new Exception('Encryption key not configured. Please update config/settings.local.php');
        }
        
        return $key;
    }
    
    /**
     * Encrypts data and returns a URL-safe hash
     */
    public static function encrypt($pixelId, $accessToken) {
        try {
            // Validate inputs
            if (empty($pixelId) || empty($accessToken)) {
                throw new Exception('Pixel ID and Access Token are required');
            }
            
            // Simple data string - NO TIMESTAMP, permanent hash
            $dataString = implode(self::DATA_SEPARATOR, [
                $pixelId,
                $accessToken
            ]);
            
            // Get key as binary
            $key = hex2bin(self::getSecretKey());
            
            // Generate deterministic IV based ONLY on credentials (always same IV for same credentials)
            $ivSeed = hash('sha256', $pixelId . '|' . $accessToken, true);
            $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $iv = substr($ivSeed, 0, $ivLength);
            
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
     */
    public static function decrypt($hash) {
        try {
            // Check cache first
            if (isset(self::$cache[$hash])) {
                return self::$cache[$hash];
            }
            
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
            $key = hex2bin(self::getSecretKey());
            
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
            
            // Parse decrypted data - NO TIMESTAMP, permanent hash
            $parts = explode(self::DATA_SEPARATOR, $decrypted);
            if (count($parts) !== 2) {
                throw new Exception('Invalid decrypted data format');
            }
            
            list($pixelId, $accessToken) = $parts;
            
            // NO EXPIRATION CHECK - hash is permanent
            
            $result = [
                'pixel_id' => $pixelId,
                'access_token' => $accessToken
            ];
            
            // Cache result
            self::$cache[$hash] = $result;
            
            return $result;
            
        } catch (Exception $e) {
            error_log('Decryption error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Quick validation of hash format without decrypting
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
     */
    public static function createTestHash($pixelId, $accessToken) {
        return self::encrypt($pixelId, $accessToken);
    }
    
    /**
     * Extracts just the pixel ID from a hash (for logging without exposing token)
     */
    public static function getPixelIdFromHash($hash) {
        $data = self::decrypt($hash);
        return $data ? $data['pixel_id'] : false;
    }
    
    /**
     * Get cipher method (public method for external access)
     */
    public static function getCipherMethod() {
        return self::CIPHER_METHOD;
    }
}

/**
 * Helper function for quick encryption
 */
function encryptData($pixelId, $accessToken) {
    return Crypto::encrypt($pixelId, $accessToken);
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