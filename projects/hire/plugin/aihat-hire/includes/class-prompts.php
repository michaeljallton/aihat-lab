<?php
/**
 * Instructions sent to the model for the three calls the tool makes. Kept
 * industry-neutral: nothing here assumes a sector. Pure PHP.
 */

namespace AIHat\Hire;

final class Prompts {

	private const STYLE = 'Write in plain, professional American English. Never use em dashes or en dashes; use a space, hyphen, space instead. Never use AI cliches or hype words such as delve, unleash, unlock, tapestry, supercharge, seamless, elevate, game-changer or revolutionize. Never state a statistic, a percentage or a price. Never use the phrases "Admin Drag", "Toggle Tax", "Pilot Purgatory", "Digital Crew" or "Patient Architect".';

	/**
	 * Industry suggestion from title and company.
	 *
	 * @return array{system:string, user:string}
	 */
	public static function industry( string $title, string $company ): array {
		$system = 'You suggest the industry a person works in from their job title and company name. Answer with a short, common industry label of one to four words, such as "Commercial insurance", "Residential real estate", "Accounting", "Higher education" or "Software". If the company name is unfamiliar, infer from the title and name as best you can and keep the label general. ' . self::STYLE;
		$user   = "Job title: {$title}\nCompany: {$company}\n\nWhat industry does this person most likely work in?";
		return compact( 'system', 'user' );
	}

	/**
	 * Five recommended crew members from the participant's tasks.
	 *
	 * @param array<string,string> $person {name,title,company,industry}
	 * @param array<int, array{id:int, tab:string, name:string, description:string, weekly_minutes:int}> $tasks
	 * @return array{system:string, user:string}
	 */
	public static function recommendations( array $person, array $tasks ): array {
		$system = implode(
			"\n\n",
			array(
				'You help a working professional decide which AI assistants to hire first. Each assistant is a "crew member": a named AI helper with one defined job, owned and managed by the person. You will receive their recurring tasks with how much time each takes per week. Recommend exactly ' . Schemas::CARD_COUNT . ' crew members that would take the most real time off their plate.',
				'How to choose: favor tasks that eat the most weekly time and that AI handles well (drafting, summarizing, formatting, organizing notes, preparing documents, researching, checking work against a standard, briefing, answering questions from known material). A crew member may cover one task or several related tasks. Do not recommend a crew member for work that needs a human relationship, a physical presence or a judgment call that should stay with a person; the person keeps every decision. Every crew member must cover at least one listed task, and you may only use the task ids given.',
				'For each crew member provide:' . "\n"
				. '- name: a memorable, role-style name of two to four words starting with "The", such as "The Appointment Scribe", "The Approval Chaser" or "The Plan Desk". Never end a name with "Tax".' . "\n"
				. '- build_tag: "skill" when the work is a repeatable procedure with a consistent output, the same steps and the same format every time; "project" when its value is standing knowledge or context it consults over time, such as documents, reference material or ongoing threads.' . "\n"
				. '- role_tag: "scout" if it finds and qualifies (leads, claims, opportunities, research); "scribe" if it handles the admin (notes, CRM, docs, follow-ups); "coach" if it briefs, answers and readies the team.' . "\n"
				. '- drag_name: a short, punchy name for the friction it ends, written as a verb phrase such as "Ends the Spec Hunt" or "Retires the Second Meeting". Never end it with "Tax" and never coin a new "Tax".' . "\n"
				. '- drag_line: one sentence describing that friction in plain terms.' . "\n"
				. '- description: what it does, two sentences at most.' . "\n"
				. '- produces: two to four concrete deliverables it hands back.' . "\n"
				. '- task_ids: the ids of the listed tasks it covers.' . "\n"
				. '- suitability: an integer from 1 to 5 for how well those tasks suit an AI assistant today (5 = very well).' . "\n"
				. '- preflight: one to three specific files or examples to gather before building it.',
				'Never invent numbers. Do not mention time savings, hours or percentages in any text; the tool calculates and shows time itself. Make the five crew members distinct from each other. ' . self::STYLE,
			)
		);

		$lines = array();
		foreach ( $tasks as $t ) {
			$line = sprintf( '[id %d] (%s) %s - %d minutes a week', $t['id'], $t['tab'], $t['name'], $t['weekly_minutes'] );
			if ( '' !== trim( (string) $t['description'] ) ) {
				$line .= ' - ' . $t['description'];
			}
			$lines[] = $line;
		}
		$user = sprintf(
			"About the person: %s, %s at %s, working in %s.\n\nTheir recurring tasks:\n%s\n\nRecommend exactly %d crew members.",
			$person['name'],
			$person['title'],
			$person['company'],
			$person['industry'],
			implode( "\n", $lines ),
			Schemas::CARD_COUNT
		);
		return compact( 'system', 'user' );
	}

	/**
	 * The variable parts of the interview prompt for one chosen crew member.
	 *
	 * @param array<string,string> $person
	 * @param array<string,mixed>  $card
	 * @param array<int, array{name:string, weekly_minutes:int}> $tasks Covered tasks.
	 * @return array{system:string, user:string}
	 */
	public static function prompt_parts( array $person, array $card, array $tasks ): array {
		$system = implode(
			"\n\n",
			array(
				'You draft the variable parts of an interview prompt. The person will paste the finished prompt into an AI tool, which will interview them and then write the onboarding packet for a new AI crew member. Write every part in the first person, as the person speaking to that AI tool ("I", "my"). Be specific to their industry, role and the tasks listed, without inventing facts about their company.',
				'Provide:' . "\n"
				. '- specialist_role: a specialist role for the interviewing AI that matches the industry and this crew member, written as a lowercase noun phrase that fits after "an Expert Prompt Engineer and", such as "commercial insurance operations specialist".' . "\n"
				. '- job_paragraph: one paragraph of two to four sentences describing what the crew member takes in, what it produces and where the output goes. Do not start with the crew member\'s name.' . "\n"
				. '- drag_line: one sentence describing the friction it ends, ending with a period.' . "\n"
				. '- not_do_line: one sentence on what it does not do and which decisions stay with me.' . "\n"
				. '- territories: six to eight interview areas, in priority order, each one line, covering where the inputs come from, what a complete output must contain, who receives it, the formats and systems it must fit, the rules it must follow, and the best example of this work I already have.' . "\n"
				. '- structural_rule: one short rule for the shape of this crew member\'s output, without a final period, such as "a one-page briefing with a two-sentence summary up top".' . "\n"
				. '- compliance_line: one or two sentences naming the industry rules that apply to this work if the industry is regulated (for example insurance, financial services, healthcare, legal or real estate), or the company policies that apply if it is not. End with a period.',
				self::STYLE,
			)
		);
		$task_lines = array();
		foreach ( $tasks as $t ) {
			$task_lines[] = '- ' . $t['name'];
		}
		$user = sprintf(
			"About me: %s, %s at %s, working in %s.\n\nThe crew member: %s.\nBuild type: %s.\nRole: %s.\nWhat it does: %s\nWhat it produces: %s\nThe friction it ends: %s - %s\nTasks it takes over:\n%s",
			$person['name'],
			$person['title'],
			$person['company'],
			$person['industry'],
			$card['name'],
			'skill' === $card['build_tag'] ? 'Skill (a repeatable procedure)' : 'Project or Notebook (standing knowledge it consults)',
			ucfirst( (string) $card['role_tag'] ),
			$card['description'],
			implode( '; ', (array) $card['produces'] ),
			$card['drag_name'],
			$card['drag_line'],
			implode( "\n", $task_lines )
		);
		return compact( 'system', 'user' );
	}
}
