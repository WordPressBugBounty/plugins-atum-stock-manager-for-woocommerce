<?php
/**
 * Extender for the WP's media endpoint
 *
 * @since       1.9.19
 * @author      BE REBEL - https://berebel.studio
 * @copyright   ©2026 Stock Management Labs™
 *
 * @package     Atum\Api
 * @subpackage  Extenders
 */

namespace Atum\Api\Extenders;

defined( 'ABSPATH' ) || die;

class Media {

	/**
	 * The singleton instance holder
	 *
	 * @var Media
	 */
	private static $instance;

	/**
	 * Registered post types allowed for the current linked_post_type filter.
	 *
	 * @var string[]
	 */
	private $linked_post_types = array();

	/**
	 * Media constructor
	 *
	 * @since 1.9.19
	 */
	private function __construct() {

		add_filter( 'rest_attachment_collection_params', array( $this, 'add_params' ), 10, 2 );

		add_filter( 'rest_attachment_query', array( $this, 'maybe_filter_linked_post_type' ), 10, 2 );

	}

	/**
	 * Add extra params to the media endpoint.
	 *
	 * @since 1.9.19
	 *
	 * @param array  $query_params
	 * @param string $post_type
	 *
	 * @return array
	 */
	public function add_params( $query_params, $post_type ) {

		$query_params['linked_post_type'] = array(
			'default'     => NULL,
			'description' => __( 'Limit result set to attachments linked to a particular post type.', ATUM_TEXT_DOMAIN ),
			'type'        => 'string',
		);

		return $query_params;

	}

	/**
	 * Check if the endpoint is being filtered by linked_post_type
	 *
	 * When the parameter holds no registered post type, post__in is set to array( 0 ) so the query matches nothing.
	 *
	 * @since 1.9.19
	 *
	 * @param array            $args
	 * @param \WP_REST_Request $request
	 *
	 * @return array The query args.
	 */
	public function maybe_filter_linked_post_type( $args, $request ) {

		// Reset the stored post types on every request so no state leaks between requests (singleton).
		$this->linked_post_types = array();

		if ( ! empty( $request['linked_post_type'] ) ) {

			// SECURITY: allow-list against the registered post types (these values are later bound into
			// a post_type IN (...) clause), instead of relying on esc_attr as a SQL sanitizer.
			$requested_post_types = array_map( 'trim', explode( ',', (string) $request['linked_post_type'] ) );
			$linked_post_types    = array_values( array_intersect( $requested_post_types, get_post_types() ) );

			if ( $linked_post_types ) {
				$this->linked_post_types = $linked_post_types;
				add_filter( 'posts_clauses', array( $this, 'filter_linked_post_type' ), 10, 2 );
			}
			else {
				// No registered post type was requested, so no attachment can be linked to it: match nothing
				// instead of ignoring the filter and returning all the media.
				$args['post__in'] = array( 0 );
			}

		}

		return $args;

	}

	/**
	 * Add extra filtering to the media endpoint for the linked_post_type param
	 *
	 * For every requested post type whose private posts the current user cannot read, only publicly
	 * viewable types in a public status count as linked posts (attachments also allow "inherit").
	 *
	 * @since 1.9.19
	 *
	 * @param string[] $clauses {
	 *     Associative array of the clauses for the query.
	 *
	 *     @type string $where    The WHERE clause of the query.
	 *     @type string $groupby  The GROUP BY clause of the query.
	 *     @type string $join     The JOIN clause of the query.
	 *     @type string $orderby  The ORDER BY clause of the query.
	 *     @type string $distinct The DISTINCT clause of the query.
	 *     @type string $fields   The SELECT clause of the query.
	 *     @type string $limits   The LIMIT clause of the query.
	 * }
	 * @param WP_Query $query   The WP_Query instance (passed by reference).
	 */
	public function filter_linked_post_type( $clauses, $query ) {

		remove_filter( 'posts_clauses', array( $this, 'filter_linked_post_type' ) );

		global $wpdb;

		if ( $this->linked_post_types ) {

			// SECURITY: a featured image linked from a draft or private post reveals that the post exists, so a user
			// who cannot read the private posts of a post type only gets links from viewable public posts.
			$unrestricted_post_types = $public_only_post_types = array();
			$restrict_attachments    = FALSE;

			foreach ( $this->linked_post_types as $linked_post_type ) {

				$post_type_object = get_post_type_object( $linked_post_type );

				if ( $post_type_object && current_user_can( $post_type_object->cap->read_private_posts ) ) {
					$unrestricted_post_types[] = $linked_post_type;
				}
				elseif ( 'attachment' === $linked_post_type ) {
					$restrict_attachments = TRUE;
				}
				elseif ( $post_type_object && is_post_type_viewable( $post_type_object ) ) {
					$public_only_post_types[] = $linked_post_type;
				}

			}

			$public_statuses = array_values( get_post_stati( array( 'public' => TRUE ) ) );
			$restrictions    = array();

			if ( $public_only_post_types ) {
				$restrictions[] = array( $public_only_post_types, $public_statuses );
			}

			// Attachments have the "inherit" status and the media collection already lists them, so they keep
			// counting as linked posts.
			if ( $restrict_attachments ) {
				$restrictions[] = array( array( 'attachment' ), array_merge( $public_statuses, array( 'inherit' ) ) );
			}

			$conditions = $values = array();

			if ( $unrestricted_post_types ) {
				$conditions[] = 'p.post_type IN (' . self::get_placeholders( $unrestricted_post_types ) . ')';
				$values       = $unrestricted_post_types;
			}

			foreach ( $restrictions as $restriction ) {

				list( $post_types, $statuses ) = $restriction;

				// Without any allowed status, these post types have no linked post to show.
				if ( ! $statuses ) {
					continue;
				}

				$conditions[] = '(p.post_type IN (' . self::get_placeholders( $post_types ) . ') AND p.post_status IN (' . self::get_placeholders( $statuses ) . '))';
				$values       = array_merge( $values, $post_types, $statuses );

			}

			if ( $conditions ) {

				$conditions = implode( ' OR ', $conditions );

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Generated %s placeholders, one per allow-listed post type and allowed status.
				$where_clause = $wpdb->prepare( "
					SELECT DISTINCT pm.meta_value FROM $wpdb->posts p
					LEFT JOIN $wpdb->postmeta pm ON (pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id')
					WHERE $conditions
				", $values );
				// phpcs:enable

				$clauses['where'] .= " AND $wpdb->posts.ID IN ($where_clause)";

			}
			else {
				// None of the requested post types has a linked post to show, so no attachment matches.
				$clauses['where'] .= ' AND 1 = 0';
			}

		}

		return $clauses;

	}

	/**
	 * Get a comma-separated list of %s placeholders, one per given value
	 *
	 * @since 2.0.5
	 *
	 * @param array $values
	 *
	 * @return string
	 */
	private static function get_placeholders( array $values ) {

		return implode( ', ', array_fill( 0, count( $values ), '%s' ) );

	}


	/****************************
	 * Instance methods
	 ****************************/

	/**
	 * Cannot be cloned
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cheatin&#8217; huh?', ATUM_TEXT_DOMAIN ), '1.0.0' );
	}

	/**
	 * Cannot be serialized
	 */
	public function __sleep() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cheatin&#8217; huh?', ATUM_TEXT_DOMAIN ), '1.0.0' );
	}

	/**
	 * Get Singleton instance
	 *
	 * @return Media instance
	 */
	public static function get_instance() {
		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

}
