<?php
/**
 * Plugin Name: Custom Query Order
 * Plugin URI: https://example.com/custom-query-order
 * Description: Extends the Query Loop block to allow custom drag-and-drop sorting of posts.
 * Version: 0.1.0
 * Author: philhoyt
 * License: GPL-2.0-or-later
 * Text Domain: custom-query-order
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'CUSTOM_QUERY_ORDER_VERSION', '1.0.0' );
define( 'CUSTOM_QUERY_ORDER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CUSTOM_QUERY_ORDER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Enqueue block editor assets.
 */
function custom_query_order_enqueue_block_editor_assets() {
	$asset_file_path = CUSTOM_QUERY_ORDER_PLUGIN_DIR . 'build/index.asset.php';

	if ( ! file_exists( $asset_file_path ) ) {
		return;
	}

	$asset_file = include $asset_file_path;

	$dependencies = isset( $asset_file['dependencies'] ) ? $asset_file['dependencies'] : array();
	$version      = isset( $asset_file['version'] ) ? $asset_file['version'] : CUSTOM_QUERY_ORDER_VERSION;

	wp_enqueue_script(
		'custom-query-order-editor',
		CUSTOM_QUERY_ORDER_PLUGIN_URL . 'build/index.js',
		$dependencies,
		$version,
		true
	);

	// WordPress scripts outputs CSS as style-index.css.
	$style_file = CUSTOM_QUERY_ORDER_PLUGIN_DIR . 'build/style-index.css';
	if ( file_exists( $style_file ) ) {
		wp_enqueue_style(
			'custom-query-order-editor',
			CUSTOM_QUERY_ORDER_PLUGIN_URL . 'build/style-index.css',
			array(),
			$version
		);
	}
}
add_action( 'enqueue_block_editor_assets', 'custom_query_order_enqueue_block_editor_assets' );

/**
 * Cache handler for Query Loop block attributes.
 * Used to pass attributes from render_block to query_loop_block_query_vars.
 */
class Custom_Query_Order_Cache {
	/**
	 * Cache storage, keyed by block ID.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Cache TTL in seconds (5 minutes).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 300;

	/**
	 * Get cached custom order for a query ID.
	 *
	 * @param string $query_id The query ID.
	 * @return array|null The custom order array or null if not found/expired.
	 */
	public static function get( $query_id ) {
		if ( ! isset( self::$cache[ $query_id ] ) ) {
			return null;
		}

		$cache_entry = self::$cache[ $query_id ];
		$current_time = time();

		// Check if cache is still valid.
		if ( isset( $cache_entry['timestamp'] ) && ( $current_time - $cache_entry['timestamp'] ) < self::CACHE_TTL ) {
			return $cache_entry['customOrder'] ?? null;
		}

		// Cache expired, remove it.
		unset( self::$cache[ $query_id ] );
		return null;
	}

	/**
	 * Set cached custom order for a query ID.
	 *
	 * @param string $query_id    The query ID.
	 * @param array  $custom_order The custom order array.
	 */
	public static function set( $query_id, $custom_order ) {
		self::$cache[ $query_id ] = array(
			'customOrder' => $custom_order,
			'timestamp'   => time(),
		);
		
		// Cleanup only when adding to cache and cache is getting large.
		// This avoids running cleanup on every render_block call.
		if ( count( self::$cache ) > 50 ) {
			self::cleanup();
		}
	}

	/**
	 * Clean up expired cache entries.
	 * Only called when cache size exceeds threshold to avoid performance issues.
	 */
	private static function cleanup() {
		$current_time = time();
		foreach ( self::$cache as $key => $cache_entry ) {
			if ( isset( $cache_entry['timestamp'] ) && ( $current_time - $cache_entry['timestamp'] ) > self::CACHE_TTL ) {
				unset( self::$cache[ $key ] );
			}
		}
	}

