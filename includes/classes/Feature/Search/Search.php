<?php

namespace ElasticPress\Feature\Search;
use ElasticPress\Feature as Feature;
use ElasticPress\Post\Post as Post;

class Search extends Feature {
	public function __construct() {
		$this->slug = 'search';

		$this->title = esc_html__( 'Search', 'elasticpress' );

		$this->requires_install_reindex = false;
		$this->default_settings = [
			'decaying_enabled' => true,
		];
	}

	public function setup() {
		add_action( 'init', [ $this, 'search_setup' ] );
	}

	public function search_setup() {
		/**
		 * By default EP will not integrate on admin or ajax requests. Since admin-ajax.php is
		 * technically an admin request, there is some weird logic here. If we are doing ajax
		 * and ep_ajax_wp_query_integration is filtered true, then we skip the next admin check.
		 */
		$admin_integration = apply_filters( 'ep_admin_wp_query_integration', false );

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			if ( ! apply_filters( 'ep_ajax_wp_query_integration', false ) ) {
				return;
			} else {
				$admin_integration = true;
			}
		}

		if ( is_admin() && ! $admin_integration ) {
			return;
		}

		add_filter( 'ep_elasticpress_enabled', [ $this, 'integrate_search_queries' ], 10, 2 );
		add_filter( 'ep_formatted_args', [ $this, 'weight_recent' ], 10, 2 );
		add_filter( 'ep_query_post_type', [ $this, 'filter_query_post_type_for_search' ], 10, 2 );
		add_action( 'pre_get_posts', [ $this, 'improve_default_search' ], 10, 1 );
	}

	public function get_searchable_post_types() {
		$post_types = get_post_types( array( 'exclude_from_search' => false ) );

		return apply_filters( 'ep_searchable_post_types', $post_types );
	}

	public function filter_query_post_type_for_search( $post_type, $query ) {
		if ( 'any' === $post_type && $query->is_search() ) {
			$searchable_post_types = $this->get_searchable_post_types();

			// If we have no searchable post types, there's no point going any further
			if ( empty( $searchable_post_types ) ) {

				// Have to return something or it improperly calculates the found_posts
				return false;
			}

			// Conform the post types array to an acceptable format for ES
			$post_types = [];

			foreach( $searchable_post_types as $type ) {
				$post_types[] = $type;
			}

			// These are now the only post types we will search
			$post_type = $post_types;
		}

		return $post_type;
	}

	public function improve_default_search( $query ) {
		if ( is_admin() ) {
			return;
		}

		/**
		 * Make sure this is an ElasticPress search query
		 */
		if ( ! Post::factory()->elasticpress_enabled( $query ) || ! $query->is_search() ) {
			return;
		}

		$search_fields = $query->get( 'search_fields' );

		// Set search fields if they are not set
		if( empty( $search_fields ) ) {
			$query->set( 'search_fields', array(
				'post_title',
				'post_content',
				'post_excerpt',
				'author_name',
				'taxonomies' => array(
					'post_tag',
					'category',
				),
			) );
		}
	}

	public function weight_recent( $formatted_args, $args ) {
		if ( ! empty( $args['s'] ) ) {
			$feature  = ep_get_registered_feature( 'search' );
			$settings = [];
			if ( $feature ) {
				$settings = $feature->get_settings();
			}

			$settings = wp_parse_args( $settings, array(
				'decaying_enabled' => true,
			) );
			if ( (bool)$settings['decaying_enabled'] ) {
				$date_score = array(
					'function_score' => array(
						'query'      => $formatted_args['query'],
						'exp'        => array(
							'post_date_gmt' => array(
								'scale'  => apply_filters( 'epwr_scale', '14d', $formatted_args, $args ),
								'decay'  => apply_filters( 'epwr_decay', .25, $formatted_args, $args ),
								'offset' => apply_filters( 'epwr_offset', '7d', $formatted_args, $args ),
							),
						),
						'score_mode' => 'avg',
						'boost_mode' => apply_filters( 'epwr_boost_mode', 'sum', $formatted_args, $args ),
					),
				);

				$formatted_args['query'] = $date_score;
			}
		}

		return $formatted_args;
	}

	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Instantly find the content you’re looking for. The first time.', 'elasticpress' ); ?></p>
		<?php
	}

	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'Overcome higher-end performance and functional limits posed by the traditional WordPress structured (SQL) database to deliver superior keyword search, instantly. ElasticPress indexes custom fields, tags, and other metadata to improve search results. Fuzzy matching accounts for misspellings and verb tenses.', 'elasticpress' ); ?></p>

		<?php
	}

	public function use_searchable_post_types_on_any( $post_type, $query ) {
		if ( $query->is_search() && 'any' === $post_type ) {

			/*
			 * This is a search query
			 * To follow WordPress conventions,
			 * make sure we only search 'searchable' post types
			 */
			$searchable_post_types = $this->get_searchable_post_types();

			// If we have no searchable post types, there's no point going any further
			if ( empty( $searchable_post_types ) ) {

				// Have to return something or it improperly calculates the found_posts
				return false;
			}

			// Conform the post types array to an acceptable format for ES
			$post_types = [];

			foreach( $searchable_post_types as $type ) {
				$post_types[] = $type;
			}

			// These are now the only post types we will search
			$post_type = $post_types;
		}

		return $post_type;
	}

	public function integrate_search_queries( $enabled, $query ) {
		if ( isset( $query->query_vars['ep_integrate'] ) && false === $query->query_vars['ep_integrate'] ) {
			$enabled = false;
		} else if ( method_exists( $query, 'is_search' ) && $query->is_search() && ! empty( $query->query_vars['s'] ) ) {
			$enabled = true;

			/**
			 * WordPress have to be version 4.6 or newer to have "fields" support
			 * since it requires the "posts_pre_query" filter.
			 *
			 * @see WP_Query::get_posts
			 */
			$fields = $query->get( 'fields' );
			if ( ! version_compare( get_bloginfo( 'version' ), '4.6', '>=' ) && ! empty( $fields ) ) {
				$enabled = false;
			}
		}

		return $enabled;
	}

	public function output_feature_box_settings() {
		$decaying_settings = $this->get_settings();

		if ( ! $decaying_settings ) {
			$decaying_settings = [];
		}

		$decaying_settings = wp_parse_args( $decaying_settings, $this->default_settings );
		?>
		<div class="field js-toggle-feature" data-feature="<?php echo esc_attr( $this->slug ); ?>">
			<div class="field-name status"><?php esc_html_e( 'Weight results by date', 'elasticpress' ); ?></div>
			<div class="input-wrap">
				<label for="decaying_enabled"><input name="decaying_enabled" id="decaying_enabled" data-field-name="decaying_enabled" class="setting-field" type="radio" <?php if ( (bool)$decaying_settings['decaying_enabled'] ) : ?>checked<?php endif; ?> value="1"><?php esc_html_e( 'Enabled', 'elasticpress' ); ?></label><br>
				<label for="decaying_disabled"><input name="decaying_enabled" id="decaying_disabled" data-field-name="decaying_enabled" class="setting-field" type="radio" <?php if ( ! (bool)$decaying_settings['decaying_enabled'] ) : ?>checked<?php endif; ?> value="0"><?php esc_html_e( 'Disabled', 'elasticpress' ); ?></label>
			</div>
		</div>
	<?php
	}
}
