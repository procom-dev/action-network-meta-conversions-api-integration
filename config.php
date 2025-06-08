<?php
/**
 * Configuration file - Only general utilities, no encryption
 */

// Include actual encryption functions from crypto.php
require_once __DIR__ . '/includes/crypto.php';

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