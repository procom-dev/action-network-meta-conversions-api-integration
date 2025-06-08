# Guía Completa de Implementación - Action Network to Meta Conversions API

## 🚀 Inicio Rápido

Esta guía contiene todos los comandos y archivos necesarios para actualizar tu instalación. Ejecuta cada sección en orden.

## 1. Preparación y Backup

```bash
# Navegar al directorio del proyecto
cd /path/to/your/project

# Crear backup completo
tar -czf backup-$(date +%Y%m%d-%H%M%S).tar.gz .
echo "✅ Backup creado: backup-$(date +%Y%m%d-%H%M%S).tar.gz"

# Crear nueva estructura de directorios
mkdir -p includes config tools
```

## 2. Archivos de Configuración

### 2.1 Crear archivo de configuración base

```bash
cat > config/settings.php << 'EOF'
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
EOF
```

### 2.2 Crear configuración local con valores seguros

```bash
# Copiar archivo base
cp config/settings.php config/settings.local.php

# Generar encryption key aleatoria
ENCRYPTION_KEY=$(php -r "echo bin2hex(random_bytes(32));")
echo "📝 Tu nueva encryption key: $ENCRYPTION_KEY"

# Generar password segura
DASHBOARD_PASS=$(openssl rand -base64 16)
echo "🔐 Tu nueva dashboard password: $DASHBOARD_PASS"

# Actualizar automáticamente el archivo
php -r "
\$config = require 'config/settings.local.php';
\$config['encryption_key'] = '$ENCRYPTION_KEY';
\$config['dashboard_password'] = '$DASHBOARD_PASS';
file_put_contents('config/settings.local.php', '<?php return ' . var_export(\$config, true) . ';');
"

echo "✅ Configuración actualizada en config/settings.local.php"
```

## 3. Archivos de Includes

### 3.1 Crear manejador de errores

```bash
cat > includes/error_handler.php << 'EOF'
<?php
/**
 * Global error handling
 */

require_once __DIR__ . '/functions.php';

function handleError($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    $errorTypes = [
        E_ERROR => 'Fatal Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_STRICT => 'Strict Standards',
        E_DEPRECATED => 'Deprecated'
    ];
    
    $type = $errorTypes[$errno] ?? 'Unknown Error';
    
    quickLog("PHP {$type}: {$errstr}", 'ERROR', [
        'file' => $errfile,
        'line' => $errline,
        'type' => $type,
        'code' => $errno
    ]);
    
    return true;
}

function handleException($exception) {
    quickLog('Uncaught Exception: ' . $exception->getMessage(), 'ERROR', [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Internal server error']);
    }
}

function handleShutdown() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        quickLog('Fatal Error on Shutdown', 'ERROR', $error);
    }
}

// Register handlers
set_error_handler('handleError');
set_exception_handler('handleException');
register_shutdown_function('handleShutdown');
EOF
```

### 3.2 Crear helpers

```bash
cat > includes/helpers.php << 'EOF'
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
EOF
```

## 4. Mover archivos a includes

```bash
# Mover archivos de utilidades a includes
mv crypto.php includes/
mv functions.php includes/
mv debug_meta_payload.php includes/

# Mover herramientas a tools
mv check_test.php tools/
mv check_script_test.php tools/
mv verify.php tools/
mv generate_hash.php tools/
```

## 5. Actualizar archivos existentes

### 5.1 Actualizar functions.php

```bash
# Backup del archivo original
cp includes/functions.php includes/functions.php.bak

# Actualizar versión de API
sed -i "s/define('META_API_VERSION', 'v18.0');/define('META_API_VERSION', 'v23.0');/g" includes/functions.php

# Añadir nueva función quickLog al final del archivo
cat >> includes/functions.php << 'EOF'

/**
 * Enhanced logging with rotation
 */
function quickLog($message, $level = 'INFO', $context = []) {
    // Ensure logs directory exists
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    // Rotate logs daily
    $date = date('Y-m-d');
    $logFile = $logDir . '/app-' . $date . '.log';
    
    // Clean old logs (older than 30 days)
    require_once __DIR__ . '/helpers.php';
    cleanOldLogs($logDir, 30);
    
    // Format timestamp
    $timestamp = date('Y-m-d H:i:s');
    
    // Create structured log entry
    $logEntry = [
        'timestamp' => $timestamp,
        'level' => $level,
        'message' => $message,
        'context' => $context
    ];
    
    // Format as JSON for better parsing
    $jsonEntry = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    
    // Write to file
    @file_put_contents($logFile, $jsonEntry, FILE_APPEND | LOCK_EX);
    
    // Also write to level-specific files
    if ($level === 'ERROR') {
        $errorFile = $logDir . '/error-' . $date . '.log';
        @file_put_contents($errorFile, $jsonEntry, FILE_APPEND | LOCK_EX);
    }
}
EOF
```

