<?php
/**
 * debug_facebook_data.php - Analyze Facebook data collection from recent logs
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔍 Facebook Data Analysis</h1>";
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
.section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.issue { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 10px; border-radius: 4px; margin: 10px 0; }
.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 10px; border-radius: 4px; margin: 10px 0; }
.warning { background: #fffbeb; border: 1px solid #fed7aa; color: #92400e; padding: 10px; border-radius: 4px; margin: 10px 0; }
pre { background: #f8f9fa; padding: 15px; overflow-x: auto; border-radius: 4px; border: 1px solid #e9ecef; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
th { background-color: #f8f9fa; }
</style>";

// Analyze Meta payload debug log
$debugLogPath = __DIR__ . '/logs/meta_payload_debug.log';

if (!file_exists($debugLogPath)) {
    echo "<div class='issue'>Meta payload debug log not found. No recent API calls to analyze.</div>";
    exit;
}

$content = file_get_contents($debugLogPath);
if (empty($content)) {
    echo "<div class='issue'>Meta payload debug log is empty.</div>";
    exit;
}

echo "<div class='section'>";
echo "<h2>📊 Facebook Data Collection Analysis</h2>";

// Parse log entries
$entries = explode('--------------------------------------------------------------------------------', $content);
$facebookDataStats = [
    'total_events' => 0,
    'with_fbp' => 0,
    'with_fbc' => 0,
    'with_fbclid' => 0,
    'pageviews' => 0,
    'form_events' => 0,
    'recent_events' => []
];

foreach ($entries as $entry) {
    $entry = trim($entry);
    if (empty($entry)) continue;
    
    // Look for payload entries
    if (strpos($entry, 'Complete Payload Being Sent') !== false) {
        $jsonStart = strpos($entry, '{');
        if ($jsonStart !== false) {
            $jsonData = substr($entry, $jsonStart);
            $payloadData = json_decode($jsonData, true);
            
            if ($payloadData && isset($payloadData['payload']['data'][0])) {
                $event = $payloadData['payload']['data'][0];
                $facebookDataStats['total_events']++;
                
                $eventInfo = [
                    'event_name' => $event['event_name'] ?? 'Unknown',
                    'timestamp' => date('Y-m-d H:i:s', $event['event_time'] ?? time()),
                    'has_fbp' => isset($event['user_data']['fbp']),
                    'has_fbc' => isset($event['user_data']['fbc']),
                    'source_url' => $event['event_source_url'] ?? 'Not provided',
                    'fbp_value' => $event['user_data']['fbp'] ?? null,
                    'fbc_value' => $event['user_data']['fbc'] ?? null
                ];
                
                // Count Facebook data presence
                if ($eventInfo['has_fbp']) $facebookDataStats['with_fbp']++;
                if ($eventInfo['has_fbc']) $facebookDataStats['with_fbc']++;
                
                // Check for fbclid in source URL
                if (strpos($eventInfo['source_url'], 'fbclid=') !== false) {
                    $facebookDataStats['with_fbclid']++;
                    $eventInfo['fbclid_in_url'] = true;
                } else {
                    $eventInfo['fbclid_in_url'] = false;
                }
                
                // Count event types
                if ($event['event_name'] === 'PageView') {
                    $facebookDataStats['pageviews']++;
                } else {
                    $facebookDataStats['form_events']++;
                }
                
                $facebookDataStats['recent_events'][] = $eventInfo;
            }
        }
    }
}

// Reverse to show most recent first
$facebookDataStats['recent_events'] = array_reverse(array_slice($facebookDataStats['recent_events'], -20));

// Display summary
echo "<h3>📈 Summary Statistics</h3>";
echo "<table>";
echo "<tr><th>Metric</th><th>Count</th><th>Percentage</th></tr>";
echo "<tr><td>Total Events Analyzed</td><td>{$facebookDataStats['total_events']}</td><td>100%</td></tr>";

if ($facebookDataStats['total_events'] > 0) {
    $fbpPercent = round(($facebookDataStats['with_fbp'] / $facebookDataStats['total_events']) * 100, 1);
    $fbcPercent = round(($facebookDataStats['with_fbc'] / $facebookDataStats['total_events']) * 100, 1);
    $fbclidPercent = round(($facebookDataStats['with_fbclid'] / $facebookDataStats['total_events']) * 100, 1);
    
    echo "<tr><td>Events with FBP (Browser Pixel)</td><td>{$facebookDataStats['with_fbp']}</td><td>{$fbpPercent}%</td></tr>";
    echo "<tr><td>Events with FBC (Click ID)</td><td>{$facebookDataStats['with_fbc']}</td><td>{$fbcPercent}%</td></tr>";
    echo "<tr><td>Events with fbclid in URL</td><td>{$facebookDataStats['with_fbclid']}</td><td>{$fbclidPercent}%</td></tr>";
    echo "<tr><td>PageView Events</td><td>{$facebookDataStats['pageviews']}</td><td>" . round(($facebookDataStats['pageviews'] / $facebookDataStats['total_events']) * 100, 1) . "%</td></tr>";
    echo "<tr><td>Form Events</td><td>{$facebookDataStats['form_events']}</td><td>" . round(($facebookDataStats['form_events'] / $facebookDataStats['total_events']) * 100, 1) . "%</td></tr>";
}
echo "</table>";

// Show issues/recommendations
echo "<h3>🚨 Issues & Recommendations</h3>";

if ($facebookDataStats['with_fbc'] === 0) {
    echo "<div class='issue'><strong>Critical Issue:</strong> No FBC (Facebook Click ID) data found in any events. This severely impacts attribution quality for Facebook ads.</div>";
    echo "<div class='warning'><strong>Possible Causes:</strong><ul>";
    echo "<li>Users are not coming from Facebook ads (no fbclid in URL)</li>";
    echo "<li>fbclid is not being captured from URL parameters</li>";
    echo "<li>fbc cookie is not being constructed properly</li>";
    echo "</ul></div>";
}

if ($facebookDataStats['with_fbp'] > 0) {
    echo "<div class='success'><strong>Good:</strong> FBP (Browser Pixel) is working correctly in {$facebookDataStats['with_fbp']} events.</div>";
}

if ($facebookDataStats['with_fbclid'] === 0) {
    echo "<div class='warning'><strong>Attribution Limited:</strong> No fbclid parameters found in source URLs. This suggests traffic is not coming from Facebook ads.</div>";
}

echo "</div>";

// Show recent events
echo "<div class='section'>";
echo "<h2>📋 Recent Events Analysis</h2>";

if (empty($facebookDataStats['recent_events'])) {
    echo "<div class='issue'>No recent events found to analyze.</div>";
} else {
    echo "<table>";
    echo "<tr><th>Event</th><th>Time</th><th>FBP</th><th>FBC</th><th>fbclid in URL</th><th>Source URL</th></tr>";
    
    foreach ($facebookDataStats['recent_events'] as $event) {
        $fbpStatus = $event['has_fbp'] ? '✅' : '❌';
        $fbcStatus = $event['has_fbc'] ? '✅' : '❌';
        $fbclidStatus = $event['fbclid_in_url'] ? '✅' : '❌';
        $shortUrl = strlen($event['source_url']) > 50 ? substr($event['source_url'], 0, 50) . '...' : $event['source_url'];
        
        echo "<tr>";
        echo "<td>{$event['event_name']}</td>";
        echo "<td>{$event['timestamp']}</td>";
        echo "<td>{$fbpStatus}</td>";
        echo "<td>{$fbcStatus}</td>";
        echo "<td>{$fbclidStatus}</td>";
        echo "<td title='{$event['source_url']}'>{$shortUrl}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

echo "<div class='section'>";
echo "<h2>🔧 Next Steps</h2>";
echo "<ul>";
echo "<li><strong>Test with Facebook ads:</strong> Create a Facebook ad campaign and test clicks to ensure fbclid is captured</li>";
echo "<li><strong>Check Action Network setup:</strong> Ensure fbclid is preserved in Action Network form URLs</li>";
echo "<li><strong>Monitor logs:</strong> Check browser console logs for Facebook data collection issues</li>";
echo "<li><strong>Verify script timing:</strong> Ensure tracking script loads before users navigate away from landing pages</li>";
echo "</ul>";
echo "</div>";
?>