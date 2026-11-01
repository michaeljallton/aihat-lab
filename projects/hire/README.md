# Hire Your First Crew Member - build workspace

- `BRIEF.md` - the build brief (v3).
- `plugin/aihat-hire/` - the WordPress plugin. Its `README.md` is the install and configuration guide.
- `tests/` - PHPUnit tests. The Anthropic API, SMTP and the Make webhook are always mocked.
- `dev/` - local-only tools: `local-mocks.php` (a must-use plugin that answers the API and webhook with canned responses and logs email; never install it on a live site), `walkthrough.js` and `admin-walkthrough.js` (Playwright run-throughs).
- `build/` - zips and screenshots (not committed).

## Run the tests

```bash
composer install
vendor/bin/phpunit
```

## Build the upload zip

```bash
cd plugin && zip -r ../build/aihat-hire-0.1.0.zip aihat-hire -x '*.DS_Store'
```

## Local walkthrough

WordPress with the SQLite Database Integration plugin, `dev/local-mocks.php` in `wp-content/mu-plugins/`, the plugin symlinked into `wp-content/plugins/`, a page at `/hire/` with `[aihat_hire]`, and a workshop code `acme-kickoff-2027-01`. Then:

```bash
node dev/walkthrough.js http://127.0.0.1:8899 /tmp/wp/mail.log build/screens
```
