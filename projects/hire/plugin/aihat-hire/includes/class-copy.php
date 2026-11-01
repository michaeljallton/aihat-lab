<?php
/**
 * Every word a participant sees, in one place for PAO's pass.
 * PAO pass 1, Nov 1 2026. Changes to this file go back through PAO.
 * Filterable with the aihat_hire_copy filter.
 */

namespace AIHat\Hire;

final class Copy {

	public static function all(): array {
		$copy = array(
			'app_title'          => 'Hire Your First Crew Member',
			'app_intro'          => 'Start with the work that fills your week. You\'ll get five crew members worth hiring and a prompt that sets one up in your own AI tool. You\'ll be its manager, and every call that matters stays with you.',
			'no_event'           => 'Open this tool from the link your workshop shared.',
			'event_closed'       => 'This workshop link is closed. Ask your host for the current one.',

			'signup_title'       => 'Create your account',
			'signin_title'       => 'Sign in',
			'first_name'         => 'First name',
			'last_name'          => 'Last name',
			'email'              => 'Work email',
			'title'              => 'Job title',
			'company'            => 'Company',
			'consent'            => 'Yes, send me email from The AI Hat. I can unsubscribe at any time.',
			'privacy'            => 'We store your name, email, title, company and the tasks you enter, and we send your tasks to an AI provider to make your recommendations. We\'ll delete your account 12 months after your last sign-in, and you can delete it yourself at any time.',
			'privacy_link'       => 'Privacy policy',
			'send_code'          => 'Email me a code',
			'have_account'       => 'Already have an account? Sign in',
			'need_account'       => 'New here? Create an account',
			'code_title'         => 'Check your email',
			'code_help'          => 'We\'ve sent a 6-digit code to %s. Enter it on whichever device you\'ll work on, phone or laptop. It\'s good for 15 minutes.',
			'code_label'         => '6-digit code',
			'verify'             => 'Sign in',
			'resend'             => 'Send a new code',
			'change_email'       => 'Use a different email',

			'industry_title'     => 'Confirm your industry',
			'industry_help'      => 'We guessed this from your title and company. Fix it if we got it wrong.',
			'industry_wait'      => 'Reading your title and company...',
			'industry_label'     => 'Industry',
			'industry_save'      => 'Looks right',

			'tasks_title'        => 'Your recurring work',
			'tasks_help'         => 'Add the work that comes around every day or every week: the re-keying, the status updates, the report you rebuild every Friday. Rough numbers are fine. You\'re looking for where the hours go.',
			'tab_daily'          => 'Daily',
			'tab_weekly'         => 'Weekly',
			'task_name'          => 'Task',
			'task_desc'          => 'Short description (optional)',
			'task_time'          => 'Time it takes',
			'unit_minutes'       => 'minutes',
			'unit_hours'         => 'hours',
			'freq_daily'         => 'Times a day',
			'freq_weekly'        => 'Times a week',
			'per_week'           => 'a week',
			'add_task'           => 'Add a task',
			'delete_task'        => 'Delete',
			'tab_total'          => 'Total for this tab: %s a week',
			'all_total'          => 'All tasks: %s a week',
			'workdays_note'      => 'Daily tasks count over a %d-day week.',
			'saved'              => 'Saved',
			'copy_tasks'         => 'Copy my tasks from my last workshop',
			'min_tasks'          => 'Add at least %d tasks to see your recommendations.',

			'generate'           => 'Show my five crew members',
			'regenerate'         => 'Try again with my current tasks',
			'generating'         => 'Reading your week and picking five crew members. This takes up to a minute.',
			'queued'             => 'You\'re in line, with %d ahead of you.',
			'recs_title'         => 'Five crew members worth hiring',
			'tag_skill'          => 'Skill',
			'tag_project'        => 'Project/Notebook',
			'tag_scout'          => 'Scout',
			'tag_scribe'         => 'Scribe',
			'tag_coach'          => 'Coach',
			'produces'           => 'What it produces',
			'covers'             => 'Tasks it covers (%s a week)',
			'preflight'          => 'Pre-flight checklist',
			'pick'               => 'Hire this one',

			'prompt_wait'        => 'Writing your interview prompt. This takes up to a minute.',
			'prompt_title'       => 'Your interview prompt for %s',
			'prompt_step1'       => 'Open a brand-new chat in your AI tool and paste in the whole prompt.',
			'prompt_step2'       => 'Set aside about twenty minutes. It\'ll interview you one question at a time, and your answers become its onboarding packet.',
			'copy'               => 'Copy prompt',
			'copied'             => 'Copied',
			'email_prompt'       => 'Email me this prompt',
			'emailed'            => 'Sent. Check your inbox.',
			'back_to_recs'       => 'Back to my five crew members',
			'platform_title'     => 'Where to set it up',
			'platform_claude'    => 'Claude: paid users can set up Skills or Projects. Free users save the instructions to a document and paste them into a new chat each time.',
			'platform_chatgpt'   => 'ChatGPT: paid users can set up Custom GPTs or Projects. Free users do the same as above.',
			'platform_copilot'   => 'Copilot: paid users can set up Agents or Notebooks. Free users do the same as above.',
			'platform_gemini'    => 'Gemini: paid users can set up Gems or Notebooks. Free users do the same as above.',

			'sign_out'           => 'Sign out',
			'delete_account'     => 'Delete my account',
			'delete_confirm'     => 'This deletes your account, your tasks and your prompts, and it can\'t be undone. Delete everything?',
			'deleted'            => 'Your account and everything in it are deleted.',

			'err_generic'        => 'Something went wrong. Please try again.',
			'err_busy'           => 'The AI service is busy right now. Give it a minute and try again.',
			'err_ai_failed'      => 'We couldn\'t get a usable answer this time. Please try again.',
			'err_network'        => 'The connection dropped. Check it and try again.',
			'err_bad_email'      => 'Enter a valid email address.',
			'err_required'       => 'Please fill in every field.',
			'err_no_account'     => 'We couldn\'t find an account for that email. Create one instead.',
			'err_rate'           => 'Too many requests. Wait a few minutes and try again.',
			'err_wrong_code'     => 'That code isn\'t right. Check it and try again.',
			'err_expired'        => 'That code has expired. Send yourself a new one.',
			'err_too_many'       => 'Too many wrong tries. Send yourself a new code.',
			'err_no_code'        => 'Send yourself a new code to sign in.',
			'err_daily_cap'      => 'You\'ve reached today\'s limit. Come back tomorrow.',
			'err_session'        => 'You\'ve been signed out. Please sign in again.',
			'err_task'           => 'This task needs a name, a time and how often it happens.',
			'err_max_tasks'      => 'You\'ve reached the maximum number of tasks.',
			'err_mail'           => 'We couldn\'t send the email. Please try again.',

			'mail_code_subject'  => 'Your sign-in code: %s',
			'mail_code_body'     => "Your sign-in code for Hire Your First Crew Member is:\n\n%s\n\nIt's good for 15 minutes. If you didn't ask for it, you can ignore this email.\n\nThe AI Hat",
			'mail_prompt_subject'=> 'Your interview prompt for %s',
			'mail_prompt_body'   => "Here's your interview prompt for %s.\n\nOpen a brand-new chat in your AI tool, paste in everything between the lines, and set aside about twenty minutes. It'll interview you one question at a time, then write the onboarding packet from your answers.\n\n----------\n\n%s\n----------\n\nThe AI Hat",
		);
		return function_exists( 'apply_filters' ) ? apply_filters( 'aihat_hire_copy', $copy ) : $copy;
	}

	public static function get( string $key ): string {
		$all = self::all();
		return $all[ $key ] ?? $key;
	}
}
