# Build brief: Hire Your First Crew Member (working name)

Owner: FLIGHT (Mike Allton), The AI Hat. Build and project seat: INCO. v3, Nov 1 2026.
v2 was drafted by CLU. v3 records FLIGHT's rulings from the INCO review on Nov 1 2026 and replaces v2 in full.

Seat map for this project: INCO owns the build and calls the other seats in. PAO owns every word a participant sees, including the final wording of the interview template. SIMSUP reviews the interview method. OPTICS passes the interface before go-live. FLIGHT gives the go on anything that goes live (Flight Rule 1-1).

## 1. What we're building

A hidden, noindexed tool on theaihat.com for workshops and training groups. It is separate from the AI Opportunity Score and does not share its data (FLIGHT, Nov 1 2026). A participant:

1. Scans a QR code or types a short link from the slide, on their phone or laptop.
2. Creates a free account with name, email, title and company, and signs in with a 6-digit code sent by email.
3. Enters their recurring tasks on two tabs, Daily and Weekly.
4. Gets **5** recommended crew members, each tagged **Skill** or **Project/Notebook** and also **Scout**, **Scribe** or **Coach**.
5. Picks one and gets a custom interview prompt to paste into the AI tool of their choice. That tool interviews them one question at a time (ten questions at most, most important first), then writes the crew member's onboarding packet using **PRICE**. Depending on the tag, it either produces the Skill or gives copy-and-paste setup steps for a Project or Notebook, and it ends with a punchlist of resources to attach.

It must work for any industry. Nothing in the logic, copy or prompts assumes an ICP.

**Devices.** Participants often sign up on a phone but do the real work on a laptop, where their AI tool is open. The phone is for sign-up and task entry. The laptop is the primary layout, above all for the prompt screen. Both must work, and the same account can be signed in on both at once.

It is used live in rooms where the builds weren't decided in advance, so it has to survive 40 people signing up and generating at once on shared hosting.

## 2. Names and addresses

- Working name: **Hire Your First Crew Member** (the Messaging Spine's name for the method's second stage). PAO confirms the final on-screen name.
- Plugin slug `aihat-hire`. Shortcode `[aihat_hire]`. Database tables, options and constants all prefixed `aihat_hire` / `AIHAT_HIRE_`. All styles scoped under `.aihat-hire`.
- Page: `theaihat.com/hire/`, noindex, nofollow, out of navigation and the sitemap. INCO confirms the slug is free at install.
- Short links per workshop, for example `theaihat.com/hire/dyn`, each a Redirection rule to `/hire/?event=<code>`.
- Lexicon: the verb for a crew member is **hire**, never build (Spine, Oct 17 2026). "Build" stays only where the object is not a crew member, such as a Skill or a Project.

## 3. Architecture

- **A self-contained WordPress plugin.** The live site runs WordPress with the Neve theme and Yoast on SiteGround GoGeek. INCO installs it and creates the page; build sessions never touch the live site.
- **Its own database tables**, created on activation. **No WordPress user accounts.**
- **Accounts.** One account per email. A participant can join more than one workshop; each join is an enrollment, and each enrollment has its own task list, with an option to copy tasks from their previous one.
- **Sign-in by emailed code.** A 6-digit code, stored hashed, valid 15 minutes, single use, with a cap on wrong attempts. It works on whichever device the participant types it into, which a sign-in link would not (a link signs in the device the email is opened on, and corporate link scanners can use it up first). A successful sign-in sets a session cookie valid 30 days on that device. Several devices can hold sessions at once.
- **Workshop code.** The URL parameter is `event`, format `<org>-<event>-<yyyy-mm>`, the same convention and parameter as the Workshop Prep survey. `event` is already on Yoast's parameter allow-list; any new parameter must be added there before a link carrying it ships. A valid, open workshop code is required to sign up. It keeps the tool closed to the open web, tags each signup, and groups one room's results.
- **AI calls go server-side only**, to the Anthropic Messages API from PHP via `wp_remote_post`. The key lives in `wp-config.php` as `AIHAT_HIRE_ANTHROPIC_KEY`, never in the browser, the database or the repo. The model is a constant (`AIHAT_HIRE_MODEL`). The account is on the Claude Console with $200 a month in API credits; its rate tier is to be confirmed from the Console's Limits page.
- **Generation is queued.** The browser submits a job and polls for the result. A poll may claim and run a pending job only while fewer than `AIHAT_HIRE_MAX_CONCURRENT` jobs are running, so a full room never holds dozens of PHP workers open at once and stays inside the API rate limit. Every API request has a timeout and a friendly retry message.
- **Limits:** per-participant daily caps on recommendation runs and prompt generations, a per-IP cap on sign-up and code requests, and a per-email cap on code sends. All constants, set conservatively to protect the monthly credits.
- **Caching.** The page must be excluded from SiteGround's dynamic cache (FLIGHT, in Site Tools, at install). The plugin also sends no-cache headers on its page and on every AJAX response. All participant state loads through AJAX, never into cached HTML.
- **Email delivery.** Codes and "email me this prompt" messages send through authenticated SMTP from a SiteGround mailbox created for the purpose (for example `crew@theaihat.com`) with DKIM on. Settings are constants in `wp-config.php`. The plugin applies them only to its own messages, never to the rest of the site's mail. Before the first workshop, delivery is tested to a Gmail inbox and to an Outlook or company inbox; if codes land in spam, the route moves to a transactional email service by changing settings, not code.
- **Front end:** vanilla JS plus scoped CSS, no build step and no framework.
- **Privacy:** a short notice at sign-up saying what is stored, that task details are processed by a third-party AI provider to make recommendations, how long data is kept, and a link to the site's privacy page. Participants can delete their account and data from inside the tool.
- **Retention:** records are deleted automatically 12 months after a participant's last sign-in, by a daily scheduled job (FLIGHT, Nov 1 2026).

