// @ts-check
/**
 * NC E2E Test Suite (Playwright)
 * Run: cd tests/e2e && npm install && npx playwright test
 *
 * Requires a running DDEV environment:
 *   ddev start  (from ~/Projects/agencyjnie)
 */

const { test, expect } = require('@playwright/test');
const { execFileSync }  = require('child_process');

// ─── WP-CLI helpers ───────────────────────────────────────────────────────────

const DDEV_ROOT = require('path').resolve(__dirname, '../../../../../..');

/**
 * Run `ddev wp <args>` synchronously and return trimmed stdout.
 * Uses execFileSync with an argument array — no shell, no injection risk.
 */
function wp(...args) {
    const out = execFileSync('ddev', ['wp', ...args], {
        encoding: 'utf8',
        cwd: DDEV_ROOT,
    }).trim();
    // LiteSpeed Cache prepends "Success: Purged all caches successfully." — take last line only
    return out.split('\n').pop().trim();
}

/**
 * Create a published nc_notification in ONE wp eval call (fast).
 * Array meta values are stored as PHP arrays.
 * Returns the new post ID (integer).
 */
function createNotif(meta = {}) {
    const defaults = {
        nc_audience:          'all',
        nc_active_from:       '',
        nc_active_to:         '',
        nc_countdown_enabled: '',
        nc_show_as_topbar:    '',
        nc_topbar_permanent:  '',
        nc_topbar_sticky:     '',
        nc_pinned:            '',
        nc_dismissible:       '1',
        nc_show_in_sidebar:   '1',  // required for sidebar rendering; empty string → JS hides it
    };

    const allMeta = { ...defaults, ...meta };

    // Build all update_post_meta calls as PHP snippet
    const metaPhp = Object.entries(allMeta).map(([key, val]) => {
        if (Array.isArray(val)) {
            const phpArr = val.length === 0
                ? 'array()'
                : `array(${val.map(v => `'${v}'`).join(',')})`;
            return `update_post_meta($id,'${key}',${phpArr});`;
        }
        const escaped = String(val).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        return `update_post_meta($id,'${key}','${escaped}');`;
    }).join('');

    const id = parseInt(wp('eval',
        `$id=wp_insert_post(['post_type'=>'nc_notification','post_status'=>'publish','post_title'=>'TEST-NC-E2E-'.uniqid()]);` +
        metaPhp +
        `update_option('nc_cache_version',time(),false);echo $id;`
    ));

    return id;
}

/**
 * Hard-delete a post and bump cache version — single wp eval call.
 */
function deleteNotif(id) {
    wp('eval', `wp_delete_post(${id},true);update_option('nc_cache_version',time(),false);`);
}

/**
 * Set post meta via `wp eval` — for nested arrays/objects (e.g. nc_rules_data).
 * phpValue is a PHP expression string, e.g. "array(array('mode'=>'show',...))"
 * Also bumps nc_cache_version.
 */
function setMetaEval(id, key, phpValue) {
    wp('eval', `update_post_meta(${id},'${key}',${phpValue});update_option('nc_cache_version',time(),false);`);
}

/** Get WP current mysql time as unix timestamp */
function wpNowTs() {
    return parseInt(wp('eval', 'echo strtotime(current_time("mysql"));'));
}

/** Format a unix timestamp as MySQL datetime string (WP timezone) */
function wpDate(ts) {
    return wp('eval', `echo date('Y-m-d H:i:s',${ts});`);
}

// ─── Global setup/teardown ────────────────────────────────────────────────────

// Clean up any leftover TEST-NC-E2E-* posts from previous aborted runs
test.beforeAll(async () => {
    wp('eval',
        `global $wpdb;` +
        `$ids=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='nc_notification' AND post_title LIKE 'TEST-NC-E2E-%'");` +
        `foreach($ids as $id) wp_delete_post($id,true);` +
        `if($ids) update_option('nc_cache_version',time(),false);`
    );
});

// ─── Utilities ────────────────────────────────────────────────────────────────

async function getNotifData(page, postId) {
    return page.evaluate((id) => {
        const data = (window.ncData && window.ncData.notifications) || [];
        return data.find(n => n.id === id) || null;
    }, postId);
}

// ─── Test: Topbar ─────────────────────────────────────────────────────────────

