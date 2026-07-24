#!/usr/bin/env node
/**
 * Invocation Fleet Hub — a stdio MCP server that routes Invocation's tools to
 * any number of WordPress sites running the Invocation plugin.
 *
 * Instead of one MCP server entry per site, Claude Code talks to this hub,
 * which exposes each Invocation tool once with an extra required `site`
 * parameter and proxies the call (JSON-RPC over Streamable HTTP, Basic auth
 * with an Application Password) to the chosen site's
 * `/wp-json/invocation/mcp` endpoint.
 *
 * Site registry (JSON, kept outside any repo):
 *   default:  ~/.config/invocation/sites.json
 *   override: INVOCATION_SITES_FILE=/path/to/sites.json
 *
 *   {
 *     "blog": { "url": "https://blog.example.com", "user": "danilo", "appPassword": "xxxx xxxx xxxx xxxx" },
 *     "shop": { "url": "https://shop.example.com/?rest_route=/invocation/mcp", "user": "danilo", "appPassword": "..." }
 *   }
 *
 * `url` may be the site origin (endpoint path is appended) or a full MCP
 * endpoint URL (anything containing `/invocation/mcp` or `rest_route=`).
 *
 * Zero dependencies; requires Node 18+ (global fetch).
 */

'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const readline = require( 'readline' );

const PROTOCOL_VERSION = '2025-06-18';
const REQUEST_TIMEOUT_MS = 180_000; // generate-layout can take up to ~120s server-side.

/* -------------------------------------------------------------------------- */
/* Site registry                                                              */
/* -------------------------------------------------------------------------- */

function sitesFilePath() {
	return (
		process.env.INVOCATION_SITES_FILE ||
		path.join( os.homedir(), '.config', 'invocation', 'sites.json' )
	);
}

function loadSites() {
	const file = sitesFilePath();
	let raw;
	try {
		raw = fs.readFileSync( file, 'utf8' );
	} catch ( e ) {
		throw new Error(
			`Cannot read site registry at ${ file } (${ e.message }). ` +
				'Create it with one entry per site: { "mysite": { "url": "https://...", "user": "...", "appPassword": "..." } }'
		);
	}
	let sites;
	try {
		sites = JSON.parse( raw );
	} catch ( e ) {
		throw new Error(
			`Site registry ${ file } is not valid JSON: ${ e.message }`
		);
	}
	for ( const [ name, site ] of Object.entries( sites ) ) {
		if ( ! site || ! site.url || ! site.user || ! site.appPassword ) {
			throw new Error(
				`Site "${ name }" in ${ file } must have "url", "user" and "appPassword".`
			);
		}
	}
	if ( ! Object.keys( sites ).length ) {
		throw new Error( `Site registry ${ file } has no sites.` );
	}
	return sites;
}

function endpointUrl( site ) {
	const url = site.url.replace( /\/$/, '' );
	if ( url.includes( '/invocation/mcp' ) || url.includes( 'rest_route=' ) ) {
		return site.url;
	}
	return `${ url }/wp-json/invocation/mcp`;
}

function authHeader( site ) {
	return (
		'Basic ' +
		Buffer.from( `${ site.user }:${ site.appPassword }` ).toString(
			'base64'
		)
	);
}

/* -------------------------------------------------------------------------- */
/* Remote MCP client (Streamable HTTP, one lazy session per site)             */
/* -------------------------------------------------------------------------- */

const sessions = new Map(); // site name -> Mcp-Session-Id (if the transport issues one)
let requestCounter = 0;

async function rpcPost( siteName, site, body ) {
	const headers = {
		'Content-Type': 'application/json',
		Accept: 'application/json, text/event-stream',
		Authorization: authHeader( site ),
		'MCP-Protocol-Version': PROTOCOL_VERSION,
	};
	const sessionId = sessions.get( siteName );
	if ( sessionId ) {
		headers[ 'Mcp-Session-Id' ] = sessionId;
	}

	const res = await fetch( endpointUrl( site ), {
		method: 'POST',
		headers,
		body: JSON.stringify( body ),
		signal: AbortSignal.timeout( REQUEST_TIMEOUT_MS ),
	} );

	const newSession = res.headers.get( 'mcp-session-id' );
	if ( newSession ) {
		sessions.set( siteName, newSession );
	}

	const text = await res.text();
	if ( ! res.ok ) {
		throw new Error(
			`Site "${ siteName }" returned HTTP ${ res.status }: ${ text.slice(
				0,
				500
			) }`
		);
	}
	if ( ! text ) {
		return null; // e.g. accepted notification
	}

	// Streamable HTTP may answer as SSE; take the last `data:` payload.
	const contentType = res.headers.get( 'content-type' ) || '';
	if ( contentType.includes( 'text/event-stream' ) ) {
		const dataLines = text
			.split( /\r?\n/ )
			.filter( ( l ) => l.startsWith( 'data:' ) )
			.map( ( l ) => l.slice( 5 ).trim() )
			.filter( Boolean );
		if ( ! dataLines.length ) {
			throw new Error(
				`Site "${ siteName }" sent an empty event stream.`
			);
		}
		return JSON.parse( dataLines[ dataLines.length - 1 ] );
	}
	return JSON.parse( text );
}

