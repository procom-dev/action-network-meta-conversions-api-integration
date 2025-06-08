<?php
/**
 * dashboard.php - Real-time Meta Conversions API Monitoring Dashboard
 * 
 * Provides real-time monitoring of API calls, success rates, match quality, and errors
 */

// Security check - simple password protection
session_start();
$dashboard_password = 'meta2024'; // Change this in production!

if (!isset($_SESSION['dashboard_auth']) || $_SESSION['dashboard_auth'] !== true) {
    if (isset($_POST['password']) && $_POST['password'] === $dashboard_password) {
        $_SESSION['dashboard_auth'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Show login form
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Meta API Dashboard - Login</title>
        <style>
            body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f5f5; }
            .login-form { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            input[type="password"] { width: 200px; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
            button { background: #1877f2; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
            button:hover { background: #166fe5; }
        </style>
    </head>
    <body>
        <form method="post" class="login-form">
            <h2>Meta API Dashboard</h2>
            <div>
                <input type="password" name="password" placeholder="Enter dashboard password" required>
                <br>
                <button type="submit">Login</button>
            </div>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Conversions API Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            color: #334155;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #1877f2, #42a5f5);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .header .status {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid #1877f2;
        }
        
        .stat-card.success {
            border-left-color: #10b981;
        }
        
        .stat-card.warning {
            border-left-color: #f59e0b;
        }
        
        .stat-card.error {
            border-left-color: #ef4444;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .stat-change {
            font-size: 0.75rem;
            margin-top: 0.5rem;
        }
        
        .stat-change.positive {
            color: #10b981;
        }
        
        .stat-change.negative {
            color: #ef4444;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .panel-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .panel-title {
            font-weight: 600;
            color: #1e293b;
        }
        
        .panel-content {
            padding: 1.5rem;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .call-item {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .call-item:hover {
            background: #f8fafc;
        }
        
        .call-item:last-child {
            border-bottom: none;
        }
        
        .call-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .call-event {
            font-weight: 600;
            color: #1e293b;
        }
        
        .call-status {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .call-status.success {
            background: #dcfce7;
            color: #166534;
        }
        
        .call-status.error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .call-meta {
            font-size: 0.875rem;
            color: #64748b;
            display: flex;
            gap: 1rem;
        }
        
        .call-details {
            margin-top: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 6px;
            display: none;
            font-size: 0.875rem;
        }
        
        .call-details.show {
            display: block;
        }
        
        .error-item {
            padding: 1rem;
            border-left: 4px solid #ef4444;
            background: #fef2f2;
            margin-bottom: 1rem;
            border-radius: 0 6px 6px 0;
        }
        
        .error-time {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        
        .error-message {
            color: #991b1b;
            font-weight: 500;
        }
        
        .refresh-btn {
            background: #1877f2;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: background-color 0.2s;
        }
        
        .refresh-btn:hover {
            background: #166fe5;
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .charts-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .field-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .field-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem;
            background: #f8fafc;
            border-radius: 4px;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            margin: 0.5rem 0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: #1877f2;
            transition: width 0.3s ease;
        }
        
        .logout-btn {
            background: transparent;
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            text-decoration: none;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-section {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Event Pairing Styles */
        .pairing-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .pairing-section {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 1rem;
            background: #f8fafc;
        }
        
        .pairing-section h4 {
            margin: 0 0 1rem 0;
            color: #1e293b;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .paired-event {
            background: white;
            border: 1px solid #dcfce7;
            border-left: 4px solid #10b981;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        
        .paired-event:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .unpaired-event {
            background: white;
            border: 1px solid #fef3c7;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        
        .unpaired-event:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .event-id {
            font-family: monospace;
            font-size: 0.75rem;
            color: #64748b;
            background: #f1f5f9;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            display: inline-block;
            margin-bottom: 0.5rem;
        }
        
        .event-sources {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .source-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .source-webhook {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .source-javascript {
            background: #fef3c7;
            color: #92400e;
        }
        
        .event-metrics {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.5rem;
        }
        
        .quality-score {
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-weight: 500;
        }
        
        .quality-high {
            background: #dcfce7;
            color: #166534;
        }
        
        .quality-medium {
            background: #fef3c7;
            color: #92400e;
        }
        
        .quality-low {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .pairing-status {
            font-size: 0.875rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        @media (max-width: 768px) {
            .pairing-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <h1>📊 Meta Conversions API Dashboard</h1>
        <div class="status">
            <div class="status-indicator"></div>
            <span>Live Monitoring</span>
            <span id="lastUpdate">Updated: Never</span>
            <a href="?logout=1" class="logout-btn">Logout</a>
        </div>
    </header>

    <div class="container">
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value" id="totalCalls">-</div>
                <div class="stat-label">Total API Calls</div>
                <div class="stat-change" id="callsChange">Last 24h: -</div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-value" id="successRate">-</div>
                <div class="stat-label">Success Rate</div>
                <div class="stat-change" id="successChange">Last 24h: -</div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-value" id="avgResponseTime">-</div>
                <div class="stat-label">Avg Response Time</div>
                <div class="stat-change" id="responseChange">Last hour: -</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value" id="matchQuality">-</div>
                <div class="stat-label">Avg Match Quality</div>
                <div class="stat-change" id="matchChange">Estimated from logs</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value" id="eventsReceived">-</div>
                <div class="stat-label">Events Received by Meta</div>
                <div class="stat-change" id="eventsChange">Confirmed deliveries</div>
            </div>
            
            <div class="stat-card error">
                <div class="stat-value" id="errorCount">-</div>
                <div class="stat-label">Errors (24h)</div>
                <div class="stat-change" id="errorChange">API errors</div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-value" id="pairingRate">-</div>
                <div class="stat-label">Event Pairing Rate</div>
                <div class="stat-change" id="pairingChange">Webhook + JavaScript</div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-value" id="unpairedEvents">-</div>
                <div class="stat-label">Unpaired Events</div>
                <div class="stat-change" id="unpairedChange">Single source only</div>
            </div>
        </div>

        <!-- Charts and Field Statistics -->
        <div class="charts-section">
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">📈 Event Types Distribution</h3>
                </div>
                <div class="panel-content">
                    <div id="eventTypesChart">
                        <div id="eventTypesList"></div>
                    </div>
                </div>
            </div>
            
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">📊 Call Sources</h3>
                </div>
                <div class="panel-content">
                    <div id="sourceBreakdown">
                        <div class="field-stats" id="sourceStatsList"></div>
                    </div>
                </div>
            </div>
            
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">📈 Meta API Insights</h3>
                </div>
                <div class="panel-content">
                    <div id="metaInsights">
                        <div class="field-stats" id="metaInsightsList"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Event Pairing Section -->
        <div class="panel" style="margin-bottom: 2rem;">
            <div class="panel-header">
                <h3 class="panel-title">🔗 Event Pairing Analysis</h3>
                <span id="pairingStatus" class="pairing-status">Loading...</span>
            </div>
            <div class="panel-content">
                <div class="pairing-grid">
                    <div class="pairing-section">
                        <h4>✅ Paired Events (Webhook + JavaScript)</h4>
                        <div id="pairedEventsList">Loading paired events...</div>
                    </div>
                    <div class="pairing-section">
                        <h4>⚠️ Unpaired Events (Single Source)</h4>
                        <div id="unpairedEventsList">Loading unpaired events...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Calls and Errors -->
        <div class="content-grid">
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">🔄 Recent API Calls</h3>
                    <button class="refresh-btn" onclick="refreshData()">Refresh</button>
                </div>
                <div class="panel-content" id="recentCalls">
                    <div style="text-align: center; color: #64748b; padding: 2rem;">
                        Loading recent calls...
                    </div>
                </div>
            </div>
            
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">❌ Recent Errors</h3>
                </div>
                <div class="panel-content" id="recentErrors">
                    <div style="text-align: center; color: #64748b; padding: 2rem;">
                        Loading errors...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let refreshInterval;
        let isLoading = false;

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            refreshData();
            startAutoRefresh();
        });

        function startAutoRefresh() {
            refreshInterval = setInterval(refreshData, 30000); // Refresh every 30 seconds
        }

        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        }

        async function refreshData() {
            if (isLoading) return;
            
            isLoading = true;
            document.body.classList.add('loading');
            
            try {
                const response = await fetch('dashboard_api.php');
                const data = await response.json();
                
                if (data.success) {
                    updateStats(data.stats);
                    updateRecentCalls(data.recentCalls);
                    updateRecentErrors(data.recentErrors);
                    updateEventTypes(data.eventTypes);
                    updateSourceStats(data.recentCalls);
                    updateMetaInsights(data.recentCalls, data.stats);
                    updatePairingData(data.pairedEvents, data.unpairedEvents, data.pairingStats);
                    
                    document.getElementById('lastUpdate').textContent = 
                        'Updated: ' + new Date().toLocaleTimeString();
                } else {
                    console.error('Dashboard API error:', data.error);
                }
            } catch (error) {
                console.error('Failed to fetch dashboard data:', error);
            } finally {
                isLoading = false;
                document.body.classList.remove('loading');
            }
        }

        function updateStats(stats) {
            document.getElementById('totalCalls').textContent = stats.totalCalls || '0';
            document.getElementById('successRate').textContent = stats.successRate || '0%';
            document.getElementById('avgResponseTime').textContent = stats.avgResponseTime || '0ms';
            document.getElementById('matchQuality').textContent = stats.matchQuality || 'Unknown';
            document.getElementById('eventsReceived').textContent = stats.eventsReceived || '0';
            document.getElementById('errorCount').textContent = stats.errorCount || '0';
            
            // Update pairing statistics
            if (document.getElementById('pairingRate')) {
                document.getElementById('pairingRate').textContent = stats.pairingRate || '0%';
            }
            if (document.getElementById('unpairedEvents')) {
                document.getElementById('unpairedEvents').textContent = stats.unpairedCount || '0';
            }
            
            // Update change indicators
            document.getElementById('callsChange').textContent = `Last 24h: ${stats.callsChange || '0'}`;
            document.getElementById('successChange').textContent = `Last 24h: ${stats.successChange || '0%'}`;
            document.getElementById('responseChange').textContent = `Last hour: ${stats.responseChange || '0ms'}`;
        }

        function updateRecentCalls(calls) {
            const container = document.getElementById('recentCalls');
            
            if (!calls || calls.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: #64748b; padding: 2rem;">No recent calls found</div>';
                return;
            }
            
            container.innerHTML = calls.map((call, index) => `
                <div class="call-item" onclick="toggleCallDetails(${index})">
                    <div class="call-header">
                        <span class="call-event">${call.eventType || 'Unknown'}</span>
                        <span class="call-status ${call.success ? 'success' : 'error'}">
                            ${call.success ? 'Success' : 'Error'}
                        </span>
                    </div>
                    <div class="call-meta">
                        <span>🕒 ${call.timestamp}</span>
                        <span>⚡ ${call.responseTime}ms</span>
                        <span>📊 ${call.eventsReceived || 0} events</span>
                        <span>📧 ${call.email || 'N/A'}</span>
                        <span>🔗 ${call.origin || 'Unknown'}</span>
                        ${call.pixelId ? `<span>🎯 Pixel: ***${call.pixelId.slice(-4)}</span>` : ''}
                    </div>
                    <div class="call-details" id="callDetails${index}">
                        <strong>Event Details:</strong><br>
                        Event ID: ${call.eventId || 'N/A'}<br>
                        Pixel ID: ${call.pixelId || 'N/A'}<br>
                        Origin: ${call.origin || 'N/A'}<br>
                        Email Status: ${call.email || 'N/A'}<br>
                        Form URL: ${call.formUrl ? `<a href="${call.formUrl}" target="_blank" style="color: #1877f2;">${call.formUrl.substring(0, 50)}...</a>` : 'N/A'}<br>
                        HTTP Code: ${call.httpCode || 'N/A'}<br>
                        ${call.error ? `<br><strong style="color: #ef4444;">Error:</strong> ${call.error}` : ''}
                        ${call.userFields ? `<br><strong>User Fields:</strong> ${call.userFields.join(', ')}` : ''}
                        ${call.hasFacebookData ? '<br><strong>Facebook Data:</strong> ✅ Present' : '<br><strong>Facebook Data:</strong> ❌ Missing'}
                    </div>
                </div>
            `).join('');
        }

        function updateRecentErrors(errors) {
            const container = document.getElementById('recentErrors');
            
            if (!errors || errors.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: #64748b; padding: 2rem;">No recent errors 🎉</div>';
                return;
            }
            
            container.innerHTML = errors.map(error => `
                <div class="error-item">
                    <div class="error-time">${error.timestamp}</div>
                    <div class="error-message">${error.message}</div>
                    ${error.context ? `<div style="margin-top: 0.5rem; font-size: 0.8rem; color: #64748b;">${error.context}</div>` : ''}
                </div>
            `).join('');
        }

        function updateEventTypes(eventTypes) {
            const container = document.getElementById('eventTypesList');
            
            if (!eventTypes || Object.keys(eventTypes).length === 0) {
                container.innerHTML = '<div style="text-align: center; color: #64748b;">No event data available</div>';
                return;
            }
            
            const total = Object.values(eventTypes).reduce((sum, count) => sum + count, 0);
            
            container.innerHTML = Object.entries(eventTypes)
                .sort(([,a], [,b]) => b - a)
                .map(([eventType, count]) => {
                    const percentage = total > 0 ? Math.round((count / total) * 100) : 0;
                    return `
                        <div class="field-item">
                            <span>${eventType}</span>
                            <span>${count} (${percentage}%)</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${percentage}%"></div>
                        </div>
                    `;
                }).join('');
        }

        function updateSourceStats(recentCalls) {
            const container = document.getElementById('sourceStatsList');
            
            if (!recentCalls || recentCalls.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: #64748b;">No call data available</div>';
                return;
            }
            
            // Count calls by source
            const sourceCounts = {
                'webhook': 0,
                'javascript': 0,
                'unknown': 0
            };
            
            recentCalls.forEach(call => {
                const origin = call.origin || 'unknown';
                if (origin === 'webhook') {
                    sourceCounts.webhook++;
                } else if (origin === 'javascript') {
                    sourceCounts.javascript++;
                } else {
                    sourceCounts.unknown++;
                }
            });
            
            const total = Object.values(sourceCounts).reduce((sum, count) => sum + count, 0);
            
            // Calculate pairing stats
            const webhookCalls = sourceCounts.webhook;
            const javascriptCalls = sourceCounts.javascript;
            const potentialPairs = Math.min(webhookCalls, javascriptCalls);
            const pairingRate = total > 0 ? Math.round((potentialPairs * 2 / total) * 100) : 0;
            
            container.innerHTML = `
                <div class="field-item">
                    <span>🌐 Webhook Calls</span>
                    <span>${webhookCalls} (${total > 0 ? Math.round((webhookCalls / total) * 100) : 0}%)</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${total > 0 ? (webhookCalls / total) * 100 : 0}%; background: #059669;"></div>
                </div>
                <div class="field-item">
                    <span>📱 JavaScript Calls</span>
                    <span>${javascriptCalls} (${total > 0 ? Math.round((javascriptCalls / total) * 100) : 0}%)</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${total > 0 ? (javascriptCalls / total) * 100 : 0}%; background: #3b82f6;"></div>
                </div>
                ${sourceCounts.unknown > 0 ? `
                <div class="field-item">
                    <span>❓ Unknown Source</span>
                    <span>${sourceCounts.unknown} (${Math.round((sourceCounts.unknown / total) * 100)}%)</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${(sourceCounts.unknown / total) * 100}%; background: #ef4444;"></div>
                </div>
                ` : ''}
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                    <div class="field-item" style="font-weight: 600;">
                        <span>🔗 Potential Pairing</span>
                        <span>${pairingRate}%</span>
                    </div>
                </div>
            `;
        }

        function updateMetaInsights(recentCalls, stats) {
            const container = document.getElementById('metaInsightsList');
            
            if (!recentCalls || recentCalls.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: #64748b;">No Meta API data available</div>';
                return;
            }
            
            // Analyze Meta API responses
            let totalEventsReceived = 0;
            let callsWithEventsReceived = 0;
            let totalResponseTime = 0;
            let responseTimeCount = 0;
            let facebookDataPresent = 0;
            
            recentCalls.forEach(call => {
                if (call.eventsReceived && call.eventsReceived > 0) {
                    totalEventsReceived += call.eventsReceived;
                    callsWithEventsReceived++;
                }
                
                if (call.responseTime && call.responseTime > 0) {
                    totalResponseTime += call.responseTime;
                    responseTimeCount++;
                }
                
                if (call.hasFacebookData) {
                    facebookDataPresent++;
                }
            });
            
            const avgResponseTime = responseTimeCount > 0 ? Math.round(totalResponseTime / responseTimeCount) : 0;
            const avgEventsReceived = callsWithEventsReceived > 0 ? (totalEventsReceived / callsWithEventsReceived).toFixed(1) : '0';
            const facebookDataRate = recentCalls.length > 0 ? Math.round((facebookDataPresent / recentCalls.length) * 100) : 0;
            
            container.innerHTML = `
                <div class="field-item">
                    <span>📊 Events Accepted by Meta</span>
                    <span>${totalEventsReceived}</span>
                </div>
                <div class="field-item">
                    <span>⚡ Avg Response Time</span>
                    <span>${avgResponseTime}ms</span>
                </div>
                <div class="field-item">
                    <span>🎯 Avg Events per Call</span>
                    <span>${avgEventsReceived}</span>
                </div>
                <div class="field-item">
                    <span>🔍 Facebook Data Rate</span>
                    <span>${facebookDataRate}%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${facebookDataRate}%; background: #1877f2;"></div>
                </div>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                    <div class="field-item" style="font-weight: 600;">
                        <span>✅ Success Rate</span>
                        <span>${stats.successRate || '0%'}</span>
                    </div>
                </div>
            `;
        }

        function updatePairingData(pairedEvents, unpairedEvents, pairingStats) {
            updatePairingStatus(pairingStats);
            updatePairedEventsList(pairedEvents);
            updateUnpairedEventsList(unpairedEvents);
        }

        function updatePairingStatus(stats) {
            const statusElement = document.getElementById('pairingStatus');
            if (statusElement) {
                statusElement.textContent = `${stats.pairedCount} paired, ${stats.unpairedCount} unpaired (${stats.pairingRate} success)`;
            }
        }

        function updatePairedEventsList(pairedEvents) {
            const container = document.getElementById('pairedEventsList');
            
            if (!pairedEvents || pairedEvents.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: #64748b; padding: 1rem;">No paired events found</div>';
                return;
            }
            
            container.innerHTML = pairedEvents.map(pair => {
                const qualityClass = pair.dataComparison.qualityScore >= 80 ? 'quality-high' : 
                                   pair.dataComparison.qualityScore >= 50 ? 'quality-medium' : 'quality-low';
                
                return `
                    <div class="paired-event">
                        <div class="event-id">ID: ***${pair.eventId.slice(-8)}</div>
                        <div class="event-sources">
                            <span class="source-badge source-webhook">📡 Webhook</span>
                            <span class="source-badge source-javascript">🟡 JavaScript</span>
                        </div>
                        <div class="event-metrics">
                            <span>⏱️ Δ ${pair.timeDifference}</span>
                            <span>📧 ${pair.dataComparison.emailMatch}</span>
                            <span class="quality-score ${qualityClass}">Quality: ${pair.dataComparison.qualityScore}%</span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function updateUnpairedEventsList(unpairedEvents) {
            const container = document.getElementById('unpairedEventsList');
            
            if (!unpairedEvents || unpairedEvents.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: #64748b; padding: 1rem;">No unpaired events found</div>';
                return;
            }
            
            container.innerHTML = unpairedEvents.slice(0, 10).map(event => {
                const sourceIcon = event.origin === 'webhook' ? '📡' : '🟡';
                const sourceText = event.origin === 'webhook' ? 'Webhook Only' : 'JavaScript Only';
                
                return `
                    <div class="unpaired-event">
                        <div class="event-id">ID: ***${(event.eventId || 'unknown').slice(-8)}</div>
                        <div class="event-sources">
                            <span class="source-badge source-${event.origin}">${sourceIcon} ${sourceText}</span>
                        </div>
                        <div class="event-metrics">
                            <span>🕒 ${event.timestamp}</span>
                            <span>📧 ${event.email || 'N/A'}</span>
                            <span>✅ ${event.success ? 'Success' : 'Failed'}</span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function toggleCallDetails(index) {
            const details = document.getElementById(`callDetails${index}`);
            details.classList.toggle('show');
        }

        // Handle page visibility change to pause/resume auto-refresh
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });
    </script>
</body>
</html>