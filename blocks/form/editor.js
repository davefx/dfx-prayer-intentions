/**
 * Editor side of the prayer request form block. Plain JavaScript, no build.
 */
( function ( wp ) {
	'use strict';

	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, Notice } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const groups = window.dfxprayGroups || [];

	wp.blocks.registerBlockType( 'dfxpray/form', {
		edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps();
			const options = [ { value: 0, label: __( '— Choose a group —', 'dfx-prayer-intentions' ) } ].concat( groups );
			const groupId = attributes.groupId || ( 1 === groups.length ? groups[ 0 ].value : 0 );

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Group', 'dfx-prayer-intentions' ) },
						el( SelectControl, {
							label: __( 'Requests go to', 'dfx-prayer-intentions' ),
							value: groupId,
							options,
							onChange: ( value ) => setAttributes( { groupId: parseInt( value, 10 ) || 0 } ),
						} )
					)
				),
				groupId
					? el( ServerSideRender, { block: 'dfxpray/form', attributes: { groupId } } )
					: el( Notice, { status: 'warning', isDismissible: false }, __( 'Choose the group that will receive the requests in the block settings.', 'dfx-prayer-intentions' ) )
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp );
