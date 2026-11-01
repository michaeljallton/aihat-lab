const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1366, height: 900 }, acceptDownloads: true }); const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.goto('http://127.0.0.1:8899/wp-login.php'); await p.fill('#user_login', 'admin'); await p.fill('#user_pass', 'admin'); await p.click('#wp-submit');
  await p.waitForURL(/wp-admin/);
  await p.goto('http://127.0.0.1:8899/wp-admin/admin.php?page=aihat-hire');
  await p.fill('input[name=code]', 'globex-training-2027-03'); await p.fill('input[name=label]', 'Globex training'); await p.click('input[value=Create]');
  console.log('create notice:', (await p.textContent('.notice')).trim());
  await p.screenshot({ path: 'build/screens/admin-01-workshops.png', fullPage: true });
  await p.click('a:has-text("acme-kickoff-2027-01")');
  await p.screenshot({ path: 'build/screens/admin-02-workshop.png', fullPage: true });
  const [dl] = await Promise.all([p.waitForEvent('download'), p.click('a:has-text("Export CSV")')]);
  await dl.saveAs('build/screens/export.csv');
  console.log(require('fs').readFileSync('build/screens/export.csv','utf8').slice(0,700));
  console.log(errs.length ? errs : 'no page errors');
  await b.close();
})();