### 5.2 Actualizar crypto.php

```bash
# Backup
cp includes/crypto.php includes/crypto.php.bak

# Crear nuevo crypto.php actualizado
cat > includes/crypto.php << 'EOF'
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
    private static function getConfig() {
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
            
            // Create data string with timestamp for added security
            $timestamp = time();
            $dataString = implode(self::DATA_SEPARATOR, [
                $pixelId,
                $accessToken,
                $timestamp
            ]);
            
            // Get key as binary
            $key = hex2bin(self::getSecretKey());
            
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
            
            // Parse decrypted data
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
            
            $result = [
                'pixel_id' => $pixelId,
                'access_token' => $accessToken,
                'timestamp' => $timestamp
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
EOF
```

### 5.3 Actualizar webhook.php - Añadir helpers y mejoras

```bash
# Añadir después de require_once de functions.php (línea ~11)
sed -i '11a require_once __DIR__ . "/includes/helpers.php";' webhook.php

# Añadir función de búsqueda recursiva al final del archivo
cat >> webhook.php << 'EOF'

/**
 * Find value recursively in array (helper function)
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
EOF
```

### 5.4 Actualizar todos los require_once paths

```bash
# Actualizar paths en archivos principales
FILES="webhook.php api.php tracker.js.php dashboard.php dashboard_api.php setup.php verify.php generate_hash.php"

for file in $FILES; do
    if [ -f "$file" ]; then
        # Actualizar paths de crypto.php
        sed -i "s|require_once __DIR__ . '/crypto.php';|require_once __DIR__ . '/includes/crypto.php';|g" "$file"
        
        # Actualizar paths de functions.php
        sed -i "s|require_once __DIR__ . '/functions.php';|require_once __DIR__ . '/includes/functions.php';|g" "$file"
        
        # Actualizar paths de debug_meta_payload.php
        sed -i "s|require_once __DIR__ . '/debug_meta_payload.php';|require_once __DIR__ . '/includes/debug_meta_payload.php';|g" "$file"
        
        # Añadir error handler después del primer require_once
        sed -i '0,/require_once/{/require_once/a\
require_once __DIR__ . "/includes/error_handler.php";\
require_once __DIR__ . "/includes/helpers.php";
}' "$file"
    fi
done

# Actualizar paths en archivos de tools/
for file in tools/*.php; do
    if [ -f "$file" ]; then
        sed -i "s|require_once __DIR__ . '/|require_once __DIR__ . '/../includes/|g" "$file"
    fi
done
```

### 5.5 Actualizar config.php para eliminar funciones duplicadas

```bash
cat > config.php << 'EOF'
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
EOF
```

### 5.6 Actualizar dashboard.php para usar configuración

```bash
# Crear script temporal para actualizar dashboard.php
cat > update_dashboard.php << 'EOF'
<?php
$content = file_get_contents('dashboard.php');

// Reemplazar password hardcodeada
$content = str_replace(
    "\$dashboard_password = 'meta2024'; // Change this in production!",
    "// Load configuration\nrequire_once __DIR__ . '/includes/crypto.php';\n\$config = Crypto::getConfig();\n\$dashboard_password = \$config['dashboard_password'];"
    $content
);

file_put_contents('dashboard.php', $content);
echo "Dashboard actualizado\n";
EOF

php update_dashboard.php
rm update_dashboard.php
```

### 5.7 Actualizar tracker.js.php con headers mejorados

```bash
# Añadir headers más agresivos después de la línea de Content-Type
sed -i '/^header.*Content-Type.*javascript/a\
header("Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0, s-maxage=0, proxy-revalidate");\
header("Pragma: no-cache");\
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");\
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");\
header("ETag: \"" . md5(uniqid("", true)) . "\"");\
header("CF-Cache-Status: BYPASS");\
header("CDN-Cache-Control: no-cache");\
header("Surrogate-Control: no-store");' tracker.js.php
```

## 6. Actualizar .htaccess

