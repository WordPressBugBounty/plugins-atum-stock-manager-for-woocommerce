<?php
/**
 * The abstract class for the ATUM Order post types
 *
 * @package         Atum\Components
 * @subpackage      AtumOrders
 * @author          BE REBEL - https://berebel.studio
 * @copyright       ©2025 Stock Management Labs™
 *
 * @since           1.2.9
 */

namespace Atum\Components\AtumOrders;

defined( 'ABSPATH' ) || die;

use Atum\Components\AtumCapabilities;
use Atum\Components\AtumHelpGuide;
use Atum\Components\AtumMarketingPopup;
use Atum\Components\AtumOrders\Models\AtumOrderModel;
use Atum\Inc\Globals;
use Atum\Inc\Helpers;
use Atum\Inc\Main;
use Atum\PurchaseOrders\PurchaseOrders;


abstract class AtumOrderPostType {

	/**
	 * The post type labels
	 *
	 * @var array
	 */
	protected $labels = array();

	/**
	 * The meta boxes' labels
	 *
	 * @var array
	 */
	protected $metabox_labels = array();

	/**
	 * The capabilities used when registering the post type
	 *
	 * @var array
	 */
	protected $capabilities = array();

	/**
	 * The help guide for the list table page
	 *
	 * @var string
	 */
	protected $help_guide = '';

	/**
	 * Number of ATUM orders with unknown status
	 *
	 * @var int
	 */
	protected $unknown_status_orders = 0;
	
	/**
	 * Status that means an ATUM Order is finished
	 */
	const FINISHED = ATUM_PREFIX . 'completed';

	/**
	 * The ATUM Order items table name
	 */
	const ORDER_ITEMS_TABLE = 'atum_order_items';

	/**
	 * The ATUM Order item meta table name
	 */
	const ORDER_ITEM_META_TABLE = 'atum_order_itemmeta';

