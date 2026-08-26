/**
 * Invocation admin app — the Site Brief editor.
 *
 * Loads/saves the brief via the REST settings endpoint and can generate it from
 * the site's own content via the gather-site-context ability.
 */

import { createRoot, useState, useEffect, useRef } from '@wordpress/element';
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
	SearchControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

import './admin.css';

// Abilities marked readonly are exposed as GET, not POST — core reads
// `input` from query params there, so it must be sent as nested params
// (input[query]=...), not a JSON body. Every write ability the chat can
// propose (create/update/duplicate-page, refine-block, generate-layout,
// upload-media, save-pattern, ...) is POST as usual; this is just the set
// the Chat tab itself calls read-only abilities for.
const INVOCATION_READONLY_ABILITIES = [
	'invocation/chat',
	'invocation/search-pages',
	'invocation/get-page',
	'invocation/search-media',
	'invocation/get-theme-context',
	'invocation/list-blocks',
	'invocation/list-templates',
	'invocation/list-patterns',
	'invocation/search-internal-links',
	'invocation/gather-site-context',
];

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

/**
 * Read a File as a base64 data: URL.
 *
 * @param {File} file The file to read.
 * @return {Promise<string>} Resolves with the data: URL.
 */
function readFileAsDataUrl( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () => resolve( reader.result );
		reader.onerror = () => reject( reader.error );
		reader.readAsDataURL( file );
	} );
}

/**
 * Run one ability over the Abilities REST endpoint.
 *
 * @param {string} ability Ability id, e.g. "invocation/chat".
 * @param {Object} input   Ability input.
 * @return {Promise<Object>} The ability's output.
 */
function runAbility( ability, input ) {
	if ( INVOCATION_READONLY_ABILITIES.includes( ability ) ) {
		return apiFetch( {
			path: addQueryArgs( `/wp-abilities/v1/abilities/${ ability }/run`, {
				input,
			} ),
		} );
	}
	return apiFetch( {
		path: `/wp-abilities/v1/abilities/${ ability }/run`,
		method: 'POST',
		data: { input },
	} );
}

/**
 * Invocation admin app — a chat assistant that proposes ability calls and
 * only runs them once the user approves, so nothing is created, changed, or
 * published without an explicit click.
 */
