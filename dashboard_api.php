<?php
/**
 * dashboard_api.php - API endpoint for dashboard data
 * 
 * Parses log files and returns statistics in JSON format
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Security check - same session validation as dashboard
session_start();
if (!isset($_SESSION['dashboard_auth']) || $_SESSION['dashboard_auth'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $logDir = __DIR__ . '/logs';
    
    // Initialize response data
    $response = [
        'success' => true,
        'stats' => [
            'totalCalls' => 0,
            'successRate' => '0%',
            'avgResponseTime' => '0ms',
            'matchQuality' => 'Unknown',
            'eventsReceived' => 0,
            'errorCount' => 0,
            'callsChange' => '0',
            'successChange' => '0%',
            'responseChange' => '0ms'
        ],
        'recentCalls' => [],
        'recentErrors' => [],
        'eventTypes' => [],
        'fieldStats' => [],
        'pairedEvents' => [],
        'unpairedEvents' => [],
        'pairingStats' => []
    ];
    
    // Parse different log files
    $stats = parseLogFiles($logDir);
    $response['stats'] = array_merge($response['stats'], $stats['summary']);
    $response['recentCalls'] = $stats['recentCalls'];
    $response['recentErrors'] = $stats['recentErrors'];
    $response['eventTypes'] = $stats['eventTypes'];
    $response['fieldStats'] = $stats['fieldStats'];
    
    // Group events by event_id to find pairs
    $pairingData = groupEventsByEventId($stats['recentCalls']);
    $response['pairedEvents'] = $pairingData['paired'];
    $response['unpairedEvents'] = $pairingData['unpaired'];
    $response['pairingStats'] = $pairingData['stats'];
    
    // DEBUG: Add debugging info to see what's happening
    $response['debug'] = [
        'totalCalls' => count($stats['recentCalls']),
        'callsWithEventId' => array_filter($stats['recentCalls'], function($call) {
            return !empty($call['eventId']);
        }),
        'eventIdsSample' => array_map(function($call) {
            return [
                'eventId' => $call['eventId'] ?? 'none',
                'origin' => $call['origin'] ?? 'unknown',
                'timestamp' => $call['timestamp'] ?? 'unknown'
            ];
        }, array_slice($stats['recentCalls'], 0, 10))
    ];
    
    // Merge pairing stats into main stats
    $response['stats']['pairingRate'] = $pairingData['stats']['pairingRate'];
    $response['stats']['unpairedCount'] = $pairingData['stats']['unpairedCount'];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}

/**
 * Parse all log files and extract statistics
 */
function parseLogFiles($logDir) {
    $stats = [
        'summary' => [
            'totalCalls' => 0,
            'successRate' => '0%',
            'avgResponseTime' => '0ms',
            'matchQuality' => 'Unknown',
            'eventsReceived' => 0,
            'errorCount' => 0,
            'callsChange' => '0',
            'successChange' => '0%',
            'responseChange' => '0ms'
        ],
        'recentCalls' => [],
        'recentErrors' => [],
        'eventTypes' => [],
        'fieldStats' => []
    ];
    
    // Parse dashboard events log first for raw emails
    // Dashboard events logging disabled
    // $dashboardLogPath = $logDir . '/dashboard_events.log';
    $dashboardEmailMap = [];
    // if (file_exists($dashboardLogPath)) {
    //     $dashboardEmailMap = parseDashboardEventsLog($dashboardLogPath);
    // }
    
    // Parse Meta payload debug log
    $debugLogPath = $logDir . '/meta_payload_debug.log';
    if (file_exists($debugLogPath)) {
        $debugStats = parseMetaPayloadDebugLog($debugLogPath, $dashboardEmailMap);
        $stats = array_merge_recursive($stats, $debugStats);
    }
    
    // Parse main app log only if debug log is empty/missing
    $appLogPath = $logDir . '/app.log';
    if (file_exists($appLogPath) && empty($stats['recentCalls'])) {
        $appStats = parseAppLog($appLogPath);
        $stats = array_merge_recursive($stats, $appStats);
    }
    
    // Parse error log
    $errorLogPath = $logDir . '/error.log';
    if (file_exists($errorLogPath)) {
        $errorStats = parseErrorLog($errorLogPath);
        $stats['recentErrors'] = array_merge($stats['recentErrors'], $errorStats['recentErrors']);
        $stats['summary']['errorCount'] += $errorStats['errorCount'];
    }
    
    // Calculate final statistics
    $stats['summary'] = calculateFinalStats($stats);
    
    // Sort and limit arrays
    $stats['recentCalls'] = array_slice(array_reverse($stats['recentCalls']), 0, 20);
    $stats['recentErrors'] = array_slice(array_reverse($stats['recentErrors']), 0, 10);
    
    return $stats;
}