```bash
# Backup
cp .htaccess .htaccess.bak

# Añadir reglas de seguridad
cat >> .htaccess << 'EOF'

# Denegar acceso a directorios sensibles
RewriteRule ^(includes|config|logs|tools)/ - [F,L]

# Denegar acceso a archivos de configuración
<FilesMatch "\.(ini|log|sh|inc|bak|config|sql|json)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Proteger archivos específicos
<FilesMatch "^(config\.php|settings\.php|settings\.local\.php)$">
    Order allow,deny
    Deny from all
</FilesMatch>
EOF
```

## 7. Actualizar .gitignore

```bash
cat >> .gitignore << 'EOF'
# Configuration
config/settings.local.php

# Logs
logs/
*.log

# Backups
backup-*.tar.gz
*.bak

# IDE
.vscode/
.idea/

# OS
.DS_Store
Thumbs.db
EOF
```

## 8. Crear índice básico

```bash
cat > index.php << 'EOF'
<?php
// Redirect to setup
header('Location: setup.php');
exit;
EOF
```

## 9. Permisos de directorios

```bash
# Asegurar permisos correctos
chmod 755 includes config tools
chmod 644 includes/*.php config/*.php tools/*.php
chmod 640 config/settings.local.php

# Crear y asegurar directorio de logs
mkdir -p logs
chmod 755 logs

# Si usas Apache/PHP-FPM, ajustar owner
# chown -R www-data:www-data logs/
```

## 10. Verificación final

### 10.1 Crear script de verificación

```bash
cat > verify_installation.php << 'EOF'
<?php
echo "🔍 Verificando instalación...\n\n";

// Verificar estructura de directorios
$dirs = ['includes', 'config', 'tools', 'logs'];
foreach ($dirs as $dir) {
    echo $dir . ": " . (is_dir($dir) ? "✅ OK" : "❌ FALTA") . "\n";
}

echo "\n";

// Verificar archivos críticos
$files = [
    'includes/crypto.php',
    'includes/functions.php',
    'includes/error_handler.php',
    'includes/helpers.php',
    'config/settings.php',
    'config/settings.local.php'
];

foreach ($files as $file) {
    echo $file . ": " . (file_exists($file) ? "✅ OK" : "❌ FALTA") . "\n";
}

echo "\n";

// Verificar configuración
if (file_exists('config/settings.local.php')) {
    $config = require 'config/settings.local.php';
    echo "Encryption key: " . 
         ($config['encryption_key'] !== 'CHANGE_THIS_TO_RANDOM_32_BYTE_HEX' ? "✅ Configurada" : "❌ No configurada") . "\n";
    echo "Dashboard password: " . 
         ($config['dashboard_password'] !== 'CHANGE_THIS_STRONG_PASSWORD' ? "✅ Configurada" : "❌ No configurada") . "\n";
}

echo "\n✨ Verificación completada\n";
EOF

# Ejecutar verificación
php verify_installation.php

# Limpiar
rm verify_installation.php
```

## 11. Prueba final

```bash
# Probar que el sitio funciona
echo "
✅ Instalación completada!

📋 Próximos pasos:
1. Visita tu sitio web para verificar que carga correctamente
2. Accede a /setup.php para configurar integraciones
3. Revisa los logs en logs/ para verificar funcionamiento

🔐 Credenciales guardadas en config/settings.local.php
"

# Mostrar credenciales
echo "
📝 Tus credenciales (guárdalas en lugar seguro):
"
php -r "
\$config = require 'config/settings.local.php';
echo 'Dashboard Password: ' . \$config['dashboard_password'] . PHP_EOL;
echo 'Encryption Key: ' . \$config['encryption_key'] . PHP_EOL;
"
```

## 🚨 Solución de Problemas

Si algo falla, restaura el backup:
```bash
# Restaurar backup
tar -xzf backup-*.tar.gz
```

### Errores comunes:

1. **Error de permisos**: Asegúrate de que el servidor web puede escribir en `logs/`
2. **Error 500**: Revisa los logs del servidor web y `logs/error-*.log`
3. **Página en blanco**: Activa display_errors temporalmente para ver el error

```bash
# Debug temporal
echo "<?php ini_set('display_errors', 1); error_reporting(E_ALL);" > debug.php
```

## ✅ Checklist Final

- [ ] Backup creado
- [ ] Directorios creados (includes, config, tools, logs)
- [ ] Configuración actualizada con valores seguros
- [ ] Archivos movidos a las ubicaciones correctas
- [ ] Paths actualizados en todos los archivos
- [ ] Permisos configurados correctamente
- [ ] .gitignore actualizado
- [ ] Sitio funcionando correctamente

---
🎉 ¡Actualización completada!