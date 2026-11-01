<?php
/**
 * Weekly time maths for task rows. Pure functions, no WordPress.
 *
 * Daily tab:  minutes per occurrence x times per day x workdays per week.
 * Weekly tab: minutes per occurrence x times per week.
 */

namespace AIHat\Hire;

final class Time_Math {

	public const TABS  = array( 'daily', 'weekly' );
	public const UNITS = array( 'minutes', 'hours' );

	/** Upper bounds that catch typos without getting in a real user's way. */
	public const MAX_MINUTES   = 600; // Ten hours per occurrence.
	public const MAX_FREQUENCY = 100;

	/**
	 * Converts the entered duration to whole minutes.
	 */
	public static function to_minutes( float $amount, string $unit ): int {
		$minutes = 'hours' === $unit ? $amount * 60 : $amount;
		return (int) round( $minutes );
	}

	/**
	 * Weekly minutes for one row.
	 */
	public static function weekly_minutes( string $tab, int $minutes, float $frequency, int $workdays ): int {
		if ( $minutes <= 0 || $frequency <= 0 ) {
			return 0;
		}
		$per_week = 'daily' === $tab ? $frequency * $workdays : $frequency;
		return (int) round( $minutes * $per_week );
	}

	/**
	 * Totals per tab and overall. Each row needs tab, minutes and frequency.
	 *
	 * @param array<int, array{tab:string, minutes:int, frequency:float}> $rows
	 * @return array{daily:int, weekly:int, all:int}
	 */
	public static function totals( array $rows, int $workdays ): array {
		$totals = array(
			'daily'  => 0,
			'weekly' => 0,
			'all'    => 0,
		);
		foreach ( $rows as $row ) {
			$weekly = self::weekly_minutes( $row['tab'], (int) $row['minutes'], (float) $row['frequency'], $workdays );
			if ( isset( $totals[ $row['tab'] ] ) ) {
				$totals[ $row['tab'] ] += $weekly;
			}
			$totals['all'] += $weekly;
		}
		return $totals;
	}

	/**
	 * Human label for a number of minutes: "45 min", "2 hr", "3 hr 20 min".
	 */
	public static function label( int $minutes ): string {
		if ( $minutes < 60 ) {
			return $minutes . ' min';
		}
		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		return $rest ? sprintf( '%d hr %d min', $hours, $rest ) : sprintf( '%d hr', $hours );
	}

	/**
	 * Validates a task row as submitted. Returns an error code or '' when valid.
	 */
	public static function validate_row( string $tab, string $name, float $amount, string $unit, float $frequency ): string {
		if ( ! in_array( $tab, self::TABS, true ) ) {
			return 'bad_tab';
		}
		if ( '' === trim( $name ) ) {
			return 'name_required';
		}
		if ( ! in_array( $unit, self::UNITS, true ) ) {
			return 'bad_unit';
		}
		$minutes = self::to_minutes( $amount, $unit );
		if ( $minutes < 1 || $minutes > self::MAX_MINUTES ) {
			return 'bad_time';
		}
		if ( $frequency <= 0 || $frequency > self::MAX_FREQUENCY ) {
			return 'bad_frequency';
		}
		return '';
	}
}
