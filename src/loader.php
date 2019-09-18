<?php
/**
 * Loader
 *
 * @package wp-graphql-persisted-queries
 */

namespace WPGraphQL\Extensions\PersistedQueries;

use \GraphQL\Error\UserError;

/**
 * Load or save a persisted query from a custom post type. This allows users to
 * avoid sending the query over the wire, saving bandwidth. In particular, it
 * allows for moving to GET requests, which can be cached at the edge.
 */
class Loader {
	/**
	 * Error message returned when the query is not persisted or cannot be found.
	 * This is important for the Apollo implementation; it looks for this exact
	 * error message in the response.
	 */
	private $error_message = 'PersistedQueryNotFound';

	/**
	 * Namespace for WP filters.
	 *
	 * @var string
	 */
	private $namespace = 'graphql_persisted_queries';

	/**
	 * Post type for default query persistence. Unused if query persistence is
	 * disabled. Filter with graphql_persisted_query_post_type.
	 *
	 * @var string
	 */
	private $post_type = 'graphql_query';

	/**
	 * Filter configuration values and register the post type used to store
	 * persisted queries.
	 *
	 * @return void
	 */
	public function init() {
		/**
		 * Post type to use to persist queries. Unused if disabled.
		 *
		 * @param string $post_type
		 * @since 1.0.0
		 */
		$this->post_type = apply_filters( "{$this->namespace}_post_type", $this->post_type );

		// If the post type doesn't look right, don't hook.
		if ( empty( $this->post_type ) || post_type_exists( $this->post_type ) ) {
			return;
		}

		// Register the post type.
		$this->register_post_type();

		// Filter request data to load/save queries.
		add_filter( 'graphql_request_data', [ $this, 'process_request_data' ], 10, 1 );

		// Filter the HTTP status code.
		add_filter( 'graphql_response_status_code', [ $this, 'get_http_status_code' ], 10, 2 );
	}

	/**
	 * Filter the HTTP status code. We should return 202 instead of 500 if
	 * retrieving the persisted query fails. This prevents the Apollo client from
	 * giving up on the request. It also prevents most edge caches from caching
	 * this initial error response.
	 *
	 * @param  int   $status_code HTTP status code
	 * @param  array $response    GraphQL response
	 * @return int
	 */
	public function get_http_status_code( $status_code, $response ) {
		if ( is_array( $response ) && isset( $response['errors'][0]['message'] ) && $this->error_message === $response['errors'][0]['message'] ) {
			return 202;
		}

		return $status_code;
	}

	/**
	 * Be a little flexible in how operation name is sent.
	 *
	 * @param  array $request_data Request data.
	 * @return string
	 */
	private function get_operation_name( $request_data ) {
		foreach( [ 'operationName', 'operation_name' ] as $key ) {
			if ( ! empty( $request_data[ $key ] ) ) {
				return $request_data[ $key ];
			}
		}

		return 'UnnamedQuery';
	}

	/**
	 * Attempts to load a persisted query corresponding to a query ID (hash).
	 *
	 * @param  string $query_id Query ID
	 * @return string Query
	 */
	private function load( $query_id ) {
		/**
		 * Allow other implementors to load queries.
		 *
		 * @param string $query    Persisted query.
		 * @param string $query_id Query ID.
		 * @since 1.1.0
		 */
		$query = apply_filters( "{$this->namespace}_load_query", null, $query_id );
		if ( $query ) {
			return $query;
		}

		// If query has been persisted to our custom post type, return it.
		$post = get_page_by_path( $query_id, 'OBJECT', $this->post_type );

		return isset( $post->post_content ) ? $post->post_content : null;
	}

	/**
	 * Filter request data and load the query if request provides a query ID. We
	 * are following the Apollo draft spec for automatic persisted queries. See:
	 *
	 * https://github.com/apollographql/apollo-link-persisted-queries#automatic-persisted-queries
	 *
	 * @param  array $request_data Request data from WPHelper
	 * @return array
	 * @throws UserError           Caught and handled by WPGraphQL
	 */
	public function process_request_data( $request_data ) {
		$has_query = ! empty( $request_data['query'] );
		$has_query_id = ! empty( $request_data['queryId'] );

		// Query IDs are case-insensitive.
		$query_id = $has_query_id ? strtolower( $request_data['queryId'] ) : null;

		// Client sends *both* queryId and query == request to persist query.
		if ( $has_query_id && $has_query ) {
			$this->save( $query_id, $request_data['query'], $this->get_operation_name( $request_data ) );
		}

		// Client sends queryId but *not* query == optimistic request to use
		// persisted query.
		if ( $has_query_id && ! $has_query ) {
			$request_data['query'] = $this->load( $query_id );

			// If the query is empty, that means it has not been persisted.
			if ( empty( $request_data['query'] ) ) {
				throw new UserError( $this->error_message );
			}
		}

		// We've got this. Call off any other persistence implementations.
		unset( $request_data['queryId'] );

		return $request_data;
	}

	/**
	 * Register the persisted query post type.
	 *
	 * @return void
	 */
	private function register_post_type() {
		$post_type_args = [
			'label'               => 'Queries',
			'public'              => false,
			'query_var'           => false,
			'rewrite'             => false,
			'show_in_rest'        => false,
			'show_in_graphql'     => false,
			'graphql_single_name' => 'persistedQuery',
			'graphql_plural_name' => 'persistedQueries',
			'show_ui'             => false,
			'supports'            => [ 'title', 'editor' ],
		];

		/**
		 * Post type args for persisted queries. Filter this to expose the post type
		 * in the UI or in GraphQL.
		 *
		 * @param bool $post_type_args Register post type args.
		 * @since 1.1.0
		 */
		$post_type_args = apply_filters( "{$this->namespace}_post_type_args", $post_type_args );

		register_post_type( $this->post_type, $post_type_args );
	}

	/**
	 * Save (persist) a query.
	 *
	 * @param  string $query_id Query ID
	 * @param  string $query    GraphQL query
	 * @param  string $name     Operation name
	 * @return void
	 */
	private function save( $query_id, $query, $name = 'UnnamedQuery' ) {
		// Check to see if the query has already been persisted. If so, we're done.
		if ( ! empty( $this->load( $query_id ) ) ) {
			return;
		}

		$post_data = [
			'post_content' => $query,
			'post_name'    => $query_id,
			'post_title'   => $name,
			'post_status'  => 'publish',
			'post_type'    => $this->post_type,
		];

		/**
		 * Allow implementors to override persisted query.
		 *
		 * @param bool   $post_data Post data for persisted query.
		 * @param string $query_id  Query ID.
		 * @since 1.1.0
		 */
		$post_data = apply_filters( "{$this->namespace}_save_query", $post_data, $query_id );

		if ( empty( $post_data ) ) {
			return;
		}

		// Persist the query.
		wp_insert_post( $post_data );
	}
}
