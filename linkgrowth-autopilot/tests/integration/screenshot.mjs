// Logs into a local WordPress install and screenshots every LinkGrowth Autopilot admin page.
// Usage: node tests/integration/screenshot.mjs http://localhost:8793 admin 'adminpass123!' /output/dir
import fs from 'node:fs';
import { createRequire } from 'node:module';
// Resolve the pre-installed Playwright through CommonJS resolution (honours NODE_PATH).
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const [base = 'http://localhost:8793', user = 'admin', pass = 'adminpass123!', out = './screenshots'] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const pages = ['lga', 'lga-prospects', 'lga-submissions', 'lga-outreach', 'lga-backlinks', 'lga-seo', 'lga-automation', 'lga-settings', 'lga-logs'];
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
page.on('console', (m) => { if (m.type() === 'error') errors.push(`console: ${m.text()}`); });
await page.goto(`${base}/wp-login.php`);
await page.fill('#user_login', user);
await page.fill('#user_pass', pass);
await page.click('#wp-submit');
await page.waitForLoadState('domcontentloaded');
await page.waitForTimeout(1500);
// WordPress redirects to its configured home host after login (e.g. localhost vs 127.0.0.1); follow it.
let origin = base;
try { const u = new URL(page.url()); if (u.pathname.includes('/wp-admin')) origin = u.origin; } catch (e) {}
if (!page.url().includes('/wp-admin')) { console.log('LOGIN FAILED: ' + page.url()); await browser.close(); process.exit(1); }
console.log('Logged in; admin origin ' + origin);
for (const slug of pages) {
  await page.goto(`${origin}/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#lga-app', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.screenshot({ path: `${out}/${slug}.png`, fullPage: true });
  const text = (await page.textContent('#lga-app').catch(() => '')) || '';
  console.log(`${slug}: ${text.replace(/\s+/g, ' ').trim().slice(0, 160)}`);
}
// Dark theme sample.
await page.goto(`${origin}/wp-admin/admin.php?page=lga`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
await page.evaluate(() => { const w = document.querySelector('.lga-wrap'); if (w) w.setAttribute('data-theme', 'dark'); });
await page.waitForTimeout(500);
await page.screenshot({ path: `${out}/lga-dark.png`, fullPage: true });
await browser.close();
if (errors.length) { console.log('BROWSER ERRORS:'); for (const e of errors) console.log(' - ' + e); process.exitCode = 1; } else { console.log('No browser errors'); }
