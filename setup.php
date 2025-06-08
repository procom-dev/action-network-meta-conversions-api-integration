<?php
/**
 * Setup.php - Configuration wizard for Meta Conversions API integration
 * 
 * Guides users through setting up their Action Network to Meta connection
 */

session_start();

// Include required files
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/helpers.php';

// Initialize crypto system
initCrypto();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Conversions API - Action Network Integration Setup</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='14' fill='%231877f2'/><path d='M21.7 12.7h-2.8v-1.8c0-.7.5-.9.8-.9h2v-3.2h-2.8c-3.1 0-3.8 2.3-3.8 3.8v2.1h-1.8v3.3h1.8v9.3h3.8V16h2.5l.3-3.3z' fill='white'/></svg>" type="image/svg+xml">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            color: #1c1e21;
            line-height: 1.5;
        }

        .container {
            max-width: 720px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, .1);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .header {
            background: linear-gradient(135deg, #1877f2 0%, #0a4eb3 100%);
            color: white;
            padding: 32px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .header .subtitle {
            font-size: 16px;
            opacity: 0.9;
        }

        .logo {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #1877f2;
        }

        .progress {
            height: 4px;
            background: #e4e6eb;
            position: relative;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: #1877f2;
            width: 20%;
            transition: width 0.3s ease;
        }

        .content {
            padding: 32px;
        }

        .step {
            display: none;
        }

        .step.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #1c1e21;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .step-number {
            width: 32px;
            height: 32px;
            background: #e4e6eb;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 600;
            color: #65676b;
        }

        .step-number.active {
            background: #1877f2;
            color: white;
        }

        .step-description {
            margin-bottom: 24px;
            color: #65676b;
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1c1e21;
            font-size: 15px;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #dddfe2;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.2s;
            background: white;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #1877f2;
            box-shadow: 0 0 0 3px rgba(24, 119, 242, .1);
        }

        .form-hint {
            margin-top: 8px;
            font-size: 13px;
            color: #65676b;
        }

        .button {
            background: #1877f2;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 120px;
            justify-content: center;
        }

        .button:hover:not(:disabled) {
            background: #166fe5;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(24, 119, 242, .25);
        }

        .button:disabled {
            background: #e4e6eb;
            color: #bcc0c4;
            cursor: not-allowed;
            transform: none;
        }

        .button-secondary {
            background: white;
            color: #1877f2;
            border: 1px solid #1877f2;
        }

        .button-secondary:hover:not(:disabled) {
            background: #f8f9fa;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 32px;
            justify-content: space-between;
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
        }

        .alert-success {
            background: #e3f2e6;
            color: #0a7c1a;
            border: 1px solid #b8dfbf;
        }

        .alert-error {
            background: #ffebe9;
            color: #c82333;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background: #e3f2fd;
            color: #0c5eb8;
            border: 1px solid #b8daff;
        }

        .code-block {
            background: #f8f9fa;
            border: 1px solid #e4e6eb;
            border-radius: 8px;
            padding: 20px;
            font-family: 'SF Mono', Monaco, Consolas, monospace;
            font-size: 14px;
            word-break: break-all;
            position: relative;
            margin: 20px 0;
        }

        .copy-button {
            position: absolute;
            top: 12px;
            right: 12px;
            background: white;
            color: #1877f2;
            border: 1px solid #1877f2;
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .copy-button:hover {
            background: #1877f2;
            color: white;
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #e4e6eb;
            border-top: 2px solid #1877f2;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            vertical-align: middle;
            margin-left: 8px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .pixel-info {
            background: #e3f2e6;
            border: 1px solid #b8dfbf;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            text-align: center;
        }

        .pixel-info h3 {
            color: #0a7c1a;
            margin-bottom: 4px;
            font-size: 18px;
        }

        .pixel-info .pixel-id {
            color: #65676b;
            font-size: 14px;
        }

        .test-status {
            padding: 24px;
            background: #f8f9fa;
            border: 1px solid #e4e6eb;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }

        .test-waiting {
            color: #65676b;
        }

        .test-success {
            background: #e3f2e6;
            border-color: #b8dfbf;
            color: #0a7c1a;
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        .screenshot {
            width: 100%;
            margin: 20px 0;
            border: 1px solid #e4e6eb;
            border-radius: 8px;
            overflow: hidden;
        }

        .screenshot img {
            width: 100%;
            height: auto;
            display: block;
        }

        .help-text {
            background: #f0f2f5;
            border-radius: 8px;
            padding: 16px;
            margin: 16px 0;
            font-size: 14px;
            color: #65676b;
        }

        .help-text strong {
            color: #1c1e21;
        }

        .final-summary {
            background: #f0f2f5;
            border-radius: 8px;
            padding: 24px;
            margin: 24px 0;
        }

        .final-summary h4 {
            margin-bottom: 16px;
            color: #1c1e21;
        }

        .final-summary ul {
            list-style: none;
            padding: 0;
        }

        .final-summary li {
            padding: 8px 0;
            padding-left: 28px;
            position: relative;
            color: #65676b;
        }

        .final-summary li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #42b883;
            font-weight: bold;
        }

        .divider {
            height: 1px;
            background: #e4e6eb;
            margin: 32px 0;
        }

        .select-wrapper {
            position: relative;
        }

        .select-wrapper:after {
            content: "▼";
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #65676b;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>
                    <span class="logo">M</span>
                    Action Network → Meta Conversions API
                </h1>
                <div class="subtitle">Connect your forms to Meta advertising platform</div>
            </div>
            
            <div class="progress">
                <div class="progress-bar" id="progressBar"></div>
            </div>

            <div class="content">
                <!-- Step 1: Credentials -->
                <div class="step active" id="step1">
                    <h2 class="step-title">
                        <span class="step-number active">1</span>
                        Enter Your Meta Credentials
                    </h2>
                    
                    <div class="step-description">
                        We need your Meta Pixel ID and Conversions API Access Token to connect your Action Network forms.
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="pixelId">Meta Pixel ID</label>
                        <input type="text" id="pixelId" class="form-input" placeholder="e.g., 123456789012345" maxlength="16">
                        <div class="form-hint">
                            Find this in Meta Events Manager → Data Sources → Your Pixel → Settings
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="accessToken">Conversions API Access Token</label>
                        <input type="password" id="accessToken" class="form-input" placeholder="EAAxxxxxxxxxx..." autocomplete="off">
                        <div class="help-text">
                            <strong>How to generate a proper Access Token:</strong>
                            <ol style="margin-left: 20px; margin-top: 8px;">
                                <li>Go to <strong>Meta Events Manager</strong></li>
                                <li>Select your Pixel from <strong>Data Sources</strong></li>
                                <li>Click <strong>Settings</strong> → <strong>Conversions API</strong></li>
                                <li>Click <strong>Generate Access Token</strong></li>
                                <li>Copy the complete token (starts with EAA)</li>
                            </ol>
                            <br>
                            <strong>⚠️ Important:</strong> Make sure you have <strong>Admin</strong> access to the Business Manager that owns this Pixel.
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="alert alert-info">
                            <strong>🎯 Automatic Event Detection</strong><br>
                            Event types are now automatically detected:
                            <ul style="margin: 8px 0 0 20px;">
                                <li><strong>Donate</strong> events for Action Network donations</li>
                                <li><strong>CompleteRegistration</strong> events for all other forms (petitions, signups, etc.)</li>
                            </ul>
                            No manual selection needed!
                        </div>
                    </div>

                    <div id="verificationResult"></div>

                    <div class="button-group">
                        <div></div>
                        <button class="button" id="verifyButton" onclick="verifyCredentials()">
                            Verify & Continue
                        </button>
                    </div>
                </div>

                <!-- Step 2: Configure & Test Webhook -->
                <div class="step" id="step2">
                    <h2 class="step-title">
                        <span class="step-number">2</span>
                        Configure & Test Webhook
                    </h2>

                    <div class="pixel-info" id="pixelInfo">
                        <h3 id="pixelName">Loading...</h3>
                        <div class="pixel-id">Pixel ID: <span id="pixelIdDisplay"></span></div>
                    </div>

                    <div class="step-description">
                        Copy this webhook URL, add it to your Action Network account, and test it.
                    </div>

                    <div class="code-block">
                        <button class="copy-button" onclick="copyToClipboard('webhookUrl')">Copy</button>
                        <code id="webhookUrl">Generating...</code>
                    </div>

                    <div class="help-text">
                        <strong>How to add and test this webhook:</strong>
                        <ol style="margin-left: 20px; margin-top: 8px;">
                            <li>Log in to your Action Network account</li>
                            <li>Go to <strong>Start Organizing → Details</strong></li>
                            <li>Click <strong>API & Sync → Webhooks</strong></li>
                            <li>Click <strong>Add Webhook</strong></li>
                            <li>Paste the URL above</li>
                            <li>Select <strong>All Actions</strong> for events</li>
                            <li>Set status to <strong>Active</strong></li>
                            <li>Click <strong>Add Webhook</strong></li>
                            <li><strong>Click "Send Test"</strong> to verify it's working</li>
                        </ol>
                    </div>

                    <div class="test-status pulse" id="testStatus">
                        <div class="test-waiting">
                            <strong>Waiting for test webhook...</strong><br>
                            After adding the webhook, click "Send Test" in Action Network
                            <span class="loading"></span>
                        </div>
                    </div>

                    <div class="button-group">
                        <button class="button button-secondary" onclick="previousStep()">
                            ← Back
                        </button>
                        <button class="button" id="skipTestButton" onclick="skipTest()" style="display: none;">
                            Skip Test
                        </button>
                    </div>
                </div>

                <!-- Step 3: Add fbclid Field -->
                <div class="step" id="step3">
                    <h2 class="step-title">
                        <span class="step-number">3</span>
                        Add Facebook Click ID Field
                    </h2>

                    <div class="step-description">
                        Add a hidden field to capture Facebook Click IDs (fbclid) for better attribution tracking.
                    </div>

                    <div class="alert alert-info">
                        <span class="alert-icon">🎯</span>
                        <div>
                            <strong>What is fbclid?</strong><br>
                            Facebook Click ID is a parameter added to URLs when someone clicks on your Facebook ads. Capturing this helps Meta connect form submissions back to the original ad click, improving attribution and campaign optimization.
                        </div>
                    </div>

                    <div class="help-text">
                        <strong>How to add the fbclid field to your Action Network forms:</strong>
                        <ol style="margin-left: 20px; margin-top: 8px;">
                            <li>In Action Network, edit your form</li>
                            <li>Go to <strong>Settings</strong> → <strong>Custom Fields</strong></li>
                            <li>Click <strong>Add Field</strong></li>
                            <li>Set type to <strong>Custom HTML</strong></li>
                            <li>Set administrative title to <strong>fbclid</strong></li>
                            <li>Paste the HTML code below</li>
                            <li>Save and add this field to ALL your forms</li>
                        </ol>
                    </div>

                    <div class="code-block">
                        <button class="copy-button" onclick="copyToClipboard('fbclidCode')">Copy</button>
                        <code id="fbclidCode">&lt;input type="hidden" name="fbclid" id="fbclid"&gt;</code>
                    </div>

                    <div class="alert alert-info">
                        <span class="alert-icon">✨</span>
                        <div>
                            <strong>Important:</strong> This field is completely invisible to users and won't affect their experience. However, it will automatically capture Facebook Click IDs when people come from Facebook ads, giving you much better attribution data.
                        </div>
                    </div>

                    <div class="button-group">
                        <button class="button button-secondary" onclick="previousStep()">
                            ← Back
                        </button>
                        <button class="button" onclick="nextStep()">
                            Continue
                        </button>
                    </div>
                </div>

                <!-- Step 4: Tracking Script -->
                <div class="step" id="step4">
                    <h2 class="step-title">
                        <span class="step-number">4</span>
                        Add Browser Tracking Script
                    </h2>

                    <div class="step-description">
                        For complete tracking including browser data and Facebook Click IDs, add this script to your Action Network forms.
                    </div>

                    <div class="alert alert-info">
                        <span class="alert-icon">💡</span>
                        <div>
                            <strong>What this script does:</strong><br>
                            • <strong>PageView events:</strong> Sent immediately when someone visits your form<br>
                            • <strong>Form submission events:</strong> Sent when someone actually submits the form (auto-detected type)<br>
                            • Captures Facebook Click ID (fbclid) for better attribution<br>
                            • Records actual visitor IP and browser information<br>
                            • Automatically deduplicates with webhook data
                        </div>
                    </div>

                    <div class="code-block">
                        <button class="copy-button" onclick="copyToClipboard('trackingScript')">Copy</button>
                        <code id="trackingScript">Generating...</code>
                    </div>

                    <div class="help-text">
                        <strong>Where to add this script:</strong>
                        <ol style="margin-left: 20px; margin-top: 8px;">
                            <li>Edit your Action Network form page</li>
                            <li>Find the <strong>Custom HTML</strong> or <strong>Thank You Page</strong> settings</li>
                            <li>Paste the script tag</li>
                            <li>Save and publish</li>
                        </ol>
                    </div>

                    <div class="button-group">
                        <button class="button button-secondary" onclick="previousStep()">
                            ← Back
                        </button>
                        <button class="button" onclick="nextStep()">
                            Continue to Test
                        </button>
                    </div>
                </div>

                <!-- Step 5: Test Script -->
                <div class="step" id="step5">
                    <h2 class="step-title">
                        <span class="step-number">5</span>
                        Test Browser Tracking
                    </h2>

                    <div class="step-description">
                        Now test that the browser tracking script is working by submitting your Action Network form.
                    </div>

                    <div class="help-text">
                        <strong>How to test the script:</strong>
                        <ol style="margin-left: 20px; margin-top: 8px;">
                            <li>Open your Action Network form in a new tab</li>
                            <li>Open Chrome DevTools (F12) and go to the Console tab</li>
                            <li><strong>Submit the form with this exact email:</strong> <code style="background: #f1f3f4; padding: 2px 6px; border-radius: 4px; color: #d73a49; font-weight: bold;">test@test.com</code></li>
                            <li>Fill out other required fields with any test data</li>
                            <li>Look for tracking messages in the console during submission</li>
                            <li>Come back here to check if the data was received</li>
                        </ol>
                    </div>

                    <div class="alert alert-info">
                        <span class="alert-icon">⚠️</span>
                        <div>
                            <strong>Important:</strong> Use exactly <code style="background: #f1f3f4; padding: 2px 6px; border-radius: 4px; color: #d73a49; font-weight: bold;">test@test.com</code> as the email address.<br>
                            This helps us distinguish your test from real form submissions.
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <span class="alert-icon">🔍</span>
                        <div>
                            <strong>What to look for in Console:</strong><br>
                            • "[Meta Tracker] Action Network page detected - sending PageView event" (immediately)<br>
                            • "[Meta Tracker] Detected POST to /answers endpoint!" (when form is submitted)<br>
                            • "[Meta Tracker] Form submission detected!" (when form is submitted)<br>
                            • "[Meta Tracker] Data sent successfully" (for both events)<br>
                            • Full payload details with form data, browser info, and Facebook data<br><br>
                            <strong>You should see TWO events:</strong> PageView (immediate) + Form submission (auto-detected type)
                        </div>
                    </div>

                    <div class="test-status pulse" id="scriptTestStatus">
                        <div class="test-waiting">
                            <strong>Waiting for test submission...</strong><br>
                            Submit your Action Network form with email <strong>test@test.com</strong>
                            <span class="loading"></span>
                        </div>
                    </div>

                    <div class="button-group">
                        <button class="button button-secondary" onclick="previousStep()">
                            ← Back
                        </button>
                        <button class="button" id="skipScriptTestButton" onclick="skipScriptTest()" style="display: none;">
                            Skip Test
                        </button>
                    </div>
                </div>

                <!-- Step 6: Complete -->
                <div class="step" id="step6">
                    <h2 class="step-title">
                        <span class="step-number active">✓</span>
                        Setup Complete!
                    </h2>

                    <div class="alert alert-success">
                        <span class="alert-icon">🎉</span>
                        <div>
                            <strong>Congratulations!</strong><br>
                            Your Action Network forms are now connected to Meta Conversions API.
                        </div>
                    </div>

                    <div class="final-summary">
                        <h4>What's been configured:</h4>
                        <ul>
                            <li>Webhook integration for server-side tracking</li>
                            <li>Hidden fbclid field for Facebook Click ID attribution</li>
                            <li>Browser tracking script for client-side data collection</li>
                            <li>Form submissions will be sent with auto-detected event types (Donate for donations, CompleteRegistration for others)</li>
                            <li>Data is securely encrypted and transmitted</li>
                            <li>Automatic deduplication prevents double-counting</li>
                        </ul>
                    </div>

                    <div class="final-summary">
                        <h4>Next steps:</h4>
                        <ul>
                            <li>Submit a test form to verify everything works</li>
                            <li>Check Meta Events Manager in 5-10 minutes</li>
                            <li>Create Custom Audiences based on form submissions</li>
                            <li>Use events for campaign optimization</li>
                        </ul>
                    </div>

                    <div class="button-group" style="justify-content: center;">
                        <button class="button" onclick="setupAnother()">
                            Setup Another Integration
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 1;
        let pixelId = '';
        let accessToken = '';
        // Event type is now auto-detected (no user input needed)
        let webhookUrl = '';
        let trackingScript = '';
        let encryptedHash = '';
        let testPollInterval = null;
        let scriptTestPollInterval = null;
        let testCheckCount = 0;
        let scriptTestCheckCount = 0;

        function updateProgress() {
            const progress = (currentStep / 6) * 100;
            document.getElementById('progressBar').style.width = progress + '%';
        }

        function showStep(step) {
            document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
            document.getElementById('step' + step).classList.add('active');
            
            document.querySelectorAll('.step-number').forEach((num, index) => {
                if (index + 1 <= step) {
                    num.classList.add('active');
                } else {
                    num.classList.remove('active');
                }
            });
            
            currentStep = step;
            updateProgress();
            
            if (step === 2) {
                startTestPolling();
            } else if (testPollInterval) {
                clearInterval(testPollInterval);
            }
            
            if (step === 5) {
                startScriptTestPolling();
            } else if (scriptTestPollInterval) {
                clearInterval(scriptTestPollInterval);
            }
        }

        async function verifyCredentials() {
            const button = document.getElementById('verifyButton');
            const resultDiv = document.getElementById('verificationResult');
            
            // Get values
            pixelId = document.getElementById('pixelId').value.trim();
            accessToken = document.getElementById('accessToken').value.trim();
            // Event type no longer needed from user input
            
            // Basic validation
            if (!pixelId || !accessToken) {
                resultDiv.innerHTML = '<div class="alert alert-error"><span class="alert-icon">⚠️</span>Please enter both Pixel ID and Access Token</div>';
                return;
            }
            
            // Show loading state
            button.disabled = true;
            button.innerHTML = 'Verifying<span class="loading"></span>';
            resultDiv.innerHTML = '';
            
            try {
                const response = await fetch('tools/verify.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        pixelId: pixelId,
                        accessToken: accessToken
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Generate encrypted hash
                    const hashResponse = await fetch('tools/generate_hash.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            pixelId: pixelId,
                            accessToken: accessToken
                        })
                    });
                    
                    const hashResult = await hashResponse.json();
                    if (hashResult.success) {
                        encryptedHash = hashResult.hash;
                        
                        // Generate URLs with cache busting
                        const baseUrl = window.location.protocol + '//' + window.location.hostname;
                        const version = Date.now(); // Cache busting parameter
                        webhookUrl = baseUrl + '/webhook?id=' + encryptedHash;
                        trackingScript = '<script src="' + baseUrl + '/tracker.js?id=' + encryptedHash + '&v=' + version + '"></' + 'script>';
                        
                        // Update UI
                        document.getElementById('pixelName').textContent = result.pixel_name || 'Meta Pixel';
                        document.getElementById('pixelIdDisplay').textContent = pixelId;
                        document.getElementById('webhookUrl').textContent = webhookUrl;
                        document.getElementById('trackingScript').textContent = trackingScript;
                        // Event type text elements removed (auto-detection)
                        
                        // Move to next step
                        showStep(2);
                    } else {
                        resultDiv.innerHTML = '<div class="alert alert-error"><span class="alert-icon">⚠️</span>Error generating secure URLs</div>';
                    }
                } else {
                    resultDiv.innerHTML = '<div class="alert alert-error"><span class="alert-icon">⚠️</span>' + (result.message || 'Verification failed') + '</div>';
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="alert alert-error"><span class="alert-icon">⚠️</span>Error connecting to server. Please try again.</div>';
            }
            
            button.disabled = false;
            button.innerHTML = 'Verify & Continue';
        }

        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            
            navigator.clipboard.writeText(text).then(() => {
                const button = event.target;
                const originalText = button.textContent;
                button.textContent = 'Copied!';
                setTimeout(() => {
                    button.textContent = originalText;
                }, 2000);
            }).catch(() => {
                // Fallback
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                
                const button = event.target;
                const originalText = button.textContent;
                button.textContent = 'Copied!';
                setTimeout(() => {
                    button.textContent = originalText;
                }, 2000);
            });
        }

        function startTestPolling() {
            testCheckCount = 0;
            
            // Check more frequently initially (every 1 second for first 30 seconds, then every 2 seconds)
            const checkTest = async () => {
                testCheckCount++;
                
                try {
                    const response = await fetch('tools/check_test.php?pixel_id=' + encodeURIComponent(pixelId) + '&access_token=' + encodeURIComponent(accessToken));
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    
                    const result = await response.json();
                    console.log('Test check result:', result);
                    
                    if (result.test_received) {
                        clearInterval(testPollInterval);
                        
                        const testStatus = document.getElementById('testStatus');
                        testStatus.className = 'test-status test-success';
                        testStatus.innerHTML = '<strong>✅ Test webhook received!</strong><br>Your webhook is working correctly.' + 
                            (result.time_ago ? '<br><small>Received ' + result.time_ago + '</small>' : '');
                        
                        setTimeout(() => {
                            showStep(3);
                        }, 2000);
                        return;
                    }
                } catch (error) {
                    console.error('Test check error:', error);
                    
                    // Show error after multiple failures
                    if (testCheckCount > 5) {
                        const testStatus = document.getElementById('testStatus');
                        testStatus.innerHTML = '<div class="test-waiting"><strong>Waiting for test webhook...</strong><br>' +
                            'Click "Send Test" in Action Network<br>' +
                            '<small style="color: #e74c3c;">Connection issue detected - check console</small>' +
                            '<span class="loading"></span></div>';
                    }
                }
                
                // After 20 seconds, show skip button
                if (testCheckCount > 20) {
                    document.getElementById('skipTestButton').style.display = 'inline-flex';
                }
                
                // After 90 seconds, stop polling
                if (testCheckCount > 90) {
                    clearInterval(testPollInterval);
                    const testStatus = document.getElementById('testStatus');
                    testStatus.className = 'test-status';
                    testStatus.innerHTML = '<div class="alert alert-error"><span class="alert-icon">⚠️</span><div><strong>No test received</strong><br>Make sure you clicked "Send Test" in Action Network.<br><small>Webhook URL: ' + webhookUrl + '</small></div></div>';
                }
            };
            
            // Start checking immediately
            checkTest();
            
            // Set up interval - check every 1 second
            testPollInterval = setInterval(checkTest, 1000);
        }

        function skipTest() {
            if (testPollInterval) {
                clearInterval(testPollInterval);
            }
            showStep(3);
        }

        function nextStep() {
            if (currentStep < 6) {
                showStep(currentStep + 1);
            }
        }

        function previousStep() {
            if (currentStep > 1) {
                showStep(currentStep - 1);
            }
        }

        function startScriptTestPolling() {
            scriptTestCheckCount = 0;
            
            const checkScriptTest = async () => {
                scriptTestCheckCount++;
                
                try {
                    const response = await fetch('tools/check_script_test.php?pixel_id=' + encodeURIComponent(pixelId) + '&access_token=' + encodeURIComponent(accessToken));
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    
                    const result = await response.json();
                    console.log('Script test check result:', result);
                    
                    if (result.test_received) {
                        clearInterval(scriptTestPollInterval);
                        
                        const testStatus = document.getElementById('scriptTestStatus');
                        testStatus.className = 'test-status test-success';
                        testStatus.innerHTML = '<strong>✅ Test form submission received!</strong><br>Browser tracking is working correctly with test@test.com.' + 
                            (result.time_ago ? '<br><small>Received ' + result.time_ago + '</small>' : '') +
                            '<br><button class="button" onclick="showStep(6)" style="margin-top: 15px;">Continue to Complete Setup →</button>';
                        
                        // Remove automatic redirect - let user manually continue
                        return;
                    }
                } catch (error) {
                    console.error('Script test check error:', error);
                    
                    if (scriptTestCheckCount > 5) {
                        const testStatus = document.getElementById('scriptTestStatus');
                        testStatus.innerHTML = '<div class="test-waiting"><strong>Waiting for form submission...</strong><br>' +
                            'Submit your Action Network form to test the script<br>' +
                            '<small style="color: #e74c3c;">Connection issue detected - check console</small>' +
                            '<span class="loading"></span></div>';
                    }
                }
                
                // After 30 seconds, show skip button
                if (scriptTestCheckCount > 30) {
                    document.getElementById('skipScriptTestButton').style.display = 'inline-flex';
                }
                
                // After 120 seconds, stop polling
                if (scriptTestCheckCount > 120) {
                    clearInterval(scriptTestPollInterval);
                    const testStatus = document.getElementById('scriptTestStatus');
                    testStatus.className = 'test-status';
                    testStatus.innerHTML = '<div class="alert alert-error"><span class="alert-icon">⚠️</span><div><strong>No form submission detected</strong><br>Make sure you added the script and submitted the form.<br><small>Script: ' + trackingScript + '</small></div></div>';
                }
            };
            
            checkScriptTest();
            scriptTestPollInterval = setInterval(checkScriptTest, 1000);
        }

        function skipScriptTest() {
            if (scriptTestPollInterval) {
                clearInterval(scriptTestPollInterval);
            }
            showStep(6);
        }

        function completeSetup() {
            showStep(6);
        }

        function setupAnother() {
            window.location.reload();
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateProgress();
            
            // Allow Enter key to submit on step 1
            document.getElementById('accessToken').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    verifyCredentials();
                }
            });
        });
    </script>
</body>
</html>