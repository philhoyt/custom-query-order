/**
 * WordPress dependencies
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Sortable post list component using HTML5 drag and drop.
 */
export default function SortablePostList( { posts, onOrderChange } ) {
	const [ draggedIndex, setDraggedIndex ] = useState( null );
	const [ draggedOverIndex, setDraggedOverIndex ] = useState( null );
	const [ localPosts, setLocalPosts ] = useState( posts );
	const scrollContainerRef = useRef( null );
	const scrollIntervalRef = useRef( null );

	// Update local state when posts prop changes.
	useEffect( () => {
		setLocalPosts( posts );
	}, [ posts ] );

	// Cleanup scroll interval on unmount.
	useEffect( () => {
		return () => {
			if ( scrollIntervalRef.current ) {
				clearInterval( scrollIntervalRef.current );
			}
		};
	}, [] );

	const startAutoScroll = ( direction, speed = 10 ) => {
		if ( scrollIntervalRef.current ) {
			clearInterval( scrollIntervalRef.current );
		}

		const scrollContainer = scrollContainerRef.current?.closest( '.custom-query-order-modal__content' );
		if ( ! scrollContainer ) {
			return;
		}

		scrollIntervalRef.current = setInterval( () => {
			if ( direction === 'up' ) {
				scrollContainer.scrollTop = Math.max( 0, scrollContainer.scrollTop - speed );
			} else {
				scrollContainer.scrollTop = Math.min(
					scrollContainer.scrollHeight - scrollContainer.clientHeight,
					scrollContainer.scrollTop + speed
				);
			}
		}, 16 ); // ~60fps
	};

	const stopAutoScroll = () => {
		if ( scrollIntervalRef.current ) {
			clearInterval( scrollIntervalRef.current );
			scrollIntervalRef.current = null;
		}
	};

	const handleDragStart = ( index ) => {
		setDraggedIndex( index );
	};

	const handleDragOver = ( e, index ) => {
		e.preventDefault();
		setDraggedOverIndex( index );

		// Auto-scroll when dragging near the edges of the scrollable container.
		const scrollContainer = scrollContainerRef.current?.closest( '.custom-query-order-modal__content' );
		if ( scrollContainer ) {
			const rect = scrollContainer.getBoundingClientRect();
			const mouseY = e.clientY;
			const scrollThreshold = 200; // Increased distance from edge to trigger scroll
			const distanceFromTop = mouseY - rect.top;
			const distanceFromBottom = rect.bottom - mouseY;

			// Calculate scroll speed based on proximity to edge (closer = faster)
			let scrollSpeed = 10;
			if ( distanceFromTop < scrollThreshold ) {
				const proximity = Math.max( 0, scrollThreshold - distanceFromTop );
				scrollSpeed = 5 + ( proximity / scrollThreshold ) * 15; // 5-20px per frame
			} else if ( distanceFromBottom < scrollThreshold ) {
				const proximity = Math.max( 0, scrollThreshold - distanceFromBottom );
				scrollSpeed = 5 + ( proximity / scrollThreshold ) * 15; // 5-20px per frame
			}

			if ( distanceFromTop < scrollThreshold && scrollContainer.scrollTop > 0 ) {
				startAutoScroll( 'up', scrollSpeed );
			} else if ( distanceFromBottom < scrollThreshold && scrollContainer.scrollTop < scrollContainer.scrollHeight - scrollContainer.clientHeight ) {
				startAutoScroll( 'down', scrollSpeed );
			} else {
				stopAutoScroll();
			}
		}
	};

	const handleDragLeave = () => {
		setDraggedOverIndex( null );
		stopAutoScroll();
	};

	const handleDrop = ( e, dropIndex ) => {
		e.preventDefault();

		if ( draggedIndex === null || draggedIndex === dropIndex ) {
			setDraggedIndex( null );
			setDraggedOverIndex( null );
			return;
		}

		const newPosts = [ ...localPosts ];
		const draggedPost = newPosts[ draggedIndex ];

		// Remove the dragged item from its original position.
		newPosts.splice( draggedIndex, 1 );

		// Calculate the correct drop index (adjust if dragging down).
		const adjustedDropIndex = draggedIndex < dropIndex ? dropIndex - 1 : dropIndex;

		// Insert it at the new position.
		newPosts.splice( adjustedDropIndex, 0, draggedPost );

		const newOrder = newPosts.map( ( post ) => post.id );
		
		setLocalPosts( newPosts );
		setDraggedIndex( null );
		setDraggedOverIndex( null );

		// Notify parent of the order change.
		onOrderChange( newOrder );
	};

	const handleDragEnd = () => {
		setDraggedIndex( null );
		setDraggedOverIndex( null );
		stopAutoScroll();
	};

	return (
		<div className="custom-query-order-sortable-list" ref={ scrollContainerRef }>
			{ localPosts.map( ( post, index ) => {
				const isDragged = index === draggedIndex;
				const isDraggedOver = index === draggedOverIndex && index !== draggedIndex;

				return (
					<div
						key={ post.id }
						draggable
						onDragStart={ () => handleDragStart( index ) }
						onDragOver={ ( e ) => handleDragOver( e, index ) }
						onDragLeave={ handleDragLeave }
						onDrop={ ( e ) => handleDrop( e, index ) }
						onDragEnd={ handleDragEnd }
						className={ `custom-query-order-sortable-item ${
							isDragged ? 'is-dragging' : ''
						} ${ isDraggedOver ? 'is-drag-over' : '' }` }
						style={ {
							opacity: isDragged ? 0.5 : 1,
						} }
					>
						<div className="custom-query-order-sortable-item__handle">
							<svg
								width="20"
								height="20"
								viewBox="0 0 20 20"
								fill="none"
								xmlns="http://www.w3.org/2000/svg"
							>
								<circle cx="7" cy="5" r="1.5" fill="currentColor" />
								<circle cx="13" cy="5" r="1.5" fill="currentColor" />
								<circle cx="7" cy="10" r="1.5" fill="currentColor" />
								<circle cx="13" cy="10" r="1.5" fill="currentColor" />
								<circle cx="7" cy="15" r="1.5" fill="currentColor" />
								<circle cx="13" cy="15" r="1.5" fill="currentColor" />
							</svg>
						</div>
						<div className="custom-query-order-sortable-item__content">
							<strong>{ post.title?.rendered || __( '(No title)', 'custom-query-order' ) }</strong>
						</div>
						<div className="custom-query-order-sortable-item__position">
							{ index + 1 }
						</div>
					</div>
				);
			} ) }
		</div>
	);
}

