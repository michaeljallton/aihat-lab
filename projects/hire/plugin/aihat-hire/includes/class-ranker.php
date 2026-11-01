<?php
/**
 * Ranks recommendation cards. The model only names the tasks a card covers and
 * scores how well they suit AI; every number shown comes from here.
 *
 * Score = combined weekly minutes of the covered tasks x (suitability / 5).
 */

namespace AIHat\Hire;

final class Ranker {

	/**
	 * @param array<int, array<string, mixed>> $cards        Validated cards (task_ids, suitability).
	 * @param array<int, int>                  $task_minutes Task id => weekly minutes.
	 * @return array<int, array<string, mixed>> Cards with weekly_minutes and score, best first.
	 */
	public static function rank( array $cards, array $task_minutes ): array {
		foreach ( $cards as $i => $card ) {
			$weekly = 0;
			foreach ( array_unique( $card['task_ids'] ) as $id ) {
				$weekly += $task_minutes[ $id ] ?? 0;
			}
			$cards[ $i ]['weekly_minutes'] = $weekly;
			$cards[ $i ]['score']          = $weekly * ( (int) $card['suitability'] / 5 );
			$cards[ $i ]['_order']         = $i;
		}
		usort(
			$cards,
			static function ( $a, $b ) {
				return array( $b['score'], $a['_order'] ) <=> array( $a['score'], $b['_order'] );
			}
		);
		foreach ( $cards as $i => $card ) {
			unset( $cards[ $i ]['_order'] );
		}
		return array_values( $cards );
	}
}
