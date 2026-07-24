/**
 * Invocation admin app — the Site Brief editor.
 *
 * Loads/saves the brief via the REST settings endpoint and can generate it from
 * the site's own content via the gather-site-context ability.
 */

import { createRoot, useState, useEffect } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	TextareaControl,
	FormTokenField,
	Button,
	Notice,
	Spinner,
	Flex,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import './admin.css';

const OPTION = 'invocation_site_brief';

const DEFAULT_BRIEF = {
	purpose: '',
	audience: '',
	toneVoice: '',
	offerings: [],
	keyTerms: [],
	avoid: [],
	generatedAt: '',
};

function SiteBriefApp() {
	const [ brief, setBrief ] = useState( DEFAULT_BRIEF );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/wp/v2/settings' } )
			.then( ( settings ) => {
				setBrief( {
					...DEFAULT_BRIEF,
					...( settings[ OPTION ] || {} ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			)
			.finally( () => setIsLoading( false ) );
	}, [] );

	const update = ( key, value ) =>
		setBrief( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const save = async () => {
		setIsSaving( true );
		setNotice( null );
		try {
			await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { [ OPTION ]: brief },
			} );
			setNotice( {
				status: 'success',
				message: __( 'Site Brief saved.', 'invocation' ),
			} );
		} catch ( e ) {
			setNotice( { status: 'error', message: e.message } );
		} finally {
			setIsSaving( false );
		}
	};

	const generate = async () => {
		setIsGenerating( true );
		setNotice( null );
		try {
			const result = await apiFetch( {
				path: '/wp-abilities/v1/abilities/invocation/gather-site-context/run',
				method: 'POST',
				data: { input: {} },
			} );
			setBrief( { ...DEFAULT_BRIEF, ...result } );
			setNotice( {
				status: 'success',
				message: __(
					'Generated from your site and saved. Review and edit as needed.',
					'invocation'
				),
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message:
					e.message ||
					__(
						'Generation failed. Check your AI Connector.',
						'invocation'
					),
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	if ( isLoading ) {
		return (
			<Flex
				justify="flex-start"
				gap={ 2 }
				style={ { padding: '24px 0' } }
			>
				<Spinner />
				{ __( 'Loading…', 'invocation' ) }
			</Flex>
		);
	}

	return (
		<div className="invocation-brief">
			<div className="invocation-brief__header">
				<p className="invocation-intro">
					{ __(
						'The Site Brief grounds every Invocation generation in your site’s purpose, audience and voice. Generate it from your content, then edit anything.',
						'invocation'
					) }
				</p>
				<Button
					variant="secondary"
					onClick={ generate }
					disabled={ isGenerating || isSaving }
				>
					{ isGenerating ? (
						<Flex gap={ 2 } justify="center">
							<Spinner />
							{ __( 'Analyzing…', 'invocation' ) }
						</Flex>
					) : (
						__( 'Generate from my site', 'invocation' )
					) }
				</Button>
			</div>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h3 style={ { margin: 0 } }>
						{ __( 'Brand', 'invocation' ) }
					</h3>
				</CardHeader>
				<CardBody>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Purpose', 'invocation' ) }
						help={ __( 'What this site is for.', 'invocation' ) }
						value={ brief.purpose }
						onChange={ ( v ) => update( 'purpose', v ) }
						rows={ 2 }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Audience', 'invocation' ) }
						value={ brief.audience }
						onChange={ ( v ) => update( 'audience', v ) }
						rows={ 2 }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Voice & tone', 'invocation' ) }
						value={ brief.toneVoice }
						onChange={ ( v ) => update( 'toneVoice', v ) }
						rows={ 2 }
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h3 style={ { margin: 0 } }>
						{ __( 'Content guidance', 'invocation' ) }
					</h3>
				</CardHeader>
				<CardBody>
					<FormTokenField
						__nextHasNoMarginBottom
						label={ __(
							'Offerings (products, services, topics)',
							'invocation'
						) }
						value={ brief.offerings }
						onChange={ ( v ) => update( 'offerings', v ) }
					/>
					<FormTokenField
						__nextHasNoMarginBottom
						label={ __( 'Preferred terms', 'invocation' ) }
						value={ brief.keyTerms }
						onChange={ ( v ) => update( 'keyTerms', v ) }
					/>
					<FormTokenField
						__nextHasNoMarginBottom
						label={ __( 'Avoid', 'invocation' ) }
						value={ brief.avoid }
						onChange={ ( v ) => update( 'avoid', v ) }
					/>
				</CardBody>
			</Card>

			<Flex justify="flex-start" className="invocation-brief__footer">
				<Button
					variant="primary"
					onClick={ save }
					disabled={ isSaving || isGenerating }
				>
					{ isSaving
						? __( 'Saving…', 'invocation' )
						: __( 'Save changes', 'invocation' ) }
				</Button>
			</Flex>

			{ brief.generatedAt && (
				<p className="invocation-note">
					{ __( 'Last generated:', 'invocation' ) }{ ' ' }
					{ brief.generatedAt }
				</p>
			) }
		</div>
	);
}

const root = document.getElementById( 'invocation-admin-root' );
if ( root ) {
	createRoot( root ).render( <SiteBriefApp /> );
}

// Select-all on click for the readonly snippet field in the Connect panel.
document
	.querySelectorAll( '.invocation-connect-snippet' )
	.forEach( ( field ) => {
		field.addEventListener( 'click', () => field.select() );
	} );

// Wire the "Copy snippet" buttons in the server-rendered Connect panel.
document.querySelectorAll( '.invocation-copy' ).forEach( ( button ) => {
	button.addEventListener( 'click', async () => {
		const target = document.querySelector(
			button.getAttribute( 'data-copy-target' )
		);
		if ( ! target ) {
			return;
		}
		const text = target.value ?? target.textContent ?? '';
		try {
			await navigator.clipboard.writeText( text );
		} catch ( e ) {
			// Clipboard API unavailable (e.g. non-secure context): fall back to
			// selecting the field so the user can copy manually.
			if ( typeof target.select === 'function' ) {
				target.select();
			}
			return;
		}
		const original = button.textContent;
		button.textContent = __( 'Copied!', 'invocation' );
		setTimeout( () => {
			button.textContent = original;
		}, 1500 );
	} );
} );
