(function () {
    'use strict';
    const apiRoot = ncData.root + 'nc/v1/';
    const bellContainer = document.getElementById('nc-bell-container');
    const badge = bellContainer ? bellContainer.querySelector('.nc-badge') : null;
    const drawer = document.getElementById('nc-drawer');
    const overlay = document.getElementById('nc-overlay');
    const listContainer = document.getElementById('nc-notification-list');
    const markAllReadBtn = document.getElementById('nc-mark-all-read');

    // Debug Helper
    const ncLog = (...args) => {
        if (ncData.debugMode) {
            console.log(...args);
        }
    };

    // State
    let notifications = [];
    const readIds = JSON.parse(localStorage.getItem('nc_read_ids') || '[]');
    let dismissedIds = JSON.parse(localStorage.getItem('nc_dismissed_ids') || '{}'); // Off-Canvas
    let dismissedToastIds = JSON.parse(localStorage.getItem('nc_dismissed_toast_ids') || '{}'); // Toast only
    let shownSessionIds = []; // Track what we showed this session to prevent re-show on re-render

    // Queue System: GLOBAL - only ONE floating notification at a time
    // Priority: center (popup) > top positions > bottom positions
    let floatingQueue = []; // Single global queue
    let activeFloatingId = null; // Currently shown notification ID

    // Behavioral Trigger System
    let triggerNotifications = []; // Notifications waiting for triggers
    let triggersFired = {}; // Track which triggers have fired for each notification
    let lastActivityTime = Date.now(); // For inactivity tracking
    let pageLoadTime = Date.now(); // For time on page tracking
    let scrollDepthReached = 0; // Maximum scroll depth reached
    let triggerListenersInitialized = false;

    // Legacy migration: if it was array, convert to object
    if (Array.isArray(dismissedIds)) {
        let newStore = {};
        dismissedIds.forEach(id => newStore[id] = Date.now());
        dismissedIds = newStore;
        localStorage.setItem('nc_dismissed_ids', JSON.stringify(dismissedIds));
    }

    // Init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        ncLog('NC: Initializing...');
        if (!bellContainer) console.warn('NC: Bell container not found (normal if shortcode missing)');

        // GLOBAL FORM SUBMIT PROTECTION
        // This ensures forms inside notifications don't cause page reload
        document.addEventListener('submit', function(e) {
            const form = e.target;

            // Only handle Fluent Forms inside notifications
            if (!form.classList.contains('frm-fluent-form')) {
                return;
            }

            const notificationContainer = form.closest('.nc-floating, .nc-item, .nc-topbar-item, .nc-pos-center-overlay');
            if (!notificationContainer) {
                return; // Form is not in a notification - let it work normally
            }

            const instanceId = form.getAttribute('data-form_instance');
            const specificVarName = 'fluent_form_' + instanceId;

            ncLog(`NC: Form submit detected for ${instanceId} inside notification`);

            // Check if config is missing
            if (!window[specificVarName]) {
                console.error(`NC: Fluent Form config ${specificVarName} is missing! Preventing page reload.`);
                e.preventDefault();
                e.stopPropagation();

                // Try to reinitialize the form
                if (typeof window.ncInitFluentForms === 'function') {
                    ncLog(`NC: Attempting to reinitialize form...`);
                    window.ncInitFluentForms(notificationContainer);

                    // Show error to user
                    const errorContainer = form.querySelector('.ff-errors-in-stack') || form.querySelector('[id*="_errors"]');
                    if (errorContainer) {
                        errorContainer.innerHTML = '<div class="error" style="color: red; padding: 10px;">Formularz nie został prawidłowo załadowany. Odśwież stronę i spróbuj ponownie.</div>';
                    }
                } else {
                    console.error('NC: Cannot reinitialize - ncInitFluentForms function not found');
                }

                return false;
            }

            // Config exists - let Fluent Forms handle the submission via AJAX
            // We don't preventDefault here because Fluent Forms needs to process it
            ncLog(`NC: Config exists for ${instanceId}, letting Fluent Forms handle AJAX submission`);
        }, true); // Capture phase to run before other handlers

        // Listeners
        if (bellContainer) bellContainer.addEventListener('click', toggleDrawer);
        if (overlay) overlay.addEventListener('click', toggleDrawer);
        const closeBtn = document.querySelector('.nc-close-drawer');
        if (closeBtn) closeBtn.addEventListener('click', toggleDrawer);

        // Global Helper for Fluent Forms
        window.ncInitFluentForms = function (container) {
            if (typeof jQuery === 'undefined') return;

            const forms = container.querySelectorAll('form.frm-fluent-form');
            if (forms.length === 0) return;

            forms.forEach(form => {
                const $form = jQuery(form);
                const instanceId = form.getAttribute('data-form_instance');
                const formId = form.getAttribute('data-form_id');

                if (instanceId && formId) {
                    let currentInstanceId = instanceId;

                    // FIX 1: Duplicate ID Collision
                    // If this form shares an ID with another element in the DOM (e.g. Panel & Popup), we MUST isolate it
                    if (form.id && document.querySelectorAll('#' + form.id).length > 1) {
                        // Generate a new unique instance ID (random suffix)
                        const uniqueSuffix = '_' + Math.random().toString(36).substr(2, 9);
                        const newInstanceId = instanceId + uniqueSuffix;
                        const newFormId = form.id + uniqueSuffix;

                        // Update form attributes BEFORE anything else
                        form.setAttribute('data-form_instance', newInstanceId);
                        form.id = newFormId;
                        
                        // Also update the CSS class that FF uses as selector
                        form.classList.remove(instanceId);
                        form.classList.add(newInstanceId);

                        currentInstanceId = newInstanceId;
                        ncLog(`NC: Duplicate collision detected. Generated new instance: ${newInstanceId} and ID: ${newFormId}`);
                    }

                    // FIX 2: Missing Configuration (innerHTML issue)
                    // Inline scripts don't run in innerHTML, so window.fluent_form_ff_... is missing.
                    // We must MANUALLY reconstruct it from the generic model.

                    const specificVarName = 'fluent_form_' + currentInstanceId;
                    const genericVarName = 'fluent_form_model_' + formId;

                    // Always try to get the generic config as a base
                    const genericConfig = window[genericVarName];

                    if (genericConfig) {
                        // Always create/overwrite the specific config to ensure it's correct
                        const config = JSON.parse(JSON.stringify(genericConfig));
                        config.form_instance = currentInstanceId;
                        config.form_id_selector = form.id;
                        
                        // Add validation rules if present in generic config
                        if (!config.rules) {
                            config.rules = {};
                        }
                        
                        // Add settings if missing
                        if (!config.settings) {
                            config.settings = {
                                layout: {
                                    labelPlacement: 'top',
                                    helpMessagePlacement: 'with_label',
                                    errorMessagePlacement: 'inline',
                                    asteriskPlacement: 'asterisk-right'
                                }
                            };
                        }

                        window[specificVarName] = config;
                        ncLog(`NC: Created Fluent Form config for ${currentInstanceId} from ${genericVarName}`);
                    } else {
                        console.error(`NC Warning: Generic Fluent Form model ${genericVarName} not found. AJAX may fail.`);
                    }

                    // CRITICAL: Add form ID to global forms array so FF knows to handle it via AJAX
                    if (window.fluentFormVars && Array.isArray(window.fluentFormVars.forms)) {
                        if (!window.fluentFormVars.forms.includes(formId)) {
                            window.fluentFormVars.forms.push(formId);
                            ncLog(`NC: Added form ID ${formId} to fluentFormVars`);
                        }
                    }
                    
                    // FIX 3: Initialize Fluent Forms properly
                    // We need to call Fluent Forms' initialization AFTER the form is in the DOM
                    // and all config variables are set

                    setTimeout(() => {
                        // Method 1: Try to use fluentFormApp if available
                        if (typeof window.fluentFormApp === 'function') {
                            try {
                                // Remove any loading/initialized flags
                                form.classList.remove('ff-form-loaded');
                                form.removeAttribute('data-ff_reinit');

                                const formApp = window.fluentFormApp($form);
                                if (formApp) {
                                    // Initialize form handlers (including submit handler)
                                    if (typeof formApp.initFormHandlers === 'function') {
                                        formApp.initFormHandlers();
                                        ncLog(`NC: Initialized form handlers for ${currentInstanceId}`);
                                    }

                                    // Initialize triggers/conditions
                                    if (typeof formApp.initTriggers === 'function') {
                                        formApp.initTriggers();
                                    }

                                    ncLog(`NC: Successfully initialized Fluent Form ${currentInstanceId} via fluentFormApp`);
                                } else {
                                    ncLog(`NC: fluentFormApp returned null for ${currentInstanceId}`);
                                }
                            } catch (error) {
                                console.error(`NC: Error initializing form ${currentInstanceId}:`, error);
                            }
                        }

                        // Method 2: Trigger ff_reinit event as fallback
                        try {
                            jQuery(document).trigger('ff_reinit', [$form]);
                            ncLog(`NC: Triggered ff_reinit for ${currentInstanceId}`);
                        } catch (error) {
                            console.error(`NC: Error triggering ff_reinit:`, error);
                        }

                        // Method 3: As final fallback, ensure form is marked as initialized
                        // so Fluent Forms knows to handle it
                        form.classList.add('ff-form-loaded');
                        form.setAttribute('data-is_initialized', 'true');

                    }, 100); // Small delay to ensure DOM is fully ready
                }
            });
        };

        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', markAllAsRead);
        }

        fetchNotifications();
    }

    function fetchNotifications() {
        // Prepare context params
        const params = new URLSearchParams({
            url: window.location.href,
            pid: getPostId() // helper needed
        });

        fetch(apiRoot + 'notifications?' + params.toString())
            .then(res => res.json())
            .then(data => {
                ncLog('NC: Fetched ' + data.length + ' notifications', data);
                // Normalize data structure for backward compatibility
                notifications = data.map(n => {
                    const s = n.settings;

                    // Floating Normalization
                    if (!s.show_as_floating) {
                        if (s.toast) {
                            s.show_as_floating = '1';
                            // Map legacy toast settings if new ones aren't present
                            if (!s.floating_position) s.floating_position = 'bottom_right';
                            if (!s.floating_width) s.floating_width = s.toast_width;
                            if (!s.floating_delay) s.floating_delay = s.toast_delay;
                            if (!s.floating_duration) s.floating_duration = s.toast_duration;
                        } else if (s.popup) {
                            s.show_as_floating = '1';
                            if (!s.floating_position) s.floating_position = 'center';
                        }
                    }

                    // Sidebar Visibility Normalization
                    // Empty string '' = user explicitly unchecked = DO NOT show
                    // undefined = legacy notification without this field = use legacy logic
                    if (s.show_in_sidebar === '') {
                        s.show_in_sidebar = '0'; // Explicit: user unchecked
                    } else if (typeof s.show_in_sidebar === 'undefined') {
                        // Legacy logic for old notifications
                        if (s.topbar) s.show_in_sidebar = '0';
                        else if (s.only_toast) s.show_in_sidebar = '0';
                        else if (s.popup && !s.pinned) s.show_in_sidebar = '0';
                        else s.show_in_sidebar = '1'; // Default for legacy
                    }

                    return n;
                });

                renderAll();
            })
            .catch(err => console.error('NC Error:', err));
    }

    function renderAll() {
        renderList();
        renderBadge();
        checkFloating();
        renderTopBar();
    }

    function renderList() {
        listContainer.innerHTML = '';

        // Filter logic with repeat check
        const validItems = notifications.filter(n => {
            // Explicit sidebar setting
            if (n.settings.show_in_sidebar !== '1') return false;

            // Dismissal Check (Sidebar History)
            let dismissedAt = 0;
            if (Array.isArray(dismissedIds)) {
                if (dismissedIds.includes(n.id)) dismissedAt = Date.now();
            } else {
                dismissedAt = dismissedIds[n.id] || 0;
            }

            if (!dismissedAt) return true; // Not dismissed yet

            // Repeat Policy
            if (n.settings.repeat_val > 0) {
                let multiplier = 1000 * 60; // minutes
                if (n.settings.repeat_unit === 'hours') multiplier *= 60;
                if (n.settings.repeat_unit === 'days') multiplier *= 60 * 24;

                const showAfter = dismissedAt + (n.settings.repeat_val * multiplier);
                if (Date.now() > showAfter) {
                    return true;
                }
            }

            return false; // Still dismissed
        });

        if (validItems.length === 0) {
            listContainer.innerHTML = '<div class="nc-empty">Brak nowych powiadomień.</div>';
            return;
        }

        validItems.forEach(n => {
            const isRead = readIds.includes(n.id);
            const el = document.createElement('div');
            el.className = `nc-item ${isRead ? 'read' : 'unread'}`;

            // Text truncation logic
            let bodyHtml = `<div class="nc-content line-clamp">${n.body}</div>`;
            let toggleBtn = '';

            // Simple expansion check (naive char count or CSS generic)
            // Ideally we check scrollHeight but that requires render first. 
            // For MVP we just add the class and a button that toggles it.
            // We can check string length > 100 char?
            if (n.body && n.body.length > 150) {
                toggleBtn = `<button class="nc-expand-btn">Pokaż więcej</button>`;
            }

            // Colors - use global colors as fallbacks
            const g = ncData.globalColors || {};
            const s = n.settings.colors || {};

            const bg = s.bg || g.bg || '#ffffff';
            const text = s.text || g.text || '#1d1d1f';
            const btnBg = s.btn_bg || g.btnBg || '#007AFF';
            const btnText = s.btn_text || g.btnText || '#ffffff';

            const itemStyle = `background-color:${bg}; color:${text};`;
            const btnStyle = `background-color:${btnBg}; color:${btnText};`;

            el.style.cssText = itemStyle;

            const renderIcon = (icon) => {
                if (!icon) return '';
                // Check if image URL (contains . or /)
                if (icon.indexOf('.') > -1 || icon.indexOf('/') > -1) {
                    return `<img src="${icon}" class="nc-icon-img" style="width:60px; height:60px; border-radius:8px; object-fit:cover; display:block;" alt="">`;
                }
                if (icon.startsWith('dashicons-')) return `<span class="dashicons ${icon}" style="font-size:40px; width:60px; height:60px; line-height:60px; text-align:center;"></span>`;
                return `<span style="font-size:40px; width:60px; height:60px; line-height:60px; text-align:center; display:block;">${icon}</span>`;
            };

            const iconHtml = n.icon ? `<div class="nc-item-icon" style="flex-shrink:0; margin-right:15px;">${renderIcon(n.icon)}</div>` : '';

            el.innerHTML = `
                <div class="nc-item-inner" style="display:flex; align-items:flex-start;">
                    ${iconHtml}
                    <div class="nc-item-content" style="flex-grow:1;">
                        <div class="nc-item-header">
                            <div style="display:flex; align-items:center;">
                                ${n.settings.sidebar_pinned ? '<span title="Przypięte" style="margin-right:5px;">📌</span> ' : ''}
                                <span class="nc-date" title="${n.date}">${timeAgo(n.date)}</span>
                            </div>
                            ${!n.settings.sidebar_permanent ? '<button class="nc-dismiss" title="Usuń">&times;</button>' : ''}
                        </div>
                        <div class="nc-item-body">
                            <h4 style="color:inherit;">${!isRead ? '<span class="nc-new-badge">Nowe</span>' : ''}${n.title}</h4>
                            ${bodyHtml}
                            ${toggleBtn}
                            ${n.settings.countdown && n.settings.countdown.enabled ? renderCountdownHTML(n.settings.countdown, true) : ''}
                            ${n.cta_label ? `<a href="${n.cta_url}" class="nc-btn" style="${btnStyle}">${n.cta_label}</a>` : ''}
                        </div>
                    </div>
                </div>
            `;

            // Listeners
            const dismissBtn = el.querySelector('.nc-dismiss');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', (e) => {
                    e.stopPropagation(); // prevent triggering item click
                    dismissItem(n.id);
                });
            }

            const expandBtn = el.querySelector('.nc-expand-btn');
            if (expandBtn) {
                expandBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const body = el.querySelector('.nc-content');
                    body.classList.toggle('line-clamp');
                    expandBtn.textContent = body.classList.contains('line-clamp') ? 'Pokaż więcej' : 'Pokaż mniej';
                });
            }

            // Click on item -> mark read
            el.addEventListener('click', () => {
                if (!isRead) {
                    markAsRead(n.id);
                }
            });

            listContainer.appendChild(el);

            // Init Fluent Forms in this item if present
            if (typeof window.ncInitFluentForms === 'function') {
                window.ncInitFluentForms(el);
            }
        });
    }

    function renderBadge() {
        if (!badge) return;

        // Count unread and effectively visible items (excluding Top Bar)
        const count = notifications.filter(n => {
            // Exclude Top Bar notifications from badge count
            if (n.settings.topbar) return false;
            // Exclude Popups (they shout at you, no need for badge technically, unless we want to persist them)
            // If pinned, we might count them. Copied logic from renderList
            if (n.settings.popup && !n.settings.pinned) return false;

            if (readIds.includes(n.id)) return false;

            // Check dismissal
            let dismissedAt = dismissedIds[n.id] || 0;
            if (dismissedAt) {
                if (n.settings.repeat_val > 0) {
                    let multiplier = 1000 * 60; // minutes
                    if (n.settings.repeat_unit === 'hours') multiplier *= 60;
                    if (n.settings.repeat_unit === 'days') multiplier *= 60 * 24;
                    if (Date.now() < dismissedAt + (n.settings.repeat_val * multiplier)) {
                        return false;
                    }
                } else {
                    return false;
                }
            }
            return true;
        }).length;

        if (count > 0) {
            // Check badge type setting
            const type = ncData.badgeType || 'count';

            if (type === 'dot') {
                badge.classList.add('dot');
                badge.textContent = ''; // Empty for dot
            } else {
                badge.classList.remove('dot');
                badge.textContent = count > 9 ? '9+' : count;
            }

            badge.style.display = 'flex';
            if (bellContainer) bellContainer.classList.add('nc-pulse-animation');
        } else {
            badge.style.display = 'none';
            if (bellContainer) bellContainer.classList.remove('nc-pulse-animation');
        }
    }

    // ============================================
    // UNIFIED FLOATING NOTIFICATIONS (Toasts + Popups)
    // ============================================
    function checkFloating() {
        ncLog('NC: checkFloating() called');
        ncLog('NC: Total notifications:', notifications.length);

        if (notifications.length === 0) {
            ncLog('NC: No notifications to check');
            return;
        }

        // Reset queues
        floatingQueue = [];
        triggerNotifications = [];

        notifications.forEach((n) => {
            const val = n.settings.show_as_floating;
            const isFloating = (val === '1' || val === 1 || val === true);

            if (!isFloating) return;

            if (shownSessionIds.includes(n.id)) {
                ncLog(`NC: ID ${n.id} already shown this session, skipping`);
                return;
            }

            // Check dismissal
            let dismissedAt = dismissedToastIds[n.id] || 0;
            if (dismissedAt) {
                if (n.settings.repeat_val > 0) {
                    let multiplier = 1000 * 60;
                    if (n.settings.repeat_unit === 'hours') multiplier *= 60;
                    if (n.settings.repeat_unit === 'days') multiplier *= 60 * 24;

                    const nextShow = dismissedAt + (n.settings.repeat_val * multiplier);
                    if (Date.now() < nextShow) {
                        ncLog(`NC: ID ${n.id} hidden by dismissal until ${new Date(nextShow)}`);
                        return;
                    }
                } else {
                    ncLog(`NC: ID ${n.id} dismissed forever`);
                    return;
                }
            }

            // Check if notification has any behavioral triggers
            const triggers = n.settings.triggers || {};
            const hasBehavioralTriggers = triggers.exit_intent || triggers.scroll_depth ||
                triggers.time_on_page || triggers.inactivity || triggers.click;

            if (triggers.delay) {
                // Delay trigger - add to queue and schedule with timeout
                floatingQueue.push(n);
                ncLog(`NC: ID ${n.id} using delay trigger (${triggers.delay_seconds}s)`);
            } else if (hasBehavioralTriggers) {
                // Other behavioral triggers - wait for events
                triggerNotifications.push(n);
                triggersFired[n.id] = false;
                ncLog(`NC: ID ${n.id} waiting for behavioral trigger`);
            } else {
                // No triggers at all - show immediately (backward compatibility)
                floatingQueue.push(n);
                ncLog(`NC: ID ${n.id} added to queue (no triggers - immediate)`);
            }
        });

        // Sort queue by priority: center > top > bottom
        const priorityOrder = { 'center': 0, 'top_right': 1, 'top_left': 1, 'bottom_right': 2, 'bottom_left': 2 };
        floatingQueue.sort((a, b) => {
            const posA = a.settings.floating_position || 'bottom_right';
            const posB = b.settings.floating_position || 'bottom_right';
            return (priorityOrder[posA] || 2) - (priorityOrder[posB] || 2);
        });

        ncLog('NC: Global queue built with', floatingQueue.length, 'immediate items');
        ncLog('NC: Trigger notifications:', triggerNotifications.length);

        // Initialize trigger listeners if we have trigger notifications
        if (triggerNotifications.length > 0 && !triggerListenersInitialized) {
            initTriggerListeners();
        }

        // Show first notification from queue (if nothing is currently active)
        showNextFromGlobalQueue();
    }

    // ============================================
    // BEHAVIORAL TRIGGER LISTENERS
    // ============================================
    function initTriggerListeners() {
        if (triggerListenersInitialized) return;
        triggerListenersInitialized = true;
        ncLog('NC: Initializing trigger listeners');

        // Exit Intent - mouse leaves viewport from top
        document.addEventListener('mouseleave', (e) => {
            if (e.clientY <= 0) {
                ncLog('NC: Exit intent detected');
                checkTrigger('exit_intent');
            }
        });

        // Scroll Depth tracking
        window.addEventListener('scroll', () => {
            const scrollTop = window.scrollY;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            const scrollPercent = docHeight > 0 ? Math.round((scrollTop / docHeight) * 100) : 0;

            if (scrollPercent > scrollDepthReached) {
                scrollDepthReached = scrollPercent;
                checkTrigger('scroll_depth', scrollPercent);
            }

            // Also update activity time
            lastActivityTime = Date.now();
        });

        // Mouse movement for activity tracking
        document.addEventListener('mousemove', () => {
            lastActivityTime = Date.now();
        });

        // Time on Page - check every second
        setInterval(() => {
            const secondsOnPage = Math.floor((Date.now() - pageLoadTime) / 1000);
            checkTrigger('time_on_page', secondsOnPage);
        }, 1000);

        // Inactivity - check every second
        setInterval(() => {
            const idleSeconds = Math.floor((Date.now() - lastActivityTime) / 1000);
            checkTrigger('inactivity', idleSeconds);
        }, 1000);

        // Click Trigger - delegate listener
        document.addEventListener('click', (e) => {
            triggerNotifications.forEach(n => {
                const triggers = n.settings.triggers || {};
                if (triggers.click && triggers.click_selector && !triggersFired[n.id]) {
                    if (e.target.matches(triggers.click_selector) ||
                        e.target.closest(triggers.click_selector)) {
                        ncLog(`NC: Click trigger matched for ID ${n.id}`);
                        fireTriggerNotification(n);
                    }
                }
            });
        });
    }

    function checkTrigger(triggerType, value) {
        triggerNotifications.forEach(n => {
            if (triggersFired[n.id]) return; // Already fired

            // Check if notification was dismissed
            const dismissedAt = dismissedToastIds[n.id] || 0;
            if (dismissedAt) {
                if (n.settings.repeat_val > 0) {
                    let multiplier = 1000 * 60;
                    if (n.settings.repeat_unit === 'hours') multiplier *= 60;
                    if (n.settings.repeat_unit === 'days') multiplier *= 60 * 24;
                    if (Date.now() < dismissedAt + (n.settings.repeat_val * multiplier)) {
                        return; // Still in dismissal period
                    }
                } else {
                    return; // Dismissed forever
                }
            }

            const triggers = n.settings.triggers || {};
            let shouldFire = false;

            switch (triggerType) {
                case 'exit_intent':
                    if (triggers.exit_intent) shouldFire = true;
                    break;
                case 'scroll_depth':
                    if (triggers.scroll_depth && value >= triggers.scroll_percent) {
                        shouldFire = true;
                    }
                    break;
                case 'time_on_page':
                    if (triggers.time_on_page && value >= triggers.time_seconds) {
                        shouldFire = true;
                    }
                    break;
                case 'inactivity':
                    if (triggers.inactivity && value >= triggers.idle_seconds) {
                        shouldFire = true;
                    }
                    break;
            }

            if (shouldFire) {
                ncLog(`NC: Trigger '${triggerType}' fired for ID ${n.id}`);
                fireTriggerNotification(n);
            }
        });
    }

    function fireTriggerNotification(n) {
        if (triggersFired[n.id]) return;
        triggersFired[n.id] = true;

        // Add to front of queue (triggered notifications have priority)
        floatingQueue.unshift(n);

        // Try to show immediately
        showNextFromGlobalQueue();
    }

    function showNextFromGlobalQueue() {
        // If something is already showing, wait
        if (activeFloatingId) {
            ncLog('NC: Active notification exists, waiting');
            return;
        }

        if (floatingQueue.length === 0) {
            ncLog('NC: Global queue empty');
            return;
        }

        const n = floatingQueue.shift();

        // Only apply delay if delay trigger is specifically enabled
        const triggers = n.settings.triggers || {};
        const delay = triggers.delay ? (parseInt(triggers.delay_seconds) || 0) * 1000 : 0;

        ncLog(`NC: Scheduling next from global queue: ID ${n.id} in ${delay}ms`);

        if (delay > 0) {
            setTimeout(() => {
                if (activeFloatingId) {
                    floatingQueue.unshift(n);
                    return;
                }
                activeFloatingId = n.id;
                showFloating(n);
            }, delay);
        } else {
            // No delay - show immediately
            activeFloatingId = n.id;
            showFloating(n);
        }
    }

    function showFloating(n) {
        ncLog('NC: showFloating() called for ID', n.id, n.title);

        // Prevent duplicate
        if (document.querySelector(`.nc-floating[data-id="${n.id}"]`)) {
            ncLog(`NC: ID ${n.id} already in DOM, skipping`);
            return;
        }

        shownSessionIds.push(n.id);
        ncLog('NC: Added to shownSessionIds, playing sound');
        playNotificationSound();

        // Position
        const pos = n.settings.floating_position || 'bottom_right';
        const isCenter = pos === 'center';

        // Setup Region/Container
        let container;
        if (isCenter) {
            // For center, we create a direct overlay container per notification (like popup)
            // Or use a shared invisible overlay? Modal usually blocks.
            // Let's create a dedicated overlay data-id
            container = document.createElement('div');
            container.className = 'nc-pos-center-overlay';
            container.dataset.id = n.id;
            document.body.appendChild(container);
            // Events for overlay
            container.addEventListener('click', (e) => {
                // Don't close if clicking on form elements (buttons, inputs, etc.)
                if (e.target.tagName === 'BUTTON' ||
                    e.target.tagName === 'INPUT' ||
                    e.target.tagName === 'TEXTAREA' ||
                    e.target.tagName === 'SELECT' ||
                    e.target.tagName === 'A' ||
                    e.target.closest('button') ||
                    e.target.closest('a') ||
                    e.target.closest('.ff-btn-submit') || // Fluent Forms submit button
                    e.target.closest('form')) {
                    return; // Let the form handle it
                }
                if (e.target === container) closeFloating(n.id, isCenter);
            });
        } else {
            // For corners, check if region container exists
            let regionClass = `nc-region-${pos.replace('_', '-')}`; // bottom_right -> bottom-right
            container = document.querySelector(`.${regionClass}`);
            if (!container) {
                container = document.createElement('div');
                container.className = `nc-region ${regionClass}`;
                document.body.appendChild(container);
            }
        }

        // Create Elements
        const el = document.createElement('div');
        el.className = `nc-floating nc-pos-${pos.replace('_', '-')}`;
        el.dataset.id = n.id;
        // Floating always dismissible

        // Apply width
        if (n.settings.floating_width > 0) {
            el.style.width = `${n.settings.floating_width}px`;
        }

        // Colors
        const g = ncData.globalColors || {};
        const s = n.settings.colors || {};
        const bg = s.bg || g.bg || '#ffffff';
        const text = s.text || g.text || '#1d1d1f';
        const btnBg = s.btn_bg || g.btnBg || '#007AFF';
        const btnText = s.btn_text || g.btnText || '#ffffff';

        el.style.backgroundColor = bg;
        el.style.color = text;

        // Content
        const btnStyle = `background-color:${btnBg}; color:${btnText}; padding:6px 12px; border-radius:4px; text-decoration:none; display:inline-block; font-size:13px; margin-top:8px;`;

        // Icon
        const renderIcon = (icon) => {
            if (!icon) return '';
            if (icon.indexOf('.') > -1 || icon.indexOf('/') > -1) {
                return `<img src="${icon}" style="width:40px; height:40px; border-radius:6px; object-fit:cover; display:block;" alt="">`;
            }
            if (icon.startsWith('dashicons-')) return `<span class="dashicons ${icon}" style="font-size:32px; width:40px; height:40px; line-height:40px; text-align:center;"></span>`;
            return `<span style="font-size:32px; width:40px; height:40px; line-height:40px; text-align:center;">${icon}</span>`;
        };
        const iconHtml = n.icon ? `<div class="nc-floating-icon" style="margin-right:12px;">${renderIcon(n.icon)}</div>` : '';

        // Layout varies slightly for center vs corner?
        // Reuse generic structure

        // Budujemy HTML
        const floatingHTML = `
            <div class="nc-floating-header">
                ${iconHtml}
                <div style="flex-grow:1;">
                    <div class="nc-floating-title">${n.title}</div>
                    <div class="nc-floating-body" style="font-size:13px; opacity:0.9; margin-top:4px;">${n.body}</div>
                    ${n.settings.countdown && n.settings.countdown.enabled ? renderCountdownHTML(n.settings.countdown, true) : ''}
                    ${n.cta_label ? `<a href="${n.cta_url}" class="nc-floating-btn" style="${btnStyle}">${n.cta_label}</a>` : ''}
                </div>
                <button class="nc-floating-close">&times;</button>
            </div>
        `;

        // Wstawiamy HTML (inline scripts nie będą wykonane przez innerHTML)
        el.innerHTML = floatingHTML;

        // Append do DOM
        container.appendChild(el);

        // KRYTYCZNE: Inicjalizacja Fluent Forms ПОСЛЕ dodania do DOM
        if (typeof window.ncInitFluentForms === 'function') {
            window.ncInitFluentForms(el);
        }

        // Close event
        el.querySelector('.nc-floating-close').addEventListener('click', (e) => {
            e.stopPropagation();
            closeFloating(n.id, isCenter);
        });

        // Prevent clicks inside the popup from propagating to the overlay (which triggers close)
        el.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        // Auto-close duration
        const duration = (parseInt(n.settings.floating_duration) || 0) * 1000;
        if (duration > 0) {
            setTimeout(() => {
                // double check existence
                if (document.body.contains(el)) closeFloating(n.id, isCenter);
            }, duration);
        }
    }

    function closeFloating(id, isCenter) {
        if (isCenter) {
            const overlay = document.querySelector(`.nc-pos-center-overlay[data-id="${id}"]`);
            if (overlay) {
                overlay.style.animation = 'ncFadeOut 0.3s forwards';
                setTimeout(() => overlay.remove(), 300);
            }
        } else {
            const el = document.querySelector(`.nc-floating[data-id="${id}"]`);
            if (el) {
                el.style.animation = 'ncFadeOut 0.3s forwards';
                setTimeout(() => el.remove(), 300);
            }
        }
        dismissToastItem(id);

        // Clear active and show next from global queue
        if (activeFloatingId === id) {
            activeFloatingId = null;

            // Show next from queue after animation completes
            setTimeout(() => {
                showNextFromGlobalQueue();
            }, 400);
        }
    }

    function markAsRead(id) {
        if (!readIds.includes(id)) {
            readIds.push(id);
            localStorage.setItem('nc_read_ids', JSON.stringify(readIds));
            renderAll();
        }
    }

    function markAllAsRead() {
        notifications.forEach(n => {
            if (!readIds.includes(n.id)) readIds.push(n.id);
        });
        localStorage.setItem('nc_read_ids', JSON.stringify(readIds));
        renderAll();
    }

    function dismissItem(id) {
        // Upgrade flow: if array, convert to map
        let store = dismissedIds;
        if (Array.isArray(store)) {
            store = {};
        }

        ncLog('NC: Dismissing item', id);
        store[id] = Date.now();
        dismissedIds = store; // Update global
        localStorage.setItem('nc_dismissed_ids', JSON.stringify(store));
        renderAll();
    }

    function dismissToastItem(id) {
        // Save dismissed toast/floating notification to localStorage
        ncLog('NC: Dismissing toast/floating item', id);
        dismissedToastIds[id] = Date.now();
        localStorage.setItem('nc_dismissed_toast_ids', JSON.stringify(dismissedToastIds));
    }

    let activeToastCount = 0; // Track active toasts for listener management

    function checkListeners() {
        if (ncData.displayMode !== 'dropdown') return;
        const drawerOpen = drawer.style.display === 'block';

        if (drawerOpen || activeToastCount > 0) {
            updateDropdownPosition();
            window.addEventListener('scroll', updateDropdownPosition);
            window.addEventListener('resize', updateDropdownPosition);
        } else {
            window.removeEventListener('scroll', updateDropdownPosition);
            window.removeEventListener('resize', updateDropdownPosition);
            // reset toast container position just in case
        }
    }

    function toggleDrawer(e) {
        if (e && e.preventDefault) e.preventDefault();

        // Check Display Mode
        const mode = ncData.displayMode || 'drawer';

        if (mode === 'dropdown') {
            drawer.classList.add('nc-mode-dropdown');

            // Toggle visibility logic
            const isOpen = drawer.style.display === 'block';

            if (isOpen) {
                drawer.style.display = 'none';
                overlay.classList.remove('open');
                document.body.classList.remove('nc-scroll-lock');
            } else {
                drawer.style.display = 'block';
                overlay.classList.add('open');
                document.body.classList.add('nc-scroll-lock');

                // User Request: Closing toasts when drawer opens
                document.querySelectorAll('.nc-toast').forEach(toast => {
                    const id = toast.dataset.id;
                    if (id) dismissToastItem(id);
                });
            }
            checkListeners();
        } else {
            // Classic Drawer
            drawer.classList.remove('nc-mode-dropdown');
            drawer.classList.toggle('open');
            overlay.classList.toggle('open');

            if (drawer.classList.contains('open')) {
                document.body.classList.add('nc-scroll-lock');
            } else {
                document.body.classList.remove('nc-scroll-lock');
            }

            // Allow closing toasts in classic mode too if desired? 
            // User asked "klikanie dzwoneczka bylo tez trger na zamkniecie toast powiadomin"
            // Let's add it here too for consistency
            if (drawer.classList.contains('open')) {
                document.querySelectorAll('.nc-toast').forEach(toast => {
                    const id = toast.dataset.id;
                    if (id) dismissToastItem(id);
                });
            }
        }
    }

    // Logic to calculate position
    function updateDropdownPosition() {
        if (!bellContainer) return;

        const rect = bellContainer.getBoundingClientRect();
        const drawerWidth = 350;

        // Vertical: under the bell + gap
        const top = window.scrollY + rect.bottom + 15;

        // Horizontal: right-aligned to bell (standard for dropdowns)
        let left = window.scrollX + rect.right - drawerWidth;

        // Boundary checks
        if (left < 10) left = 10;

        // Only update drawer position if it is open (visible)
        // This prevents moving the drawer onto screen if it's in "hidden sidebar" state
        if (drawer && drawer.style.display === 'block') {
            drawer.style.top = `${top}px`;
            drawer.style.left = `${left}px`;
        }

        // Update Toast Container position too if in dropdown mode
        const toastContainer = document.getElementById('nc-toast-container');
        if (toastContainer && toastContainer.classList.contains('nc-mode-dropdown')) {
            toastContainer.style.top = `${top}px`;
            toastContainer.style.left = `${left}px`;
        }
    }

    // Helper to try and get Post ID from DOM or global var
    function getPostId() {
        // Often themes put body class page-id-X or postid-X
        const classes = document.body.className.split(' ');
        for (let c of classes) {
            if (c.startsWith('postid-') || c.startsWith('page-id-')) {
                return c.replace(/\D/g, '');
            }
        }
        return 0;
    }

    // ============================================
    // TOP BAR - Pasek nad headerem
    // ============================================
    const topBarContainer = document.getElementById('nc-topbar');
    let topBarItems = [];
    let topBarCurrentIndex = 0;
    let topBarInterval = null;
    const topBarDismissedKey = 'nc_topbar_dismissed';

    function getTopBarDismissed() {
        try {
            return JSON.parse(localStorage.getItem(topBarDismissedKey) || '{}');
        } catch {
            return {};
        }
    }

    function renderTopBar() {
        if (!topBarContainer) return;
        // Check global disable setting (inverted from old enabled logic)
        if (ncData.topBar && ncData.topBar.disabled) {
            topBarContainer.style.display = 'none';
            document.body.classList.remove('nc-topbar-active');
            return;
        }

        // Filter notifications for topbar
        const dismissed = getTopBarDismissed();
        topBarItems = notifications.filter(n => {
            if (!n.settings.topbar) return false;

            // Check if dismissed
            const dismissedAt = dismissed[n.id] || 0;
            if (!dismissedAt) return true;

            // Check repeat policy
            if (n.settings.repeat_val > 0) {
                let multiplier = 1000 * 60;
                if (n.settings.repeat_unit === 'hours') multiplier *= 60;
                if (n.settings.repeat_unit === 'days') multiplier *= 60 * 24;
                if (Date.now() > dismissedAt + (n.settings.repeat_val * multiplier)) {
                    return true;
                }
            }
            return false;
        });

        if (topBarItems.length === 0) {
            topBarContainer.style.display = 'none';
            document.body.classList.remove('nc-topbar-active');
            stopTopBarRotation();
            return;
        }

        // Build HTML
        const config = ncData.topBar;
        let html = '<div class="nc-topbar-inner">';

        // Dots indicator (only if multiple items)
        if (topBarItems.length > 1) {
            html += '<div class="nc-topbar-dots">';
            topBarItems.forEach((_, i) => {
                html += `<span class="nc-topbar-dot${i === 0 ? ' active' : ''}" data-index="${i}"></span>`;
            });
            html += '</div>';
        }

        // Items
        topBarItems.forEach((n, i) => {
            const activeClass = i === 0 ? 'active' : '';
            html += `<div class="nc-topbar-item ${activeClass}" data-id="${n.id}">`;
            html += `<span class="nc-topbar-title">${n.title}</span>`;
            if (n.settings.countdown && n.settings.countdown.enabled) {
                html += renderCountdownHTML(n.settings.countdown, false);
            }
            if (n.cta_label && n.cta_url) {
                html += `<a href="${n.cta_url}" class="nc-topbar-btn">${n.cta_label}</a>`;
            }
            html += '</div>';
        });

        // Close button - show if allowed and not permanent
        const isPermanent = topBarItems.some(n => n.settings.topbar_permanent === '1');
        // config is already defined above

        if (!isPermanent && config.dismissible) {
            html += '<button class="nc-topbar-close" title="Zamknij">&times;</button>';
        }

        html += '</div>';

        topBarContainer.innerHTML = html;
        topBarContainer.style.display = 'flex';
        document.body.classList.add('nc-topbar-active');
        topBarCurrentIndex = 0;

        // Toggle sticky class based on setting
        topBarContainer.classList.toggle('nc-topbar-sticky', config.sticky);

        // Compact mode - check if any topbar item has compact style
        const hasCompactStyle = topBarItems.some(n => n.settings.topbar_style === 'compact');
        topBarContainer.classList.toggle('nc-topbar-compact', hasCompactStyle);

        // Handle position (above/below header)
        // If any topbar item has position 'below', position the entire bar below header
        const hasBelowPosition = topBarItems.some(n => n.settings.topbar_position === 'below');
        topBarContainer.classList.toggle('nc-topbar-below-header', hasBelowPosition);

        // Move container in DOM if needed for 'below' positioning
        if (hasBelowPosition) {
            // Try to find header element and insert after it - extended selectors for different themes
            const headerSelectors = [
                '.elementor-location-header',  // Elementor
                'header[data-elementor-type="header"]',  // Elementor alt
                '.site-header',  // Common theme class
                '#masthead',  // Common theme ID
                'header',  // Standard HTML5
                '.header',
                '#header',
                '[role="banner"]'
            ];

            let header = null;
            for (const selector of headerSelectors) {
                header = document.querySelector(selector);
                if (header) break;
            }

            if (header && header.parentNode) {
                header.parentNode.insertBefore(topBarContainer, header.nextSibling);
                ncLog('NC: Top Bar inserted below header', header);
            } else {
                ncLog('NC: Could not find header element for below positioning');
            }
        }

        // Add close listener
        const closeBtn = topBarContainer.querySelector('.nc-topbar-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', dismissTopBar);
        }

        // Start rotation if multiple items
        if (topBarItems.length > 1) {
            startTopBarRotation();
        }
    }

    function startTopBarRotation() {
        stopTopBarRotation();
        const speed = ncData.topBar.rotationSpeed || 5000;

        topBarInterval = setInterval(() => {
            rotateTopBar();
        }, speed);
    }

    function stopTopBarRotation() {
        if (topBarInterval) {
            clearInterval(topBarInterval);
            topBarInterval = null;
        }
    }

    function rotateTopBar() {
        if (topBarItems.length <= 1) return;

        const items = topBarContainer.querySelectorAll('.nc-topbar-item');
        const dots = topBarContainer.querySelectorAll('.nc-topbar-dot');
        const currentItem = items[topBarCurrentIndex];

        // Calculate next index
        const nextIndex = (topBarCurrentIndex + 1) % topBarItems.length;
        const nextItem = items[nextIndex];

        // Animate out current (slide up)
        currentItem.classList.remove('active');
        currentItem.classList.add('exit');

        // Animate in next (slide from bottom)
        nextItem.classList.remove('exit');
        nextItem.classList.add('active');

        // Update dots
        dots.forEach((dot, i) => {
            dot.classList.toggle('active', i === nextIndex);
        });

        // Clean up exit class after animation
        setTimeout(() => {
            currentItem.classList.remove('exit');
        }, 400);

        topBarCurrentIndex = nextIndex;
    }

    function dismissTopBar() {
        // Dismiss all topbar items
        const dismissed = getTopBarDismissed();
        topBarItems.forEach(n => {
            dismissed[n.id] = Date.now();
        });
        localStorage.setItem(topBarDismissedKey, JSON.stringify(dismissed));

        // Hide the bar
        topBarContainer.style.display = 'none';
        document.body.classList.remove('nc-topbar-active');
        stopTopBarRotation();
    }

    // ============================================
    // COUNTDOWN TIMER
    // ============================================
    function getCountdownTarget(countdown) {
        if (!countdown || !countdown.enabled) return null;

        let target;

        if (countdown.type === 'date' && countdown.date) {
            // Specific date/time
            target = new Date(countdown.date).getTime();
        } else if (countdown.type === 'daily' && countdown.time) {
            // Daily - today at specified time
            const [hours, minutes] = countdown.time.split(':').map(Number);
            const now = new Date();
            target = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hours, minutes, 0).getTime();

            // If already passed today, set for tomorrow
            if (target < Date.now()) {
                target += 24 * 60 * 60 * 1000;
            }
        }

        return target || null;
    }

    function calculateTimeLeft(targetMs) {
        const now = Date.now();
        const diff = targetMs - now;

        if (diff <= 0) {
            return { expired: true, days: 0, hours: 0, minutes: 0, seconds: 0 };
        }

        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);

        return { expired: false, days, hours, minutes, seconds };
    }

    function renderCountdownHTML(countdown, includeLabel = true) {
        const target = getCountdownTarget(countdown);
        if (!target) return '';

        const time = calculateTimeLeft(target);
        const label = countdown.label && includeLabel ? `<span class="nc-countdown-label">${countdown.label}</span>` : '';
        const showUnits = ncData.countdown?.showUnits !== false;

        const pad = n => String(n).padStart(2, '0');

        let html = `<div class="nc-countdown${time.expired ? ' expired' : ''}" data-target="${target}" data-type="${countdown.type}" data-time="${countdown.time || ''}">`;
        html += label;
        html += '<div class="nc-countdown-timer">';

        if (time.days > 0) {
            html += `<div class="nc-countdown-segment"><span class="nc-countdown-value nc-cd-days">${time.days}</span>${showUnits ? '<span class="nc-countdown-unit">dni</span>' : ''}</div>`;
            html += '<span class="nc-countdown-separator">:</span>';
        }

        html += `<div class="nc-countdown-segment"><span class="nc-countdown-value nc-cd-hours">${pad(time.hours)}</span>${showUnits ? '<span class="nc-countdown-unit">godz</span>' : ''}</div>`;
        html += '<span class="nc-countdown-separator">:</span>';
        html += `<div class="nc-countdown-segment"><span class="nc-countdown-value nc-cd-minutes">${pad(time.minutes)}</span>${showUnits ? '<span class="nc-countdown-unit">min</span>' : ''}</div>`;
        html += '<span class="nc-countdown-separator">:</span>';
        html += `<div class="nc-countdown-segment"><span class="nc-countdown-value nc-cd-seconds">${pad(time.seconds)}</span>${showUnits ? '<span class="nc-countdown-unit">sek</span>' : ''}</div>`;

        html += '</div></div>';
        return html;
    }

    // Global countdown ticker
    function startCountdownTicker() {
        setInterval(() => {
            document.querySelectorAll('.nc-countdown').forEach(el => {
                let target = parseInt(el.dataset.target);

                // For daily type, check if we need to reset
                if (el.dataset.type === 'daily' && target < Date.now()) {
                    const [hours, minutes] = (el.dataset.time || '10:00').split(':').map(Number);
                    const now = new Date();
                    target = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hours, minutes, 0).getTime();
                    if (target < Date.now()) target += 24 * 60 * 60 * 1000;
                    el.dataset.target = target;
                }

                const time = calculateTimeLeft(target);
                const pad = n => String(n).padStart(2, '0');

                const daysEl = el.querySelector('.nc-cd-days');
                const hoursEl = el.querySelector('.nc-cd-hours');
                const minutesEl = el.querySelector('.nc-cd-minutes');
                const secondsEl = el.querySelector('.nc-cd-seconds');

                if (daysEl) daysEl.textContent = time.days;
                if (hoursEl) hoursEl.textContent = pad(time.hours);
                if (minutesEl) minutesEl.textContent = pad(time.minutes);
                if (secondsEl) secondsEl.textContent = pad(time.seconds);

                el.classList.toggle('expired', time.expired);
            });
        }, 1000);
    }

    // Start the countdown ticker
    startCountdownTicker();

    // Helper: Relative Time
    function timeAgo(dateString) {
        if (!dateString) return '';

        // Fix for Safari: replace dashes with slashes if needed or standard ISO
        const d = new Date(dateString.replace(/-/g, '/'));
        const now = new Date();
        const seconds = Math.floor((now - d) / 1000);

        if (isNaN(seconds)) return dateString;

        const interval = Math.floor(seconds / 31536000);
        if (interval > 1) return interval + " lat temu";

        const intervalMonth = Math.floor(seconds / 2592000);
        if (intervalMonth > 1) return intervalMonth + " miesięcy temu";

        const intervalDay = Math.floor(seconds / 86400);
        if (intervalDay > 1) return intervalDay + " dni temu";
        if (intervalDay === 1) return "Wczoraj";

        const intervalHour = Math.floor(seconds / 3600);
        if (intervalHour >= 1) return intervalHour + (intervalHour === 1 ? " godzinę temu" : (intervalHour < 5 ? " godziny temu" : " godzin temu"));

        const intervalMinute = Math.floor(seconds / 60);
        if (intervalMinute >= 1) return intervalMinute + (intervalMinute === 1 ? " minutę temu" : (intervalMinute < 5 ? " minuty temu" : " minut temu"));

        return "Przed chwilą";
    }

    function playNotificationSound() {
        if (!ncData.enableSound) return;
        // Demo sound (Bell)
        const audio = new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3');
        audio.volume = 0.5;
        audio.play().catch(() => { });
    }

})();
