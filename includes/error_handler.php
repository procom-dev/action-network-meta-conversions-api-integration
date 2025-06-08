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