## 4. Email list: Constant Contact

- **Consent.** Sign-up has a separate, **unticked** box to receive email from The AI Hat, with a line that they can unsubscribe at any time. Only participants who tick it are sent to Constant Contact. The plugin stores the consent, its timestamp and the version of the wording shown. (Required for Canada; applied to everyone.)
- **Route:** after a confirmed sign-in, the plugin POSTs a JSON payload to a webhook URL set as `AIHAT_HIRE_CTCT_WEBHOOK`. A Make scenario that INCO builds adds the contact to Constant Contact. The Constant Contact login stays out of WordPress. If the constant is empty, the push is skipped and logged.
- **Payload:** event type, first name, last name, email, title, company, confirmed industry, workshop code, consent timestamp and wording version, sign-up timestamp. When they pick a crew member, a second `update` event carries its name and tags.
- **Retries:** a failed push is queued and retried with WP-Cron (three attempts); every attempt is logged. A failed push never blocks the participant.
- The list, tags and field mapping are set in the Make scenario following `procedure-lead-intake`. **No list names or tags in the plugin.**

## 5. Screens and fields

**Sign up / sign in:** name, email, title, company, the consent box, then the code. After the first sign-in, the AI suggests an **industry** from the title and company. The participant confirms or edits it, so nothing is stored as a guess they didn't see.

**Tasks: two tabs, Daily and Weekly.** Each row has a task name (required), a one-line description (optional), time per occurrence (minutes or hours), and frequency (times per day on Daily, times per week on Weekly). The tool shows each row's weekly time and a running total per tab. Rows can be added, edited and deleted, and they autosave. A minimum number of tasks (`AIHAT_HIRE_MIN_TASKS`, default 5) is required before recommendations run.

**Recommendations:** one button generates **5**. Each card shows:
- **Crew member name**, role-style and memorable ("The Appointment Scribe", "The Approval Chaser", "The Plan Desk").
- **Build tag:** Skill or Project/Notebook.
- **Role tag:** Scout, Scribe or Coach.
- **The drag it ends:** a one-line name for the friction ("Ends the Spec Hunt", "Retires The Second Meeting"). The model must not coin names ending in "Tax", which sits too close to the owned term Toggle Tax, and must not use any term from the published lexicon as a name.
- **Description**, two sentences at most.
- **What it produces:** concrete deliverables.
- **Tasks it covers**, with their combined weekly time.
- **Pre-flight checklist:** 1 to 3 files to gather before starting.

**Ranking is computed in code, not by the model.** The model returns, per card, the IDs of the tasks it covers and a suitability score from 1 to 5. Code sums the weekly minutes from the participant's own rows and ranks cards by weekly time weighted by suitability. No figure on a card is written by the model.

**Build tag rule** (given to the model; code checks the value is one of the two allowed tags):
- **Skill** when the work is a repeatable procedure with a consistent output: the same steps and format every time.
- **Project/Notebook** when the value is standing knowledge or context consulted over time: documents, reference material, ongoing threads.

**Role tag rule** (Messaging Spine wording):
- **Scout** finds and qualifies: leads, claims, opportunities, research.
- **Scribe** handles the admin: notes, CRM, docs, follow-ups.
- **Coach** briefs, answers and readies the team.

