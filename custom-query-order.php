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
 * Storage for Query Loop block attributes, keyed by block ID.
 * Used to pass attributes from render_block to query_loop_block_query_vars.
 *
 * @var array
 */
$custom_query_order_block_cache = array();

/**
 * Capture Query Loop block attributes when the block is rendered.
 * This allows us to access the parent block's attributes in query_loop_block_query_vars.
 *
 * @param string   $block_content The block content.
 * @param array    $block         The block array.
 * @return string Unmodified block content.
 */
function custom_query_order_render_block( $block_content, $block ) {
	global $custom_query_order_block_cache;
	
	// Only process Query Loop blocks.
	if ( 'core/query' !== ( $block['blockName'] ?? '' ) ) {
		return $block_content;
	}

	// Clean up old cache entries (older than 5 minutes).
	$current_time = time();
	foreach ( $custom_query_order_block_cache as $key => $cache_entry ) {
		if ( isset( $cache_entry['timestamp'] ) && ( $current_time - $cache_entry['timestamp'] ) > 300 ) {
			unset( $custom_query_order_block_cache[ $key ] );
		}
	}

	// Check if this block has customOrder attribute.
	$custom_order = $block['attrs']['customOrder'] ?? null;
	
	if ( ! empty( $custom_order ) && is_array( $custom_order ) ) {
		// Get a unique identifier for this block.
		$block_id = $block['attrs']['queryId'] ?? $block['attrs']['id'] ?? $block['attrs']['anchor'] ?? uniqid( 'query_', true );
		
		// Store the custom order with a timestamp for cleanup.
		$custom_query_order_block_cache[ $block_id ] = array(
			'customOrder' => $custom_order,
			'timestamp'   => time(),
		);
	}

	return $block_content;
}
add_filter( 'render_block', 'custom_query_order_render_block', 10, 2 );

/**
 * Recursively search blocks for a Query Loop block with matching queryId.
 *
 * @param array $blocks Array of block arrays.
 * @param int   $query_id The queryId to search for.
 * @return array|null The customOrder array if found, null otherwise.
 */
function custom_query_order_find_block_attributes( $blocks, $query_id ) {
	foreach ( $blocks as $block ) {
		// Check if this is a Query Loop block with matching queryId.
		if ( 'core/query' === ( $block['blockName'] ?? '' ) ) {
			$block_query_id = $block['attrs']['queryId'] ?? null;
			if ( $block_query_id == $query_id ) {
				$custom_order = $block['attrs']['customOrder'] ?? null;
				if ( ! empty( $custom_order ) && is_array( $custom_order ) ) {
					return $custom_order;
				}
			}
		}
		
		// Recursively search inner blocks.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$found = custom_query_order_find_block_attributes( $block['innerBlocks'], $query_id );
			if ( $found ) {
				return $found;
			}
		}
	}
	
	return null;
}

/**
 * Modify the query to prepare for custom order.
 * Sets orderby to 'none' to prevent default ordering, and stores custom order in query args.
 *
 * @param array    $query_args Array of arguments used to query for posts.
 * @param WP_Block $block      The block instance (this is the inner block, not the Query Loop block).
 * @return array Modified query arguments.
 */
