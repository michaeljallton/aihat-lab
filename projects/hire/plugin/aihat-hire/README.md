# Hire Your First Crew Member (`aihat-hire`)

A WordPress plugin for workshops. A participant opens the workshop link, creates an account with an emailed 6-digit code, lists their daily and weekly tasks, gets five recommended crew members, and takes away an interview prompt for their own AI tool.

Version 0.1.0. Requires WordPress 6.4+ and PHP 8.1+. Brief: `projects/hire/BRIEF.md` (v3).

## Install (INCO)

1. **Zip and upload.** Zip the `aihat-hire` folder (or use the built zip) and upload it under Plugins > Add New > Upload. Activate. Activation creates the plugin's own tables (prefix `wp_aihat_hire_`) and a five-minute WP-Cron job. It creates no WordPress users.
2. **Add the settings to `wp-config.php`**, above the "That's all, stop editing" line. Minimum:

   ```php
   define( 'AIHAT_HIRE_ANTHROPIC_KEY', 'sk-ant-...' );          // Claude Console API key
   define( 'AIHAT_HIRE_SMTP_HOST', '...' );                     // SMTP host shown for the mailbox in Site Tools > Email
   define( 'AIHAT_HIRE_SMTP_USER', 'crew@theaihat.com' );
   define( 'AIHAT_HIRE_SMTP_PASS', '...' );
   define( 'AIHAT_HIRE_CTCT_WEBHOOK', 'https://hook.us1.make.com/...' );
   ```

   The key never goes in the database, the browser or the repo. Hire Tool in wp-admin shows a check or a cross for the key, SMTP and webhook.
3. **Create the page.** New page, title "Hire Your First Crew Member", slug `hire`, content `[aihat_hire]`. In Yoast, set "Allow search engines to show this page" to No (the plugin also sends `noindex, nofollow` on its own). Keep it out of menus. In Neve's page settings, use a full-width layout with no sidebar and hide the page title (the tool has its own header).
4. **Exclude the page from SiteGround's cache.** Site Tools > Speed > Caching > Dynamic Cache > Exclude URLs: add `/hire/`. The plugin sends no-cache headers and `DONOTCACHEPAGE`, but SiteGround's server cache needs the exclusion. Verify logged out: two fresh loads should not show `x-proxy-cache: HIT`.
5. **Create the first workshop code.** wp-admin > Hire Tool > New workshop code, for example `acme-kickoff-2027-01`. Codes are lowercase letters, numbers and hyphens, same convention as Workshop Prep (`<org>-<event>-<yyyy-mm>`).
6. **Short link for the slide.** In Redirection, add a 302 from `/hire/acme` to `/hire/?event=acme-kickoff-2027-01`. `event` is already on Yoast's parameter allow-list. Test the short link logged out before it goes on a slide.
7. **Test.** Sign up with a test address under the INCO test-contact rule, enter five tasks, generate, pick, copy, and check the code email lands in a Gmail and an Outlook/company inbox (not spam).

To close a workshop after the event, press Close on its row. Closed codes take no new sign-ups; existing participants can still sign in.

## Settings (constants)

All optional except the API key. Set in `wp-config.php`.