async function remoteInitialize( siteName, site ) {
	const init = await rpcPost( siteName, site, {
		jsonrpc: '2.0',
		id: `hub-init-${ ++requestCounter }`,
		method: 'initialize',
		params: {
			protocolVersion: PROTOCOL_VERSION,
			capabilities: {},
			clientInfo: { name: 'invocation-fleet-hub', version: '0.1.0' },
		},
	} );
	if ( init && init.error ) {
		throw new Error(
			`Site "${ siteName }" failed to initialize: ${ init.error.message }`
		);
	}
	// Fire-and-forget per spec; some transports 4xx unknown notifications — ignore.
	try {
		await rpcPost( siteName, site, {
			jsonrpc: '2.0',
			method: 'notifications/initialized',
		} );
	} catch ( e ) {
		/* non-fatal */
	}
}

async function remoteRequest( siteName, site, method, params ) {
	const body = {
		jsonrpc: '2.0',
		id: `hub-${ ++requestCounter }`,
		method,
		params,
	};
	let response;
	try {
		response = await rpcPost( siteName, site, body );
	} catch ( e ) {
		response = null;
		// A session/initialization error is retried below; anything else rethrows.
		if ( ! /session|initializ|400|404/i.test( String( e.message ) ) ) {
			throw e;
		}
	}
	const needsInit =
		! response ||
		( response.error &&
			/session|initializ/i.test(
				String( response.error.message || '' )
			) );
	if ( needsInit ) {
		sessions.delete( siteName );
		await remoteInitialize( siteName, site );
		response = await rpcPost( siteName, site, body );
	}
	if ( response && response.error ) {
		throw new Error(
			response.error.message || JSON.stringify( response.error )
		);
	}
	return response ? response.result : null;
}

/* -------------------------------------------------------------------------- */
/* Tool list: fetched from the first reachable site, `site` param added       */
/* -------------------------------------------------------------------------- */

let toolCache = null;

const LIST_SITES_TOOL = {
	name: 'list-sites',
	description:
		'List the WordPress sites registered with this Invocation fleet hub. Every other tool requires one of these site names as its "site" argument.',
	inputSchema: {
		type: 'object',
		properties: {},
		additionalProperties: false,
	},
};

const CHECK_SITE_TOOL = {
	name: 'check-site',
	description:
		"Check that a registered site is reachable and its credentials work, reporting the WordPress-side server name and how many tools it offers. Use this to verify a newly added site — it works even before that site's own tools have been registered in this session.",
	inputSchema: {
		type: 'object',
		properties: {
			site: {
				type: 'string',
				description:
					'Name of the site to check, as it appears in the registry.',
			},
		},
		required: [ 'site' ],
		additionalProperties: false,
	},
};

/**
 * Tools that exist regardless of registry state.
 *
 * These must never depend on a reachable site: on a fresh install the registry
 * does not exist yet, and if `tools/list` failed outright the client would
 * register nothing at all — leaving no way to discover or diagnose that. They
 * are the bootstrap surface for connecting the first site.
 */
const ALWAYS_TOOLS = [ LIST_SITES_TOOL, CHECK_SITE_TOOL ];

function withSiteParam( tool, siteNames ) {
	const schema = tool.inputSchema || { type: 'object', properties: {} };
	const properties = {
		site: {
			type: 'string',
			enum: siteNames,
			description:
				'Which registered WordPress site to run this tool on (see list-sites).',
		},
		...( schema.properties || {} ),
	};
	const required = [
		'site',
		...( schema.required || [] ).filter( ( r ) => r !== 'site' ),
	];
	return { ...tool, inputSchema: { ...schema, properties, required } };
}

/**
 * Build the advertised tool list.
 *
 * Never throws: a failure here would make the client register no tools at all,
 * including the ones needed to fix the failure. When no site can be reached the
 * always-available tools are returned and nothing is cached, so the full list
 * appears as soon as a site works.
 */
async function buildToolList() {
	if ( toolCache ) {
		return toolCache;
	}

	let sites;
	try {
		sites = loadSites();
	} catch ( e ) {
		// No (or unreadable) registry — the normal state before the first
		// connect. list-sites reports the same error with guidance.
		return ALWAYS_TOOLS;
	}

	const siteNames = Object.keys( sites );
	for ( const name of siteNames ) {
		try {
			const result = await remoteRequest(
				name,
				sites[ name ],
				'tools/list',
				{}
			);
			const tools = ( result && result.tools ) || [];
			if ( tools.length ) {
				toolCache = [
					...ALWAYS_TOOLS,
					...tools.map( ( t ) => withSiteParam( t, siteNames ) ),
				];
				return toolCache;
			}
		} catch ( e ) {
			// Try the next site; check-site reports per-site failures in detail.
		}
	}

	return ALWAYS_TOOLS;
}

/* -------------------------------------------------------------------------- */
/* stdio JSON-RPC server                                                      */
/* -------------------------------------------------------------------------- */

function send( message ) {
	process.stdout.write( JSON.stringify( message ) + '\n' );
}