	/**
	 * Get the most recent cache entry (fallback method).
	 *
	 * @return array|null The most recent custom order or null.
	 */
	public static function get_most_recent() {
		if ( empty( self::$cache ) ) {
			return null;
		}

		$most_recent = end( self::$cache );
		$current_time = time();

		if ( isset( $most_recent['timestamp'] ) && ( $current_time - $most_recent['timestamp'] ) < self::CACHE_TTL ) {
			return $most_recent['customOrder'] ?? null;
		}

		return null;
	}
}

/**
 * Capture Query Loop block attributes when the block is rendered.
 * This allows us to access the parent block's attributes in query_loop_block_query_vars.
 *
 * @param string   $block_content The block content.
 * @param array    $block         The block array.
 * @return string Unmodified block content.
 */
function custom_query_order_render_block( $block_content, $block ) {
	// Only process Query Loop blocks.
	if ( 'core/query' !== ( $block['blockName'] ?? '' ) ) {
		return $block_content;
	}

	// Check if this block has customOrder attribute.
	$custom_order = $block['attrs']['customOrder'] ?? null;
	
	if ( ! empty( $custom_order ) && is_array( $custom_order ) ) {
		// Get a unique identifier for this block.
		$block_id = $block['attrs']['queryId'] ?? $block['attrs']['id'] ?? $block['attrs']['anchor'] ?? uniqid( 'query_', true );
		
		// Store the custom order in cache.
		Custom_Query_Order_Cache::set( $block_id, $custom_order );
	}

	return $block_content;
}
add_filter( 'render_block', 'custom_query_order_render_block', 10, 2 );

/**
 * Modify the query to prepare for custom order.
 * Sets orderby to 'none' to prevent default ordering, and stores custom order in query args.
 *
 * @param array    $query_args Array of arguments used to query for posts.
 * @param WP_Block $block      The block instance (this is the inner block, not the Query Loop block).
 * @return array Modified query arguments.
 */
function custom_query_order_modify_query( $query_args, $block ) {
	// Try to get the Query Loop block's attributes from context or cache.
	// The block passed here is the inner block (post-template), so we need to get the parent's attributes.
	$custom_order = null;
	
	// Need queryId to proceed.
	if ( ! isset( $block->context['queryId'] ) ) {
		return $query_args;
	}
	
	$query_id = $block->context['queryId'];
	
	// Method 1: Try to get from cache (populated by render_block hook).
	$custom_order = Custom_Query_Order_Cache::get( $query_id );
	
	// Method 2: If cache is empty, parse block attributes from post content (frontend only).
	// This handles cases where query_loop_block_query_vars fires before render_block.
	if ( ! $custom_order && ! defined( 'REST_REQUEST' ) ) {
		// Try to get the current post ID from WordPress context (frontend only).
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			global $post;
			$post_id = $post->ID ?? null;
		}
		
		if ( $post_id ) {
			$post_content = get_post_field( 'post_content', $post_id );
			if ( false !== $post_content && ! empty( $post_content ) ) {
				$blocks = parse_blocks( $post_content );
				if ( is_array( $blocks ) ) {
					// Search for the Query Loop block with matching queryId.
					foreach ( $blocks as $parsed_block ) {
						if ( 'core/query' === ( $parsed_block['blockName'] ?? '' ) ) {
							$block_query_id = $parsed_block['attrs']['queryId'] ?? null;
							if ( $block_query_id === $query_id ) {
								$found_order = $parsed_block['attrs']['customOrder'] ?? null;
								if ( ! empty( $found_order ) && is_array( $found_order ) ) {
									$custom_order = $found_order;
									// Cache it for future use.
									Custom_Query_Order_Cache::set( $query_id, $custom_order );
									break;
								}
							}
						}
						// Recursively search inner blocks.
						if ( ! empty( $parsed_block['innerBlocks'] ) ) {
							foreach ( $parsed_block['innerBlocks'] as $inner_block ) {
								if ( 'core/query' === ( $inner_block['blockName'] ?? '' ) ) {
									$inner_query_id = $inner_block['attrs']['queryId'] ?? null;
									if ( $inner_query_id === $query_id ) {
										$found_order = $inner_block['attrs']['customOrder'] ?? null;
										if ( ! empty( $found_order ) && is_array( $found_order ) ) {
											$custom_order = $found_order;
											Custom_Query_Order_Cache::set( $query_id, $custom_order );
											break 2;
										}
									}
								}
							}
						}
					}
				}
			}
		}
	}
	
	// Only apply custom order if it's set.
	if ( ! $custom_order || ! is_array( $custom_order ) || empty( $custom_order ) ) {
		return $query_args;
	}

	// Store the custom order directly in query args so we can retrieve it in posts_results.
	// WordPress will preserve custom query vars in $query->query_vars.
	$query_args['_custom_query_order'] = $custom_order;

	// Store the original posts_per_page so we can apply pagination after reordering.
	$original_posts_per_page = $query_args['posts_per_page'] ?? 10;
	$query_args['_original_posts_per_page'] = $original_posts_per_page;
	$query_args['_original_offset'] = $query_args['offset'] ?? 0;

	// When custom order is set, we need to fetch enough posts to cover:
	// 1. All posts in the custom order array
	// 2. The original pagination requirements (posts_per_page + offset)
	// We'll apply pagination after reordering in posts_results.
	$max_posts_needed = max( count( $custom_order ), $original_posts_per_page + ( $query_args['offset'] ?? 0 ) );
	$query_args['posts_per_page'] = $max_posts_needed; // Fetch exactly what we need.
	unset( $query_args['offset'] ); // Remove offset, we'll handle it after reordering.

	// Set orderby to 'none' to prevent default ordering from interfering.
	// We'll apply the custom order in posts_results filter.
	$query_args['orderby'] = 'none';
	unset( $query_args['order'] ); // Remove order as we'll handle it in posts_results.

	return $query_args;
}
add_filter( 'query_loop_block_query_vars', 'custom_query_order_modify_query', 10, 2 );

