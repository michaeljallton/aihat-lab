// Local walkthrough: drives every participant screen on a phone and a laptop
// against the local WordPress with dev/local-mocks.php installed.
// Usage: node dev/walkthrough.js http://127.0.0.1:8899 /tmp/wp/mail.log build/screens
const { chromium } = require('playwright');
const fs = require('fs');

const [base, mailLog, out] = process.argv.slice(2);
const EVENT = 'acme-kickoff-2027-01';
const EMAIL = 'pat.rivera@example.com';
const errors = [];

function lastCode() {
  const lines = fs.readFileSync(mailLog, 'utf8').trim().split('\n');
  for (let i = lines.length - 1; i >= 0; i--) {
    const m = JSON.parse(lines[i]);
    if (m.to === EMAIL || (Array.isArray(m.to) && m.to.includes(EMAIL))) {
      const c = /\b(\d{6})\b/.exec(m.subject);
      if (c) return c[1];
    }
  }
  throw new Error('no code mailed');
}

async function shot(page, name) {
  await page.screenshot({ path: `${out}/${name}.png`, fullPage: true });
  console.log('shot', name);
}

async function codeStep(page, prefix) {
  await page.waitForSelector('#ah-code');
  await shot(page, `${prefix}-03-code`);
  await page.fill('#ah-code', lastCode());
  await page.click('button[type=submit]');
}

(async () => {
  const browser = await chromium.launch();

  // Phone: sign up, confirm industry, enter tasks.
  const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  let page = await phone.newPage();
  page.on('pageerror', e => errors.push('phone: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('phone console: ' + m.text()); });
  await page.goto(`${base}/hire/?event=${EVENT}`);
  await page.waitForSelector('#ah-first_name');
  await shot(page, 'phone-01-signup');
  await page.click('button[type=submit]');
  await page.waitForSelector('.aihat-hire__msg');
  await shot(page, 'phone-02-signup-error');
  await page.fill('#ah-first_name', 'Pat');
  await page.fill('#ah-last_name', 'Rivera');
  await page.fill('#ah-email', EMAIL);
  await page.fill('#ah-title', 'Operations Manager');
  await page.fill('#ah-company', 'Northwind Logistics');
  await page.check('#ah-consent');
  await page.click('button[type=submit]');
  await codeStep(page, 'phone');
  await page.waitForSelector('#ah-industry', { timeout: 30000 });
  if (await page.inputValue('#ah-industry') !== 'Freight logistics') errors.push('industry suggestion not filled');
  await shot(page, 'phone-04-industry');
  await page.fill('#ah-industry', 'Freight logistics');
  await page.click('button[type=submit]');
  await page.waitForSelector('.aihat-hire__tabs');

  const tasks = [
    ['daily', 'Check carrier updates', '20', 'minutes', '3'],
    ['daily', 'Answer customer status emails', '10', 'minutes', '8'],
    ['daily', 'Update the TMS', '15', 'minutes', '4'],
    ['weekly', 'Build the weekly exceptions report', '2', 'hours', '1'],
    ['weekly', 'Quote new lanes', '45', 'minutes', '3'],
    ['weekly', 'Train new hires on the playbook', '1', 'hours', '1'],
  ];
  for (const [tab, name, amt, unit, freq] of tasks) {
    await page.click(`.aihat-hire__tab:has-text("${tab === 'daily' ? 'Daily' : 'Weekly'}")`);
    await page.click('button:has-text("Add a task")');
    const row = page.locator('.aihat-hire__task').last();
    await row.locator('[data-f=name]').fill(name);
    await row.locator('[data-f=amount]').fill(amt);
    await row.locator('[data-f=unit]').selectOption(unit);
    await row.locator('[data-f=frequency]').fill(freq);
    await row.locator('.aihat-hire__saved:has-text("Saved")').waitFor({ timeout: 10000 });
  }
  await page.click('.aihat-hire__tab:has-text("Daily")');
  const total = await page.textContent('.aihat-hire__total--all');
  console.log('phone total:', total);
  // Daily: 300 + 400 + 300 over a 5-day week; weekly: 120 + 135 + 60. 1315 min.
  if (!/21 hr 55 min/.test(total)) errors.push('unexpected all-tasks total: ' + total);
  await shot(page, 'phone-05-tasks');

  // Laptop: same email signs in on a second device and finishes.
  const laptop = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  await laptop.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: base });
  page = await laptop.newPage();
  page.on('pageerror', e => errors.push('laptop: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('laptop console: ' + m.text()); });
  await page.goto(`${base}/hire/?event=${EVENT}`);
  await page.click('button:has-text("Already have an account")');
  await page.fill('#ah-email', EMAIL);
  await shot(page, 'laptop-01-signin');
  await page.click('button[type=submit]');
  await codeStep(page, 'laptop');
  await page.waitForSelector('.aihat-hire__tabs');
  const rows = await page.locator('.aihat-hire__task').count();
  if (rows !== 3) errors.push('laptop daily tab should show 3 tasks entered on the phone, saw ' + rows);
  await shot(page, 'laptop-04-tasks');
  await page.click('button:has-text("Show my five crew members")');
  await page.waitForSelector('.aihat-hire__wait');
  await shot(page, 'laptop-05-generating');
  await page.waitForSelector('.aihat-hire__rec', { timeout: 60000 });
  const cards = await page.locator('.aihat-hire__rec').count();
  if (cards !== 5) errors.push('expected 5 cards, saw ' + cards);
  await page.waitForTimeout(800);
  await shot(page, 'laptop-06-recommendations');
  await page.locator('.aihat-hire__rec').first().locator('button:has-text("Hire this one")').click();
  await page.waitForSelector('.aihat-hire__prompt', { timeout: 60000 });
  const prompt = await page.inputValue('.aihat-hire__prompt');
  for (const must of ['Expert Prompt Engineer', 'Pat Rivera, Operations Manager at Northwind Logistics', 'Start by asking me your first question. Nothing else.']) {
    if (!prompt.includes(must)) errors.push('prompt missing: ' + must);
  }
  if (/[–—]/.test(prompt)) errors.push('prompt contains an em or en dash');
  if (/\{\{/.test(prompt)) errors.push('prompt has an unfilled placeholder');
  await page.click('button:has-text("Copy prompt")');
  await page.waitForSelector('button:has-text("Copied")');
  const clip = await page.evaluate(() => navigator.clipboard.readText());
  if (clip !== prompt) errors.push('clipboard does not match prompt');
  await page.click('button:has-text("Email me this prompt")');
  await page.waitForSelector('.aihat-hire__msg--ok');
  await shot(page, 'laptop-07-prompt');
  fs.writeFileSync(`${out}/sample-prompt.txt`, prompt);

  // Reload keeps the session and returns to the work.
  await page.reload();
  await page.waitForSelector('.aihat-hire__tabs');

  // Phone sees the same recommendations after reload.
  page = phone.pages()[0];
  await page.reload();
  await page.waitForSelector('.aihat-hire__rec', { timeout: 15000 });
  await shot(page, 'phone-06-recommendations');

  // No event link and a closed link.
  const anon = await browser.newContext({ viewport: { width: 390, height: 844 } });
  page = await anon.newPage();
  await page.goto(`${base}/hire/`);
  await page.waitForSelector('.aihat-hire__card');
  await shot(page, 'phone-07-no-event');

  await browser.close();
  console.log(errors.length ? 'ERRORS:\n' + errors.join('\n') : 'walkthrough: no errors');
  process.exit(errors.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