The model returns strict JSON validated against a schema. On a parse or validation failure, retry once, then show an error.

**Prompt screen** (laid out for a laptop): the participant picks one card and gets the generated interview prompt in a copy box with a copy button and "copied" feedback, plus an **Email me this prompt** button so a participant on a phone can open it on their laptop. Above it, two short lines: open a brand-new chat and paste the whole thing in; set aside about twenty minutes. Below it, a platform note:
- **Claude:** paid users can set up Skills or Projects; free users save the instructions to a document and paste them into a new chat each time.
- **ChatGPT:** paid users can set up Custom GPTs or Projects; free users do the same as above.
- **Copilot:** paid users can set up Agents or Notebooks; free users do the same as above.
- **Gemini:** paid users can set up Gems or Notebooks; free users do the same as above.

**Admin (WordPress admin, `manage_options` only):** create, open and close workshop codes; a list of codes with signup counts. Per code: participants, their tasks, the recommendations and which crew member each person picked, consent status, and the Constant Contact push status. CSV export per code.

## 6. The interview prompt

Generated by filling a template. The model drafts only the variable parts (marked `{{...}}`), so the structure is fixed. The template lives in `templates/interview-prompt.md` and can be edited without touching code. PAO owns its final wording and SIMSUP reviews its method before go-live. Skeleton:

```
You are an Expert Prompt Engineer and {{specialist role matched to the participant's industry and the crew member}}. Your job today is to help me hire a new AI crew member called {{Crew Member Name}} by writing its onboarding packet.

About me: {{name}}, {{title}} at {{company}}, working in {{industry}}. I am {{Crew Member Name}}'s manager and owner. It reports to me.

{{Crew Member Name}}'s job: {{one paragraph: what it takes in, what it produces, where the output goes}}. It exists to end {{the drag it ends}}: {{one line describing that friction}}. {{One line on what it does not do, and the decisions that stay with me.}}

The tasks it takes over: {{task list, each with its weekly time}}.

Before you write anything, interview me. Ask one question at a time and wait for my answer before asking the next. Never send me a list of questions. Ask no more than ten, the most important first, and skip any question an earlier answer already covered. If an answer is vague or generic, push back once and ask me to be more specific.

First, before the ten, ask me which AI tool I'm using (Claude, ChatGPT, Copilot, Gemini or another) and whether it's a free or paid plan, so you know what you can set up for me.

Then cover these areas: {{6 to 8 interview territories specific to this crew member, in priority order, each one line: where the inputs come from, what a complete output must contain, who receives it, the formats and systems it must fit, the rules it must follow, and the best example of this work I already have}}.

When the interview is complete, write {{Crew Member Name}}'s onboarding packet using the PRICE framework, built entirely from my answers:

- Persona: the job description. Its role, seniority, domain, and who it reports to (me).
- Resources: the onboarding docs. What a capable new hire would need in week one, marked mandatory or optional. Business documents go in only if my AI tool is approved for them at my company. Personal or client-identifying information stays out unless both the tool and the use are approved.
- Instructions: the standard operating procedure. The steps in order, the output format, and what done looks like. Step 1 checks that every mandatory resource is present; if one is missing, it stops and asks me for it.
- Constraints: company policy. What it must never do, written as five guardrails:
  - Semantic: plain professional language; never AI cliches such as delve, unleash or tapestry; use a space, hyphen, space instead of em dashes.
  - Structural: {{format rule for this crew member's output}}; clear prose over walls of bullet points.
  - Behavioral: "Never guess what I meant. If something is missing or unclear, ask before you start." "Never just agree with me. If you see a flaw or a better option, say so and explain why." Every response ends with one question that keeps me in charge of the decision.
  - Logical: work only from what I provide, flag gaps, and never invent a detail.
  - Compliance: {{the industry rules that apply, in one line; for an unregulated industry, the company policies that apply}}. Nothing it produces reaches a client or customer until I have reviewed it.
- Examples: the performance standard. Define a five-star output from the example I gave you. If I had none, write a baseline example and say that's what you did.

Then deliver it for the tool I named:
{{If tagged Skill: produce the complete Skill, ready to save: a name, a one-line description of when to use it, then the PRICE packet. If my tool doesn't support Skills, give me the same packet as custom instructions for that tool and tell me where to paste them.}}
{{If tagged Project/Notebook: give me step-by-step setup for my tool (a Claude Project, a ChatGPT Project or Custom GPT, a Copilot Notebook or Agent, a Gemini Gem or NotebookLM notebook): the name, the full instructions in one block I can copy in a single motion, and which resources to add. On a free plan: save the instructions to a document and paste them into a new chat each time.}}

Finish with a punchlist of 3 to 7 resources to attach or add, each with one line on why it matters.

Start by asking me your first question. Nothing else.
```