/**
 * Parse dashboard events log to create email mapping
 */
function parseDashboardEventsLog($logPath) {
    $emailMap = [];
    
    if (!file_exists($logPath)) {
        return $emailMap;
    }
    
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return $emailMap;
    }
    
    // Parse last 1000 lines for recent events
    $recentLines = array_slice($lines, -1000);
    
    foreach ($recentLines as $line) {
        $eventData = json_decode($line, true);
        if ($eventData && isset($eventData['event_id']) && isset($eventData['raw_email'])) {
            $emailMap[$eventData['event_id']] = [
                'raw_email' => $eventData['raw_email'],
                'source' => $eventData['source'],
                'additional_data' => $eventData['additional_data'] ?? []
            ];
        }
    }
    
    return $emailMap;
}

/**
 * Parse the Meta payload debug log
 */
function parseMetaPayloadDebugLog($logPath, $emailMap = []) {
    $stats = [
        'recentCalls' => [],
        'eventTypes' => [],
        'fieldStats' => [],
        'responseTimes' => [],
        'successCount' => 0,
        'totalCount' => 0,
        'eventsReceived' => 0
    ];
    
    if (!file_exists($logPath)) {
        return $stats;
    }
    
    $content = file_get_contents($logPath);
    if (empty($content)) {
        return $stats;
    }
    
    // Split into log entries (separated by dashes)
    $entries = explode('--------------------------------------------------------------------------------', $content);
    
    $currentCall = null;
    
    foreach ($entries as $entry) {
        $entry = trim($entry);
        if (empty($entry)) continue;
        
        // Parse timestamp and level
        if (preg_match('/\[([^\]]+)\] \[([^\]]+)\] (.+)/', $entry, $matches)) {
            $timestamp = $matches[1];
            $level = $matches[2];
            $message = $matches[3];
            
            // Handle different types of log entries
            if (strpos($message, 'Event') === 0 && strpos($message, 'Structure Analysis') !== false) {
                // Start of a new API call
                if ($currentCall) {
                    $stats['recentCalls'][] = $currentCall;
                }
                
                $currentCall = [
                    'timestamp' => formatTimestamp($timestamp),
                    'eventType' => 'Unknown',
                    'success' => false,
                    'responseTime' => 0,
                    'eventsReceived' => 0,
                    'userFields' => [],
                    'hasFacebookData' => false,
                    'httpCode' => null,
                    'error' => null,
                    'source' => 'api',
                    'pixelId' => null,
                    'eventId' => null,
                    'email' => null,
                    'formUrl' => null,
                    'origin' => 'unknown'
                ];
                
                // Parse the JSON data for event analysis
                $jsonStart = strpos($entry, '{');
                if ($jsonStart !== false) {
                    $jsonData = substr($entry, $jsonStart);
                    $analysisData = json_decode($jsonData, true);
                    if ($analysisData) {
                        $currentCall['eventType'] = $analysisData['event_name'] ?? 'Unknown';
                        $currentCall['userFields'] = extractUserFields($analysisData);
                        $currentCall['hasFacebookData'] = checkForFacebookData($analysisData);
                        
                        // Count event types
                        $eventType = $currentCall['eventType'];
                        $stats['eventTypes'][$eventType] = ($stats['eventTypes'][$eventType] ?? 0) + 1;
                        
                        // Count field usage
                        foreach ($currentCall['userFields'] as $field) {
                            $stats['fieldStats'][$field] = ($stats['fieldStats'][$field] ?? 0) + 1;
                        }
                    }
                }
                
            } elseif (strpos($message, 'Complete Payload Being Sent') !== false) {
                // Parse payload details
                $jsonStart = strpos($entry, '{');
                if ($jsonStart !== false && $currentCall) {
                    $jsonData = substr($entry, $jsonStart);
                    $payloadData = json_decode($jsonData, true);
                    if ($payloadData) {
                        $currentCall['pixelId'] = $payloadData['pixel_id'] ?? null;
                        if (isset($payloadData['payload']['data'][0]['event_id'])) {
                            $currentCall['eventId'] = $payloadData['payload']['data'][0]['event_id'];
                        }
                        
                        // Extract email, form URL, and determine origin
                        if (isset($payloadData['payload']['data'][0])) {
                            $eventData = $payloadData['payload']['data'][0];
                            
                            // Extract form URL
                            $currentCall['formUrl'] = $eventData['event_source_url'] ?? null;
                            
                            // Check if we have raw email from dashboard events log
                            $eventId = $currentCall['eventId'];
                            if ($eventId && isset($emailMap[$eventId])) {
                                $currentCall['email'] = $emailMap[$eventId]['raw_email'];
                            } else {
                                // Fallback to hash detection
                                if (isset($eventData['user_data']['em'])) {
                                    $currentCall['email'] = 'hashed_email_present';
                                } elseif (isset($eventData['user_data']) && 
                                         count(array_filter($eventData['user_data'], function($v) { return !empty($v); })) > 2) {
                                    $currentCall['email'] = 'user_data_present';
                                } else {
                                    $currentCall['email'] = 'no_email';
                                }
                            }
                            
                            // Determine origin from tracking_source
                            if (isset($eventData['custom_data']['tracking_source'])) {
                                $trackingSource = $eventData['custom_data']['tracking_source'];
                                if ($trackingSource === 'action_network_webhook') {
                                    $currentCall['origin'] = 'webhook';
                                } elseif ($trackingSource === 'action_network_javascript') {
                                    $currentCall['origin'] = 'javascript';
                                } else {
                                    $currentCall['origin'] = $trackingSource;
                                }
                            }
                        }
                    }
                }
                
            } elseif (strpos($message, 'Meta API Response Details') !== false) {
                // Parse response details
                $jsonStart = strpos($entry, '{');
                if ($jsonStart !== false && $currentCall) {
                    $jsonData = substr($entry, $jsonStart);
                    $responseData = json_decode($jsonData, true);
                    if ($responseData) {
                        $currentCall['httpCode'] = $responseData['http_code'] ?? null;
                        $currentCall['responseTime'] = $responseData['response_time_ms'] ?? 0;
                        $currentCall['success'] = ($responseData['http_code'] ?? 0) === 200;
                        
                        if ($currentCall['success']) {
                            $stats['successCount']++;
                        }
                        
                        $stats['responseTimes'][] = $currentCall['responseTime'];
                        $stats['totalCount']++;
                    }
                }
                
            } elseif ($level === 'ERROR' && $currentCall) {
                // Parse error messages
                if (strpos($message, 'API Error') !== false) {
                    $currentCall['error'] = 'API Error - Check Meta response details';
                }
            }
        }
    }
    
    // Add the last call if exists
    if ($currentCall) {
        $stats['recentCalls'][] = $currentCall;
    }
    
    return $stats;
}