	/**
	 * Load the ATUM Order's common stuff
	 *
	 * @since 1.2.9
	 */
	protected function init() {

		// Add the ATUM Orders' meta table to wpdb.
		global $wpdb;

		if ( ! in_array( self::ORDER_ITEM_META_TABLE, $wpdb->tables ) ) {
			$wpdb->atum_order_itemmeta = $wpdb->prefix . self::ORDER_ITEM_META_TABLE;
			$wpdb->tables[]            = self::ORDER_ITEM_META_TABLE;
		}

		// Register the post type.
		add_action( 'init', array( $this, 'register_post_type' ), 5 ); // Using the same priority as WooCommerce.
		$post_type = static::POST_TYPE;

		if ( is_admin() ) {

			// Add the custom columns to the post type list table.
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_columns' ), 2 );
			add_filter( 'post_row_actions', array( $this, 'row_actions' ), 2, 100 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'sortable_columns' ) );
			add_filter( 'list_table_primary_column', array( $this, 'list_table_primary_column' ), 10, 2 );

			// Filters and sorts the ATUM Orders' list tables.
			add_filter( 'request', array( $this, 'request_query' ) );

			// Add meta boxes to ATUM Order UI.
			add_action( "add_meta_boxes_{$post_type}", array( $this, 'add_meta_boxes' ), 30 );

			// Save the meta boxes.
			add_action( "save_post_{$post_type}", array( $this, 'save_meta_boxes' ) );

			// Disable post type view mode options.
			add_filter( 'view_mode_post_types', array( $this, 'disable_view_mode_options' ) );

			// Disable Auto Save.
			add_action( 'admin_print_scripts', array( $this, 'disable_autosave' ) );

			// Post update messages.
			add_filter( 'post_updated_messages', array( $this, 'post_updated_messages' ) );

			// Bulk actions.
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add_bulk_actions' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_actions' ), 10, 3 );
			add_action( 'admin_notices', array( $this, 'bulk_admin_notices' ) );
			add_filter( 'bulk_post_updated_messages', array( $this, 'bulk_post_updated_messages' ), 10, 2 );

			// Enqueue scripts.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 11 );
			add_action( 'admin_footer', array( $this, 'print_scripts' ) );

			// Enhanced ATUM Orders search.
			add_filter( 'posts_search', array( $this, 'add_search_criteria_to_orders_list' ), 100, 2 );

			// Move the 'trash' status to the end of the list.
			add_filter( "views_edit-$post_type", array( $this, 'edit_list_table_views' ) );

			// Filter the unknown status in ATUM Orders list tables.
			add_action( 'load-edit.php', array( $this, 'maybe_add_unknown_status_filter' ), 9 );
			
		}

		// Recalculate the ATUM props for products within ATUM Orders, every time an ATUM Order is moved or restored from trash.
		add_action( 'trashed_post', array( $this, 'update_order_item_props' ) );
		add_action( 'untrashed_post', array( $this, 'update_order_item_props' ) );
		
		do_action( 'atum/order_post_type/init', $post_type );

		// Delete the ATUM orders with our own method to be able to clean up all the data.
		// When deleting from API, it's not really needed and does not work correctly.
		if ( ! Helpers::is_rest_request() ) {
			add_filter( 'pre_delete_post', array( $this, 'maybe_delete_atum_order' ), PHP_INT_MAX, 3 );
		}

	}

	/**
	 * Register the ATUM Order post type
	 *
	 * @param array $args
	 *
	 * @since 1.2.9
	 */
	public function register_post_type( $args = array() ) {

		$this->set_labels();
		$main_menu_item = Main::get_main_menu_item();
		$post_type      = static::POST_TYPE;

		$args = apply_filters( 'atum/order_post_type/post_type_args', wp_parse_args( array(
			'labels'              => $this->labels,
			'description'         => __( 'This is where ATUM orders are stored.', ATUM_TEXT_DOMAIN ),
			'public'              => FALSE,
			'show_ui'             => TRUE,
			'publicly_queryable'  => FALSE,
			'exclude_from_search' => TRUE,
			'show_in_menu'        => $main_menu_item['slug'],
			'hierarchical'        => FALSE,
			'show_in_nav_menus'   => FALSE,
			'rewrite'             => FALSE,
			'query_var'           => is_admin(),
			'supports'            => array( 'title', 'comments', 'custom-fields' ),
			'has_archive'         => FALSE,
			'capabilities'        => $this->capabilities,
			'map_meta_cap'        => TRUE,
		), $args ), $post_type );

		// Register the ATUM Order post type.
		register_post_type( $post_type, $args );

		// Register the post statuses.
		$atum_statuses = [];
		foreach ( static::get_statuses() as $status => $label ) {
			
			$count = "$label <span class='count'>(%s)</span>";
			
			$atum_statuses[ $status ] = array(
				'label'                     => $label,
				'public'                    => FALSE,
				'exclude_from_search'       => FALSE,
				'show_in_admin_all_list'    => 'trash' !== $status,
				'show_in_admin_status_list' => TRUE,
				/* translators: the count of orders in current status */
				'label_count'               => array(
					0          => $count,
					1          => $count,
					'singular' => $count,
					'plural'   => $count,
					'context'  => NULL,
					'domain'   => ATUM_TEXT_DOMAIN,
				),
			);
			
		}
		
		$atum_statuses = (array) apply_filters( 'atum/order_post_type/register_post_statuses', $atum_statuses );
		
		foreach ( $atum_statuses as $atum_status => $values ) {

			// Only register the statuses that starts with the ATUM prefix.
			if ( ! str_contains( $atum_status, ATUM_PREFIX ) ) {
				continue;
			}

			register_post_status( $atum_status, $values );

		}

		// Register the taxomony only if needed by the custom post type.
		if ( defined( 'static::TAXONOMY' ) && ! empty( static::TAXONOMY ) ) {

			$args = apply_filters( 'atum/order_post_type/taxonomy_args', wp_parse_args( array(
				'hierarchical'          => FALSE,
				'show_ui'               => FALSE,
				'show_in_nav_menus'     => FALSE,
				'query_var'             => is_admin(),
				'rewrite'               => FALSE,
				'public'                => FALSE,
				'update_count_callback' => array( $this, 'order_term_recount' ),
			), $args ));

			// Register the hidden order type taxonomy (if used).
			register_taxonomy( static::TAXONOMY, array( $post_type ), $args );

		}

	}

	/**
	 * Method for recounting order terms (types)
	 *
	 * @since 1.2.9
	 *
	 * @param array        $terms
	 * @param \WP_Taxonomy $taxonomy
	 */
	public function order_term_recount( $terms, $taxonomy ) {

		global $wpdb;

		// Custom version of the WP "_update_post_term_count()" function.
		$object_types = (array) $taxonomy->object_type;

		foreach ( $object_types as &$object_type ) {
			list( $object_type ) = explode( ':', $object_type );
		}

		$object_types = array_unique( $object_types );

		if ( $object_types ) {
			$object_types = esc_sql( array_filter( $object_types, 'post_type_exists' ) );
		}

		foreach ( (array) $terms as $term ) {
			$count = 0;

			// Count all the orders with one of the ATUM Order's statuses.
			if ( $object_types ) {
				$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_relationships, $wpdb->posts WHERE $wpdb->posts.ID = $wpdb->term_relationships.object_id AND post_status IN ('" . implode( "','", array_keys( static::get_statuses() ) ) . "') AND post_type IN ('" . implode( "', '", $object_types ) . "') AND term_taxonomy_id = %d", $term ) ); // phpcs:ignore
			}

			// This action is documented in wp-includes/taxonomy.php.
			do_action( 'edit_term_taxonomy', $term, $taxonomy->name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$wpdb->update( $wpdb->term_taxonomy, compact( 'count' ), array( 'term_taxonomy_id' => $term ) );

			// This action is documented in wp-includes/taxonomy.php.
			do_action( 'edited_term_taxonomy', $term, $taxonomy->name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

	}

	/**
	 * Filters and sorting handler
	 *
	 * @since 1.2.4
	 *
	 * @param  array $query_vars
	 *
	 * @return array
	 */
	public function request_query( $query_vars ) {

		global $typenow, $wp_post_statuses;

		if ( static::POST_TYPE === $typenow ) {

			// Sorting.
			if ( isset( $query_vars['orderby'] ) && 'total' === $query_vars['orderby'] ) {

				$query_vars = array_merge( $query_vars, array(
					'meta_key' => '_total',
					'orderby'  => 'meta_value_num',
				) );

			}

			// Status.
			if ( empty( $query_vars['post_status'] ) ) {
				
				// All the ATUM Order posts must have the custom statuses created for them.
				$statuses = array_keys( static::get_statuses() );
				
				foreach ( $statuses as $key => $status ) {
					if ( isset( $wp_post_statuses[ $status ] ) && FALSE === $wp_post_statuses[ $status ]->show_in_admin_all_list ) {
						unset( $statuses[ $key ] );
					}
				}

				$query_vars['post_status'] = $statuses;

			}

		}

		return $query_vars;

	}

	/**
	 * Customize the columns used in the ATUM Order's list table
	 *
	 * @since 1.2.4
	 *
	 * @param array $existing_columns
	 *
	 * @return array
	 */
	abstract public function add_columns( $existing_columns );

	/**
	 * Output custom columns for ATUM Order's list table
	 *
	 * @since 1.2.4
	 *
	 * @param string $column
	 *
	 * @return bool True if the column is rendered or False if not
	 */
	public function render_columns( $column ) {

		global $post;

		$output    = '';
		$post_type = static::POST_TYPE;

		switch ( $column ) {

			case 'status':
				$statuses = static::get_statuses();

				if ( ! array_key_exists( 'trash', $statuses ) ) {
					$statuses['trash'] = __( 'Trash', ATUM_TEXT_DOMAIN );
				}

				$status_colors = static::get_status_colors();
				$atum_order    = Helpers::get_atum_order_model( $post->ID, FALSE, $post_type );

				if ( ! is_wp_error( $atum_order ) ) {

					$status        = $atum_order->get_status();
					$status_name   = $statuses[ $status ] ?? sprintf( __( '%s (unknown)', ATUM_TEXT_DOMAIN ), $status );
					$status_color  = ' style="background-color: ';
					$status_color .= isset( $status_colors[ $status ] ) ? $status_colors[ $status ] . '"' : 'rgba(255,72,72,.5)"';

					$output = sprintf( '<div class="order-status-container"><div class="atum-order-status"><mark class="status-%s tips" data-tip="%s"' . $status_color . '></mark></div><span>%s</span></div>', esc_attr( sanitize_html_class( $status ) ), esc_attr( $status_name ), esc_html( $status_name ) );

				}

				break;

			case 'atum_order_title':
				$author = $post->post_author;

				if ( $author ) {

					$user = get_user_by( 'id', $author );

					if ( $user instanceof \WP_User ) {
						$username = ucwords( $user->display_name );
					}
					else {
						$username = __( 'User not found', ATUM_TEXT_DOMAIN );
					}

				}
				else {
					$username = 'ATUM';
				}

				if ( 'trash' !== $post->post_status ) {
					$output = '<a href="' . admin_url( 'post.php?post=' . absint( $post->ID ) . '&action=edit' ) . '" class="row-title"><strong>#' . esc_attr( $post->ID ) . ' ' . $username . '</strong></a>';
				}
				else {
					$output = '<strong>#' . esc_attr( $post->ID ) . ' ' . $username . '</strong>';
				}

				break;

			case 'date_created':
				$post_date = strtotime( $post->post_date );
				$output    = sprintf( '<abbr title="%1$s" class="atum-tooltip">%2$s</abbr>', date_i18n( 'Y-m-d H:i', $post_date ), date_i18n( get_option( 'date_format' ), $post_date ) );
				break;

			case 'last_modified':
				$output = sprintf( '<abbr title="%1$s" class="atum-tooltip">%2$s</abbr>', date_i18n( 'Y-m-d H:i', strtotime( $post->post_modified ) ), Helpers::get_relative_date( $post->post_modified ) );
				break;

			case 'notes':
				if ( $post->comment_count && AtumCapabilities::current_user_can( 'read_order_notes' ) ) {

					// Check the status of the post.
					$status = 'trash' !== $post->post_status ? '' : 'post-trashed';
					
					$latest_notes = get_comments( array(
						'post_id' => $post->ID,
						'number'  => 1,
						'status'  => $status,
					) );
					
					$latest_note = current( $latest_notes );

					if ( isset( $latest_note->comment_content ) && 1 === absint( $post->comment_count ) ) {
						$output = '<span class="note-on tips" data-tip="' . wc_sanitize_tooltip( $latest_note->comment_content ) . '">' . esc_attr__( 'Yes', ATUM_TEXT_DOMAIN ) . '</span>';
					}
					elseif ( isset( $latest_note->comment_content ) ) {
						/* translators: the notes' count */
						$output = '<span class="note-on tips" data-tip="' . wc_sanitize_tooltip( $latest_note->comment_content . '<br/><small style="display:block">' . sprintf( _n( 'plus %d other note', 'plus %d other notes', ( $post->comment_count - 1 ), ATUM_TEXT_DOMAIN ), $post->comment_count - 1 ) . '</small>' ) . '">' . esc_attr__( 'Yes', ATUM_TEXT_DOMAIN ) . '</span>';
					}
					else {
						/* translators: the notes' count */
						$output = '<span class="note-on tips" data-tip="' . wc_sanitize_tooltip( sprintf( _n( '%d note', '%d notes', $post->comment_count, ATUM_TEXT_DOMAIN ), $post->comment_count ) ) . '">' . esc_attr__( 'Yes', ATUM_TEXT_DOMAIN ) . '</span>';
					}

				}
				else {
					$output = '<span class="na">&ndash;</span>';
				}

				break;

			case 'total':
				$atum_order = Helpers::get_atum_order_model( $post->ID, FALSE, $post_type );

				if ( ! is_wp_error( $atum_order ) ) {
					$output = $atum_order->get_formatted_total();
				}

				break;

			case 'actions':
				$atum_order = Helpers::get_atum_order_model( $post->ID, FALSE, $post_type );

				if ( is_wp_error( $atum_order ) ) {
					break;
				}

				ob_start();

				?><p>
					<?php

					do_action( "atum/$post_type/admin_actions_start", $atum_order );

					$actions  = array();
					$statuses = static::get_statuses();

					if ( static::FINISHED !== $atum_order->get_status() ) {

						if ( isset( $statuses[ static::FINISHED ] ) ) {

							$actions['complete'] = array(
								'url'    => wp_nonce_url( admin_url( 'admin-ajax.php?action=atum_order_mark_status&status=' . static::FINISHED . "&atum_order_id=$post->ID" ), 'atum-order-mark-status' ),
								/* translators: Change the order's status to "finished" */
								'name'   => sprintf( __( 'Mark as %s', ATUM_TEXT_DOMAIN ), $statuses[ static::FINISHED ] ),
								'action' => 'complete',
								'target' => '_self',
								'icon'   => '<i class="atum-icon atmi-checkmark-circle"></i>',
							);

						}

					}

					$actions = apply_filters( "atum/$post_type/admin_order_actions", $actions, $atum_order );

					foreach ( $actions as $action ) {
						printf( '<a class="%1$s tips" target="%2$s" href="%3$s" data-tip="%4$s">%5$s</a>', esc_attr( $action['action'] ), esc_attr( $action['target'] ), esc_url( $action['url'] ), esc_attr( $action['name'] ), $action['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}

					do_action( "atum/$post_type/admin_actions_end", $atum_order ); ?>
				</p>
				<?php

				$output = ob_get_clean();
				break;

		}

		$output = apply_filters( "atum/$post_type/list_cell_content", $output, $column );

		if ( $output ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return ! empty( $output );

	}

	/**
	 * Set row actions for ATUM Order's list table
	 *
	 * @since 1.2.4
	 *
	 * @param array    $actions
	 * @param \WP_Post $post
	 *
	 * @return array
	 */
	public function row_actions( $actions, $post ) {

		if ( static::POST_TYPE === $post->post_type && isset( $actions['inline hide-if-no-js'] ) ) {
			unset( $actions['inline hide-if-no-js'] );
		}

		return apply_filters( "atum/$post->post_type/row_actions", $actions, $post );
	}

	/**
	 * Set the primary column for the ATUM Orders' table list
	 *
	 * @since 1.2.0
	 *
	 * @param string $default
	 * @param string $screen_id
	 *
	 * @return string
	 */
	public function list_table_primary_column( $default, $screen_id ) {

		if ( 'edit-' . static::POST_TYPE === $screen_id ) {
			return 'atum_order_title';
		}

		return $default;

	}

	/**
	 * Make columns sortable - https://gist.github.com/906872
	 *
	 * @since 1.2.4
	 *
	 * @param  array $columns
	 *
	 * @return array
	 */
	public function sortable_columns( $columns ) {

		$custom = array(
			'atum_order_title' => 'ID',
			'date_created'     => 'post_date',
			'last_modified'    => 'post_modified',
			'total'            => 'total',
		);

		unset( $columns['comments'] );

		return apply_filters( 'atum/order_post_type/sortable_columns', wp_parse_args( $custom, $columns ) );

	}

	/**
	 * Add the ATUM Order's meta boxes
	 *
	 * @since 1.2.9
	 */
	public function add_meta_boxes() {

		$post_type = static::POST_TYPE;

		// Data meta box.
		add_meta_box(
			'atum_order_data',
			! empty( $this->metabox_labels['data'] ) ? $this->metabox_labels['data'] : __( 'Data', ATUM_TEXT_DOMAIN ),
			array( $this, 'show_data_meta_box' ),
			$post_type,
			'normal',
			'high'
		);

		// Items meta box.
		add_meta_box(
			'atum_order_items',
			! empty( $this->metabox_labels['items'] ) ? $this->metabox_labels['items'] : __( 'Items', ATUM_TEXT_DOMAIN ),
			array( $this, 'show_items_meta_box' ),
			$post_type,
			'normal',
			'high'
		);

		// Notes meta box.
		if ( AtumCapabilities::current_user_can( 'read_order_notes' ) ) {

			add_meta_box(
				'atum_order_notes',
				! empty( $this->metabox_labels['notes'] ) ? $this->metabox_labels['notes'] : __( 'Notes', ATUM_TEXT_DOMAIN ),
				array( $this, 'show_notes_meta_box' ),
				$post_type,
				'side',
				'default'
			);

		}

		// Actions meta box.
		add_meta_box(
			'atum_order_actions',
			! empty( $this->metabox_labels['actions'] ) ? $this->metabox_labels['actions'] : __( 'Actions', ATUM_TEXT_DOMAIN ),
			array( $this, 'show_actions_meta_box' ),
			$post_type,
			'side',
			'high'
		);

		// Remove unneeded WP meta boxes.
		remove_meta_box( 'commentsdiv', $post_type, 'normal' );
		remove_meta_box( 'commentstatusdiv', $post_type, 'normal' );
		remove_meta_box( 'slugdiv', $post_type, 'normal' );
		remove_meta_box( 'submitdiv', $post_type, 'side' );

	}

	/**
	 * Displays the Data meta box at ATUM Order posts
	 *
	 * @since 1.2.9
	 *
	 * @param \WP_Post $post
	 */
	abstract public function show_data_meta_box( $post );

	/**
	 * Displays the Items meta box at ATUM Order posts
	 *
	 * @since 1.2.9
	 *
	 * @param \WP_Post $post
	 */
	public function show_items_meta_box( $post ) {

		$atum_order = $this->get_current_atum_order( $post->ID, TRUE );
		Helpers::load_view( 'meta-boxes/atum-order/items', compact( 'atum_order' ) );

	}

	/**
	 * Displays the Notes meta box at ATUM Order posts
	 *
	 * @since 1.2.9
	 *
	 * @param \WP_Post $post
	 */
	public function show_notes_meta_box( $post ) {
		Helpers::load_view( 'meta-boxes/atum-order/notes', compact( 'post' ) );
	}

	/**
	 * Displays the Actions meta box at ATUM Order posts
	 *
	 * @since 1.2.9
	 *
	 * @param \WP_Post $post
	 */
	public function show_actions_meta_box( $post ) {
		Helpers::load_view( 'meta-boxes/atum-order/actions', compact( 'post' ) );
	}

	/**
	 * Save the ATUM Order meta boxes
	 *
	 * @since 1.2.9
	 *
	 * @param int $atum_order_id
	 */
	abstract public function save_meta_boxes( $atum_order_id );

	/**
	 * Removes ATUM Orders from the list of post types that support "View Mode" switching.
	 * View mode is seen on posts where you can switch between list or excerpt. Our post types don't support
	 * it, so we want to hide the useless UI from the screen options tab.
	 *
	 * @since 1.2.9
	 *
	 * @param  array $post_types Post types supporting view mode.
	 *
	 * @return array
	 */
	public function disable_view_mode_options( $post_types ) {

		unset( $post_types[ static::POST_TYPE ] );
		return $post_types;
	}

	/**
	 * Disable the WP auto-save functionality for ATUM Orders
	 *
	 * @since 1.2.4
	 */
	public function disable_autosave() {

		global $post;

		if ( $post && get_post_type( $post->ID ) === static::POST_TYPE ) {
			wp_dequeue_script( 'autosave' );
		}

	}

	/**
	 * Manipulate ATUM Orders' bulk actions
	 *
	 * @since 1.2.4
	 *
	 * @param array $actions List of actions.
	 *
	 * @return array
	 */
	public function add_bulk_actions( $actions ) {

		if ( isset( $actions['edit'] ) ) {
			unset( $actions['edit'] );
		}
		
		foreach ( static::get_statuses() as $status => $label ) {

			// Makes no sense to add a bulk action to convert any ATUM order to the same status is being listed.
			if ( ! empty( $_GET['post_status'] ) && $status === $_GET['post_status'] ) {
				continue;
			}

			/* translators: Change the order's status to $status */
			$actions[ "atum_order_mark_$status" ] = sprintf( __( 'Mark as %s', ATUM_TEXT_DOMAIN ), $label );
		}
		
		return apply_filters( 'atum/' . static::POST_TYPE . '/bulk_actions', $actions );

	}

	/**
	 * Handle ATUM Orders' bulk actions
	 *
	 * @since  1.2.4
	 *
	 * @param  string $redirect_to URL to redirect to.
	 * @param  string $action      Action name.
	 * @param  array  $ids         List of ids.
	 *
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $ids ) {

		// Bail out if this is not a status-changing action.
		if ( ! str_contains( $action, 'atum_order_mark_' ) ) {
			return $redirect_to;
		}

		$statuses      = static::get_statuses();
		$new_status    = substr( $action, 16 ); // Get the status name from action (removing 'atum_order_mark_' = 16 characters).
		$report_action = "marked_$new_status";

		// Sanity check: bail out if this is actually not a status, or is not a registered status.
		if ( ! isset( $statuses[ $new_status ] ) ) {
			return $redirect_to;
		}

		$changed   = 0;
		$ids       = array_map( 'absint', $ids );
		$post_type = static::POST_TYPE;

		foreach ( $ids as $id ) {
			$atum_order = Helpers::get_atum_order_model( $id, TRUE, $post_type );
			$atum_order->update_status( $new_status );
			$changed++;
		}

		$redirect_to = add_query_arg( array(
			'post_type'    => $post_type,
			$report_action => TRUE,
			'changed'      => $changed,
			'ids'          => implode( ',', $ids ),
		), $redirect_to );

		return esc_url_raw( $redirect_to );

	}

	/**
	 * Show confirmation message that ATUM Order status changed for number of orders
	 *
	 * @since 1.2.4
	 */
	public function bulk_admin_notices() {

		global $post_type, $pagenow;

		// Bail out if not on ATUM Order's list page.
		if ( 'edit.php' !== $pagenow || static::POST_TYPE !== $post_type ) {
			return;
		}

		$statuses = static::get_statuses();

		// Check if any status changes happened.
		foreach ( $statuses as $slug => $name ) {

			if ( isset( $_REQUEST[ "marked_$slug" ] ) ) {

				$number = isset( $_REQUEST['changed'] ) ? absint( $_REQUEST['changed'] ) : 0;
				/* translators: the number of changed statuses */
				$message = sprintf( _n( '%s status changed.', '%s statuses changed.', $number, ATUM_TEXT_DOMAIN ), number_format_i18n( $number ) );
				echo '<div class="updated"><p>' . esc_html( $message ) . '</p></div>';

				break;
			}
		}

	}

	/**
	 * Specify custom bulk actions messages for the ATUM Order post type
	 *
	 * @since 1.2.4
	 *
	 * @param  array $bulk_messages
	 * @param  array $bulk_counts
	 *
	 * @return array
	 */
	abstract public function bulk_post_updated_messages( $bulk_messages, $bulk_counts );

	/**
	 * Change messages when an ATUM Order post type is updated
	 *
	 * @since 1.2.9
	 *
	 * @param  array $messages
	 *
	 * @return array
	 */
	abstract public function post_updated_messages( $messages );

	/**
	 * Enqueue the scripts
	 *
	 * @since 1.2.4
	 *
	 * @param string $hook
	 */
	public function enqueue_scripts( $hook ) {

		global $post_type;

		if ( static::POST_TYPE === $post_type ) {

			global $post;

			// Sweet Alert assets.
			Helpers::register_swal_scripts();

			// ATUM marketing popup.
			$show_marketing_popup = AtumMarketingPopup::get_instance()->maybe_enqueue_scripts();

			if ( in_array( $hook, [ 'post-new.php', 'post.php' ] ) ) {

				wp_register_style( 'atum-orders', ATUM_URL . 'assets/css/atum-orders.css', [ 'sweetalert2' ], ATUM_VERSION );
				wp_enqueue_style( 'atum-orders' );

				if ( is_rtl() ) {
					wp_register_style( 'atum-orders-rtl', ATUM_URL . 'assets/css/atum-orders-rtl.css', [ 'atum-orders' ], ATUM_VERSION );
					wp_enqueue_style( 'atum-orders-rtl' );
				}

				// Enqueue the script with the required WooCommerce dependencies.
				$wc_dependencies = (array) apply_filters('atum/order_post_type/scripts/woocommerce_dependencies', array(
					'wc-enhanced-select',
					'wc-backbone-modal',
					'jquery-blockui',
					'stupidtable',
					'sweetalert2',
					'wp-hooks',
				));

				wp_register_script( 'atum-orders', ATUM_URL . 'assets/js/build/atum-orders.js', $wc_dependencies, ATUM_VERSION, TRUE );

				$vars = apply_filters( 'atum/order_post_type/js_vars', array(
					'addNoteNonce'              => wp_create_nonce( 'add-atum-order-note' ),
					'areYouSure'                => __( 'Are you sure?', ATUM_TEXT_DOMAIN ),
					'atumOrderItemNonce'        => wp_create_nonce( 'atum-order-item' ),
					'calcTotals'                => __( 'Recalculate totals? This will calculate taxes based on the store base country and update totals.', ATUM_TEXT_DOMAIN ),
					'calcTotalsNonce'           => wp_create_nonce( 'calc-totals' ),
					'cancel'                    => __( 'Cancel', ATUM_TEXT_DOMAIN ),
					'confirmPurchasePrice'      => __( 'Do you want to set the purchase price of {{name}} to {{number}}?', ATUM_TEXT_DOMAIN ),
					'confirmPurchasePriceTitle' => __( 'Please, confirm the new purchase price', ATUM_TEXT_DOMAIN ),
					'continue'                  => __( 'Continue', ATUM_TEXT_DOMAIN ),
					'deleteNoteNonce'           => wp_create_nonce( 'delete-atum-order-note' ),
					'deleteNote'                => __( 'Are you sure you wish to delete this note? This action cannot be undone.', ATUM_TEXT_DOMAIN ),
					'decreaseStockMsg'          => __( 'This will decrease the stock of the selected products by their quantity amount.', ATUM_TEXT_DOMAIN ),
					'deleteTaxNotice'           => __( 'Are you sure you wish to delete this tax column? This action cannot be undone.', ATUM_TEXT_DOMAIN ),
					'done'                      => __( 'Done!', ATUM_TEXT_DOMAIN ),
					// Disable order item selection for only PO when WC version >= 3.5.0.
					'enableSelectItems'         => version_compare( WC()->version, '3.5.0', '<' ) || PurchaseOrders::POST_TYPE !== $post_type,
					'error'                     => __( 'Error!', ATUM_TEXT_DOMAIN ),
					'importOrderItemsIL'        => __( 'Do you want to import all the items within the selected order into this Inventory Log?', ATUM_TEXT_DOMAIN ),
					'importOrderItemsPO'        => __( 'Do you want to import all the items within the selected order into this Purchase Order?', ATUM_TEXT_DOMAIN ),
					'importOrderItemsNonce'     => wp_create_nonce( 'import-order-items' ),
					'increaseStockMsg'          => __( 'This will increase the stock of the selected products by their quantity amount.', ATUM_TEXT_DOMAIN ),
					'metaPlaceholderName'       => esc_attr__( 'Name (required)', ATUM_TEXT_DOMAIN ),
					'metaPlaceholderValue'      => esc_attr__( 'Value (required)', ATUM_TEXT_DOMAIN ),
					'no'                        => __( 'No', ATUM_TEXT_DOMAIN ),
					'ok'                        => __( 'OK', ATUM_TEXT_DOMAIN ),
					'postId'                    => $post->ID ?: '',
					'priceDecimalSep'           => wc_get_price_decimal_separator(),
					'priceNumDecimals'          => wc_get_price_decimals(),
					'purchasePriceChanged'      => __( 'The purchase price was changed successfully', ATUM_TEXT_DOMAIN ),
					'purchasePriceField'        => Globals::PURCHASE_PRICE_KEY,
					'removeItemMeta'            => __( 'Remove this item meta?', ATUM_TEXT_DOMAIN ),
					'removeItemNotice'          => __( 'Are you sure you want to remove this item?', ATUM_TEXT_DOMAIN ),
					'roundingPrecision'         => wc_get_rounding_precision(),
					'stockDecreased'            => __( 'The stock was decreased successfully', ATUM_TEXT_DOMAIN ),
					'stockIncreased'            => __( 'The stock was increased successfully', ATUM_TEXT_DOMAIN ),
					'taxesName'                 => __( 'VAT', ATUM_TEXT_DOMAIN ),
					'taxRateAlreadyExists'      => __( 'You cannot add the same tax rate twice!', ATUM_TEXT_DOMAIN ),
					'yes'                       => __( 'Yes', ATUM_TEXT_DOMAIN ),
				) );

				$vars = array_merge( $vars, Globals::get_date_time_picker_js_vars() );

				wp_localize_script( 'atum-orders', 'atumOrder', $vars );
				wp_enqueue_script( 'atum-orders' );

			}
			elseif ( 'edit.php' === $hook ) {

				$css_dependencies = [];
				$js_dependencies  = [ 'jquery', 'jquery-tiptip', 'wp-hooks' ];

				if ( $show_marketing_popup ) {
					$css_dependencies[] = 'sweetalert2';
					$js_dependencies[]  = 'sweetalert2';
				}

				wp_register_style( 'atum-orders-list', ATUM_URL . 'assets/css/atum-orders-list.css', $css_dependencies, ATUM_VERSION );
				wp_register_script( 'atum-orders-list', ATUM_URL . 'assets/js/build/atum-post-type-list.js', $js_dependencies, ATUM_VERSION, TRUE );

				$vars = array(
					'placeholderSearch' => __( 'Search...', ATUM_TEXT_DOMAIN ),
				);

				if ( $this->help_guide ) {
					$vars = array_merge( $vars, AtumHelpGuide::get_instance()->get_help_guide_js_vars( $this->help_guide, $this->help_guide ) );
				}

				wp_localize_script( 'atum-orders-list', 'atumPostTypeListVars', $vars );

				wp_enqueue_style( 'atum-orders-list' );
				wp_enqueue_script( 'atum-orders-list' );

				// Load the ATUM colors.
				Helpers::enqueue_atum_colors( 'atum-orders-list' );

				if ( is_rtl() ) {
					wp_register_style( 'atum-orders-list-rtl', ATUM_URL . 'assets/css/atum-orders-list-rtl.css', array( 'atum-orders-list' ), ATUM_VERSION );
					wp_enqueue_style( 'atum-orders-list-rtl' );
				}

			}

		}

	}

	/**
	 * Print the scripts to the admin page footer
	 *
	 * @since 1.2.4
	 */
	public function print_scripts() {

		global $post_type, $pagenow;

		if ( static::POST_TYPE === $post_type && 'edit.php' === $pagenow ) {

			?>
			<script type="text/javascript">
				jQuery(function($){

					if ( $.fn.hasOwnProperty('tipTip') ) {
						$( '.tips' ).tipTip( {
							'attribute': 'data-tip',
							'fadeIn'   : 50,
							'fadeOut'  : 50,
							'delay'    : 200
						} );
					}

				});
			</script>
			<?php

		}

	}

	/**
	 * Allow searching within ATUM orders' meta keys and ATUM order items.
	 *
	 * @since 1.9.37
	 *
	 * @param string    $search   Search clause used.
	 * @param \WP_Query $wp_query The current WP_Query object.
	 *
	 * @return string
	 */
	public function add_search_criteria_to_orders_list( $search, $wp_query ) {

		global $pagenow, $wpdb;

		$post_type = static::POST_TYPE;

		// Check if we are on the pick-pack list table.
		if ( $post_type !== $wp_query->get( 'post_type' ) ) {
			return $search;
		}

		$search_terms = $wp_query->get( 'search_terms' );

		// Check if we are searching.
		if ( 'edit.php' !== $pagenow || empty( $search_terms ) ) {
			return $search;
		}

		// Add the extended search clause to each search group of the main search.
		$search_groups = explode( 'AND', $search );

		foreach ( $search_groups as &$search_group ) {

			if ( empty( $search_group ) ) {
				continue;
			}

			$prepared_term = '';

			// Calculate the prepared term to search for the current group
			foreach ( $search_terms as $search_term ) {

				// Remove non-needed strings from search term.
				$search_term = str_replace(
					apply_filters( "atum/$post_type/replace_order_name_strings", array(
						__( 'Order #', ATUM_TEXT_DOMAIN ),
						'Order #',
						__( 'Purchase Order #', ATUM_TEXT_DOMAIN ),
						'Purchase Order #',
						__( 'PO #', ATUM_TEXT_DOMAIN ),
						'PO #',
						__( 'Log #', ATUM_TEXT_DOMAIN ),
						'Log #',
						'#',
					) ),
					'',
					wc_clean( $search_term )

				);

				$prepared_term = $wpdb->prepare( "%%$search_term%%" );

				if ( strpos( $search_group, $prepared_term ) !== FALSE ) {
					break;
				}

			}

			if ( empty( $prepared_term ) ) {
				continue;
			}


			// Add the post_id to the search
			$id_search = " OR ( $wpdb->posts.ID LIKE '$prepared_term' )";

			// Add searching of order item fields
			$criteria = array(
				'cols'         => array( 'DISTINCT oi.`order_id`' ),
				'from'         => "FROM `$wpdb->prefix" . self::ORDER_ITEMS_TABLE . "`",
				'join'         => array(
					"LEFT JOIN `$wpdb->atum_order_itemmeta` AS oim ON (oi.`order_item_id` = oim.`order_item_id` AND oim.`meta_key` IN ('_product_id', '_variation_id'))",
					"LEFT JOIN `$wpdb->posts` AS p ON oi.`order_id` = p.`ID`",
				),
				// The where is the main clause separated by ANDs.
				'where'        => array(
					"oi.`order_item_type` = 'line_item'",
					'oim.`meta_value` > 0',
					$wpdb->prepare( 'p.`post_type` = %s', static::POST_TYPE ),
				),
				// The search where is a clause separated by ORs.
				'search_where' => array(
					"oi.`order_item_name` LIKE '$prepared_term'", // Search by order item names.
				),
			);

			// Join needed to search by SKU.
			if ( ! empty( $wpdb->wc_product_meta_lookup ) ) {
				$criteria['join'][]         = "LEFT JOIN $wpdb->wc_product_meta_lookup AS pml ON oim.`meta_value` = pml.`product_id`";
				$criteria['search_where'][] = "pml.`sku` LIKE '$prepared_term'"; // Search by SKU.
			}
			else {
				$criteria['join'][]         = "LEFT JOIN $wpdb->postmeta AS pmoi ON (oim.`meta_value` = pmoi.`post_id` AND pmoi.`meta_key` = '_sku')";
				$criteria['search_where'][] = "pmoi.`meta_value` LIKE '$prepared_term'"; // Search by SKU.
			}

			// Search by order meta.
			// NOTE: Searches on post metadata can be slow - this let you choose what fields to search in.
			$search_meta_keys = array_map( 'wc_clean', apply_filters( "atum/$post_type/search_meta_keys", [] ) );
			if ( ! empty( $search_meta_keys ) ) {
				$criteria['join'][]         = "LEFT JOIN $wpdb->postmeta AS pmo ON (p.`ID` = pmo.`post_id` AND pmo.`meta_key` IN ('" . implode( "','", array_map( 'esc_sql', $search_meta_keys ) ) . "'))";
				$criteria['search_where'][] = "pmo.`meta_value` LIKE '$prepared_term'"; // Search by order meta keys.
			}

			$criteria = apply_filters( "atum/$post_type/extra_search", $criteria, $search_term );

			$sql = '
			SELECT ' . implode( ', ', $criteria['cols'] ) . "\n" .
				   $criteria['from'] . " AS oi\n" .
				   implode( "\n", $criteria['join'] ) . "\n
			WHERE " . ( ! empty( $criteria['where'] ) ? implode( ' AND ', $criteria['where'] ) . " AND\n" : '' ) .
				   '(' . implode( ' OR ', $criteria['search_where'] ) . ') ';


			$search_parts = explode( ')', $search_group );

			foreach ( $search_parts as $index => $part ) {

				// Search for the first occurrence of the closing parenthesis.
				if ( ! $part ) {

					// Insert the new search clause after this index.
					array_splice( $search_parts, $index, 0, "$id_search OR ( $wpdb->posts.ID IN ($sql)" );
					break;
				}

			}

			$search_group = implode( ')', $search_parts );

		}


		return implode( 'AND', $search_groups );

	}

	/**
	 * Check the inbound stock for the PO items every time the PO status is changed
	 *
	 * @since 1.5.8
	 *
	 * @param int $po_id
	 */
	public function update_order_item_props( $po_id ) {

		if ( static::POST_TYPE !== get_post_type( $po_id ) ) {
			return;
		}

		$atum_order = $this->get_current_atum_order( $po_id, TRUE );
		$atum_order->after_save( $atum_order );

		if ( doing_action( 'trashed_post' ) ) {
			update_post_meta( $po_id, '_status', 'trash' );
		}
		if ( doing_action( 'untrashed_post' ) ) {
			update_post_meta( $po_id, '_status', $atum_order->get_post()->post_status );
		}

	}

	/**
	 * Output a dropdown to choose the ATUM Order status
	 *
	 * @since 1.2.9
	 *
	 * @param string $id    The select ID.
	 * @param string $name  The select field name.
	 * @param string $value The selected option.
	 * @param string $class Optional. The CSS classes for the select.
	 */
	public static function atum_order_status_dropdown( $id, $name, $value, $class = 'wc-enhanced-select atum-enhanced-select' ) {

		global $pagenow;

		?>
		<select id="<?php echo esc_attr( $id ) ?>" name="<?php echo esc_attr( $name ) ?>" class="<?php echo esc_attr( $class ) ?>">
			<?php if ( 'post-new.php' !== $pagenow && ! array_key_exists( $value, static::get_statuses() ) ) : ?>
			<option value="<?php echo esc_attr( $value ) ?>" selected><?php esc_html_e( 'Unknown', ATUM_TEXT_DOMAIN ); ?></option>
			<?php endif; ?>
			<?php foreach ( static::get_statuses() as $status => $status_label ) : ?>
				<option value="<?php echo esc_attr( $status ) ?>"<?php selected( $status, $value ) ?>><?php echo esc_html( $status_label ) ?></option>
			<?php endforeach; ?>
		</select>
		<?php

	}

	/**
	 * Use our own method to delete ATUM Orders in order to clean up all the data
	 *
	 * @since 1.6.2
	 *
	 * @param mixed    $check        Just return NULL to bypass this filter or any other value to bypass the original delete_post.
	 * @param \WP_Post $post         The post being deleted.
	 * @param bool     $force_delete Whether to bypass the trash and delete the post definitely.
	 *
	 * @return mixed
	 */
	public function maybe_delete_atum_order( $check, $post, $force_delete ) {

		if ( static::POST_TYPE === $post->post_type ) {

			global $pagenow;

			// Id the page is edit.php, it's a 'Delete permanently' action.
			if ( 'edit.php' === $pagenow ) {
				$force_delete = TRUE;
			}

			// Avoid cyclical calls to this method.
			remove_filter( 'pre_delete_post', array( $this, 'maybe_delete_atum_order' ), PHP_INT_MAX );

			$atum_order = Helpers::get_atum_order_model( $post->ID, TRUE, $post->post_type );
			$atum_order->delete( $force_delete );

			$check = TRUE; // Let WP know that the post was successfully deleted.

		}

		return $check;

	}

	/**
	 * Edit the List Table views
	 *
	 * @since 1.8.2
	 *
	 * @param array $views
	 *
	 * @return array
	 */
	public function edit_list_table_views( $views ) {

		// Send the trash status to the end of the status views list on ATUM Orders' List Tables.
		// But before the "Unknown" status if it exists.
		if ( array_key_exists( 'trash', $views ) ) {
			$trash_status = $views['trash'];
			unset( $views['trash'] );
			$views['trash'] = $trash_status;
		}

		// Check if there are ATUM Orders with unknown statuses.
		if ( $this->unknown_status_orders ) {
			// Maybe add the current class.
			$current = ! empty( $_REQUEST['post_status'] ) && 'unknown' === $_REQUEST['post_status'] ? 'class="current" ' : '';

			$views['unknown'] = '<a ' . $current . 'href="edit.php?post_status=unknown&#038;post_type=' . static::POST_TYPE . '">' . esc_html__( 'Unknown', ATUM_TEXT_DOMAIN ) . "<span class='count'>($this->unknown_status_orders)</span></a>";
		}

		// Ensure publish is not present if not needed.
		if ( ! array_key_exists( 'publish', static::get_statuses() ) ) {
			unset( $views['publish'] );
		}

		return $views;

	}

	/**
	 * Add the hook to filter the unknown status if loading the ATUM Orders list table
	 *
	 * @since 1.9.48
	 */
	public function maybe_add_unknown_status_filter() {

		$screen = get_current_screen();

		if ( $screen && str_contains( $screen->id, static::POST_TYPE ) ) {
			add_filter( 'posts_where', array( $this, 'filter_unknown_status' ), 10, 2 );
		}

	}

	/**
	 * Modify the WP Query to list the ATUM Orders with unknown status.
	 *
	 * @since 1.9.11
	 *
	 * @param string    $where
	 * @param \WP_Query $wp_query
	 *
	 * @return string
	 */
	public function filter_unknown_status( $where, $wp_query ) {

		global $wpdb;

		// Ensure it's the correct WP Query.
		if ( static::POST_TYPE === $wp_query->query_vars['post_type'] ) {

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_clause     = $wpdb->prepare( "
				post_type = %s AND post_status NOT IN (
			    '" . implode( "','", array_merge( array_keys( static::get_statuses() ), [ 'auto-draft', 'trash' ] ) ) . "')", static::POST_TYPE );
			$unknown_statuses = $wpdb->get_col( "SELECT DISTINCT post_status FROM $wpdb->posts WHERE $where_clause" );

			if ( $unknown_statuses ) {

				$this->unknown_status_orders = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE $where_clause" );

				// Filter ATUM Orders with unknown statuses if queried.
				if ( ! empty( $_REQUEST['post_status'] ) && 'unknown' === $_REQUEST['post_status'] ) {

					// Remove the post_status query used and replace it with the unknown statuses query.
					$begin           = strpos( $where, "{$wpdb->posts}.post_status" );
					$last_occurrence = strrpos( $where, "{$wpdb->posts}.post_status" );
					$end             = strpos( $where, ')', $last_occurrence );

					$post_status_query = "{$wpdb->posts}.post_status IN ('" . implode( "','", $unknown_statuses ) . "')";

					$where = substr_replace( $where, $post_status_query, $begin, $end - $begin );

				}

			}
			//phpcs:enable

		}

		return $where;

	}

	/**
	 * Get the currently instantiated ATUM Order object (if any) or create a new one
	 *
	 * @since 1.2.9
	 *
	 * @param int  $post_id
	 * @param bool $read_items
	 *
	 * @return AtumOrderModel|NULL
	 */
	abstract public function get_current_atum_order( $post_id, $read_items );

	/**
	 * Get the available ATUM Order statuses
	 *
	 * @since 1.2.9
	 *
	 * @return array
	 */
	abstract public static function get_statuses();

	/**
	 * Get the colors for every ATUM order status
	 *
	 * @since 1.8.2
	 *
	 * @return array
	 */
	abstract public static function get_status_colors();

	/**
	 * Getter for the ATUM Order post type name
	 *
	 * @since 1.2.9
	 *
	 * @return string
	 */
	public static function get_post_type() {
		return static::POST_TYPE;
	}

	/**
	 * Getter for the ATUM Order taxonomy name
	 *
	 * @since 1.2.9
	 *
	 * @return string|bool
	 */
	public static function get_type_taxonomy() {
		return ! empty( static::TAXONOMY ) ? static::TAXONOMY : FALSE;
	}

	/**
	 * Set the post type labels
	 *
	 * @since 1.9.44
	 */
	protected function set_labels() {}

}
