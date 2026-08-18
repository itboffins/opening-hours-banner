/* ITBoffins Live Opening Hours — block editor script.
 *
 * No build step: relies on the WordPress packages bundled with core, referenced
 * via the global `wp` object. The block is server-rendered (ServerSideRender),
 * so the editor preview matches the front end exactly.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var useBlockProps = wp.blockEditor.useBlockProps;

	wp.blocks.registerBlockType( 'iboh/opening-hours', {
		apiVersion: 3,
		title: __( 'Live Opening Hours', 'itboffins-live-opening-hours' ),
		description: __( 'Show dynamic opening hours and a live open/closed message.', 'itboffins-live-opening-hours' ),
		icon: 'clock',
		category: 'widgets',
		keywords: [ __( 'hours', 'itboffins-live-opening-hours' ), __( 'open', 'itboffins-live-opening-hours' ), __( 'business', 'itboffins-live-opening-hours' ) ],
		attributes: {
			show: { type: 'string', 'default': 'table' }
		},
		edit: function ( props ) {
			var blockProps = useBlockProps ? useBlockProps() : {};
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Display', 'itboffins-live-opening-hours' ) },
						el( SelectControl, {
							label: __( 'Show', 'itboffins-live-opening-hours' ),
							value: props.attributes.show,
							options: [
								{ label: __( 'Hours table', 'itboffins-live-opening-hours' ), value: 'table' },
								{ label: __( 'Open or closed message', 'itboffins-live-opening-hours' ), value: 'status' },
								{ label: __( 'Both', 'itboffins-live-opening-hours' ), value: 'both' }
							],
							onChange: function ( value ) {
								props.setAttributes( { show: value } );
							}
						} )
					)
				),
				el( ServerSideRender, {
					block: 'iboh/opening-hours',
					attributes: props.attributes
				} )
			);
		},
		save: function () {
			return null; // Dynamic block, rendered in PHP.
		}
	} );
} )( window.wp );