/**
 * Parse the main app log
 */
function parseAppLog($logPath) {
    $stats = [
        'recentCalls' => [],
        'eventTypes' => []
    ];
    
    if (!file_exists($logPath)) {
        return $stats;
    }
    
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return $stats;
    }
    
    // Parse last 1000 lines for recent activity
    $recentLines = array_slice($lines, -1000);
    
    foreach ($recentLines as $line) {
        if (preg_match('/\[([^\]]+)\] \[([^\]]+)\] (.+)/', $line, $matches)) {
            $timestamp = $matches[1];
            $level = $matches[2];
            $message = $matches[3];
            
            // Look for successful/failed Meta API calls
            if (strpos($message, 'Successfully sent') !== false || strpos($message, 'Failed to send') !== false) {
                $success = strpos($message, 'Successfully sent') !== false;
                
                // Try to extract pixel ID and event type from context
                $pixelId = null;
                $eventType = 'Unknown';
                
                if (preg_match('/pixel[_\s]*id[:\s]*([a-zA-Z0-9]+)/i', $line, $pixelMatch)) {
                    $pixelId = $pixelMatch[1];
                }
                
                if (preg_match('/event[_\s]*type[:\s]*([a-zA-Z0-9]+)/i', $line, $eventMatch)) {
                    $eventType = $eventMatch[1];
                }
                
                // Extract additional info from context
                $email = 'N/A';
                $origin = 'unknown';
                $eventId = null;
                if (preg_match('/Context:\s*\{([^}]+)\}/', $line, $contextMatch)) {
                    $contextStr = '{' . $contextMatch[1] . '}';
                    $contextData = json_decode($contextStr, true);
                    if ($contextData) {
                        if (isset($contextData['has_email']) && $contextData['has_email']) {
                            $email = 'email_present';
                        } elseif (isset($contextData['has_email']) && !$contextData['has_email']) {
                            $email = 'no_email';
                        }
                        
                        if (isset($contextData['source'])) {
                            $origin = strpos($contextData['source'], 'webhook') !== false ? 'webhook' : 'javascript';
                        }
                        
                        if (isset($contextData['event_id'])) {
                            $eventId = $contextData['event_id'];
                        }
                    }
                }

                $call = [
                    'timestamp' => formatTimestamp($timestamp),
                    'eventType' => $eventType,
                    'success' => $success,
                    'source' => 'app_log',
                    'pixelId' => $pixelId,
                    'responseTime' => 0,
                    'eventsReceived' => 0,
                    'userFields' => [],
                    'hasFacebookData' => false,
                    'httpCode' => $success ? 200 : null,
                    'error' => $success ? null : 'Check error logs for details',
                    'eventId' => $eventId,
                    'email' => $email,
                    'formUrl' => null,
                    'origin' => $origin
                ];
                
                $stats['recentCalls'][] = $call;
                
                // Count event types
                $stats['eventTypes'][$eventType] = ($stats['eventTypes'][$eventType] ?? 0) + 1;
            }
        }
    }
    
    return $stats;
}

/**
 * Parse error log
 */
function parseErrorLog($logPath) {
    $stats = [
        'recentErrors' => [],
        'errorCount' => 0
    ];
    
    if (!file_exists($logPath)) {
        return $stats;
    }
    
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return $stats;
    }
    
    // Parse last 500 lines for recent errors
    $recentLines = array_slice($lines, -500);
    $yesterday = time() - (24 * 60 * 60);
    
    foreach ($recentLines as $line) {
        if (preg_match('/\[([^\]]+)\] \[ERROR\] (.+)/', $line, $matches)) {
            $timestamp = $matches[1];
            $message = $matches[2];
            
            $errorTime = strtotime($timestamp);
            if ($errorTime >= $yesterday) {
                $stats['errorCount']++;
            }
            
            $stats['recentErrors'][] = [
                'timestamp' => formatTimestamp($timestamp),
                'message' => $message,
                'context' => extractErrorContext($line)
            ];
        }
    }
    
    return $stats;
}

/**
 * Calculate final statistics
 */
function calculateFinalStats($stats) {
    $summary = $stats['summary'];
    
    // Calculate total calls
    $summary['totalCalls'] = $stats['totalCount'] ?? 0;
    
    // Calculate success rate
    if ($stats['totalCount'] > 0) {
        $successRate = round(($stats['successCount'] / $stats['totalCount']) * 100, 1);
        $summary['successRate'] = $successRate . '%';
    }
    
    // Calculate average response time
    if (!empty($stats['responseTimes'])) {
        $avgTime = round(array_sum($stats['responseTimes']) / count($stats['responseTimes']));
        $summary['avgResponseTime'] = $avgTime . 'ms';
    }
    
    // Estimate match quality based on Facebook data presence
    $callsWithFbData = 0;
    $totalCallsAnalyzed = 0;
    foreach ($stats['recentCalls'] as $call) {
        if (isset($call['hasFacebookData'])) {
            $totalCallsAnalyzed++;
            if ($call['hasFacebookData']) {
                $callsWithFbData++;
            }
        }
    }
    
    if ($totalCallsAnalyzed > 0) {
        $fbDataPercent = round(($callsWithFbData / $totalCallsAnalyzed) * 100);
        if ($fbDataPercent >= 80) {
            $summary['matchQuality'] = 'High';
        } elseif ($fbDataPercent >= 50) {
            $summary['matchQuality'] = 'Medium';
        } else {
            $summary['matchQuality'] = 'Low';
        }
    }
    
    // Count events received
    $summary['eventsReceived'] = $stats['eventsReceived'] ?? 0;
    
    return $summary;
}

