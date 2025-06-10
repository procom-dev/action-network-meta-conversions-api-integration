<?php
/**
 * Tracker.js.php - Generates dynamic JavaScript for browser-side tracking
 * 
 * This file is served as tracker.js and tracks Action Network form interactions
 * Sends PageView events immediately and form submission events when detected
 */

// Set correct content type for JavaScript
header('Content-Type: application/javascript; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Aggressive cache-busting headers
header('Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0, s-maxage=0, proxy-revalidate');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('ETag: "' . md5(uniqid("", true)) . '"');
header('CF-Cache-Status: BYPASS');
header('CDN-Cache-Control: no-cache');
header('Surrogate-Control: no-store');

// Cloudflare-specific headers
header('Vary: *');

// Include required files
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/functions.php';

// Get hash from URL parameter
$hash = $_GET['id'] ?? '';

// Validate hash format
if (empty($hash) || !Crypto::isValidHashFormat($hash)) {
    // Return empty JavaScript if invalid
    echo '/* Invalid or missing configuration */';
    exit;
}

// Decrypt hash to get configuration
$config = Crypto::decrypt($hash);
if (!$config) {
    // Return empty JavaScript if decryption fails
    echo '/* Configuration error */';
    
    // Log with more context but less noise for obvious bot attempts
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $referer = $_SERVER['HTTP_REFERER'] ?? 'Direct';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    // Don't log obvious bot/crawler attempts as ERROR
    $isBotRequest = (
        empty($hash) || 
        strpos($userAgent, 'bot') !== false || 
        strpos($userAgent, 'crawler') !== false ||
        strpos($userAgent, 'spider') !== false ||
        $referer === 'Direct'
    );
    
    $logLevel = $isBotRequest ? 'DEBUG' : 'WARNING';
    
    quickLog('Failed to decrypt tracker hash', $logLevel, [
        'hash_provided' => !empty($hash),
        'hash_length' => strlen($hash),
        'user_agent' => substr($userAgent, 0, 100),
        'referer' => $referer,
        'ip' => $ip
    ]);
    exit;
}

// Extract configuration
$pixelId = $config['pixel_id'];
// Event type is now auto-detected (CompleteRegistration for forms, Donate for donations)

// Log tracker request (without sensitive data)
quickLog('Tracker.js requested for pixel: ' . $pixelId, 'DEBUG');

// Get current domain for API endpoint
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$domain = $_SERVER['HTTP_HOST'];
$apiEndpoint = $protocol . '://' . $domain . '/api.php';

// Output the JavaScript code
?>
/**
 * Meta Conversions API Tracker for Action Network
 * Sends PageView events immediately and form submission events when detected
 * Version: <?php echo date('Y-m-d H:i:s'); ?> - Clean Implementation
 */
(function() {
    'use strict';
    
    // Initialize call log array
    window.fbqCallLog = [];
    
    // Configuration
    const CONFIG = {
        hash: '<?php echo $hash; ?>',
        endpoint: '<?php echo $apiEndpoint; ?>',
        pixelId: '<?php echo $pixelId; ?>',
        debug: true
    };
    
    // Prevent multiple initializations
    if (window.MetaTrackerInitialized) {
        console.log('[Meta Tracker] ⚠️ Already initialized, skipping...');
        return;
    }
    window.MetaTrackerInitialized = true;
    
    // Utility functions
    const Utils = {
        log: function(message, data) {
            if (CONFIG.debug && window.console) {
                console.log('[Meta Tracker] ' + message, data || '');
            }
        },
        
        getCookie: function(name) {
            const value = '; ' + document.cookie;
            const parts = value.split('; ' + name + '=');
            if (parts.length === 2) {
                const cookieValue = parts.pop().split(';').shift();
                if (cookieValue && cookieValue !== 'undefined' && cookieValue !== 'null') {
                    return cookieValue;
                }
            }
            return null;
        },
        
        // Enhanced Facebook data collection
        getFacebookData: function() {
            const fbData = {
                fbp: this.getCookie('_fbp'),
                fbc: this.getCookie('_fbc'),
                fbclid: this.getUrlParam('fbclid')
            };
            
            // Try alternative sources for fbclid
            if (!fbData.fbclid) {
                // Check URL hash
                if (window.location.hash) {
                    const hashParams = new URLSearchParams(window.location.hash.substring(1));
                    fbData.fbclid = hashParams.get('fbclid');
                }
                
                // Check referrer
                if (!fbData.fbclid && document.referrer) {
                    try {
                        const referrerUrl = new URL(document.referrer);
                        fbData.fbclid = referrerUrl.searchParams.get('fbclid');
                    } catch (e) {
                        // Ignore parsing errors
                    }
                }
                
                // Check sessionStorage/localStorage for stored fbclid
                if (!fbData.fbclid) {
                    try {
                        fbData.fbclid = sessionStorage.getItem('fbclid') || localStorage.getItem('fbclid');
                    } catch (e) {
                        // Ignore storage errors
                    }
                }
            }
            
            // Store fbclid for future use if found
            if (fbData.fbclid) {
                try {
                    sessionStorage.setItem('fbclid', fbData.fbclid);
                } catch (e) {
                    // Ignore storage errors
                }
            }
            
            // Construct fbc if we have fbclid but no fbc
            if (fbData.fbclid && !fbData.fbc) {
                fbData.fbc = 'fb.1.' + Date.now() + '.' + fbData.fbclid;
            }
            
            return fbData;
        },
        
        getUrlParam: function(name) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        },
        
        // Add SHA256 implementation
        sha256: async function(message) {
            if (window.crypto && window.crypto.subtle) {
                const msgBuffer = new TextEncoder().encode(message);
                const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
                const hashArray = Array.from(new Uint8Array(hashBuffer));
                return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
            }
            // Fallback for older browsers
            return this.sha256Fallback(message);
        },

        sha256Fallback: function(message) {
            // Simple fallback - won't match PHP exactly but better than nothing
            console.warn('[Meta Tracker] Using fallback hash - may not match server');
            let hash = 0;
            for (let i = 0; i < message.length; i++) {
                const char = message.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return 'js_fallback_' + Math.abs(hash).toString(16);
        },

        // Updated event ID generation - MUST match PHP logic exactly
        generateEventId: async function(email, fbclid = null) {
            const timestamp = Math.floor(Date.now() / 1000);
            const roundedTime = Math.floor(timestamp / 1800) * 1800; // 30 minutes
            
            // Priority 1: email-based
            if (email && email.trim() !== '') {
                const normalizedEmail = email.toLowerCase().trim();
                const input = normalizedEmail + '_' + roundedTime;
                return await this.sha256(input);
            }
            
            // Priority 2: fbclid-based
            if (fbclid && fbclid.trim() !== '') {
                const input = fbclid + '_' + roundedTime;
                return await this.sha256(input);
            }
            
            // No valid identifiers
            return null;
        },
        
        // Synchronous version for pixel enhancer
        generateEventIdSync: function(email, fbclid = null) {
            const timestamp = Math.floor(Date.now() / 1000);
            const roundedTime = Math.floor(timestamp / 1800) * 1800;
            
            if (email && email.trim() !== '') {
                const normalizedEmail = email.toLowerCase().trim();
                const input = normalizedEmail + '_' + roundedTime;
                // Use simple hash for sync version
                return 'sync_' + this.simpleHash(input);
            }
            
            if (fbclid && fbclid.trim() !== '') {
                const input = fbclid + '_' + roundedTime;
                return 'sync_' + this.simpleHash(input);
            }
            
            return null;
        },
        
        generatePageViewEventId: function() {
            // Create deterministic PageView event_id based on URL and time window
            const url = window.location.href.split('?')[0]; // Remove query params
            const timestamp = Math.floor(Date.now() / 1000);
            const timeWindow = Math.floor(timestamp / 300) * 300; // 5-minute windows
            const pageViewKey = url + '_pageview_' + timeWindow;
            return Utils.simpleHash(pageViewKey);
        },
        
        simpleHash: function(str) {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return 'js_' + Math.abs(hash).toString(16);
        },
        
        sendData: function(data) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', CONFIG.endpoint, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        console.log('[Meta Tracker] ✅ Data sent successfully!');
                        try {
                            const response = JSON.parse(xhr.responseText);
                            console.log('[Meta Tracker] 📨 Server response:', response);
                            if (response.success) {
                                console.log('[Meta Tracker] 🎉 Event successfully sent to Meta!');
                                if (response.data && response.data.event_id) {
                                    console.log('[Meta Tracker] 🆔 Event ID:', response.data.event_id);
                                }
                                if (response.data && response.data.events_received) {
                                    console.log('[Meta Tracker] 📊 Events received by Meta:', response.data.events_received);
                                }
                            } else {
                                console.warn('[Meta Tracker] ⚠️ Server reported error:', response.message);
                            }
                        } catch (e) {
                            console.log('[Meta Tracker] 📨 Raw server response:', xhr.responseText);
                        }
                    } else {
                        console.error('[Meta Tracker] ❌ Error sending data. Status:', xhr.status);
                        console.error('[Meta Tracker] 📨 Error response:', xhr.responseText);
                    }
                }
            };
            
            const payload = { hash: CONFIG.hash, data: data };
            console.log('[Meta Tracker] 📡 Sending request to API...');
            xhr.send(JSON.stringify(payload));
        }
    };
    
    // Pixel enhancement functionality - inject event_ids into existing pixel
    const PixelEnhancer = {
        originalFbq: null,
        enhancementActive: false,
        
        hasExistingPixel: function() {
            return typeof window.fbq !== 'undefined';
        },
        
        enhanceExistingPixel: function() {
            if (typeof window.fbq !== 'undefined' && !this.enhancementActive) {
                Utils.log('🎯 Enhancing existing Meta Pixel with event_id injection');
                Utils.log('📊 Original pixel version:', window.fbq.version);
                Utils.log('📊 Original pixel properties:', Object.keys(window.fbq));
                
                try {
                    // Store original fbq function
                    this.originalFbq = window.fbq;
                    this.enhancementActive = true;
                    
                    // Create enhanced wrapper that injects our event_ids
                    const enhancedFbq = function(action, eventName, parameters, options) {
                        try {
                            // Log ALL fbq calls
                            window.fbqCallLog.push({
                                time: new Date().toISOString(),
                                action: action,
                                eventName: eventName,
                                hasEventId: !!(options && options.eventID),
                                eventId: options && options.eventID,
                                source: 'pixel'
                            });
                            
                            console.log('[Meta Tracker] 📸 Pixel call intercepted:', {
                                action, eventName, hasEventId: !!(options && options.eventID)
                            });
                            
                            // Inject our event_id for track calls
                            if (action === 'track') {
                                options = options || {};
                                parameters = parameters || {};
                                
                                // Enhanced event_id injection with pre-calculated matching IDs
                                if (!options.eventID) {
                                    if (eventName === 'CompleteRegistration' && window.pendingCompleteRegistrationId) {
                                        options.eventID = window.pendingCompleteRegistrationId;
                                        Utils.log('🆔 Using pre-calculated matching event_id:', options.eventID);
                                        
                                        // Clear it after use
                                        delete window.pendingCompleteRegistrationId;
                                    } else if (eventName === 'PageView') {
                                        options.eventID = Utils.generatePageViewEventId();
                                        Utils.log('🆔 Injected PageView event_id:', options.eventID);
                                    } else {
                                        // Fallback: try to generate from parameters
                                        const email = parameters && parameters.email ? parameters.email : null;
                                        const fbData = Utils.getFacebookData();
                                        
                                        if (email || fbData.fbclid) {
                                            // Generate synchronously (not ideal but necessary here)
                                            options.eventID = Utils.generateEventIdSync(email, fbData.fbclid);
                                            Utils.log('🆔 Generated fallback event_id:', options.eventID);
                                        } else {
                                            // Last resort: timestamp-based (won't match webhook)
                                            options.eventID = 'no_match_' + Date.now();
                                            Utils.log('⚠️ No identifiers - using non-matching event_id:', options.eventID);
                                        }
                                    }
                                    
                                    // Store event_id for debugging
                                    MetaTracker.storeEventId(eventName, options.eventID, parameters);
                                }
                                
                                // Only add value/currency for events with real monetary value
                                if (eventName === 'Donate') {
                                    if (!parameters.value) {
                                        parameters.value = 1.00;
                                        parameters.currency = 'EUR';
                                        Utils.log('💰 Added value/currency to donation event:', {value: parameters.value, currency: parameters.currency});
                                    }
                                }
                                
                                Utils.log('📞 Enhanced fbq track call:', {action, eventName, parameters, options});
                                
                                // Call original pixel function with enhanced parameters (not original arguments)
                                return PixelEnhancer.originalFbq.call(this, action, eventName, parameters, options);
                            } else {
                                Utils.log('📞 fbq call (passthrough):', {action, eventName, parameters, options});
                                
                                // For non-track calls, pass through original arguments unchanged
                                return PixelEnhancer.originalFbq.apply(this, arguments);
                            }
                        } catch (error) {
                            // Critical fallback: if our enhancement fails, always call original pixel
                            console.error('[Meta Tracker] ❌ Enhancement failed, falling back to original pixel:', error);
                            return PixelEnhancer.originalFbq.apply(this, arguments);
                        }
                    };
                    
                    // Safer property copying approach
                    try {
                        // Method 1: Direct descriptor copying (most accurate)
                        const originalDescriptors = Object.getOwnPropertyDescriptors(PixelEnhancer.originalFbq);
                        for (const [key, descriptor] of Object.entries(originalDescriptors)) {
                            if (key !== 'length' && key !== 'name' && key !== 'prototype') {
                                try {
                                    Object.defineProperty(enhancedFbq, key, descriptor);
                                } catch (e) {
                                    // Fallback: direct assignment
                                    enhancedFbq[key] = PixelEnhancer.originalFbq[key];
                                }
                            }
                        }
                        
                        // Set prototype chain
                        Object.setPrototypeOf(enhancedFbq, Object.getPrototypeOf(PixelEnhancer.originalFbq));
                        
                    } catch (descriptorError) {
                        Utils.log('⚠️ Property descriptor copying failed, using direct assignment');
                        
                        // Method 2: Direct property copying (fallback)
                        for (let prop in PixelEnhancer.originalFbq) {
                            try {
                                if (PixelEnhancer.originalFbq.hasOwnProperty(prop)) {
                                    enhancedFbq[prop] = PixelEnhancer.originalFbq[prop];
                                }
                            } catch (e) {
                                // Skip problematic properties
                            }
                        }
                    }
                    
                    // Ensure critical Meta Pixel properties are preserved
                    const criticalProps = ['version', 'queue', 'loaded', 'pixelsByID', '_fbq', 'callMethod', 'agent'];
                    criticalProps.forEach(prop => {
                        if (PixelEnhancer.originalFbq[prop] !== undefined) {
                            try {
                                enhancedFbq[prop] = PixelEnhancer.originalFbq[prop];
                            } catch (e) {
                                Utils.log('⚠️ Failed to copy property:', prop);
                            }
                        }
                    });
                    
                    // Ensure push compatibility
                    enhancedFbq.push = enhancedFbq;
                    
                    // Replace the global fbq function
                    window.fbq = enhancedFbq;
                    
                    Utils.log('✅ Meta Pixel enhancement successful');
                    return true;
                    
                } catch (enhancementError) {
                    console.error('[Meta Tracker] ❌ Critical enhancement error, disabling enhancement:', enhancementError);
                    
                    // Restore original pixel if enhancement failed
                    if (this.originalFbq) {
                        window.fbq = this.originalFbq;
                        this.enhancementActive = false;
                    }
                    
                    return false;
                }
            }
            return false;
        },
        
        initializeBasicPixel: function() {
            if (typeof window.fbq === 'undefined') {
                Utils.log('📦 No existing pixel found - initializing basic fbq function');
                
                // Create basic fbq function for when no pixel exists
                window.fbq = function(action, eventName, parameters, options) {
                    Utils.log('📞 Basic fbq called:', {action, eventName, parameters, options});
                    
                    if (action === 'track') {
                        // Route through our tracking system since no real pixel exists
                        MetaTracker.handleDirectPixelCall(eventName, parameters || {}, options || {});
                    }
                };
                
                window.fbq.version = '2.0';
                window.fbq.queue = [];
                window.fbq.push = window.fbq;
            }
        }
    };

    // Main tracker object
    const MetaTracker = {
        storedEventIds: new Map(),
        
        isActionNetworkPage: function() {
            return window.location.href.includes('actionnetwork.org/forms/') ||
                   document.querySelector('form[action*="actionnetwork.org"]') !== null;
        },
        
        storeEventId: function(eventName, eventId, parameters) {
            // Store event_id for webhook correlation
            this.storedEventIds.set(eventName + '_' + Date.now(), {
                eventId: eventId,
                eventName: eventName,
                parameters: parameters,
                timestamp: Date.now()
            });
            
            Utils.log('💾 Stored event_id for webhook correlation:', eventId);
        },
        
        handleDirectPixelCall: function(eventName, parameters, options) {
            // Handle fbq calls when no real pixel exists
            Utils.log('🎯 Handling direct pixel call:', eventName, parameters, options);
            
            if (eventName === 'PageView') {
                this.sendPageView(options.eventID);
            } else {
                this.sendCustomEvent(eventName, parameters, options.eventID);
            }
        },
        
        sendCustomEvent: function(eventName, parameters, eventId) {
            Utils.log('🔧 Sending custom event:', eventName);
            
            const browserData = {
                user_agent: navigator.userAgent,
                language: navigator.language,
                platform: navigator.platform,
                screen_width: screen.width,
                screen_height: screen.height
            };
            
            const fbData = Utils.getFacebookData();
            
            const pageData = {
                url: window.location.href,
                referrer: document.referrer,
                title: document.title
            };
            
            // Only add value/currency for events with real monetary value
            const enhancedParameters = parameters || {};
            if (eventName === 'Donate') {
                if (!enhancedParameters.value) {
                    enhancedParameters.value = 1.00;
                    enhancedParameters.currency = 'EUR';
                }
            }

            const payload = {
                event_type: eventName,
                event_id: eventId || (eventName.toLowerCase() + '_' + Date.now()),
                event_time: Math.floor(Date.now() / 1000),
                form_data: enhancedParameters,
                browser_data: browserData,
                fb_data: fbData,
                page_data: pageData,
                source: 'javascript_pixel_enhanced'
            };
            
            // Only add value/currency at payload level for events with real monetary value
            if (eventName === 'Donate') {
                payload.value = enhancedParameters.value || 1.00;
                payload.currency = enhancedParameters.currency || 'EUR';
            }
            
            Utils.sendData(payload);
        },
        
        sendPageView: function(providedEventId) {
            console.log('[Meta Tracker] 👁️ Sending PageView event...');
            
            const browserData = {
                user_agent: navigator.userAgent,
                language: navigator.language,
                platform: navigator.platform,
                screen_width: screen.width,
                screen_height: screen.height
            };
            
            const fbData = Utils.getFacebookData();
            
            const pageData = {
                url: window.location.href,
                referrer: document.referrer,
                title: document.title
            };
            
            // Use provided event_id or generate one
            const pageViewId = providedEventId || Utils.generatePageViewEventId();
            
            const payload = {
                event_type: 'PageView',
                event_id: pageViewId,
                event_time: Math.floor(Date.now() / 1000),
                form_data: {},
                browser_data: browserData,
                fb_data: fbData,
                page_data: pageData,
                source: 'javascript_pixel_enhanced'
            };
            
            console.log('[Meta Tracker] 📦 PageView payload prepared:', payload);
            Utils.sendData(payload);
        },
        
        sendCoordinatedPageView: function() {
            console.log('[Meta Tracker] 🎯 Sending coordinated PageView (Pixel + Conversions API)...');
            
            // Generate single event_id for both Pixel and Conversions API
            const pageViewEventId = Utils.generatePageViewEventId();
            console.log('[Meta Tracker] 🆔 Generated PageView event_id for deduplication:', pageViewEventId);
            
            // Step 1: Fire Meta Pixel PageView with our event_id
            if (typeof window.fbq === 'function') {
                console.log('[Meta Tracker] 🔥 Firing Meta Pixel PageView with event_id:', pageViewEventId);
                window.fbq('track', 'PageView', {}, { eventID: pageViewEventId });
                console.log('[Meta Tracker] ✅ Meta Pixel PageView fired');
            } else {
                console.log('[Meta Tracker] ⚠️ No fbq function available for Pixel PageView');
            }
            
            // Step 2: Send same PageView data + event_id to our server for Conversions API
            console.log('[Meta Tracker] 📡 Sending PageView data to Conversions API with same event_id...');
            this.sendPageView(pageViewEventId);
            
            console.log('[Meta Tracker] 🎉 Coordinated PageView complete - both Pixel and Conversions API will use same event_id for deduplication');
        },
        
        sendCoordinatedPageViewWithoutEnhancement: function() {
            console.log('[Meta Tracker] 🎯 Sending PageView without pixel enhancement (safer mode)...');
            
            // Generate event_id for Conversions API only
            const pageViewEventId = Utils.generatePageViewEventId();
            console.log('[Meta Tracker] 🆔 Generated PageView event_id for Conversions API:', pageViewEventId);
            
            // Let the original pixel fire its own PageView (without our event_id)
            console.log('[Meta Tracker] 📺 Original pixel will fire its own PageView event');
            
            // Send PageView data to our server for Conversions API
            console.log('[Meta Tracker] 📡 Sending PageView data to Conversions API...');
            this.sendPageView(pageViewEventId);
            
            console.log('[Meta Tracker] 🎉 Unenhanced PageView complete - original pixel and Conversions API working independently');
        },
        
        parseFormDataFromPayload: function(payload) {
            const data = {};
            
            if (typeof payload === 'string') {
                const pairs = payload.split('&');
                for (const pair of pairs) {
                    const [key, value] = pair.split('=');
                    if (key && value) {
                        const decodedKey = decodeURIComponent(key);
                        const decodedValue = decodeURIComponent(value);
                        
                        // Extract fields with various possible names
                        if (decodedKey === 'answer[email]' || decodedKey === 'email') {
                            data.email = decodedValue;
                        } else if (decodedKey === 'answer[first_name]' || decodedKey === 'first_name') {
                            data.first_name = decodedValue;
                        } else if (decodedKey === 'answer[last_name]' || decodedKey === 'last_name') {
                            data.last_name = decodedValue;
                        } else if (decodedKey === 'answer[phone]' || decodedKey === 'phone') {
                            data.phone = decodedValue;
                        } else if (decodedKey === 'answer[city]' || decodedKey === 'city') {
                            data.city = decodedValue;
                        } else if (decodedKey === 'answer[state]' || decodedKey === 'state') {
                            data.state = decodedValue;
                        } else if (decodedKey === 'answer[zip_code]' || decodedKey === 'zip' || decodedKey === 'zip_code') {
                            data.zip = decodedValue;
                        } else if (decodedKey === 'answer[country]' || decodedKey === 'country') {
                            data.country = decodedValue;
                        }
                    }
                }
            }
            
            return data;
        },
        
        sendFormSubmission: function(formData) {
            // Log form submission detection to call log
            window.fbqCallLog.push({
                time: new Date().toISOString(),
                action: 'custom',
                eventName: 'CompleteRegistration',
                source: 'answers_detection',
                formData: formData
            });
            
            console.log('[Meta Tracker] 🎯 Action Network form submission detected!');
            console.log('[Meta Tracker] 📋 Form submission detected, log so far:', window.fbqCallLog);
            console.log('[Meta Tracker] 📝 Sending CompleteRegistration to Conversions API with browser data');
            
            // All Action Network form submissions are treated as CompleteRegistration
            // (Donations will be detected server-side and converted to Donate events)
            
            // Enhanced browser data for better attribution
            const browserData = {
                user_agent: navigator.userAgent,
                language: navigator.language,
                platform: navigator.platform,
                screen_width: screen.width,
                screen_height: screen.height,
                viewport_width: window.innerWidth,
                viewport_height: window.innerHeight,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                connection: navigator.connection ? navigator.connection.effectiveType : null
            };
            
            // Enhanced Facebook data collection
            const fbData = Utils.getFacebookData();
            
            const pageData = {
                url: window.location.href,
                referrer: document.referrer,
                title: document.title,
                domain: window.location.hostname
            };
            
            const sendEventToAPI = async () => {
                const fbData = Utils.getFacebookData();
                
                // Generate event ID based on available data
                const eventId = await Utils.generateEventId(
                    formData.email || null,
                    fbData.fbclid || null
                );
                
                if (!eventId) {
                    console.log('[Meta Tracker] ⚠️ No email or fbclid - skipping tracker CAPI to prevent duplication');
                    console.log('[Meta Tracker] 📊 Only webhook will send this conversion');
                    return; // DON'T SEND
                }
                
                // Store event ID for pixel enhancer to use
                window.pendingCompleteRegistrationId = eventId;
                console.log('[Meta Tracker] 💾 Stored event_id for pixel enhancer:', eventId);
                
                // Prepare payload with browser data
                const payload = {
                    event_type: 'CompleteRegistration',
                    event_id: eventId,
                    event_time: Math.floor(Date.now() / 1000),
                    form_data: formData,
                    browser_data: browserData,
                    fb_data: fbData,
                    page_data: pageData,
                    source: 'javascript_conversions_api',
                    purpose: 'browser_data_enhancement'
                };
                
                console.log('[Meta Tracker] 🚀 Sending to Conversions API with matching event_id...');
                Utils.sendData(payload);
            };
            
            // Execute async
            sendEventToAPI();
        },
        
        monitorFormSubmissions: function() {
            // Override XMLHttpRequest
            const originalXHROpen = XMLHttpRequest.prototype.open;
            const originalXHRSend = XMLHttpRequest.prototype.send;
            
            XMLHttpRequest.prototype.open = function(method, url, async) {
                this._method = method;
                this._url = url;
                return originalXHROpen.apply(this, arguments);
            };
            
            XMLHttpRequest.prototype.send = function(data) {
                if (this._method === 'POST' && this._url && this._url.includes('/answers')) {
                    console.log('[Meta Tracker] 🚀 Detected POST to /answers endpoint!');
                    console.log('[Meta Tracker] 📝 Form submission URL:', this._url);
                    console.log('[Meta Tracker] 📦 Payload:', data);
                    
                    const payloadData = MetaTracker.parseFormDataFromPayload(data);
                    console.log('[Meta Tracker] 📝 Extracted from payload:', payloadData);
                    
                    // Debug: Log if no email was found to help identify false positives
                    if (!payloadData.email) {
                        console.warn('[Meta Tracker] ⚠️ No email extracted from /answers payload - possible false positive');
                        console.warn('[Meta Tracker] 📦 Raw payload for debugging:', data);
                    }
                    
                    setTimeout(() => {
                        MetaTracker.sendFormSubmission(payloadData);
                    }, 100);
                }
                return originalXHRSend.apply(this, arguments);
            };
            
            // Override fetch API
            if (window.fetch) {
                const originalFetch = window.fetch;
                window.fetch = function(url, options) {
                    if (options && options.method === 'POST' && url && url.includes('/answers')) {
                        console.log('[Meta Tracker] 🚀 Detected fetch POST to /answers endpoint!');
                        console.log('[Meta Tracker] 📝 Form submission URL:', url);
                        console.log('[Meta Tracker] 📦 Payload:', options.body);
                        
                        const payloadData = MetaTracker.parseFormDataFromPayload(options.body);
                        console.log('[Meta Tracker] 📝 Extracted from payload:', payloadData);
                        
                        // Debug: Log if no email was found to help identify false positives
                        if (!payloadData.email) {
                            console.warn('[Meta Tracker] ⚠️ No email extracted from /answers payload - possible false positive');
                            console.warn('[Meta Tracker] 📦 Raw payload for debugging:', options.body);
                        }
                        
                        setTimeout(() => {
                            MetaTracker.sendFormSubmission(payloadData);
                        }, 100);
                    }
                    return originalFetch.apply(this, arguments);
                };
            }
        },
        
        init: function() {
            console.log('[Meta Tracker] 🚀 Meta Conversions API Tracker starting...');
            console.log('[Meta Tracker] 🎯 Will enhance existing Meta Pixel with event_id injection');
            console.log('[Meta Tracker] 📋 Will auto-detect event types (Donate for donations, CompleteRegistration for forms)');
            console.log('[Meta Tracker] ⚙️ Configuration:', CONFIG);
            
            // Wait for pixel to fully load before enhancement
            let attempts = 0;
            const maxAttempts = 10;
            
            const checkAndEnhance = () => {
                attempts++;
                const hasExistingPixel = PixelEnhancer.hasExistingPixel();
                
                if (hasExistingPixel) {
                    // Additional check to ensure pixel is fully loaded
                    const isPixelReady = window.fbq && (window.fbq.loaded || window.fbq.queue !== undefined);
                    
                    if (isPixelReady) {
                        console.log('[Meta Tracker] 🔍 Existing Meta Pixel detected and ready');
                        console.log('[Meta Tracker] 🎯 Enhancing with event_id injection');
                        const enhancementSuccess = PixelEnhancer.enhanceExistingPixel();
                        
                        if (enhancementSuccess) {
                            console.log('[Meta Tracker] ✅ Meta Pixel enhanced - event_ids will be injected automatically');
                            MetaTracker.sendCoordinatedPageView();
                        } else {
                            console.log('[Meta Tracker] ❌ Enhancement failed, falling back to unenhanced mode');
                            MetaTracker.sendCoordinatedPageViewWithoutEnhancement();
                        }
                    } else if (attempts < maxAttempts) {
                        console.log('[Meta Tracker] ⏳ Pixel found but not ready, waiting... (attempt ' + attempts + ')');
                        setTimeout(checkAndEnhance, 200);
                        return;
                    } else {
                        console.log('[Meta Tracker] ⚠️ Pixel found but never became ready');
                        console.log('[Meta Tracker] 🎯 Proceeding with enhancement anyway');
                        const enhancementSuccess = PixelEnhancer.enhanceExistingPixel();
                        
                        if (enhancementSuccess) {
                            MetaTracker.sendCoordinatedPageView();
                        } else {
                            MetaTracker.sendCoordinatedPageViewWithoutEnhancement();
                        }
                    }
                } else if (attempts < maxAttempts) {
                    console.log('[Meta Tracker] 🔍 Looking for Meta Pixel... (attempt ' + attempts + ')');
                    setTimeout(checkAndEnhance, 200);
                    return;
                } else {
                    console.log('[Meta Tracker] 📦 No existing Meta Pixel found after ' + maxAttempts + ' attempts');
                    PixelEnhancer.initializeBasicPixel();
                    MetaTracker.sendCoordinatedPageView();
                }
            };
            
            // Start checking immediately
            checkAndEnhance();
            
            // Set up network monitoring for form submissions
            MetaTracker.monitorFormSubmissions();
            console.log('[Meta Tracker] 📡 Network monitoring active for /answers submissions');
            console.log('[Meta Tracker] 🎉 Initialization complete - ready to enhance tracking!');
        }
    };
    
    // Initialize immediately
    try {
        MetaTracker.init();
    } catch (e) {
        console.error('[Meta Tracker] Error during initialization:', e);
    }
    
})();

/* Meta Conversions API Tracker - Action Network Integration */