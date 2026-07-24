/**
 * Invocation editor sidebar.
 *
 * A PluginSidebar that calls the invocation/generate-layout ability over the
 * Abilities REST endpoint and inserts the returned blocks into the editor.
 * Supports generation scopes: a single section, a full page, or filling a
 * chosen section pattern with content. All intelligence lives server-side.
 */

import { registerPlugin } from '@wordpress/plugins';
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import {
	PanelBody,
	TextareaControl,
	SelectControl,
	ComboboxControl,
	Button,
	Notice,
	Spinner,
	Flex,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

import './refine';
import { INVOCATION_ICON } from './constants';

const SIDEBAR_NAME = 'invocation-sidebar';

const TONE_OPTIONS = [
	{ label: __( 'Professional', 'invocation' ), value: 'professional' },
	{ label: __( 'Casual', 'invocation' ), value: 'casual' },
	{ label: __( 'Creative', 'invocation' ), value: 'creative' },
	{ label: __( 'Minimal', 'invocation' ), value: 'minimal' },
	{ label: __( 'Bold', 'invocation' ), value: 'bold' },
];

const SCOPE_OPTIONS = [
	{ label: __( 'Section', 'invocation' ), value: 'section' },
	{ label: __( 'Full page', 'invocation' ), value: 'full-page' },
	{ label: __( 'Fill a pattern', 'invocation' ), value: 'fill-from-pattern' },
];

function InvocationSidebar() {
	const [ prompt, setPrompt ] = useState( '' );
	const [ tone, setTone ] = useState( 'professional' );
	const [ scope, setScope ] = useState( 'section' );
	const [ patternName, setPatternName ] = useState( '' );
	const [ patterns, setPatterns ] = useState( [] );
	const [ patternsLoaded, setPatternsLoaded ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const { insertBlocks } = useDispatch( blockEditorStore );
	const postTitle = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'title' ),
		[]
	);

	const loadPatterns = async () => {
		if ( patternsLoaded ) {
			return;
		}
		try {
			// Read-only abilities are exposed as GET; core reads `input` from query
			// params, so it must be passed as nested params (input[limit]=100), not
			// a JSON string.
			const result = await apiFetch( {
				path: addQueryArgs(
					'/wp-abilities/v1/abilities/invocation/list-patterns/run',
					{ input: { limit: 100 } }
				),
			} );
			setPatterns(
				( result?.items || [] ).map( ( p ) => ( {
					value: p.name,
					label: p.title || p.name,
				} ) )
			);
			setPatternsLoaded( true );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error?.message ||
					__( 'Could not load patterns.', 'invocation' ),
			} );
		}
	};

	const onScopeChange = ( value ) => {
		setScope( value );
		if ( 'fill-from-pattern' === value ) {
			loadPatterns();
		}
	};

	const isFill = 'fill-from-pattern' === scope;

	const onGenerate = async () => {
		const trimmed = prompt.trim();
		if ( ! trimmed ) {
			setNotice( {
				status: 'warning',
				message: __( 'Describe what you want first.', 'invocation' ),
			} );
			return;
		}
		if ( isFill && ! patternName ) {
			setNotice( {
				status: 'warning',
				message: __( 'Choose a pattern to fill.', 'invocation' ),
			} );
			return;
		}

		setIsLoading( true );
		setNotice( null );

		try {
			const input = {
				prompt: trimmed,
				tone,
				scope,
				...( postTitle ? { postTitle } : {} ),
				...( isFill ? { patternName } : {} ),
			};

			const result = await apiFetch( {
				path: '/wp-abilities/v1/abilities/invocation/generate-layout/run',
				method: 'POST',
				data: { input },
			} );

			if ( ! result?.blockMarkup ) {
				throw new Error(
					__( 'No layout was returned.', 'invocation' )
				);
			}

			const blocks = parse( result.blockMarkup );
			if ( ! blocks.length ) {
				throw new Error(
					__(
						'The generated layout could not be parsed.',
						'invocation'
					)
				);
			}

			insertBlocks( blocks );

			const warning = result.warnings?.length
				? ' ' +
				  __( 'Unrecognized blocks were skipped:', 'invocation' ) +
				  ' ' +
				  result.warnings.join( ', ' )
				: '';

			setNotice( {
				status: 'success',
				message:
					( result.summary ||
						__( 'Layout inserted.', 'invocation' ) ) + warning,
			} );
			setPrompt( '' );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error?.message ||
					__(
						'Generation failed. Check your AI Connector.',
						'invocation'
					),
			} );
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<>
			<PluginSidebarMoreMenuItem
				target={ SIDEBAR_NAME }
				icon={ INVOCATION_ICON }
			>
				{ __( 'Invocation', 'invocation' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name={ SIDEBAR_NAME }
				icon={ INVOCATION_ICON }
				title={ __( 'Invocation', 'invocation' ) }
			>
				<PanelBody>
					<p>
						{ __(
							'Describe what you want and Invocation will build it with your theme’s blocks, patterns, and styles.',
							'invocation'
						) }
					</p>

					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Scope', 'invocation' ) }
						value={ scope }
						options={ SCOPE_OPTIONS }
						onChange={ onScopeChange }
						disabled={ isLoading }
					/>

					{ isFill && (
						<ComboboxControl
							__nextHasNoMarginBottom
							label={ __( 'Pattern to fill', 'invocation' ) }
							value={ patternName }
							options={ patterns }
							onChange={ ( value ) =>
								setPatternName( value || '' )
							}
							placeholder={ __(
								'Search patterns…',
								'invocation'
							) }
						/>
					) }

					<TextareaControl
						__nextHasNoMarginBottom
						label={
							isFill
								? __(
										'Content to put into the pattern',
										'invocation'
								  )
								: __( 'What should we build?', 'invocation' )
						}
						placeholder={
							isFill
								? __(
										'e.g. Promote a free 14-day trial of our app.',
										'invocation'
								  )
								: __(
										'e.g. A hero with a heading and CTA button, then a three-column features section.',
										'invocation'
								  )
						}
						value={ prompt }
						onChange={ setPrompt }
						rows={ 5 }
						disabled={ isLoading }
					/>

					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Tone', 'invocation' ) }
						value={ tone }
						options={ TONE_OPTIONS }
						onChange={ setTone }
						disabled={ isLoading }
					/>

					<Button
						variant="primary"
						onClick={ onGenerate }
						disabled={ isLoading || ( isFill && ! patternName ) }
						style={ { marginTop: '12px' } }
					>
						{ isLoading ? (
							<Flex justify="center" gap={ 2 }>
								<Spinner />
								{ __( 'Generating…', 'invocation' ) }
							</Flex>
						) : (
							__( 'Generate', 'invocation' )
						) }
					</Button>

					{ notice && (
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
							politeness="assertive"
						>
							{ notice.message }
						</Notice>
					) }
				</PanelBody>
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'invocation', { render: InvocationSidebar } );
