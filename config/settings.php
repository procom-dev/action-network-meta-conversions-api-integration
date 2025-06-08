<?php
/**
 * Application Settings
 * Copy this file to settings.local.php and update values
 */

return [
    // Security
    'encryption_key' => 'CHANGE_THIS_TO_RANDOM_32_BYTE_HEX', // Use: bin2hex(random_bytes(32))
    'dashboard_password' => 'CHANGE_THIS_STRONG_PASSWORD',
    
    // Meta API
    'meta_api_version' => 'v23.0',
    'meta_api_timeout' => 15,
    
    // Logging
    'log_level' => 'INFO', // DEBUG, INFO, WARNING, ERROR
    'log_max_days' => 30,
    
    // Application
    'debug_mode' => false,
    'timezone' => 'Europe/Madrid'
];