| Constant | Default | What it does |
| --- | --- | --- |
| `AIHAT_HIRE_ANTHROPIC_KEY` | (empty) | Claude Console API key. Without it, generation fails cleanly. |
| `AIHAT_HIRE_MODEL` | `claude-opus-5-5` | Model for every call. |
| `AIHAT_HIRE_EFFORT` | `medium` | Effort for recommendations and prompts (`low`, `medium`, `high`). The industry suggestion always uses `low`. Lower is faster and cheaper. |
| `AIHAT_HIRE_API_TIMEOUT` | `90` | Seconds per API request. |
| `AIHAT_HIRE_FALLBACKS` | `true` | Opts into Anthropic's server-side fallback, which reruns a request on another model if the first one declines it. |
| `AIHAT_HIRE_MAX_CONCURRENT` | `4` | AI jobs running at once. Others wait in line and see their place. |
| `AIHAT_HIRE_DAILY_RUNS` | `5` | Recommendation runs per participant per 24 hours. Failed runs don't count. |
| `AIHAT_HIRE_DAILY_PROMPTS` | `10` | Prompt generations per participant per 24 hours. |
| `AIHAT_HIRE_DAILY_PROMPT_EMAILS` | `5` | "Email me this prompt" sends per participant per day. |
| `AIHAT_HIRE_IP_CODE_LIMIT` | `120` | Code requests per IP per hour. A room on one wifi shares an IP, so keep this above the room size. |
| `AIHAT_HIRE_EMAIL_CODE_LIMIT` | `5` | Code requests per email per hour. |
| `AIHAT_HIRE_CODE_TTL` | `900` | Seconds a code stays valid. |
| `AIHAT_HIRE_CODE_ATTEMPTS` | `5` | Wrong guesses before a code stops working. |
| `AIHAT_HIRE_SESSION_DAYS` | `30` | Days a device stays signed in. |
| `AIHAT_HIRE_MIN_TASKS` | `5` | Tasks needed before recommendations run. |
| `AIHAT_HIRE_MAX_TASKS` | `40` | Tasks allowed per workshop. |
| `AIHAT_HIRE_WORKDAYS` | `5` | Days a week a daily task is counted. |
| `AIHAT_HIRE_RETENTION_DAYS` | `365` | Accounts are deleted this long after the last sign-in. |
| `AIHAT_HIRE_SMTP_HOST` | (empty) | SMTP host. Empty means WordPress's default mail. Applies only to this plugin's emails. |
| `AIHAT_HIRE_SMTP_PORT` | `465` | |
| `AIHAT_HIRE_SMTP_SECURE` | `ssl` | `ssl` or `tls`. |
| `AIHAT_HIRE_SMTP_USER` / `_PASS` | (empty) | Mailbox login. |
| `AIHAT_HIRE_MAIL_FROM` | SMTP user | From address. |
| `AIHAT_HIRE_MAIL_FROM_NAME` | `The AI Hat` | From name. |
| `AIHAT_HIRE_CTCT_WEBHOOK` | (empty) | Make webhook URL. Empty means pushes are skipped and logged. |
| `AIHAT_HIRE_CTCT_SECRET` | (empty) | If set, each push carries `x-aihat-signature: sha256=<HMAC of the body>`. |
| `AIHAT_HIRE_PRIVACY_URL` | site privacy page | Link in the sign-up notice. |
| `AIHAT_HIRE_LOAD_FONTS` | `true` | Loads Michroma, Raleway and Courier Prime from Google Fonts. Set false if the theme already loads them. |
| `AIHAT_HIRE_CONSENT_VERSION` | `v1-2026-11` | Stored with each consent. Change it whenever the consent wording changes. |
| `AIHAT_HIRE_KEEP_DATA` | (unset) | If true, deleting the plugin keeps its tables. Otherwise deleting it drops all data. |

## Webhook payload (for the Make scenario)

The plugin POSTs JSON to `AIHAT_HIRE_CTCT_WEBHOOK`. Only participants who ticked the email consent box are ever sent.

**Signup event**, sent once, when the participant confirms their industry (or, if they skip ahead, when they first generate recommendations):

```json
{
  "event": "signup",
  "source": "aihat-hire",
  "first_name": "Pat",
  "last_name": "Rivera",
  "email": "pat@example.com",
  "title": "Operations Manager",
  "company": "Northwind Logistics",
  "industry": "Freight logistics",
  "workshop_code": "acme-kickoff-2027-01",
  "consent": true,
  "consent_at": "2027-01-14T15:47:02+00:00",
  "consent_version": "v1-2026-11",
  "signup_at": "2027-01-14T15:47:02+00:00",
  "sent_at": "2027-01-14T15:47:04+00:00"
}
```

**Update event**, sent each time the participant picks a crew member: the same fields with `"event": "update"`, plus

```json
"crew_member": { "name": "The Shipment Scribe", "build_tag": "skill", "role_tag": "scribe" }
```

`build_tag` is `skill` or `project`; `role_tag` is `scout`, `scribe` or `coach`. `workshop_code` is the participant's most recent workshop.

A 2xx response counts as sent. Anything else is retried by WP-Cron after 5 minutes and again after 30 minutes; after three failed attempts the push is marked failed. Every attempt is logged on the participant's row in Hire Tool, where a failed push has a Retry button. List names, tags and field mapping belong in the Make scenario (per `procedure-lead-intake`); the plugin sends none.

## Editing the interview prompt

The prompt is `templates/interview-prompt.md`. Edit the wording freely; the code only fills the placeholders.

- `{{name}}` placeholders are filled by the plugin. Available: `specialist_role`, `crew_member_name`, `participant_name`, `participant_title`, `participant_company`, `participant_industry`, `job_paragraph`, `drag_name`, `drag_line`, `not_do_line`, `task_list`, `territories`, `structural_rule`, `compliance_line`. Don't invent new ones; the plugin refuses to produce a prompt with a placeholder it can't fill.
- Text between `{{#skill}}` and `{{/skill}}` appears only for Skill-tagged crew members; `{{#project}}`...`{{/project}}` only for Project/Notebook.
- After editing, run the tests (`projects/hire`: `vendor/bin/phpunit`); they check the fixed parts of the prompt are still there.

All on-screen words live in `includes/class-copy.php` (and can be overridden with the `aihat_hire_copy` filter). They are placeholders until PAO's pass.

## Privacy and data

Stored: name, email, title, company, industry, the consent choice with its time and wording version, tasks, recommendations, generated prompts, and the push log. Codes and session tokens are stored only as hashes. Task details are sent to Anthropic to make recommendations. Nothing a participant later types into their own AI tool is stored. Participants can delete their account and all of it from the tool. Accounts are deleted automatically 12 months after the last sign-in.
