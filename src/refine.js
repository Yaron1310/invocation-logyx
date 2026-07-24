/**
 * Invocation "Refine with AI" block toolbar action.
 *
 * Adds a toolbar button to every selected block that lets the user describe a
 * change in natural language; the selected block(s) are serialized, sent to the
 * invocation/refine-block ability, and replaced in place with the result.
 */

import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import {
	BlockControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	ToolbarGroup,
	ToolbarButton,
	Modal,
	TextareaControl,
	Button,
	Notice,
	Flex,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { serialize, parse } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { INVOCATION_ICON } from './constants';

function RefineControl( { clientId } ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ instruction, setInstruction ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const block = useSelect(
		( select ) => select( blockEditorStore ).getBlock( clientId ),
		[ clientId ]
	);
	const { replaceBlocks } = useDispatch( blockEditorStore );

	const close = () => {
		if ( isLoading ) {
			return;
		}
		setIsOpen( false );
		setError( null );
		setInstruction( '' );
	};

	const onRefine = async () => {
		const trimmed = instruction.trim();
		if ( ! trimmed || ! block ) {
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const result = await apiFetch( {
				path: '/wp-abilities/v1/abilities/invocation/refine-block/run',
				method: 'POST',
				data: {
					input: {
						blockMarkup: serialize( block ),
						instruction: trimmed,
					},
				},
			} );

			if ( ! result?.blockMarkup ) {
				throw new Error(
					__( 'No refined content was returned.', 'invocation' )
				);
			}

			const blocks = parse( result.blockMarkup );
			if ( ! blocks.length ) {
				throw new Error(
					__(
						'The refined content could not be parsed.',
						'invocation'
					)
				);
			}

			replaceBlocks( clientId, blocks );
			setIsOpen( false );
			setInstruction( '' );
		} catch ( err ) {
			setError(
				err?.message ||
					__(
						'Refine failed. Check your AI Connector.',
						'invocation'
					)
			);
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton
						icon={ INVOCATION_ICON }
						label={ __( 'Refine with Invocation', 'invocation' ) }
						onClick={ () => setIsOpen( true ) }
					/>
				</ToolbarGroup>
			</BlockControls>

			{ isOpen && (
				<Modal
					title={ __( 'Refine with Invocation', 'invocation' ) }
					onRequestClose={ close }
					style={ { maxWidth: '480px' } }
				>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'How should this block change?',
							'invocation'
						) }
						placeholder={ __(
							'e.g. Make the heading punchier and add a short subheading.',
							'invocation'
						) }
						value={ instruction }
						onChange={ setInstruction }
						rows={ 4 }
						disabled={ isLoading }
					/>

					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }

					<Flex
						justify="flex-end"
						gap={ 2 }
						style={ { marginTop: '16px' } }
					>
						<Button
							variant="tertiary"
							onClick={ close }
							disabled={ isLoading }
						>
							{ __( 'Cancel', 'invocation' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ onRefine }
							disabled={ isLoading || ! instruction.trim() }
						>
							{ isLoading ? (
								<Flex justify="center" gap={ 2 }>
									<Spinner />
									{ __( 'Refining…', 'invocation' ) }
								</Flex>
							) : (
								__( 'Refine', 'invocation' )
							) }
						</Button>
					</Flex>
				</Modal>
			) }
		</>
	);
}

const withInvocationRefine = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if ( ! props.isSelected ) {
			return <BlockEdit { ...props } />;
		}
		return (
			<>
				<BlockEdit { ...props } />
				<RefineControl clientId={ props.clientId } />
			</>
		);
	};
}, 'withInvocationRefine' );

addFilter(
	'editor.BlockEdit',
	'invocation/refine-toolbar',
	withInvocationRefine
);
