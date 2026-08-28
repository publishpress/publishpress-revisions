( function( wp ) {
	if (
		! wp ||
		! wp.blocks ||
		! wp.components ||
		! wp.element ||
		! wp.i18n ||
		! wp.serverSideRender ||
		! ( wp.blockEditor || wp.editor )
	) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = ( wp.blockEditor || wp.editor ).InspectorControls;
	var useBlockProps = ( wp.blockEditor && wp.blockEditor.useBlockProps ) ? wp.blockEditor.useBlockProps : null;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;

	registerBlockType( 'revisionary/recent-revisions', {
		title: __( 'Recent Revisions', 'revisionary' ),
		description: __( 'Display recent revisions and a summary of the fields changed in each revision.', 'revisionary' ),
		icon: 'backup',
		category: 'widgets',
		supports: {
			html: false
		},
		attributes: {
			heading: {
				type: 'string',
				default: ''
			},
			postId: {
				type: 'number',
				default: 0
			},
			count: {
				type: 'number',
				default: 5
			},
			showAuthor: {
				type: 'boolean',
				default: true
			},
			showDiff: {
				type: 'boolean',
				default: false
			},
			hideUnchanged: {
				type: 'boolean',
				default: true
			},
			includeWorkflow: {
				type: 'boolean',
				default: false
			}
		},
		edit: function( props ) {
			var attributes = props.attributes;
			var blockProps = useBlockProps ? useBlockProps() : { className: 'wp-block-revisionary-recent-revisions' };

			function setNumberAttribute( name ) {
				return function( value ) {
					var update = {};
					update[ name ] = parseInt( value, 10 ) || 0;
					props.setAttributes( update );
				};
			}

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Revision Settings', 'revisionary' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Heading', 'revisionary' ),
							value: attributes.heading,
							placeholder: __( 'Recent Revisions', 'revisionary' ),
							onChange: function( value ) {
								props.setAttributes( { heading: value } );
							}
						} ),
						el( TextControl, {
							label: __( 'Post ID', 'revisionary' ),
							help: __( 'Leave empty to use the current post.', 'revisionary' ),
							type: 'number',
							value: attributes.postId || '',
							onChange: setNumberAttribute( 'postId' )
						} ),
						el( RangeControl, {
							label: __( 'Number of revisions', 'revisionary' ),
							value: attributes.count,
							min: 1,
							max: 20,
							onChange: setNumberAttribute( 'count' )
						} ),
						el( ToggleControl, {
							label: __( 'Show author', 'revisionary' ),
							checked: !! attributes.showAuthor,
							onChange: function( value ) {
								props.setAttributes( { showAuthor: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show diff details to authorized users', 'revisionary' ),
							checked: !! attributes.showDiff,
							onChange: function( value ) {
								props.setAttributes( { showDiff: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Hide revisions with no tracked field changes', 'revisionary' ),
							checked: attributes.hideUnchanged !== false,
							onChange: function( value ) {
								props.setAttributes( { hideUnchanged: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Include pending and scheduled revisions', 'revisionary' ),
							help: __( 'Only users with permission to edit the post can see these revisions.', 'revisionary' ),
							checked: !! attributes.includeWorkflow,
							onChange: function( value ) {
								props.setAttributes( { includeWorkflow: value } );
							}
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'revisionary/recent-revisions',
						attributes: attributes
					} )
				)
			);
		},
		save: function() {
			return null;
		}
	} );
}( window.wp ) );
