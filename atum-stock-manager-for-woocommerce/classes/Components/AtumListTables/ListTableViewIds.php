<?php
/**
 * Align Stock Central view counters with the same product IDs the table queries.
 *
 * @package         Atum\Components
 * @subpackage      AtumListTables
 * @author          BE REBEL - https://berebel.studio
 * @copyright       ©2026 Stock Management Labs™
 *
 * @since           2.0.4
 */

namespace Atum\Components\AtumListTables;

defined( 'ABSPATH' ) || die;

/**
 * Class ListTableViewIds
 */
final class ListTableViewIds {

	/**
	 * Sanitize a list of product IDs (unique positive integers).
	 *
	 * @since 2.0.4
	 *
	 * @param mixed $ids
	 *
	 * @return int[]
	 */
	public static function sanitize_ids( $ids ) {

		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return [];
		}

		$ids = array_filter( array_map( 'intval', $ids ) );

		return array_values( array_unique( $ids ) );

	}

	/**
	 * Keep only candidate IDs that belong to the set the current view can show.
	 *
	 * Used so restock_status rows from an unscoped SQL query cannot inflate
	 * the badge or become post__in IDs the controlled table then drops.
	 *
	 * @since 2.0.4
	 *
	 * @param array $candidate_ids
	 * @param array $allowed_ids
	 *
	 * @return int[]
	 */
	public static function restrict_to_allowed_ids( $candidate_ids, $allowed_ids ) {

		$candidate_ids = self::sanitize_ids( $candidate_ids );
		$allowed_ids   = self::sanitize_ids( $allowed_ids );

		if ( empty( $candidate_ids ) || empty( $allowed_ids ) ) {
			return [];
		}

		return array_values( array_intersect( $candidate_ids, $allowed_ids ) );

	}

	/**
	 * Count unique IDs — the same set used as the table's post__in.
	 *
	 * @since 2.0.4
	 *
	 * @param array $ids
	 *
	 * @return int
	 */
	public static function count_unique_ids( $ids ) {
		return count( self::sanitize_ids( $ids ) );
	}

	/**
	 * Whether IDs can be passed to WP_Query as post__in.
	 *
	 * An empty post__in is ignored by WordPress, which removes the ID restriction.
	 *
	 * @since 2.0.4
	 *
	 * @param array $ids
	 *
	 * @return bool
	 */
	public static function has_restricting_post_in( $ids ) {
		return ! empty( self::sanitize_ids( $ids ) );
	}

}
