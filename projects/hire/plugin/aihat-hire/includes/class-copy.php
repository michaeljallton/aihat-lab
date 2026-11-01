<?php
/**
 * Every word a participant sees, in one place for PAO's pass.
 * PLACEHOLDER COPY: not final until PAO delivers it.
 * Filterable with the aihat_hire_copy filter.
 */

namespace AIHat\Hire;

final class Copy {

	public static function all(): array {
		$copy = array(
			'app_title'          => 'Hire Your First Crew Member',
			'app_intro'          => 'List the work that fills your week. Get five crew members worth hiring, then a prompt that sets one up in your own AI tool.',
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
			'privacy'            => 'We store your name, email, title, company and the tasks you enter so the tool can make recommendations. Your tasks are sent to an AI provider to make them. We delete your account 12 months after your last sign-in, and you can delete it yourself at any time.',
			'privacy_link'       => 'Privacy policy',
			'send_code'          => 'Email me a code',
			'have_account'       => 'Already have an account? Sign in',
			'need_account'       => 'New here? Create an account',
			'code_title'         => 'Check your email',
			'code_help'          => 'We sent a 6-digit code to %s. Type it here on whichever device you want to use. It works for 15 minutes.',
			'code_label'         => '6-digit code',
			'verify'             => 'Sign in',
			'resend'             => 'Send a new code',
			'change_email'       => 'Use a different email',

			'industry_title'     => 'Confirm your industry',
			'industry_help'      => 'We suggested this from your title and company. Change it if it is wrong.',
			'industry_wait'      => 'Working out your industry...',
			'industry_label'     => 'Industry',
			'industry_save'      => 'Looks right',

			'tasks_title'        => 'Your recurring work',
			'tasks_help'         => 'Add the tasks that come around every day or every week. Rough numbers are fine.',
			'tab_daily'          => 'Daily',
			'tab_weekly'         => 'Weekly',
			'task_name'          => 'Task',
			'task_desc'          => 'Short description (optional)',
			'task_time'          => 'Time each time',
			'unit_minutes'       => 'minutes',
			'unit_hours'         => 'hours',
			'freq_daily'         => 'Times a day',
			'freq_weekly'        => 'Times a week',
			'per_week'           => 'a week',
			'add_task'           => 'Add a task',
			'delete_task'        => 'Delete',
			'tab_total'          => 'Total for this tab: %s a week',
			'all_total'          => 'All tasks: %s a week',
			'workdays_note'      => 'Daily tasks are counted over a %d-day week.',
			'saved'              => 'Saved',
			'copy_tasks'         => 'Copy my tasks from my last workshop',
			'min_tasks'          => 'Add at least %d tasks to get recommendations.',

			'generate'           => 'Show my five crew members',
			'regenerate'         => 'Try again with my current tasks',
			'generating'         => 'Picking your crew members. This can take up to a minute.',
			'queued'             => 'You are in line. %d ahead of you.',
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

			'prompt_wait'        => 'Writing your interview prompt. This can take up to a minute.',
			'prompt_title'       => 'Your interview prompt for %s',
			'prompt_step1'       => 'Open a brand-new chat in your AI tool and paste in the whole prompt.',
			'prompt_step2'       => 'Set aside about twenty minutes to answer its questions.',
			'copy'               => 'Copy prompt',
			'copied'             => 'Copied',
			'email_prompt'       => 'Email me this prompt',
			'emailed'            => 'Sent. Check your inbox.',
			'back_to_recs'       => 'Back to my five',
			'platform_title'     => 'Where to set it up',
			'platform_claude'    => 'Claude: paid users can set up Skills or Projects. Free users save the instructions to a document and paste them into a new chat each time.',
			'platform_chatgpt'   => 'ChatGPT: paid users can set up Custom GPTs or Projects. Free users do the same as above.',
			'platform_copilot'   => 'Copilot: paid users can set up Agents or Notebooks. Free users do the same as above.',
			'platform_gemini'    => 'Gemini: paid users can set up Gems or Notebooks. Free users do the same as above.',

			'sign_out'           => 'Sign out',
			'delete_account'     => 'Delete my account',
			'delete_confirm'     => 'This deletes your account, your tasks and your prompts. It cannot be undone. Delete everything?',
			'deleted'            => 'Your account and data are deleted.',

			'err_generic'        => 'Something went wrong. Please try again.',
			'err_busy'           => 'The AI service is busy. Please try again in a minute.',
			'err_ai_failed'      => 'We could not get a usable answer this time. Please try again.',
			'err_network'        => 'Connection lost. Check your connection and try again.',
			'err_bad_email'      => 'Enter a valid email address.',
			'err_required'       => 'Please fill in every field.',
			'err_no_account'     => 'We could not find an account for that email. Create one instead.',
			'err_rate'           => 'Too many requests. Please wait a few minutes and try again.',
			'err_wrong_code'     => 'That code is not right. Check it and try again.',
			'err_expired'        => 'That code has expired. Send a new one.',
			'err_too_many'       => 'Too many wrong tries. Send a new code.',
			'err_no_code'        => 'Send a new code to sign in.',
			'err_daily_cap'      => 'You have reached today\'s limit. Please come back tomorrow.',
			'err_session'        => 'Your session has ended. Please sign in again.',
			'err_task'           => 'Check this task: it needs a name, a time and how often it happens.',
			'err_max_tasks'      => 'You have reached the maximum number of tasks.',
			'err_mail'           => 'We could not send the email. Please try again.',

			'mail_code_subject'  => 'Your sign-in code: %s',
			'mail_code_body'     => "Your sign-in code for Hire Your First Crew Member is:\n\n%s\n\nIt works for 15 minutes. If you did not ask for it, you can ignore this email.\n\nThe AI Hat",
			'mail_prompt_subject'=> 'Your interview prompt for %s',
			'mail_prompt_body'   => "Here is your interview prompt for %s.\n\nOpen a brand-new chat in your AI tool, paste in everything between the lines, and set aside about twenty minutes.\n\n----------\n\n%s\n----------\n\nThe AI Hat",
		);
		return function_exists( 'apply_filters' ) ? apply_filters( 'aihat_hire_copy', $copy ) : $copy;
	}

	public static function get( string $key ): string {
		$all = self::all();
		return $all[ $key ] ?? $key;
	}
}