/**
 * Apply custom order to query results.
 * This works for both frontend queries and REST API requests.
 *
 * @param array    $posts The array of post objects.
 * @param WP_Query $query The WP_Query instance.
 * @return array Reordered posts.
 */
function custom_query_order_apply_custom_order( $posts, $query ) {
	// Check if this query has a custom order.
	$saved_order = $query->query_vars['_custom_query_order'] ?? null;
	
	if ( ! $saved_order || ! is_array( $saved_order ) || empty( $saved_order ) ) {
		return $posts;
	}

	// Also verify that orderby is 'none' to ensure this is our custom query.
	$orderby = $query->query_vars['orderby'] ?? '';
	if ( is_array( $orderby ) ) {
		// Sometimes orderby can be an array, check if 'none' is in it.
		if ( ! in_array( 'none', $orderby, true ) ) {
			return $posts;
		}
	} elseif ( 'none' !== $orderby ) {
		return $posts;
	}

	// Create a map of post IDs to posts for quick lookup.
	$posts_map = array();
	foreach ( $posts as $post ) {
		$posts_map[ $post->ID ] = $post;
	}

	// Build ordered array: first by saved order, then append any not in saved order.
	$ordered_posts   = array();
	$unordered_posts = array();

	// Add posts in saved order.
	foreach ( $saved_order as $post_id ) {
		$post_id = (int) $post_id; // Ensure it's an integer.
		if ( isset( $posts_map[ $post_id ] ) ) {
			$ordered_posts[] = $posts_map[ $post_id ];
			unset( $posts_map[ $post_id ] );
		}
	}

	// Add any remaining posts that weren't in the saved order.
	foreach ( $posts_map as $post ) {
		$unordered_posts[] = $post;
	}

	// Combine ordered and unordered posts.
	$reordered_posts = array_merge( $ordered_posts, $unordered_posts );
	
	// Apply pagination after reordering.
	$original_posts_per_page = $query->query_vars['_original_posts_per_page'] ?? count( $reordered_posts );
	$original_offset         = $query->query_vars['_original_offset'] ?? 0;
	
	// Slice the reordered posts to apply pagination.
	$paginated_posts = array_slice( $reordered_posts, $original_offset, $original_posts_per_page );

	return $paginated_posts;
}
add_filter( 'posts_results', 'custom_query_order_apply_custom_order', 10, 2 );

