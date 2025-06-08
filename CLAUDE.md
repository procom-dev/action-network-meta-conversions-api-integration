# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based Action Network to Meta Conversions API integration system. It bridges Action Network form submissions with Meta's advertising platform by:

1. **Webhook Processing**: Receives Action Network webhooks and forwards data to Meta Conversions API
2. **Browser Tracking**: JavaScript tracker captures client-side data (cookies, browser info, fbclid)
3. **Secure Configuration**: Encrypted parameter passing to protect sensitive credentials
4. **Dual Data Sources**: Combines server-side webhook data with client-side browser data

## Architecture

### Core Components

- **webhook.php**: Main webhook endpoint - receives Action Network form submissions, processes and forwards to Meta API
- **api.php**: REST endpoint for JavaScript tracker to submit browser-side data
- **includes/crypto.php**: Encryption/decryption system using AES-256-CBC for secure credential storage
- **includes/functions.php**: Shared utilities for Meta API communication, logging, data hashing, phone processing
- **includes/helpers.php**: General utility functions for data processing and log management
- **tracker.js.php**: Dynamic JavaScript generator that creates form tracking code
- **setup.php**: Web-based configuration wizard for setting up integrations
- **config/settings.php**: Centralized configuration management

### Data Flow

1. User submits Action Network form
2. Action Network sends webhook to `webhook.php?id={encrypted_hash}`
3. Simultaneously, JavaScript tracker sends browser data to `api.php`
4. Both endpoints decrypt the hash to get Meta credentials
5. Data is formatted, hashed (SHA256 for PII), and sent to Meta Conversions API
6. Event deduplication using consistent event IDs

### Security Model

- Credentials (Pixel ID, Access Token) are AES-256-CBC encrypted with HMAC integrity verification
- All PII is SHA256 hashed before transmission to Meta
- Encrypted hashes include timestamps and expire after 1 year
- No credentials stored in plaintext - only in encrypted URL parameters

## Development Commands

### PHP Server (for testing)
```bash
php -S localhost:8000
```

### Testing and Verification
```bash
# Test Meta API credentials and connectivity
php tools/verify.php

# Generate new encrypted hash for webhook URL
php tools/generate_hash.php

# Check if webhook received test data
php tools/check_test.php

# Check if JavaScript tracker received test data
php tools/check_script_test.php

# Test webhook endpoint directly
curl -X POST "http://localhost:8000/webhook.php?id={encrypted_hash}" \
  -H "Content-Type: application/json" \
  -d @test_webhooks.json
```

### Log Management
```bash
# Monitor logs in real-time
tail -f logs/app.log
tail -f logs/error.log
tail -f logs/debug.log
tail -f logs/webhooks.log

# Clear all logs
rm logs/*.log

# View recent webhook activity
tail -50 logs/webhooks.log | grep "Meta API"
```

### Dashboard Access
```bash
# Access monitoring dashboard (requires password from config)
open http://localhost:8000/dashboard.php
```

## Key Configuration

### Security Keys (includes/crypto.php)
- `SECRET_KEY`: 32-byte hex key for AES encryption - **MUST BE CHANGED IN PRODUCTION**
- `HASH_SALT`: Random string for HMAC verification

### Meta API Settings (includes/functions.php)
- `META_API_VERSION`: Currently v23.0
- `META_API_BASE_URL`: Graph API endpoint

## Data Processing Patterns

### Phone Number Handling
Spanish mobile numbers (9 digits starting with 6/7) automatically prefixed with country code 34. All phones stripped of non-numeric chars and leading zeros.

### Event ID Generation
Consistent deduplication using SHA256 of `email + rounded_timestamp_to_60s`. Same algorithm in both PHP and JavaScript ensures webhook and browser events don't duplicate.

### Test Detection
Webhook automatically detects Action Network test submissions using known test email patterns and UUIDs to avoid sending test data to Meta. Test data is stored in `script_tests.json` and `test_webhooks.json` for verification.

## Integration URLs

- **Setup Wizard**: `/setup.php`
- **Webhook Endpoint**: `/webhook.php?id={encrypted_hash}`
- **JavaScript Tracker**: `/tracker.js?id={encrypted_hash}`
- **Browser API**: `/api.php`
- **Monitoring Dashboard**: `/dashboard.php` (password protected)
- **Dashboard API**: `/dashboard_api.php`
- **Credential Verification**: `/tools/verify.php`
- **Hash Generation**: `/tools/generate_hash.php`
- **Test Checkers**: `/tools/check_test.php`, `/tools/check_script_test.php`

## Directory Structure

The codebase is organized into logical directories:

- **`/`** - Root endpoints (webhook.php, api.php, setup.php, etc.)
- **`includes/`** - Core utilities (crypto.php, functions.php, helpers.php)
- **`config/`** - Configuration files (settings.php, settings.local.php)
- **`tools/`** - Utility scripts (verify.php, generate_hash.php, check_test.php)
- **`logs/`** - Application logs (app.log, error.log, debug.log, webhooks.log)
- **`backups/`** - Automatic backups with timestamps

## Configuration Management

### Primary Configuration
- **`config.php`** - Basic domain utilities and crypto inclusion
- **`config/settings.php`** - Centralized settings (encryption keys, dashboard password)
- **`config/settings.local.php`** - Local overrides (gitignored)

### Credential Storage
All sensitive credentials (Pixel ID, Access Token) are stored encrypted in URL parameters using AES-256-CBC. The system includes:
- Automatic credential expiration (1 year default)
- HMAC integrity verification
- No plaintext storage anywhere in the system

## Error Handling

All errors are logged to structured log files in `/logs/` directory. The system includes:
- Non-blocking 500ms webhook response requirement for Action Network
- Background processing after immediate webhook response
- Graceful fallbacks for missing data fields
- Safe error responses that don't expose internal details
- Centralized error handling via `includes/error_handler.php`

## Monitoring and Analytics

### Real-time Dashboard
- **`dashboard.php`** - Web-based monitoring interface with password protection
- **`dashboard_api.php`** - JSON API for dashboard data
- Displays success rates, match quality, recent events, and error patterns
- Auto-refreshes every 30 seconds with real-time data

### Debug Tools
- **`debug_facebook_data.php`** - Test Meta API connectivity and response handling
- **`debug_meta_payload.php`** - Inspect and validate payload formatting
- **Test data storage** - `script_tests.json` and `test_webhooks.json` track test submissions