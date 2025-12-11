/**
 * WordPress dependencies
 */
import { registerBlockVariation } from '@wordpress/blocks';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CustomOrderModal from './components/custom-order-modal';
import './style.scss';

/**
 * Register block variation for Query Loop with custom order support.
 * Use a try-catch to prevent errors from breaking the editor.
 */
try {
	registerBlockVariation( 'core/query', {
		name: 'custom-query-order',
		title: __( 'Query Loop (Custom Order)', 'custom-query-order' ),
		description: __( 'Query Loop block with custom drag-and-drop sorting.', 'custom-query-order' ),
		attributes: {
			namespace: 'custom-query-order',
			customOrder: {
				type: 'array',
				default: [],
			},
		},
		isActive: ( blockAttributes, variationAttributes ) => {
			// Only return true if namespace is explicitly set to our namespace
			return blockAttributes?.namespace === variationAttributes?.namespace;
		},
	} );
} catch ( error ) {
	// Silently fail if variation registration fails
}

/**
 * Register custom attributes for the Query Loop block.
 * This ensures the attributes are properly recognized and saved.
 * Using a lower priority to ensure core variations are registered first.
 */
addFilter(
	'blocks.registerBlockType',
	'custom-query-order/add-attributes',
	( settings, name ) => {
		if ( name === 'core/query' && settings && typeof settings === 'object' ) {
			// Create a new settings object to avoid mutating the original
			const newSettings = { ...settings };
			
			// Safely merge attributes without overwriting existing ones
			if ( ! newSettings.attributes ) {
				newSettings.attributes = {};
			} else {
				// Create a new attributes object to avoid mutation
				newSettings.attributes = { ...newSettings.attributes };
			}
			
			// Only add our attributes if they don't already exist
			if ( ! newSettings.attributes.namespace ) {
				newSettings.attributes.namespace = {
					type: 'string',
					default: '',
				};
			}
			if ( ! newSettings.attributes.customOrder ) {
				newSettings.attributes.customOrder = {
					type: 'array',
					default: [],
				};
			}
			
			return newSettings;
		}
		return settings;
	},
	20 // Lower priority - run after core variations are set up
);

/**
 * Add custom order control to Query Loop block sidebar.
 */
const withCustomOrderControl = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		const { attributes, setAttributes, clientId } = props;
		const [ isModalOpen, setIsModalOpen ] = useState( false );

		// Only show for Query Loop blocks with our namespace.
		if ( props.name !== 'core/query' || attributes.namespace !== 'custom-query-order' ) {
			return <BlockEdit { ...props } />;
		}

		// No need to automatically set orderBy - custom order works independently.

		return (
			<>
				<BlockEdit { ...props } />
				<InspectorControls>
					<PanelBody
						title={ __( 'Custom Order', 'custom-query-order' ) }
						initialOpen={ false }
					>
						<p>
							{ __(
								'Set a custom order for the posts in this query. When a custom order is set, it will override the "Order By" setting in the Query panel.',
								'custom-query-order'
							) }
						</p>
						<p style={ { fontSize: '12px', color: '#666', fontStyle: 'italic', marginTop: '8px' } }>
							{ __(
								'Note: The editor preview may not reflect the custom order. The order will be correct on the frontend.',
								'custom-query-order'
							) }
						</p>
						<Button
							variant="primary"
							onClick={ () => {
								setIsModalOpen( true );
							} }
							style={ { width: '100%', marginTop: '10px' } }
						>
							{ __( 'Manage Post Order', 'custom-query-order' ) }
						</Button>
						{ attributes.customOrder && attributes.customOrder.length > 0 && (
							<p style={ { marginTop: '10px', fontSize: '12px', color: '#666' } }>
								{ __(
									`${ attributes.customOrder.length } posts in custom order`,
									'custom-query-order'
								) }
							</p>
						) }
					</PanelBody>
				</InspectorControls>
				{ isModalOpen && (
					<CustomOrderModal
						clientId={ clientId }
						attributes={ attributes }
						setAttributes={ ( newAttributes ) => {
							setAttributes( newAttributes );
						} }
						onClose={ () => {
							setIsModalOpen( false );
						} }
					/>
				) }
			</>
		);
	};
}, 'withCustomOrderControl' );

addFilter(
	'editor.BlockEdit',
	'custom-query-order/add-control',
	withCustomOrderControl
);