test.describe('Topbar', () => {

    test('renders when nc_show_as_topbar is enabled', async ({ page }) => {
        const id = createNotif({ nc_show_as_topbar: '1', nc_topbar_position: 'above' });
        try {
            await page.goto('/');
            await expect(page.locator('.nc-topbar')).toBeVisible({ timeout: 8_000 });
        } finally {
            deleteNotif(id);
        }
    });

    test('permanent topbar has no close button', async ({ page }) => {
        const id = createNotif({ nc_show_as_topbar: '1', nc_topbar_permanent: '1' });
        try {
            await page.goto('/');
            await expect(page.locator('.nc-topbar')).toBeVisible({ timeout: 8_000 });
            // X button must NOT exist when permanent
            await expect(page.locator('.nc-topbar .nc-topbar-close')).toHaveCount(0);
        } finally {
            deleteNotif(id);
        }
    });

    test('non-permanent topbar shows close button', async ({ page }) => {
        const id = createNotif({ nc_show_as_topbar: '1', nc_topbar_permanent: '' });
        try {
            await page.goto('/');
            await expect(page.locator('.nc-topbar')).toBeVisible({ timeout: 8_000 });
            await expect(page.locator('.nc-topbar .nc-topbar-close')).toBeVisible();
        } finally {
            deleteNotif(id);
        }
    });

    test('clicking close dismisses the non-permanent item', async ({ page }) => {
        const id = createNotif({ nc_show_as_topbar: '1', nc_topbar_permanent: '' });
        try {
            await page.goto('/');
            await expect(page.locator('.nc-topbar')).toBeVisible({ timeout: 8_000 });
            await page.locator('.nc-topbar .nc-topbar-close').click();
            // After dismissal, dismissed item should no longer be in topbar DOM
            await expect(page.locator(`.nc-topbar-item[data-id="${id}"]`)).not.toBeVisible({ timeout: 5_000 });
        } finally {
            deleteNotif(id);
        }
    });

    test('sticky topbar has position:sticky CSS', async ({ page }) => {
        wp('option', 'update', 'nc_topbar_sticky', '1');
        const id = createNotif({ nc_show_as_topbar: '1' });
        try {
            await page.goto('/');
            await expect(page.locator('.nc-topbar')).toBeVisible({ timeout: 8_000 });
            const position = await page.locator('.nc-topbar').evaluate(el => {
                return getComputedStyle(el).position;
            });
            expect(position).toBe('sticky');
        } finally {
            deleteNotif(id);
            wp('option', 'update', 'nc_topbar_sticky', '');
        }
    });

    test('topbar flag is false when nc_show_as_topbar is off', async ({ page }) => {
        const id = createNotif({ nc_show_as_topbar: '' });
        try {
            await page.goto('/');
            await page.waitForTimeout(2_000);
            const n = await getNotifData(page, id);
            expect(n).not.toBeNull();
            // Our notification's settings.topbar must be false — DOM may still
            // contain topbars from other notifications on this test site.
            expect(n.settings.topbar).toBe(false);
        } finally {
            deleteNotif(id);
        }
    });
});

// ─── Test: Time Restrictions ──────────────────────────────────────────────────