**PRICE is fixed: Persona, Resources, Instructions, Constraints, Examples**, as defined in the Messaging Spine (canonical, Sep 30 2026). The Spine wins over any blog post or older workshop page. Constraints always carries all five guardrails, Compliance included. The two standing Flight Rules always go into Behavioral in their instruction form.

## 7. Reference examples

Study the prompts on these workshop pages for structure, tone and level of detail. Where they differ from the Spine on PRICE, the Spine wins. **Don't copy any company names, product names or industry specifics into the tool.** If the network blocks the site, work from section 6 and say so in the report.

- Strongest: `/workshops/berwick/`, `/workshops/thavma/`, `/workshops/wsutech/`, `/workshops/marketingprofs/`
- Earlier PRICE versions: `/workshops/kellogg/`, `/workshops/nevco/`
- Tone only: `/workshops/ues/`, `/workshops/wap/`, `/workshops/workshop-template/`

## 8. Look and feel

Match the site's landing-page system (see `/workshop-prep/`): Michroma (display), Raleway (body), Courier Prime (labels); charcoal `#313131`, off-white `#EBE9D7`, steel blue `#5fa8e8`, green `#069448`, tan `#CBC1A4`; a dark hero with a grid background, then light content sections. Laptop is the primary layout; phone layouts must work for sign-up and tasks. All styles scoped under `.aihat-hire`. Check existing Additional CSS for class collisions. OPTICS passes the interface before go-live.

## 9. Copy rules

All on-screen copy is a **placeholder** until PAO delivers it. Hard rules for anything participants see and for the generated prompt:
- No em dashes.
- Never the phrase "Patient Architect".
- No AI-styled filler ("unlock", "supercharge", "game-changer", "seamless", "delve", "elevate", and similar).
- Never state a statistic or a price.
- Hire, never build, for crew members.

## 10. Testing

- Stand WordPress up locally (PHP plus WordPress with the SQLite integration, or `wp-env` if Docker is available), activate the plugin, and run through every screen at laptop and phone widths.
- PHPUnit tests for code issue, expiry, attempt caps and multi-device sessions; the rate limits; the job queue and its concurrency cap; JSON schema validation (both tag sets); the ranking and weekly-time maths; the prompt template fill; consent gating of the webhook; the webhook payload and retry queue; retention purge.
- **Mock the Anthropic API, SMTP and the webhook in tests.** No key is ever needed in a build session.
- Security basics: nonces on every form and AJAX call, sanitized input, escaped output, prepared statements, capability checks on admin screens, codes and session tokens stored hashed.

## 11. Deliverables

1. A plugin folder that zips cleanly for upload, with a version in its header.
2. `README.md`: install steps for INCO (upload, activate, add the API key, SMTP and webhook settings to `wp-config.php`, exclude the page from SiteGround's cache, create the noindexed page with the shortcode, add the short-link redirects, create the first workshop code), every config constant, the webhook payload spec for the Make scenario, and how to edit the prompt template.
3. Test results, the list of placeholder copy for PAO, and anything left undone.
4. Site systems registry entries for the plugin, the SMTP mailbox and Make, added at install.

## 12. Out of scope for v1

Payments. Teams or org accounts. Storing anything the participant pastes into their own AI tool. Translations (English only, US and Canada). Any link to the AI Opportunity Score's data.

## Changelog

- **v3, Nov 1 2026 (INCO, FLIGHT rulings).** Renamed to Hire Your First Crew Member; hire verb throughout. Emailed 6-digit code replaces the magic link; multi-device sessions; laptop primary for the work. `event` replaces `w`. Queued generation with a concurrency cap; SiteGround cache exclusion. SMTP through a SiteGround mailbox. Unticked consent box gates the Constant Contact push; AI-provider processing disclosed. PRICE and role tags aligned to the Messaging Spine (Compliance always on; the two standing Flight Rules in Behavioral; Persona includes who it reports to). Ranking computed in code. Minimum task count. Workshop code management in admin. One account per email with enrollments per workshop; 12-month retention. Separate from the AI Opportunity Score. INCO owns the project.
- **v2, Nov 1 2026 (CLU).** Original brief.