function custom_query_order_modify_query( $query_args, $block ) {
	global $custom_query_order_block_cache;
	
	// Try to get the Query Loop block's attributes from context or cache.
	// The block passed here is the inner block (post-template), so we need to get the parent's attributes.
	$custom_order = null;
	
	// Method 1: Try to get from block context (if available).
	if ( isset( $block->context['queryId'] ) ) {
		$query_id = $block->context['queryId'];
		if ( isset( $custom_query_order_block_cache[ $query_id ] ) ) {
			$cache_entry = $custom_query_order_block_cache[ $query_id ];
			// Check if cache is still valid (5 minutes).
			if ( isset( $cache_entry['timestamp'] ) && ( time() - $cache_entry['timestamp'] ) < 300 ) {
				$custom_order = $cache_entry['customOrder'] ?? null;
			}
		}
	}
	
	// Method 2: Try to get from block attributes (if the block itself has it - shouldn't happen but just in case).
	if ( ! $custom_order && isset( $block->attributes['customOrder'] ) ) {
		$custom_order = $block->attributes['customOrder'];
	}
	
	// Method 3: Try to parse block attributes from post content (for REST API requests).
	if ( ! $custom_order && isset( $block->context['queryId'] ) ) {
		$query_id = $block->context['queryId'];
		// Try to get the current post ID from various sources.
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			// Try to get from global post.
			global $post;
			$post_id = $post->ID ?? null;
		}
		if ( ! $post_id && isset( $_GET['post'] ) ) {
			$post_id = intval( $_GET['post'] );
		}
		if ( ! $post_id && isset( $_POST['post_id'] ) ) {
			$post_id = intval( $_POST['post_id'] );
		}
		
		// For REST API requests, try to get post ID from the request context.
		if ( ! $post_id && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// Try to get from REST API request.
			global $wp_rest_server;
			if ( $wp_rest_server ) {
				$request = $wp_rest_server->get_request();
				if ( $request ) {
					// Check if this is a request for a specific post.
					$route = $request->get_route();
					// Routes like /wp/v2/posts/123 or /wp/v2/pages/123
					if ( preg_match( '#/wp/v2/(?:posts|pages|wp_peeps_people)/(\d+)#', $route, $matches ) ) {
						$post_id = intval( $matches[1] );
					}
					// Also check query parameters.
					if ( ! $post_id ) {
						$params = $request->get_query_params();
						if ( isset( $params['post'] ) ) {
							$post_id = intval( $params['post'] );
						} elseif ( isset( $params['post_id'] ) ) {
							$post_id = intval( $params['post_id'] );
						} elseif ( isset( $params['context'] ) && $params['context'] === 'edit' ) {
							// For editor preview, the post ID might be in the referer or headers.
							$referer = $request->get_header( 'referer' );
							if ( $referer && preg_match( '/post\.php\?post=(\d+)/', $referer, $matches ) ) {
								$post_id = intval( $matches[1] );
							}
						}
					}
				}
			}
		}
		
		// Also try to get from the block's attributes if it has a postId (unlikely but possible).
		if ( ! $post_id && isset( $block->attributes['postId'] ) ) {
			$post_id = intval( $block->attributes['postId'] );
		}
		
		if ( $post_id ) {
			$post_content = get_post_field( 'post_content', $post_id );
			if ( $post_content ) {
				$blocks = parse_blocks( $post_content );
				$custom_order = custom_query_order_find_block_attributes( $blocks, $query_id );
				if ( $custom_order ) {
					// Cache it for future use.
					$custom_query_order_block_cache[ $query_id ] = array(
						'customOrder' => $custom_order,
						'timestamp'   => time(),
					);
				}
			}
		}
	}
	
	// Method 4: Try to find in cache by matching query parameters (fallback).
	if ( ! $custom_order && ! empty( $custom_query_order_block_cache ) ) {
		// Use the most recent cache entry as fallback.
		$most_recent = end( $custom_query_order_block_cache );
		if ( isset( $most_recent['timestamp'] ) && ( time() - $most_recent['timestamp'] ) < 300 ) {
			$custom_order = $most_recent['customOrder'] ?? null;
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

	// When custom order is set, we need to fetch ALL posts that match the query
	// (or at least all posts in the custom order) so we can reorder them properly.
	// We'll apply pagination after reordering in posts_results.
	// Fetch enough posts to cover all posts in the custom order plus a buffer.
	$max_posts_needed = max( count( $custom_order ), $original_posts_per_page + ( $query_args['offset'] ?? 0 ) );
	$query_args['posts_per_page'] = max( $max_posts_needed, 100 ); // Fetch at least 100 posts or enough to cover custom order.
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