/**
 * Extract user fields from analysis data
 */
function extractUserFields($analysisData) {
    $fields = [];
    
    if (isset($analysisData['strengths'])) {
        foreach ($analysisData['strengths'] as $strength) {
            if (preg_match("/User data field '([^']+)'/", $strength, $matches)) {
                $fields[] = $matches[1];
            }
        }
    }
    
    return $fields;
}

/**
 * Check if Facebook data is present
 */
function checkForFacebookData($analysisData) {
    if (isset($analysisData['strengths'])) {
        foreach ($analysisData['strengths'] as $strength) {
            if (strpos($strength, 'Facebook browser identifier') !== false) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Extract error context from log line
 */
function extractErrorContext($line) {
    // Try to extract JSON context
    $contextStart = strpos($line, '|');
    if ($contextStart !== false) {
        $context = trim(substr($line, $contextStart + 1));
        if (strpos($context, 'Context:') === 0) {
            return substr($context, 8);
        }
    }
    
    return null;
}

/**
 * Format timestamp for display
 */
function formatTimestamp($timestamp) {
    $time = strtotime($timestamp);
    if ($time === false) {
        return $timestamp;
    }
    
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' min ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, H:i', $time);
    }
}

/**
 * Group events by event_id to identify paired webhook/javascript events
 */
function groupEventsByEventId($recentCalls) {
    $eventGroups = [];
    $paired = [];
    $unpaired = [];
    $stats = [
        'totalEvents' => count($recentCalls),
        'pairedCount' => 0,
        'unpairedCount' => 0,
        'webhookOnlyCount' => 0,
        'javascriptOnlyCount' => 0,
        'pairingRate' => '0%'
    ];
    
    // Group calls by event_id
    foreach ($recentCalls as $call) {
        if (!empty($call['eventId'])) {
            $eventId = $call['eventId'];
            if (!isset($eventGroups[$eventId])) {
                $eventGroups[$eventId] = [];
            }
            $eventGroups[$eventId][] = $call;
        }
    }
    
    // Analyze each group
    foreach ($eventGroups as $eventId => $calls) {
        if (count($calls) >= 2) {
            // This is a paired event (webhook + javascript)
            $webhookCall = null;
            $javascriptCall = null;
            
            foreach ($calls as $call) {
                if ($call['origin'] === 'webhook') {
                    $webhookCall = $call;
                } elseif ($call['origin'] === 'javascript') {
                    $javascriptCall = $call;
                }
            }
            
            if ($webhookCall && $javascriptCall) {
                $paired[] = [
                    'eventId' => $eventId,
                    'webhook' => $webhookCall,
                    'javascript' => $javascriptCall,
                    'timeDifference' => calculateTimeDifference($webhookCall, $javascriptCall),
                    'dataComparison' => compareEventData($webhookCall, $javascriptCall)
                ];
                $stats['pairedCount']++;
            } else {
                // Multiple calls but not properly paired
                foreach ($calls as $call) {
                    $unpaired[] = $call;
                    $stats['unpairedCount']++;
                }
            }
        } else {
            // Single unpaired event
            $call = $calls[0];
            $unpaired[] = $call;
            $stats['unpairedCount']++;
            
            if ($call['origin'] === 'webhook') {
                $stats['webhookOnlyCount']++;
            } elseif ($call['origin'] === 'javascript') {
                $stats['javascriptOnlyCount']++;
            }
        }
    }
    
    // Calculate pairing rate
    if ($stats['totalEvents'] > 0) {
        $pairingRate = round(($stats['pairedCount'] * 2) / $stats['totalEvents'] * 100, 1);
        $stats['pairingRate'] = $pairingRate . '%';
    }
    
    // Sort by most recent
    usort($paired, function($a, $b) {
        $timeA = strtotime($a['webhook']['timestamp'] ?? $a['javascript']['timestamp']);
        $timeB = strtotime($b['webhook']['timestamp'] ?? $b['javascript']['timestamp']);
        return $timeB - $timeA;
    });
    
    usort($unpaired, function($a, $b) {
        $timeA = strtotime($a['timestamp']);
        $timeB = strtotime($b['timestamp']);
        return $timeB - $timeA;
    });
    
    return [
        'paired' => array_slice($paired, 0, 20),
        'unpaired' => array_slice($unpaired, 0, 20),
        'stats' => $stats
    ];
}

/**
 * Calculate time difference between webhook and javascript calls
 */
function calculateTimeDifference($webhookCall, $javascriptCall) {
    $webhookTime = strtotime($webhookCall['timestamp']);
    $javascriptTime = strtotime($javascriptCall['timestamp']);
    
    if ($webhookTime === false || $javascriptTime === false) {
        return 'Unknown';
    }
    
    $diff = abs($webhookTime - $javascriptTime);
    
    if ($diff < 60) {
        return $diff . 's';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . 'm ' . ($diff % 60) . 's';
    } else {
        return floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'm';
    }
}

/**
 * Compare data between webhook and javascript events
 */
function compareEventData($webhookCall, $javascriptCall) {
    $comparison = [
        'emailMatch' => 'unknown',
        'facebookDataPresent' => false,
        'qualityScore' => 0
    ];
    
    // Check email presence
    $webhookHasEmail = ($webhookCall['email'] === 'hashed_email_present' || $webhookCall['email'] === 'email_present');
    $jsHasEmail = ($javascriptCall['email'] === 'hashed_email_present' || $javascriptCall['email'] === 'email_present');
    
    if ($webhookHasEmail && $jsHasEmail) {
        $comparison['emailMatch'] = 'both_have_email';
        $comparison['qualityScore'] += 40;
    } elseif ($webhookHasEmail || $jsHasEmail) {
        $comparison['emailMatch'] = 'partial_email';
        $comparison['qualityScore'] += 20;
    } else {
        $comparison['emailMatch'] = 'no_email';
    }
    
    // Check Facebook data
    if ($webhookCall['hasFacebookData'] || $javascriptCall['hasFacebookData']) {
        $comparison['facebookDataPresent'] = true;
        $comparison['qualityScore'] += 30;
    }
    
    // Check success
    if ($webhookCall['success'] && $javascriptCall['success']) {
        $comparison['qualityScore'] += 30;
    }
    
    return $comparison;
}

?>