test.describe('Time Restrictions', () => {

    test('active_from in future → notification hidden', async ({ page }) => {
        const id = createNotif({ nc_active_from: wpDate(wpNowTs() + 3600) });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('active_to in past → notification hidden', async ({ page }) => {
        const id = createNotif({ nc_active_to: wpDate(wpNowTs() - 3600) });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('within active date range → notification visible', async ({ page }) => {
        const now = wpNowTs();
        const id = createNotif({ nc_active_from: wpDate(now - 3600), nc_active_to: wpDate(now + 3600) });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('no date range → notification visible', async ({ page }) => {
        const id = createNotif();
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('expired date range → notification hidden', async ({ page }) => {
        const now = wpNowTs();
        const id = createNotif({ nc_active_from: wpDate(now - 7200), nc_active_to: wpDate(now - 3600) });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });
});

// ─── Test: Audience ───────────────────────────────────────────────────────────

test.describe('Audience', () => {

    test('guests-only notification appears for anonymous visitor', async ({ page }) => {
        const id = createNotif({ nc_audience: 'guests' });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            const n = await getNotifData(page, id);
            // Anonymous visitor gets inlined __ncData
            expect(n).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('logged_in notification is absent for anonymous visitor', async ({ page }) => {
        const id = createNotif({ nc_audience: 'logged_in' });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            const n = await getNotifData(page, id);
            expect(n).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });
});

// ─── Test: Bell & Sidebar ─────────────────────────────────────────────────────

test.describe('Bell / Sidebar', () => {

    test('bell button is visible on page', async ({ page }) => {
        const id = createNotif();
        try {
            await page.goto('/');
            await expect(page.locator('#nc-bell-container')).toBeVisible({ timeout: 8_000 });
        } finally {
            deleteNotif(id);
        }
    });

    test('clicking bell opens the notification drawer', async ({ page }) => {
        const id = createNotif();
        try {
            await page.goto('/');
            await page.locator('#nc-bell-container').click();
            await expect(page.locator('#nc-drawer')).toBeVisible({ timeout: 5_000 });
        } finally {
            deleteNotif(id);
        }
    });

    test('notification appears inside open drawer', async ({ page }) => {
        const id = createNotif();
        try {
            await page.goto('/');
            await page.locator('#nc-bell-container').click();
            await expect(page.locator(`.nc-item[data-id="${id}"]`)).toBeVisible({ timeout: 5_000 });
        } finally {
            deleteNotif(id);
        }
    });

    test('sidebar_permanent notification has no dismiss button', async ({ page }) => {
        // nc_sidebar_permanent controls the X button in the drawer (not nc_pinned)
        const id = createNotif({ nc_sidebar_permanent: '1' });
        try {
            await page.goto('/');
            await page.locator('#nc-bell-container').click();
            const card = page.locator(`.nc-item[data-id="${id}"]`);
            await expect(card).toBeVisible({ timeout: 5_000 });
            await expect(card.locator('.nc-dismiss')).toHaveCount(0);
        } finally {
            deleteNotif(id);
        }
    });

    test('non-permanent notification can be dismissed', async ({ page }) => {
        const id = createNotif({ nc_sidebar_permanent: '' });
        try {
            await page.goto('/');
            await page.locator('#nc-bell-container').click();
            const card = page.locator(`.nc-item[data-id="${id}"]`);
            await expect(card).toBeVisible({ timeout: 5_000 });
            await card.locator('.nc-dismiss').click();
            await expect(card).not.toBeVisible();
        } finally {
            deleteNotif(id);
        }
    });

    test('badge is visible when unread notifications exist', async ({ page }) => {
        const id = createNotif();
        try {
            await page.goto('/');
            await expect(page.locator('.nc-badge')).toBeVisible({ timeout: 8_000 });
        } finally {
            deleteNotif(id);
        }
    });
});

// ─── Test: Admin Preview Mode ─────────────────────────────────────────────────

test.describe('Admin Preview Mode', () => {

    test('?nc_preview=1 does NOT bypass time restrictions for anonymous visitors', async ({ page }) => {
        const id = createNotif({ nc_active_from: wpDate(wpNowTs() + 3600) });
        try {
            await page.goto('/?nc_preview=1');
            await page.waitForTimeout(3_000);
            // Anonymous user should NOT see the future notification even with preview param
            const n = await getNotifData(page, id);
            expect(n).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });
});

// ─── Test: Day Exclusions ─────────────────────────────────────────────────────

test.describe('Day Exclusions', () => {

    test('current day excluded → notification hidden', async ({ page }) => {
        const today = wp('eval', 'echo date("N");'); // ISO 8601: 1=Mon … 7=Sun
        const id = createNotif({ nc_excluded_days: [today] });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('different day excluded → notification visible', async ({ page }) => {
        const today    = parseInt(wp('eval', 'echo date("N");'));
        const otherDay = String((today % 7) + 1); // next weekday (wraps Sun→Mon)
        const id = createNotif({ nc_excluded_days: [otherDay] });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('empty exclusion list → notification visible', async ({ page }) => {
        const id = createNotif({ nc_excluded_days: [] });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });
});


// ─── Test: Page Rules ─────────────────────────────────────────────────────────

test.describe('Page Rules', () => {

    test('show URL match → visible on matching path', async ({ page }) => {
        const id = createNotif();
        setMetaEval(id, 'nc_rules_data', `array(array('mode'=>'show','type'=>'url','value'=>'/shop/'))`);
        try {
            await page.goto('/shop/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('show URL match → hidden on non-matching path', async ({ page }) => {
        const id = createNotif();
        setMetaEval(id, 'nc_rules_data', `array(array('mode'=>'show','type'=>'url','value'=>'/shop/'))`);
        try {
            await page.goto('/blog/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('hide URL match → hidden on matching path', async ({ page }) => {
        const id = createNotif();
        setMetaEval(id, 'nc_rules_data', `array(array('mode'=>'hide','type'=>'url','value'=>'/cart/'))`);
        try {
            await page.goto('/cart/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('hide URL no match → visible on other path', async ({ page }) => {
        const id = createNotif();
        setMetaEval(id, 'nc_rules_data', `array(array('mode'=>'hide','type'=>'url','value'=>'/cart/'))`);
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('hide beats show on same URL', async ({ page }) => {
        const id = createNotif();
        setMetaEval(id, 'nc_rules_data', `array(
            array('mode'=>'show','type'=>'url','value'=>'/oferta/'),
            array('mode'=>'hide','type'=>'url','value'=>'/oferta/')
        )`);
        try {
            await page.goto('/oferta/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });
});


// ─── Test: Countdown Visibility ───────────────────────────────────────────────

test.describe('Countdown Visibility', () => {

    test('date countdown expired + autohide → hidden', async ({ page }) => {
        const past = wpDate(wpNowTs() - 3600);
        const id = createNotif({
            nc_countdown_enabled: '1',
            nc_countdown_type:    'date',
            nc_countdown_date:    past,
            nc_countdown_autohide:'1',
        });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('date countdown future + autohide → visible', async ({ page }) => {
        const future = wpDate(wpNowTs() + 3600);
        const id = createNotif({
            nc_countdown_enabled: '1',
            nc_countdown_type:    'date',
            nc_countdown_date:    future,
            nc_countdown_autohide:'1',
        });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('date countdown expired + no autohide → visible', async ({ page }) => {
        const past = wpDate(wpNowTs() - 3600);
        const id = createNotif({
            nc_countdown_enabled: '1',
            nc_countdown_type:    'date',
            nc_countdown_date:    past,
            nc_countdown_autohide:'',
        });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('daily countdown: start_time in past → visible', async ({ page }) => {
        const startPast = wp('eval', `echo date('H:i', strtotime(current_time('mysql')) - 7200);`);
        const id = createNotif({
            nc_countdown_enabled:     '1',
            nc_countdown_type:        'daily',
            nc_countdown_start_time:  startPast,
        });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).not.toBeNull();
        } finally {
            deleteNotif(id);
        }
    });

    test('daily countdown: start_time in future → hidden', async ({ page }) => {
        const startFuture = wp('eval', `echo date('H:i', strtotime(current_time('mysql')) + 7200);`);
        const id = createNotif({
            nc_countdown_enabled:     '1',
            nc_countdown_type:        'daily',
            nc_countdown_start_time:  startFuture,
        });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            expect(await getNotifData(page, id)).toBeNull();
        } finally {
            deleteNotif(id);
        }
    });
});


// ─── Test: Pinned Sort Order ──────────────────────────────────────────────────

test.describe('Pinned Sort Order', () => {

    test('pinned notification appears before non-pinned in drawer', async ({ page }) => {
        const idNormal = createNotif({ nc_pinned: '' });
        const idPinned = createNotif({ nc_pinned: '1' });
        try {
            await page.goto('/');
            await page.locator('#nc-bell-container').click();
            await expect(page.locator('#nc-drawer')).toBeVisible({ timeout: 5_000 });

            const cards = page.locator('.nc-item');
            await expect(cards.first()).toBeVisible({ timeout: 5_000 });

            const ids = await cards.evaluateAll(els =>
                els.map(el => parseInt(el.getAttribute('data-id')))
            );
            const posNormal = ids.indexOf(idNormal);
            const posPinned = ids.indexOf(idPinned);

            expect(posPinned).toBeGreaterThanOrEqual(0);
            expect(posNormal).toBeGreaterThanOrEqual(0);
            expect(posPinned).toBeLessThan(posNormal);
        } finally {
            deleteNotif(idNormal);
            deleteNotif(idPinned);
        }
    });
});


// ─── Test: API Response Shape ─────────────────────────────────────────────────

test.describe('API Response Shape', () => {

    test('notification object has required fields with correct types', async ({ page }) => {
        const id = createNotif({ nc_show_as_topbar: '1', nc_topbar_permanent: '1' });
        try {
            await page.goto('/');
            await page.waitForTimeout(3_000);
            const n = await getNotifData(page, id);
            expect(n).not.toBeNull();
            expect(typeof n.id).toBe('number');
            expect(typeof n.settings.topbar).toBe('boolean');
            expect(typeof n.settings.topbar_permanent).toBe('boolean');
            expect(typeof n.settings.dismissible).toBe('boolean');
            expect(typeof n.settings.pinned).toBe('boolean');
            expect(n.settings.colors).not.toBeNull();
            expect(typeof n.settings.colors).toBe('object'); // PHP assoc array → JSON object
            expect(n.settings.countdown).not.toBeNull();
            expect(n.settings.topbar).toBe(true);
            expect(n.settings.topbar_permanent).toBe(true);
        } finally {
            deleteNotif(id);
        }
    });
});
