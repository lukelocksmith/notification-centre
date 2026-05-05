// @ts-check
/**
 * Cache / Activation test
 * Sprawdza czy deaktywacja powiadomienia od razu usuwa je z REST API i frontendu.
 * Run: cd tests/e2e && npx playwright test cache-test.spec.js
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const DDEV_ROOT = require('path').resolve(__dirname, '../../../../../..');

function wp(...args) {
    const out = execFileSync('ddev', ['wp', ...args], { encoding: 'utf8', cwd: DDEV_ROOT }).trim();
    // LiteSpeed Cache adds "Success: Purged all caches successfully." lines — take last line only
    return out.split('\n').pop().trim();
}

function createNotif() {
    const php = `
        $id = wp_insert_post([
            'post_type'   => 'nc_notification',
            'post_status' => 'publish',
            'post_title'  => 'TEST-CACHE-POPUP',
        ]);
        update_post_meta($id, 'nc_show_as_floating',  '1');
        update_post_meta($id, 'nc_floating_position', 'center');
        update_post_meta($id, 'nc_title',             'TEST-CACHE-POPUP');
        update_post_meta($id, 'nc_trigger_delay',     '1');
        update_post_meta($id, 'nc_floating_delay',    '1');
        update_post_meta($id, 'nc_audience',          'all');
        echo $id;
    `;
    return parseInt(wp('eval', php), 10);
}

/** Sprawdza REST API czy powiadomienie o danym ID jest w odpowiedzi */
async function isInApi(page, id) {
    const url = page.url().split('?')[0];
    const apiUrl = `/wp-json/nc/v1/notifications?url=${encodeURIComponent(url)}&post_id=0&user_id=0`;
    const resp = await page.request.get(apiUrl);
    const data = await resp.json();
    return Array.isArray(data) && data.some(n => n.id === id);
}

/** Sprawdza DOM czy popup konkretnego powiadomienia jest widoczny */
async function isPopupVisible(page, id) {
    return page.evaluate((nid) => {
        const overlays = document.querySelectorAll('.nc-pos-center-overlay');
        for (const overlay of overlays) {
            const floatEl = overlay.closest('[data-nc-id]') || overlay.querySelector('[data-nc-id]');
            if (floatEl && parseInt(floatEl.dataset.ncId) === nid) return true;
        }
        // fallback: szukaj po tytule w dowolnym floating
        const title = document.querySelector('.nc-floating-title');
        return title?.textContent?.includes('TEST-CACHE-POPUP') || false;
    }, id);
}

test.describe('Cache / Aktywacja popup', () => {
    let notifId;

    test.beforeAll(() => {
        notifId = createNotif();
    });

    test.afterAll(() => {
        try { wp('post', 'delete', String(notifId), '--force'); } catch {}
    });

    test('1. powiadomienie w API gdy aktywne (publish)', async ({ page }) => {
        await page.goto('/');
        const inApi = await isInApi(page, notifId);
        expect(inApi, `ID ${notifId} powinno być w API`).toBe(true);
    });

    test('2. po odświeżeniu popup pojawia się na stronie', async ({ page }) => {
        await page.goto('/');
        await page.waitForTimeout(3000);
        const visible = await isPopupVisible(page, notifId);
        expect(visible, 'Popup powinien być widoczny').toBe(true);
    });

    test('3. po deaktywacji (draft) znika z API natychmiast', async ({ page }) => {
        wp('post', 'update', String(notifId), '--post_status=draft');
        await page.goto('/');
        const inApi = await isInApi(page, notifId);
        expect(inApi, `ID ${notifId} NIE powinno być w API po deaktywacji`).toBe(false);
    });

    test('4. po ponownej aktywacji wraca do API', async ({ page }) => {
        wp('post', 'update', String(notifId), '--post_status=publish');
        await page.goto('/');
        const inApi = await isInApi(page, notifId);
        expect(inApi, `ID ${notifId} powinno wrócić do API`).toBe(true);
    });

});
