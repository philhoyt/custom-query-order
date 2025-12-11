/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import { Modal, Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import SortablePostList from './sortable-post-list';

export default function CustomOrderModal( {
	clientId,
	attributes,
	setAttributes,
	onClose,
} ) {
	const [ posts, setPosts ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ orderedPostIds, setOrderedPostIds ] = useState( [] );

	useEffect( () => {
		// Fetch posts based on the query parameters.
		const fetchPosts = async () => {
			setIsLoading( true );
			try {
				// Get query parameters from the Query Loop block.
				const query = attributes.query || {};
				const postType = query.postType || 'post';

				// Build REST API path based on post type.
				const restBase = postType === 'post' ? 'posts' : postType;
				let apiPath = `/wp/v2/${ restBase }?per_page=100&status=publish`;

				// Add query parameters.
				const params = new URLSearchParams();

				if ( query.categories && query.categories.length > 0 ) {
					params.append( 'categories', query.categories.join( ',' ) );
				}

				if ( query.tags && query.tags.length > 0 ) {
					params.append( 'tags', query.tags.join( ',' ) );
				}

				if ( query.author ) {
					params.append( 'author', query.author );
				}

				if ( query.search ) {
					params.append( 'search', query.search );
				}

				if ( query.exclude && query.exclude.length > 0 ) {
					params.append( 'exclude', query.exclude.join( ',' ) );
				}

				if ( query.include && query.include.length > 0 ) {
					params.append( 'include', query.include.join( ',' ) );
				}

				if ( params.toString() ) {
					apiPath += '&' + params.toString();
				}

				const fetchedPosts = await apiFetch( {
					path: apiPath,
				} );

				// Get saved order from attributes.
				const savedOrder = attributes.customOrder || [];

				// If we have a saved custom order, use it to sort the posts.
				if ( savedOrder.length > 0 ) {
					const orderedPosts = [];
					const unorderedPosts = [];
					const fetchedPostsMap = new Map(
						fetchedPosts.map( ( post ) => [ post.id, post ] )
					);

					// First, add posts in the saved order.
					savedOrder.forEach( ( id ) => {
						if ( fetchedPostsMap.has( id ) ) {
							orderedPosts.push( fetchedPostsMap.get( id ) );
						}
					} );

					// Then add any posts that weren't in the saved order.
					fetchedPosts.forEach( ( post ) => {
						if ( ! savedOrder.includes( post.id ) ) {
							unorderedPosts.push( post );
						}
					} );

					const sortedPosts = [ ...orderedPosts, ...unorderedPosts ];
					setPosts( sortedPosts );
					setOrderedPostIds( sortedPosts.map( ( post ) => post.id ) );
				} else {
					setPosts( fetchedPosts );
					setOrderedPostIds(
						fetchedPosts.map( ( post ) => post.id )
					);
				}
			} catch ( error ) {
				// Log error in development mode.
				if ( process.env.NODE_ENV === 'development' ) {
					// eslint-disable-next-line no-console
					console.error(
						'[CUSTOM_QUERY_ORDER] Error fetching posts:',
						error
					);
				}
				// Set empty state on error.
				setPosts( [] );
				setOrderedPostIds( [] );
			} finally {
				setIsLoading( false );
			}
		};

		fetchPosts();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ clientId, attributes.query ] );

	const handleSave = () => {
		if ( ! orderedPostIds || orderedPostIds.length === 0 ) {
			return;
		}

		setIsSaving( true );

		// Ensure we have a valid array of numbers.
		const validOrder = orderedPostIds.filter(
			( id ) => Number.isInteger( id ) && id > 0
		);

		// Use setAttributes with just the customOrder to merge properly.
		setAttributes( {
			customOrder: validOrder,
		} );

		// Wait a bit to ensure the save completes.
		setTimeout( () => {
			setIsSaving( false );
			onClose();
		}, 300 );
	};

	const handleOrderChange = ( newOrder ) => {
		setOrderedPostIds( newOrder );

		// Update the posts array to match the new order.
		const orderedPostsMap = new Map(
			posts.map( ( post ) => [ post.id, post ] )
		);
		const reorderedPosts = newOrder
			.map( ( id ) => orderedPostsMap.get( id ) )
			.filter( ( post ) => post !== undefined );

		setPosts( reorderedPosts );
	};

	return (
		<Modal
			title={ __( 'Custom Post Order', 'custom-query-order' ) }
			onRequestClose={ onClose }
			className="custom-query-order-modal"
			style={ { maxWidth: '800px' } }
		>
			<div className="custom-query-order-modal__wrapper">
				{ ! isLoading && posts.length > 0 && (
					<div className="custom-query-order-modal__header">
						<p className="custom-query-order-modal__description">
							{ __(
								'Drag and drop posts to reorder them.',
								'custom-query-order'
							) }
						</p>
						<div className="custom-query-order-modal__actions">
							<Button variant="secondary" onClick={ onClose }>
								{ __( 'Cancel', 'custom-query-order' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ handleSave }
								isBusy={ isSaving }
							>
								{ __( 'Save Order', 'custom-query-order' ) }
							</Button>
						</div>
					</div>
				) }
				<div className="custom-query-order-modal__content">
					{ isLoading && (
						<div style={ { textAlign: 'center', padding: '40px' } }>
							<Spinner />
							<p>
								{ __(
									'Loading posts…',
									'custom-query-order'
								) }
							</p>
						</div>
					) }
					{ ! isLoading && posts.length === 0 && (
						<p>
							{ __(
								'No posts found to sort.',
								'custom-query-order'
							) }
						</p>
					) }
					{ ! isLoading && posts.length > 0 && (
						<SortablePostList
							posts={ posts }
							onOrderChange={ handleOrderChange }
						/>
					) }
				</div>
			</div>
		</Modal>
	);
}