function sendResult( id, result ) {
	send( { jsonrpc: '2.0', id, result } );
}

function sendError( id, code, message ) {
	send( { jsonrpc: '2.0', id, error: { code, message } } );
}

function toolError( id, message ) {
	sendResult( id, {
		content: [ { type: 'text', text: message } ],
		isError: true,
	} );
}

/**
 * Rebuild the tool list and, if it grew, tell the client to re-read it.
 *
 * Connecting a site mid-session turns the bootstrap-only list into the full
 * one. Without this notification the new tools would not appear until the user
 * restarted or reconnected the server.
 */
async function refreshToolList() {
	const before = toolCache ? toolCache.length : ALWAYS_TOOLS.length;
	toolCache = null;
	const after = ( await buildToolList() ).length;
	if ( after !== before ) {
		send( { jsonrpc: '2.0', method: 'notifications/tools/list_changed' } );
	}
}

async function handleToolCall( id, params ) {
	const { name, arguments: args = {} } = params || {};
	let sites;
	try {
		sites = loadSites();
	} catch ( e ) {
		return toolError( id, e.message );
	}

	if ( name === 'list-sites' ) {
		const listing = Object.entries( sites ).map( ( [ n, s ] ) => ( {
			site: n,
			url: s.url,
			user: s.user,
		} ) );
		return sendResult( id, {
			content: [
				{ type: 'text', text: JSON.stringify( listing, null, 2 ) },
			],
		} );
	}

	if ( name === 'check-site' ) {
		const target = args.site;
		if ( ! target || ! sites[ target ] ) {
			return toolError(
				id,
				`Unknown or missing "site" argument${
					target ? ` "${ target }"` : ''
				}. Registered sites: ${ Object.keys( sites ).join( ', ' ) }.`
			);
		}
		try {
			const init = await remoteRequest(
				target,
				sites[ target ],
				'tools/list',
				{}
			);
			const tools = ( init && init.tools ) || [];
			// The site works, so the cached (possibly bootstrap-only) list is
			// stale. Refresh it and tell the client to re-read it.
			await refreshToolList();
			return sendResult( id, {
				content: [
					{
						type: 'text',
						text: `Connected to "${ target }" successfully. It offers ${ tools.length } tools.`,
					},
				],
			} );
		} catch ( e ) {
			return toolError(
				id,
				`Could not connect to "${ target }": ${ e.message }`
			);
		}
	}

	const { site: siteName, ...rest } = args;
	if ( ! siteName || ! sites[ siteName ] ) {
		return toolError(
			id,
			`Unknown or missing "site" argument${
				siteName ? ` "${ siteName }"` : ''
			}. Registered sites: ${ Object.keys( sites ).join( ', ' ) }.`
		);
	}

	try {
		const result = await remoteRequest(
			siteName,
			sites[ siteName ],
			'tools/call',
			{
				name,
				arguments: rest,
			}
		);
		return sendResult( id, result );
	} catch ( e ) {
		return toolError(
			id,
			`Tool "${ name }" failed on site "${ siteName }": ${ e.message }`
		);
	}
}

async function handleMessage( message ) {
	const { id, method, params } = message;

	// Notifications (no id) need no reply.
	if ( id === undefined || id === null ) {
		return;
	}

	switch ( method ) {
		case 'initialize':
			return sendResult( id, {
				protocolVersion:
					( params && params.protocolVersion ) || PROTOCOL_VERSION,
				capabilities: { tools: { listChanged: true } },
				serverInfo: { name: 'invocation-fleet-hub', version: '0.1.0' },
				instructions:
					'Routes Invocation tools to multiple WordPress sites. Call list-sites first; every other tool takes a required "site" argument naming the target site. If only list-sites and check-site are available, no site is registered yet — run /invocation:connect to add one.',
			} );

		case 'ping':
			return sendResult( id, {} );

		case 'tools/list':
			return sendResult( id, { tools: await buildToolList() } );

		case 'tools/call':
			return handleToolCall( id, params );

		default:
			return sendError( id, -32601, `Method not found: ${ method }` );
	}
}

const rl = readline.createInterface( {
	input: process.stdin,
	terminal: false,
} );

// Requests are handled asynchronously, so stdin can reach EOF while calls are
// still in flight. Exit only once they have drained, otherwise the client loses
// the responses to anything it sent just before closing the stream.
let inFlight = 0;
let stdinClosed = false;

function exitWhenDrained() {
	if ( stdinClosed && 0 === inFlight ) {
		process.exit( 0 );
	}
}

rl.on( 'line', ( line ) => {
	const trimmed = line.trim();
	if ( ! trimmed ) {
		return;
	}
	let message;
	try {
		message = JSON.parse( trimmed );
	} catch ( e ) {
		return sendError( null, -32700, 'Parse error' );
	}
	inFlight++;
	handleMessage( message )
		.catch( ( e ) => {
			if ( message.id !== undefined && message.id !== null ) {
				sendError( message.id, -32603, e.message );
			}
		} )
		.finally( () => {
			inFlight--;
			exitWhenDrained();
		} );
} );

rl.on( 'close', () => {
	stdinClosed = true;
	exitWhenDrained();
} );