function ChatApp() {
	const [ messages, setMessages ] = useState( [] );
	const [ input, setInput ] = useState( '' );
	const [ attachment, setAttachment ] = useState( null );
	const [ pendingAction, setPendingAction ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ isPagePickerOpen, setIsPagePickerOpen ] = useState( false );
	const [ pageQuery, setPageQuery ] = useState( '' );
	const [ pageResults, setPageResults ] = useState( [] );
	const [ isPageSearching, setIsPageSearching ] = useState( false );
	const [ selectedPage, setSelectedPage ] = useState( null );
	const fileInputRef = useRef( null );
	const logRef = useRef( null );
	const textareaRef = useRef( null );

	useEffect( () => {
		if ( logRef.current ) {
			logRef.current.scrollTop = logRef.current.scrollHeight;
		}
	}, [ messages, pendingAction ] );

	const searchPages = async ( query ) => {
		setIsPageSearching( true );
		try {
			const result = await runAbility( 'invocation/search-pages', {
				query,
				limit: 20,
			} );
			setPageResults( result.items || [] );
		} catch ( e ) {
			setError(
				e.message || __( 'Could not load pages.', 'invocation' )
			);
		} finally {
			setIsPageSearching( false );
		}
	};

	const openPagePicker = () => {
		setIsPagePickerOpen( ( wasOpen ) => {
			const nowOpen = ! wasOpen;
			if ( nowOpen ) {
				searchPages( pageQuery );
			}
			return nowOpen;
		} );
	};

	const handlePickPage = ( page ) => {
		// Kept out of the message text on purpose: this becomes the `pageId`
		// sent alongside every turn, not something the user has to type or
		// the model has to parse back out of prose.
		setSelectedPage( page );
		setIsPagePickerOpen( false );
		textareaRef.current?.focus();
	};

	const toHistory = ( list ) =>
		list
			.filter( ( m ) => m.role !== 'attachment' )
			.map( ( m ) => ( { role: m.role, content: m.content } ) );

	const ask = async ( messageText, priorMessages ) => {
		setIsBusy( true );
		setError( null );
		try {
			const result = await runAbility( 'invocation/chat', {
				message: messageText,
				history: toHistory( priorMessages ),
				...( selectedPage ? { pageId: selectedPage.id } : {} ),
			} );
			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: result.reply },
			] );
			setPendingAction( result.action || null );
		} catch ( e ) {
			setError(
				e.message || __( 'Something went wrong.', 'invocation' )
			);
		} finally {
			setIsBusy( false );
		}
	};

	const handleSend = async () => {
		const trimmed = input.trim();
		if ( ! trimmed && ! attachment ) {
			return;
		}

		let userMessage = trimmed;
		let nextMessages = messages;

		if ( attachment ) {
			setIsBusy( true );
			setError( null );
			try {
				const dataUrl = await readFileAsDataUrl( attachment.file );
				const uploaded = await runAbility( 'invocation/upload-media', {
					data: dataUrl,
					filename: attachment.file.name,
				} );
				nextMessages = [
					...messages,
					{
						role: 'tool',
						content: `Uploaded attachment: ATTACHMENT_ID=${ uploaded.id } ATTACHMENT_URL=${ uploaded.url }`,
						attachmentUrl: uploaded.url,
					},
				];
				userMessage =
					( trimmed || __( 'I attached an image.', 'invocation' ) ) +
					`\n(ATTACHMENT_ID: ${ uploaded.id }, ATTACHMENT_URL: ${ uploaded.url })`;
			} catch ( e ) {
				setIsBusy( false );
				setError(
					e.message ||
						__( 'The image could not be uploaded.', 'invocation' )
				);
				return;
			}
		}

		nextMessages = [
			...nextMessages,
			{
				role: 'user',
				content: trimmed || __( '(sent an image)', 'invocation' ),
			},
		];
		setMessages( nextMessages );
		setInput( '' );
		setAttachment( null );
		if ( fileInputRef.current ) {
			fileInputRef.current.value = '';
		}

		await ask( userMessage, nextMessages );
	};

	const handleRunAction = async () => {
		if ( ! pendingAction ) {
			return;
		}
		setIsBusy( true );
		setError( null );
		const action = pendingAction;
		setPendingAction( null );
		try {
			const result = await runAbility( action.ability, action.input );
			const toolMessage = {
				role: 'tool',
				content: `${ action.ability } -> ${ JSON.stringify( result ) }`,
			};
			const nextMessages = [ ...messages, toolMessage ];
			setMessages( nextMessages );
			await ask(
				`TOOL_RESULT for ${ action.ability }: ${ JSON.stringify(
					result
				) }`,
				nextMessages
			);
		} catch ( e ) {
			setError( e.message || __( 'The action failed.', 'invocation' ) );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<div className="invocation-chat">
			<p className="invocation-intro">
				{ __(
					'Ask for a page, an edit, or an image. Nothing is created, changed, or published until you approve the proposed action below.',
					'invocation'
				) }
			</p>

			{ error && (
				<Notice
					status="error"
					isDismissible
					onDismiss={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			<div className="invocation-chat__log" ref={ logRef }>
				{ messages.map( ( m, i ) => (
					<div
						key={ i }
						className={ `invocation-chat__message invocation-chat__message--${ m.role }` }
					>
						{ m.content }
						{ m.attachmentUrl && (
							<img
								className="invocation-chat__attachment"
								src={ m.attachmentUrl }
								alt=""
							/>
						) }
					</div>
				) ) }

				{ pendingAction && (
					<div className="invocation-chat__action">
						<strong>
							{ __( 'Proposed action:', 'invocation' ) }{ ' ' }
							{ pendingAction.ability }
						</strong>
						<pre>
							{ JSON.stringify( pendingAction.input, null, 2 ) }
						</pre>
						<Flex gap={ 2 }>
							<Button
								variant="primary"
								isBusy={ isBusy }
								disabled={ isBusy }
								onClick={ handleRunAction }
							>
								{ __( 'Approve & run', 'invocation' ) }
							</Button>
							<Button
								variant="tertiary"
								disabled={ isBusy }
								onClick={ () => setPendingAction( null ) }
							>
								{ __( 'Dismiss', 'invocation' ) }
							</Button>
						</Flex>
					</div>
				) }

				{ isBusy && ! pendingAction && <Spinner /> }
			</div>

			{ selectedPage && (
				<div className="invocation-chat__preview">
					<span>
						{ __( 'Editing:', 'invocation' ) }{ ' ' }
						{ selectedPage.title }{ ' ' }
						<span className="invocation-chat__page-status">
							{ selectedPage.status }
						</span>
					</span>
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => setSelectedPage( null ) }
					>
						{ __( 'Clear', 'invocation' ) }
					</Button>
				</div>
			) }

			{ attachment && (
				<div className="invocation-chat__preview">
					<img src={ attachment.previewUrl } alt="" />
					<span>{ attachment.file.name }</span>
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => setAttachment( null ) }
					>
						{ __( 'Remove', 'invocation' ) }
					</Button>
				</div>
			) }

			{ isPagePickerOpen && (
				<div className="invocation-chat__page-picker">
					<SearchControl
						__nextHasNoMarginBottom
						label={ __( 'Search pages', 'invocation' ) }
						placeholder={ __(
							'Search by page title…',
							'invocation'
						) }
						value={ pageQuery }
						onChange={ ( value ) => {
							setPageQuery( value );
							searchPages( value );
						} }
					/>
					{ isPageSearching ? (
						<Spinner />
					) : (
						<ul className="invocation-chat__page-list">
							{ pageResults.length === 0 && (
								<li className="invocation-chat__page-empty">
									{ __( 'No pages found.', 'invocation' ) }
								</li>
							) }
							{ pageResults.map( ( page ) => (
								<li key={ page.id }>
									<button
										type="button"
										className="invocation-chat__page-item"
										onClick={ () => handlePickPage( page ) }
									>
										<span className="invocation-chat__page-title">
											{ page.title }
										</span>
										<span className="invocation-chat__page-status">
											{ page.status }
										</span>
									</button>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }

			<div className="invocation-chat__composer">
				<input
					ref={ fileInputRef }
					type="file"
					accept="image/png,image/jpeg,image/gif,image/webp"
					style={ { display: 'none' } }
					onChange={ ( e ) => {
						const file = e.target.files && e.target.files[ 0 ];
						if ( file ) {
							setAttachment( {
								file,
								previewUrl: URL.createObjectURL( file ),
							} );
						}
					} }
				/>
				<Button
					variant="secondary"
					disabled={ isBusy }
					isPressed={ isPagePickerOpen }
					onClick={ openPagePicker }
				>
					{ __( 'Browse pages', 'invocation' ) }
				</Button>
				<Button
					variant="secondary"
					disabled={ isBusy }
					onClick={ () => fileInputRef.current?.click() }
				>
					{ __( 'Attach image', 'invocation' ) }
				</Button>
				<TextareaControl
					ref={ textareaRef }
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Message', 'invocation' ) }
					value={ input }
					onChange={ setInput }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' && ! e.shiftKey ) {
							e.preventDefault();
							handleSend();
						}
					} }
					placeholder={ __(
						'e.g. Duplicate the About page as a draft, change the header to…',
						'invocation'
					) }
				/>
				<Button
					variant="primary"
					disabled={ isBusy || ( ! input.trim() && ! attachment ) }
					isBusy={ isBusy }
					onClick={ handleSend }
				>
					{ __( 'Send', 'invocation' ) }
				</Button>
			</div>
		</div>
	);
}

const root = document.getElementById( 'invocation-admin-root' );
if ( root ) {
	createRoot( root ).render( <SiteBriefApp /> );
}

const chatRoot = document.getElementById( 'invocation-chat-root' );
if ( chatRoot ) {
	createRoot( chatRoot ).render( <ChatApp /> );
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
