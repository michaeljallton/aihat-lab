/* Hire Your First Crew Member - front end. Vanilla JS, no build step.
 * Every string comes from AIHAT_HIRE.copy (includes/class-copy.php).
 * All DOM is built with textContent, never innerHTML, so nothing a participant
 * or the model writes is ever parsed as HTML. */
(function () {
	'use strict';

	var CFG = window.AIHAT_HIRE || {};
	var C = CFG.copy || {};
	var root = document.getElementById('aihat-hire-app');
	if (!root) { return; }

	var S = {
		view: 'loading',
		authMode: 'signup',
		email: '',
		data: null,
		tab: 'daily',
		busy: false,
		message: '',
		messageKind: '',
		pollTimers: {}
	};

	// Helpers.

	function t(key) {
		var s = C[key] || key;
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return s.replace(/%[sd]/g, function () { return String(args[i++]); });
	}

	function el(tag, attrs) {
		var node = document.createElement(tag);
		var a = attrs || {};
		Object.keys(a).forEach(function (k) {
			var v = a[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { node.className = v; }
			else if (k === 'text') { node.textContent = v; }
			else if (k.indexOf('on') === 0) { node.addEventListener(k.slice(2), v); }
			else if (v === true) { node.setAttribute(k, ''); }
			else { node.setAttribute(k, v); }
		});
		for (var i = 2; i < arguments.length; i++) {
			var child = arguments[i];
			if (child === null || child === undefined || child === false) { continue; }
			if (Array.isArray(child)) {
				child.forEach(function (c) { if (c) { node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
			} else {
				node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
			}
		}
		return node;
	}

	function minutesLabel(m) {
		m = Math.round(m);
		if (m < 60) { return m + ' min'; }
		var h = Math.floor(m / 60), r = m % 60;
		return r ? h + ' hr ' + r + ' min' : h + ' hr';
	}

	function weekly(tab, minutes, freq) {
		var wd = (S.data && S.data.settings) ? S.data.settings.workdays : 5;
		if (!(minutes > 0) || !(freq > 0)) { return 0; }
		return Math.round(minutes * (tab === 'daily' ? freq * wd : freq));
	}

	function errorText(code) {
		var map = {
			bad_email: 'err_bad_email', required: 'err_required', no_account: 'err_no_account',
			rate: 'err_rate', wrong_code: 'err_wrong_code', expired: 'err_expired',
			too_many_attempts: 'err_too_many', no_code: 'err_no_code', daily_cap: 'err_daily_cap',
			session: 'err_session', task: 'err_task', max_tasks: 'err_max_tasks', mail: 'err_mail',
			busy: 'err_busy', ai_failed: 'err_ai_failed', network: 'err_network', event_closed: 'event_closed',
			min_tasks: 'min_tasks'
		};
		if (code === 'min_tasks') { return t('min_tasks', (S.data && S.data.settings) ? S.data.settings.min_tasks : 5); }
		return t(map[code] || 'err_generic');
	}

	function say(text, kind) {
		S.message = text || '';
		S.messageKind = kind || 'error';
		render();
	}

	function post(action, fields) {
		var body = new FormData();
		body.append('action', 'aihat_hire_' + action);
		body.append('nonce', CFG.nonce);
		if (CFG.event) { body.append('event', CFG.event); }
		Object.keys(fields || {}).forEach(function (k) {
			var v = fields[k];
			body.append(k, v === true ? '1' : v === false ? '' : (v === null || v === undefined ? '' : String(v)));
		});
		return fetch(CFG.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.catch(function () { return { ok: false, error: 'network' }; })
			.then(function (res) {
				if (!res.ok && res.error === 'nonce') { window.location.reload(); }
				if (!res.ok && res.error === 'session' && action !== 'state') {
					S.data = null; S.view = 'auth'; S.authMode = 'signin';
				}
				return res;
			});
	}

	// State.

	function load() {
		post('state', {}).then(function (res) {
			if (!res.ok) { S.view = 'error'; say(errorText(res.error)); return; }
			apply(res);
		});
	}

	function apply(res) {
		if (!res.signed_in) {
			S.data = null;
			var ev = res.event;
			if (!CFG.event) { S.view = 'noevent'; }
			else if (!ev || !ev.open) { S.view = 'closed'; }
			else { S.view = 'auth'; }
			render();
			return;
		}
		S.data = res;
		if (!res.participant.industry_confirmed) {
			S.view = 'industry';
			if (res.industry_job) { pollJob(res.industry_job, 'industry'); }
		} else if (!res.enrollment) {
			S.view = 'noevent';
		} else if (res.prompt && S.view === 'prompt') {
			S.view = 'prompt';
		} else {
			S.view = 'main';
		}
		if (res.open_jobs) {
			if (res.open_jobs.recommend) { S.generating = true; pollJob(res.open_jobs.recommend, 'recommend'); }
			if (res.open_jobs.prompt) { S.prompting = true; pollJob(res.open_jobs.prompt, 'prompt'); }
		}
		render();
	}

	// Jobs: poll every 2.5s; a poll may run the job and take up to a minute.

	function pollJob(id, type) {
		if (S.pollTimers[id]) { return; }
		S.pollTimers[id] = true;
		var tick = function () {
			post('poll', { job_id: id }).then(function (res) {
				if (!res.ok) {
					delete S.pollTimers[id];
					finishJob(type, null, res.error);
					return;
				}
				if (res.status === 'done') {
					delete S.pollTimers[id];
					finishJob(type, res, null);
				} else if (res.status === 'failed') {
					delete S.pollTimers[id];
					finishJob(type, null, res.error);
				} else {
					S.queuePos = res.status === 'queued' ? res.position : 0;
					render();
					setTimeout(tick, 2500);
				}
			});
		};
		tick();
	}

	function finishJob(type, res, error) {
		S.queuePos = 0;
		if (type === 'industry') {
			if (res && res.industry && S.data) { S.data.participant.industry_suggested = res.industry; }
			S.industryReady = true;
			render();
			return;
		}
		if (type === 'recommend') {
			S.generating = false;
			if (res && res.run) { S.data.run = res.run; S.message = ''; render(); scrollTo('aihat-hire-recs'); }
			else { say(errorText(error)); }
			return;
		}
		if (type === 'prompt') {
			S.prompting = false;
			if (res && res.prompt) { S.data.prompt = res.prompt; S.view = 'prompt'; S.message = ''; render(); window.scrollTo(0, root.offsetTop - 20); }
			else { say(errorText(error)); }
		}
	}

	function scrollTo(id) {
		var n = document.getElementById(id);
		if (n && n.scrollIntoView) { n.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	}

	// Views.

	function header() {
		return el('header', { class: 'aihat-hire__hero' },
			el('p', { class: 'aihat-hire__label', text: S.data && S.data.enrollment ? (S.data.enrollment.label || S.data.enrollment.code) : 'The AI Hat' }),
			el('h1', { class: 'aihat-hire__title', text: t('app_title') }),
			el('p', { class: 'aihat-hire__intro', text: t('app_intro') })
		);
	}

	function messageBox() {
		if (!S.message) { return null; }
		return el('p', { class: 'aihat-hire__msg aihat-hire__msg--' + S.messageKind, role: 'alert', text: S.message });
	}

	function field(label, attrs) {
		var id = 'ah-' + attrs.name;
		attrs.id = id;
		return el('div', { class: 'aihat-hire__field' }, el('label', { for: id, text: label }), el('input', attrs));
	}

	function formData(form) {
		var out = {};
		Array.prototype.forEach.call(form.elements, function (f) {
			if (!f.name) { return; }
			out[f.name] = f.type === 'checkbox' ? f.checked : f.value;
		});
		return out;
	}

	function viewAuth() {
		var signup = S.authMode === 'signup';
		var form = el('form', { class: 'aihat-hire__card', novalidate: true, onsubmit: function (e) {
			e.preventDefault();
			if (S.busy) { return; }
			var d = formData(form);
			d.mode = signup ? 'signup' : 'signin';
			S.busy = true; S.message = ''; render();
			post('request_code', d).then(function (res) {
				S.busy = false;
				if (!res.ok) { say(errorText(res.error)); return; }
				S.email = res.email; S.view = 'code'; render();
			});
		} },
			el('h2', { text: signup ? t('signup_title') : t('signin_title') }),
			signup ? el('div', { class: 'aihat-hire__row' },
				field(t('first_name'), { name: 'first_name', type: 'text', autocomplete: 'given-name', required: true, maxlength: '100' }),
				field(t('last_name'), { name: 'last_name', type: 'text', autocomplete: 'family-name', required: true, maxlength: '100' })) : null,
			field(t('email'), { name: 'email', type: 'email', autocomplete: 'email', required: true, value: S.email || '', maxlength: '191' }),
			signup ? field(t('title'), { name: 'title', type: 'text', autocomplete: 'organization-title', required: true, maxlength: '150' }) : null,
			signup ? field(t('company'), { name: 'company', type: 'text', autocomplete: 'organization', required: true, maxlength: '150' }) : null,
			signup ? el('div', { class: 'aihat-hire__check' },
				el('input', { type: 'checkbox', name: 'consent', id: 'ah-consent' }),
				el('label', { for: 'ah-consent', text: t('consent') })) : null,
			signup ? el('p', { class: 'aihat-hire__fine' }, t('privacy') + ' ',
				CFG.privacy ? el('a', { href: CFG.privacy, target: '_blank', rel: 'noopener', text: t('privacy_link') }) : null) : null,
			messageBox(),
			el('button', { type: 'submit', class: 'aihat-hire__btn', disabled: S.busy, text: t('send_code') }),
			el('button', { type: 'button', class: 'aihat-hire__link', onclick: function () {
				S.authMode = signup ? 'signin' : 'signup'; S.message = ''; render();
			}, text: signup ? t('have_account') : t('need_account') })
		);
		return form;
	}

	function viewCode() {
		var form = el('form', { class: 'aihat-hire__card', onsubmit: function (e) {
			e.preventDefault();
			if (S.busy) { return; }
			S.busy = true; S.message = ''; render();
			post('verify_code', { email: S.email, code: formData(form).code }).then(function (res) {
				S.busy = false;
				if (!res.ok) { say(errorText(res.error)); return; }
				S.message = '';
				apply(res);
			});
		} },
			el('h2', { text: t('code_title') }),
			el('p', { text: t('code_help', S.email) }),
			field(t('code_label'), { name: 'code', type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', pattern: '[0-9 ]*', maxlength: '7', required: true, class: 'aihat-hire__code' }),
			messageBox(),
			el('button', { type: 'submit', class: 'aihat-hire__btn', disabled: S.busy, text: t('verify') }),
			el('div', { class: 'aihat-hire__links' },
				el('button', { type: 'button', class: 'aihat-hire__link', onclick: function () { S.view = 'auth'; S.authMode = 'signin'; S.message = ''; render(); }, text: t('resend') }),
				el('button', { type: 'button', class: 'aihat-hire__link', onclick: function () { S.view = 'auth'; S.email = ''; S.message = ''; render(); }, text: t('change_email') }))
		);
		return form;
	}

	function viewIndustry() {
		var p = S.data.participant;
		var waiting = !S.industryReady && S.data.industry_job && !p.industry_suggested;
		if (waiting) {
			return el('div', { class: 'aihat-hire__card' }, el('h2', { text: t('industry_title') }), el('p', { class: 'aihat-hire__wait', text: t('industry_wait') }));
		}
		var form = el('form', { class: 'aihat-hire__card', onsubmit: function (e) {
			e.preventDefault();
			if (S.busy) { return; }
			S.busy = true; render();
			post('confirm_industry', { industry: formData(form).industry }).then(function (res) {
				S.busy = false;
				if (!res.ok) { say(errorText(res.error)); return; }
				S.data.participant.industry = res.industry;
				S.data.participant.industry_confirmed = true;
				S.message = '';
				S.view = S.data.enrollment ? 'main' : 'noevent';
				render();
			});
		} },
			el('h2', { text: t('industry_title') }),
			el('p', { text: t('industry_help') }),
			field(t('industry_label'), { name: 'industry', type: 'text', required: true, maxlength: '100', value: p.industry || p.industry_suggested || '' }),
			messageBox(),
			el('button', { type: 'submit', class: 'aihat-hire__btn', disabled: S.busy, text: t('industry_save') })
		);
		return form;
	}

	// Tasks.

	var saveTimers = {};

	function taskRow(task) {
		var isDaily = task.tab === 'daily';
		var status = el('span', { class: 'aihat-hire__saved', 'aria-live': 'polite' });
		var weeklyOut = el('span', { class: 'aihat-hire__weekly' });
		var row = el('div', { class: 'aihat-hire__task' });

		function readRow() {
			return {
				id: task.id || '',
				tab: task.tab,
				name: row.querySelector('[data-f=name]').value,
				description: row.querySelector('[data-f=description]').value,
				amount: row.querySelector('[data-f=amount]').value,
				unit: row.querySelector('[data-f=unit]').value,
				frequency: row.querySelector('[data-f=frequency]').value
			};
		}

		function refreshWeekly() {
			var r = readRow();
			var mins = parseFloat(r.amount) * (r.unit === 'hours' ? 60 : 1);
			var w = weekly(task.tab, mins, parseFloat(r.frequency));
			task._weekly = w;
			weeklyOut.textContent = w ? minutesLabel(w) + ' ' + t('per_week') : '';
			updateTotals();
		}

		function schedule() {
			// Keep typed values on the task object so a re-render never loses them.
			var cur = readRow();
			['name', 'description', 'amount', 'unit', 'frequency'].forEach(function (k) { task[k] = cur[k]; });
			refreshWeekly();
			var key = task.id || task._tmp;
			clearTimeout(saveTimers[key]);
			saveTimers[key] = setTimeout(function () {
				var r = readRow();
				if (!r.name.trim() || !(parseFloat(r.amount) > 0) || !(parseFloat(r.frequency) > 0)) { return; }
				status.textContent = '...';
				post('save_task', r).then(function (res) {
					if (!res.ok) { status.textContent = errorText(res.error); return; }
					if (!task.id) {
						task.id = res.task.id;
						delete saveTimers[key];
					}
					Object.keys(res.task).forEach(function (k) { task[k] = res.task[k]; });
					status.textContent = t('saved');
					updateTotals();
				});
			}, 700);
		}

		var unitSel = el('select', { 'data-f': 'unit', 'aria-label': t('task_time'), onchange: schedule },
			el('option', { value: 'minutes', text: t('unit_minutes') }), el('option', { value: 'hours', text: t('unit_hours') }));
		unitSel.value = task.unit || 'minutes';

		row.appendChild(el('div', { class: 'aihat-hire__task-main' },
			el('input', { 'data-f': 'name', type: 'text', placeholder: t('task_name'), 'aria-label': t('task_name'), maxlength: '150', value: task.name || '', oninput: schedule }),
			el('input', { 'data-f': 'description', type: 'text', placeholder: t('task_desc'), 'aria-label': t('task_desc'), maxlength: '255', value: task.description || '', oninput: schedule })));
		row.appendChild(el('div', { class: 'aihat-hire__task-nums' },
			el('label', {}, el('span', { text: t('task_time') }),
				el('span', { class: 'aihat-hire__inline' },
					el('input', { 'data-f': 'amount', type: 'number', min: '0', step: 'any', inputmode: 'decimal', value: task.amount || '', oninput: schedule }),
					unitSel)),
			el('label', {}, el('span', { text: isDaily ? t('freq_daily') : t('freq_weekly') }),
				el('input', { 'data-f': 'frequency', type: 'number', min: '0', step: 'any', inputmode: 'decimal', value: task.frequency || '', oninput: schedule })),
			weeklyOut,
			status,
			el('button', { type: 'button', class: 'aihat-hire__link aihat-hire__del', onclick: function () {
				var finish = function () {
					S.data.tasks = S.data.tasks.filter(function (x) { return x !== task; });
					render();
				};
				if (task.id) { post('delete_task', { id: task.id }).then(finish); } else { finish(); }
			}, text: t('delete_task') })));
		setTimeout(refreshWeekly, 0);
		return row;
	}

	var totalsNodes = {};

	function updateTotals() {
		if (!S.data) { return; }
		var sum = { daily: 0, weekly: 0 };
		S.data.tasks.forEach(function (x) {
			var w = typeof x._weekly === 'number' ? x._weekly : (x.weekly_minutes || 0);
			sum[x.tab] += w;
		});
		if (totalsNodes.tab) { totalsNodes.tab.textContent = t('tab_total', minutesLabel(sum[S.tab])); }
		if (totalsNodes.all) { totalsNodes.all.textContent = t('all_total', minutesLabel(sum.daily + sum.weekly)); }
	}

	function viewTasks() {
		var tasks = S.data.tasks.filter(function (x) { return x.tab === S.tab; });
		totalsNodes = {
			tab: el('p', { class: 'aihat-hire__total' }),
			all: el('p', { class: 'aihat-hire__total aihat-hire__total--all' })
		};
		var tabs = el('div', { class: 'aihat-hire__tabs', role: 'tablist' },
			['daily', 'weekly'].map(function (tab) {
				return el('button', { type: 'button', role: 'tab', 'aria-selected': S.tab === tab ? 'true' : 'false',
					class: 'aihat-hire__tab' + (S.tab === tab ? ' is-active' : ''),
					onclick: function () { S.tab = tab; render(); }, text: t('tab_' + tab) });
			}));
		var saved = S.data.tasks.filter(function (x) { return x.id; }).length;
		var min = S.data.settings.min_tasks;
		var section = el('section', { class: 'aihat-hire__card' },
			el('h2', { text: t('tasks_title') }),
			el('p', { text: t('tasks_help') }),
			S.data.copy_from && !S.data.tasks.length ? el('button', { type: 'button', class: 'aihat-hire__link', onclick: function () {
				post('copy_tasks', { from: S.data.copy_from }).then(function (res) {
					if (!res.ok) { say(errorText(res.error)); return; }
					S.data.tasks = res.tasks; render();
				});
			}, text: t('copy_tasks') }) : null,
			tabs,
			el('div', { class: 'aihat-hire__tasks' }, tasks.map(taskRow)),
			el('button', { type: 'button', class: 'aihat-hire__btn aihat-hire__btn--ghost', onclick: function () {
				S.data.tasks.push({ tab: S.tab, _tmp: 'n' + Date.now(), name: '', description: '', amount: '', unit: 'minutes', frequency: '' });
				render();
				var rows = root.querySelectorAll('.aihat-hire__task [data-f=name]');
				if (rows.length) { rows[rows.length - 1].focus(); }
			}, text: t('add_task') }),
			totalsNodes.tab,
			totalsNodes.all,
			el('p', { class: 'aihat-hire__fine', text: t('workdays_note', S.data.settings.workdays) }),
			messageBox(),
			saved < min ? el('p', { class: 'aihat-hire__fine', text: t('min_tasks', min) }) : null,
			el('button', { type: 'button', class: 'aihat-hire__btn', disabled: S.generating || saved < min, onclick: generate,
				text: S.data.run ? t('regenerate') : t('generate') }),
			S.generating ? el('p', { class: 'aihat-hire__wait', text: S.queuePos ? t('queued', S.queuePos) : t('generating') }) : null
		);
		updateTotals();
		return section;
	}

	function generate() {
		S.generating = true; S.message = ''; render();
		post('generate', {}).then(function (res) {
			if (!res.ok) { S.generating = false; say(errorText(res.error)); return; }
			pollJob(res.job_id, 'recommend');
		});
	}

	function tag(kind, value) {
		return el('span', { class: 'aihat-hire__tag aihat-hire__tag--' + value, text: t('tag_' + value) });
	}

	function viewRecs() {
		var run = S.data.run;
		if (!run) { return null; }
		return el('section', { class: 'aihat-hire__recs', id: 'aihat-hire-recs' },
			el('h2', { text: t('recs_title') }),
			run.cards.map(function (c) {
				return el('article', { class: 'aihat-hire__rec' },
					el('div', { class: 'aihat-hire__tags' }, tag('build', c.build_tag), tag('role', c.role_tag)),
					el('h3', { text: c.name }),
					el('p', { class: 'aihat-hire__drag', text: c.drag_name }),
					el('p', { text: c.description }),
					el('h4', { text: t('produces') }),
					el('ul', {}, c.produces.map(function (x) { return el('li', { text: x }); })),
					el('h4', { text: t('covers', minutesLabel(c.weekly_minutes)) }),
					el('ul', {}, c.tasks.map(function (x) { return el('li', { text: x }); })),
					el('h4', { text: t('preflight') }),
					el('ul', {}, c.preflight.map(function (x) { return el('li', { text: x }); })),
					el('button', { type: 'button', class: 'aihat-hire__btn', disabled: S.prompting, onclick: function () {
						S.prompting = true; S.message = ''; render();
						post('pick', { run_id: run.id, index: c.index }).then(function (res) {
							if (!res.ok) { S.prompting = false; say(errorText(res.error)); return; }
							pollJob(res.job_id, 'prompt');
						});
					}, text: t('pick') }));
			}),
			S.prompting ? el('p', { class: 'aihat-hire__wait aihat-hire__wait--sticky', text: S.queuePos ? t('queued', S.queuePos) : t('prompt_wait') }) : null
		);
	}

	function copyText(text, button) {
		var done = function () {
			button.textContent = t('copied');
			setTimeout(function () { button.textContent = t('copy'); }, 2000);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
		} else {
			fallbackCopy(text); done();
		}
	}

	function fallbackCopy(text) {
		var ta = root.querySelector('.aihat-hire__prompt');
		if (ta) { ta.focus(); ta.select(); try { document.execCommand('copy'); } catch (e) { /* user can copy by hand */ } }
	}

	function viewPrompt() {
		var pr = S.data.prompt;
		var copyBtn = el('button', { type: 'button', class: 'aihat-hire__btn', onclick: function () { copyText(pr.text, copyBtn); }, text: t('copy') });
		var mailBtn = el('button', { type: 'button', class: 'aihat-hire__btn aihat-hire__btn--ghost', onclick: function () {
			mailBtn.disabled = true;
			post('email_prompt', { prompt_id: pr.id }).then(function (res) {
				mailBtn.disabled = false;
				say(res.ok ? t('emailed') : errorText(res.error), res.ok ? 'ok' : 'error');
			});
		}, text: t('email_prompt') });
		return el('section', { class: 'aihat-hire__card aihat-hire__card--wide' },
			el('div', { class: 'aihat-hire__tags' }, tag('build', pr.build_tag), tag('role', pr.role_tag)),
			el('h2', { text: t('prompt_title', pr.crew_member) }),
			el('ol', { class: 'aihat-hire__steps' }, el('li', { text: t('prompt_step1') }), el('li', { text: t('prompt_step2') })),
			el('textarea', { class: 'aihat-hire__prompt', readonly: true, rows: '18', 'aria-label': t('prompt_title', pr.crew_member) }, pr.text),
			el('div', { class: 'aihat-hire__actions' }, copyBtn, mailBtn),
			messageBox(),
			el('h3', { text: t('platform_title') }),
			el('ul', { class: 'aihat-hire__platforms' }, ['claude', 'chatgpt', 'copilot', 'gemini'].map(function (k) { return el('li', { text: t('platform_' + k) }); })),
			el('button', { type: 'button', class: 'aihat-hire__link', onclick: function () { S.view = 'main'; S.message = ''; render(); scrollTo('aihat-hire-recs'); }, text: t('back_to_recs') })
		);
	}

	function footer() {
		if (!S.data) { return null; }
		return el('footer', { class: 'aihat-hire__footer' },
			el('span', { text: S.data.participant.email }),
			el('button', { type: 'button', class: 'aihat-hire__link', onclick: function () {
				post('sign_out', {}).then(function () { S.data = null; S.view = 'auth'; S.authMode = 'signin'; render(); });
			}, text: t('sign_out') }),
			el('button', { type: 'button', class: 'aihat-hire__link aihat-hire__del', onclick: function () {
				if (!window.confirm(t('delete_confirm'))) { return; }
				post('delete_account', {}).then(function (res) {
					if (res.ok) { S.data = null; S.view = 'deleted'; render(); } else { say(errorText(res.error)); }
				});
			}, text: t('delete_account') }));
	}

	function render() {
		var focusedName = document.activeElement && root.contains(document.activeElement) ? document.activeElement.getAttribute('data-f') || document.activeElement.name : null;
		var main;
		switch (S.view) {
			case 'loading': main = el('p', { class: 'aihat-hire__wait', text: '...' }); break;
			case 'noevent': main = el('div', { class: 'aihat-hire__card' }, el('p', { text: t('no_event') }),
				S.data ? null : el('button', { type: 'button', class: 'aihat-hire__link', onclick: function () { S.view = 'auth'; S.authMode = 'signin'; render(); }, text: t('have_account') })); break;
			case 'closed': main = el('div', { class: 'aihat-hire__card' }, el('p', { text: t('event_closed') }),
				el('button', { type: 'button', class: 'aihat-hire__link', onclick: function () { S.view = 'auth'; S.authMode = 'signin'; render(); }, text: t('have_account') })); break;
			case 'auth': main = viewAuth(); break;
			case 'code': main = viewCode(); break;
			case 'industry': main = viewIndustry(); break;
			case 'prompt': main = viewPrompt(); break;
			case 'deleted': main = el('div', { class: 'aihat-hire__card' }, el('p', { text: t('deleted') })); break;
			case 'error': main = el('div', { class: 'aihat-hire__card' }, messageBox()); break;
			default: main = el('div', {}, viewTasks(), viewRecs());
		}
		// Typing in a task row must not be interrupted by a full re-render.
		if (S.view === 'main' && root.querySelector('.aihat-hire__tasks') && focusedName && root.contains(document.activeElement) && document.activeElement.closest('.aihat-hire__task') ) {
			// Partial refresh: replace only the recommendations and status areas.
			var recs = root.querySelector('.aihat-hire__recs');
			var fresh = viewRecs();
			if (recs && fresh) { recs.replaceWith(fresh); } else if (!recs && fresh) { root.querySelector('.aihat-hire__body').appendChild(fresh); }
			return;
		}
		root.textContent = '';
		root.appendChild(el('div', { class: 'aihat-hire__wrap' }, header(), el('div', { class: 'aihat-hire__body' }, main), footer()));
	}

	load();
}());
