#!/usr/bin/env node
/**
 * Generate languages/blt-toolkit.pot from the plugin's PHP and JS sources.
 *
 * A dependency-free stand-in for `wp i18n make-pot`, so the template can be
 * regenerated (and checked in CI) without WP-CLI or PHP installed. Handles
 * __, _e, esc_html__, esc_html_e, esc_attr__, esc_attr_e, _x, _ex,
 * esc_attr_x, esc_html_x, _n, _nx, _n_noop, _nx_noop (also as wp.i18n.*
 * in JS) with the blt-toolkit text domain, the translatable plugin header
 * fields, and "translators:" comments (block or line style) placed before
 * a call in the same statement, as WP-CLI reads them.
 *
 * Scans the main file, uninstall.php, includes/, admin/, templates/ and
 * assets/js/: every PHP and JS file in the tree except the vendored and
 * dev-only paths listed in SKIP_DIRS and SKIP_PATHS. Output is sorted by
 * file path and line, so it is byte-identical on every machine.
 *
 * Usage: node bin/make-pot.js
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const DOMAIN = 'blt-toolkit';
const MAIN_FILE = 'blt-toolkit.php';
const OUT = path.join( ROOT, 'languages', 'blt-toolkit.pot' );

const SKIP_DIRS = new Set( [ '.git', '.github', 'node_modules', 'vendor', 'build', 'tests', 'tasks', 'languages' ] );
const SKIP_PATHS = [ 'includes/lib/', 'includes/blt-family/', 'blt-family-smoke.php', 'bin/' ];

// function name -> argument roles (positions are 0-based).
const SIGNATURES = {
	__: { text: 0, domain: 1 },
	_e: { text: 0, domain: 1 },
	esc_html__: { text: 0, domain: 1 },
	esc_html_e: { text: 0, domain: 1 },
	esc_attr__: { text: 0, domain: 1 },
	esc_attr_e: { text: 0, domain: 1 },
	_x: { text: 0, context: 1, domain: 2 },
	_ex: { text: 0, context: 1, domain: 2 },
	esc_attr_x: { text: 0, context: 1, domain: 2 },
	esc_html_x: { text: 0, context: 1, domain: 2 },
	_n: { text: 0, plural: 1, domain: 3 },
	_nx: { text: 0, plural: 1, context: 3, domain: 4 },
	_n_noop: { text: 0, plural: 1, domain: 2 },
	_nx_noop: { text: 0, plural: 1, context: 2, domain: 3 },
};

// Plugin header fields WordPress translates with the plugin's text domain
// (_get_plugin_data_markup_translate()), in the order WP-CLI writes them.
const HEADER_FIELDS = [ 'Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI' ];

const FUNCTIONS = Object.keys( SIGNATURES ).join( '|' );
// Not preceded by an identifier character, a dot (other than wp.i18n.) or
// "->", so methods and properties that share a name are left alone.
const callRegex = new RegExp( `(?<![A-Za-z0-9_$.>])(?:wp\\.i18n\\.)?(${ FUNCTIONS })\\s*\\(`, 'g' );
const anyCallRegex = new RegExp( `(?<![A-Za-z0-9_$.>])(?:wp\\.i18n\\.)?(?:${ FUNCTIONS })\\s*\\(` );

function walk( dir, files ) {
	const entries = fs.readdirSync( dir, { withFileTypes: true } );
	entries.sort( ( a, b ) => compareStrings( a.name, b.name ) );
	for ( const entry of entries ) {
		if ( entry.isDirectory() ) {
			if ( SKIP_DIRS.has( entry.name ) ) {
				continue;
			}
			walk( path.join( dir, entry.name ), files );
			continue;
		}
		const full = path.join( dir, entry.name );
		const rel = path.relative( ROOT, full ).split( path.sep ).join( '/' );
		if ( SKIP_PATHS.some( ( p ) => rel.startsWith( p ) ) ) {
			continue;
		}
		if ( /\.(php|js)$/.test( entry.name ) ) {
			files.push( { full, rel } );
		}
	}
	return files;
}

// Locale-independent comparison, so CI and every laptop sort alike.
function compareStrings( a, b ) {
	if ( a === b ) {
		return 0;
	}
	return a < b ? -1 : 1;
}

/**
 * Parse the argument list starting right after the opening parenthesis.
 * Returns { args: [ { value, isString } ], end } or null when the list never
 * closes. An argument counts as a string only when it is one plain literal
 * (no concatenation, variables or calls). Comments between arguments are
 * skipped.
 */
function parseArgs( src, start ) {
	const args = [];
	let i = start;
	let depth = 0;
	let current = { text: '', isString: false, literal: '', parts: 0 };

	const push = () => {
		args.push( { value: current.literal, isString: current.isString && current.parts === 1 && current.text.trim() === '' } );
		current = { text: '', isString: false, literal: '', parts: 0 };
	};

	while ( i < src.length ) {
		const ch = src[ i ];

		if ( ch === '/' && src[ i + 1 ] === '*' ) {
			const close = src.indexOf( '*/', i + 2 );
			i = close === -1 ? src.length : close + 2;
			continue;
		}
		if ( ( ch === '/' && src[ i + 1 ] === '/' ) || ch === '#' ) {
			const eol = src.indexOf( '\n', i );
			i = eol === -1 ? src.length : eol + 1;
			continue;
		}

		if ( ch === "'" || ch === '"' ) {
			const quote = ch;
			let j = i + 1;
			let out = '';
			while ( j < src.length && src[ j ] !== quote ) {
				if ( src[ j ] === '\\' && j + 1 < src.length ) {
					const next = src[ j + 1 ];
					if ( quote === "'" ) {
						out += next === "'" || next === '\\' ? next : '\\' + next;
					} else {
						const map = { n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\', $: '$' };
						out += Object.prototype.hasOwnProperty.call( map, next ) ? map[ next ] : '\\' + next;
					}
					j += 2;
					continue;
				}
				out += src[ j ];
				j++;
			}
			current.literal += out;
			current.isString = true;
			current.parts++;
			i = j + 1;
			continue;
		}

		if ( ch === '(' || ch === '[' || ch === '{' ) {
			depth++;
			current.text += ch;
		} else if ( ch === ')' || ch === ']' || ch === '}' ) {
			if ( depth === 0 ) {
				push();
				return { args, end: i };
			}
			depth--;
			current.text += ch;
		} else if ( ch === ',' && depth === 0 ) {
			push();
		} else if ( ! /\s/.test( ch ) ) {
			current.text += ch;
		}
		i++;
	}
	return null;
}

/**
 * The translators comment that belongs to the call at callIndex: the
 * nearest "translators:" comment before it, block or line style, with no
 * statement or block boundary and no other gettext call in between. That
 * covers both
 *
 *     /* translators: %s: user name *\/
 *     printf( esc_html__( 'Switched to %s.', 'blt-toolkit' ), $name );
 *
 * and the comment placed inside sprintf( ... ) right before the call.
 */
function translatorsComment( src, callIndex ) {
	const before = src.slice( Math.max( 0, callIndex - 600 ), callIndex );
	// A block comment body may not contain "*/", so an earlier comment can
	// never swallow the code between it and this call.
	const commentRegex = /\/\*[\s*]*translators:\s*((?:(?!\*\/)[\s\S])*?)\*\/|\/\/\s*translators:\s*([^\n]*)/gi;
	let match;
	let last = null;
	while ( ( match = commentRegex.exec( before ) ) !== null ) {
		last = match;
	}
	if ( ! last ) {
		return '';
	}
	const gap = before.slice( last.index + last[ 0 ].length );
	if ( /[;{}]|\?>/.test( gap ) || anyCallRegex.test( gap ) ) {
		return '';
	}
	const body = last[ 1 ] !== undefined ? last[ 1 ] : last[ 2 ];
	// Drop the leading " * " of continuation lines in a multi-line comment.
	return body.replace( /\n[ \t]*\*[ \t]?/g, '\n' ).replace( /\s+/g, ' ' ).trim();
}

function lineOf( src, index ) {
	let line = 1;
	for ( let i = 0; i < index; i++ ) {
		if ( src.charCodeAt( i ) === 10 ) {
			line++;
		}
	}
	return line;
}

const entries = new Map();

function addEntry( { text, plural, context, comment, ref } ) {
	const key = `${ context || '' }\u0004${ text }`;
	if ( ! entries.has( key ) ) {
		entries.set( key, { text, plural: plural || '', context: context || '', comments: new Set(), refs: [] } );
	}
	const entry = entries.get( key );
	if ( plural && ! entry.plural ) {
		entry.plural = plural;
	}
	if ( comment ) {
		entry.comments.add( comment );
	}
	if ( ! entry.refs.some( ( r ) => r.file === ref.file && r.line === ref.line ) ) {
		entry.refs.push( ref );
	}
}

// Plugin header strings come first, as in a WP-CLI template.
const mainPath = path.join( ROOT, MAIN_FILE );
let version = '0.0.0';
const headerEntries = [];
if ( fs.existsSync( mainPath ) ) {
	const mainSrc = fs.readFileSync( mainPath, 'utf8' );
	const docblock = ( mainSrc.match( /\/\*\*[\s\S]*?\*\// ) || [ '' ] )[ 0 ];
	const field = ( name ) => {
		const m = docblock.match( new RegExp( `^[\\s*]*${ name }:[ \\t]*(.+)$`, 'mi' ) );
		return m ? m[ 1 ].trim() : '';
	};
	version = field( 'Version' ) || version;
	for ( const name of HEADER_FIELDS ) {
		const value = field( name );
		if ( value ) {
			headerEntries.push( { name, value } );
		}
	}
} else {
	console.warn( `${ MAIN_FILE } not found: writing version ${ version } and no header strings.` );
}

for ( const file of walk( ROOT, [] ) ) {
	const src = fs.readFileSync( file.full, 'utf8' );
	let match;
	callRegex.lastIndex = 0;
	while ( ( match = callRegex.exec( src ) ) !== null ) {
		const fn = match[ 1 ];
		const sig = SIGNATURES[ fn ];
		const parsed = parseArgs( src, match.index + match[ 0 ].length );
		if ( ! parsed ) {
			continue;
		}
		const { args } = parsed;
		const domainArg = args[ sig.domain ];
		if ( ! domainArg || ! domainArg.isString || domainArg.value !== DOMAIN ) {
			continue;
		}
		const textArg = args[ sig.text ];
		if ( ! textArg || ! textArg.isString || textArg.value === '' ) {
			continue;
		}
		const pluralArg = sig.plural !== undefined ? args[ sig.plural ] : null;
		const contextArg = sig.context !== undefined ? args[ sig.context ] : null;
		if ( ( pluralArg && ! pluralArg.isString ) || ( contextArg && ! contextArg.isString ) ) {
			continue;
		}

		addEntry( {
			text: textArg.value,
			plural: pluralArg ? pluralArg.value : '',
			context: contextArg ? contextArg.value : '',
			comment: translatorsComment( src, match.index ),
			ref: { file: file.rel, line: lineOf( src, match.index ) },
		} );
	}
}

function po( str ) {
	const escaped = String( str )
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\t/g, '\\t' );
	if ( ! escaped.includes( '\n' ) ) {
		return `"${ escaped }"`;
	}
	const lines = escaped.split( '\n' );
	const last = lines.pop();
	let out = '""\n';
	for ( const line of lines ) {
		out += `"${ line }\\n"\n`;
	}
	if ( last !== '' ) {
		out += `"${ last }"`;
	} else {
		out = out.trimEnd();
	}
	return out;
}

function compareRefs( a, b ) {
	return compareStrings( a.file, b.file ) || a.line - b.line;
}

const now = new Date().toISOString().replace( /\.\d{3}Z$/, '+0000' ).replace( 'T', ' ' ).replace( /:\d{2}\+0000$/, '+0000' );

let out = `# Copyright (C) ${ new Date().getFullYear() } S-FX.com
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: BLT Toolkit ${ version }\\n"
"Report-Msgid-Bugs-To: https://github.com/S-FX-com/BLT-Toolkit/issues\\n"
"POT-Creation-Date: ${ now }\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"X-Generator: bin/make-pot.js\\n"
"X-Domain: ${ DOMAIN }\\n"

`;

function emit( entry, extraComments, extraRefs ) {
	let block = '';
	for ( const comment of extraComments ) {
		block += `#. ${ comment }\n`;
	}
	for ( const comment of entry.comments ) {
		block += `#. translators: ${ comment }\n`;
	}
	for ( const ref of extraRefs ) {
		block += `#: ${ ref }\n`;
	}
	for ( const ref of entry.refs ) {
		block += `#: ${ ref.file }:${ ref.line }\n`;
	}
	if ( entry.context ) {
		block += `msgctxt ${ po( entry.context ) }\n`;
	}
	block += `msgid ${ po( entry.text ) }\n`;
	if ( entry.plural ) {
		block += `msgid_plural ${ po( entry.plural ) }\n`;
		block += 'msgstr[0] ""\nmsgstr[1] ""\n\n';
	} else {
		block += 'msgstr ""\n\n';
	}
	return block;
}

const withSortedRefs = ( entry ) => ( { ...entry, refs: entry.refs.slice().sort( compareRefs ) } );

// Header strings first. A msgid may appear only once in a template, so a
// header value that the code also translates (no context) is merged into
// one entry carrying both the header note and the code references.
const headerByValue = new Map();
for ( const { name, value } of headerEntries ) {
	if ( ! headerByValue.has( value ) ) {
		headerByValue.set( value, [] );
	}
	headerByValue.get( value ).push( `${ name } of the plugin` );
}
for ( const [ value, notes ] of headerByValue ) {
	const key = `\u0004${ value }`;
	const code = entries.get( key );
	entries.delete( key );
	const entry = code ? withSortedRefs( code ) : { text: value, plural: '', context: '', comments: new Set(), refs: [] };
	out += emit( entry, notes, [ MAIN_FILE ] );
}

const sorted = [ ...entries.values() ]
	.map( withSortedRefs )
	.sort( ( a, b ) => compareRefs( a.refs[ 0 ], b.refs[ 0 ] ) || compareStrings( a.context, b.context ) || compareStrings( a.text, b.text ) );

for ( const entry of sorted ) {
	out += emit( entry, [], [] );
}

fs.mkdirSync( path.dirname( OUT ), { recursive: true } );
fs.writeFileSync( OUT, out.trimEnd() + '\n' );
console.log( `Wrote ${ headerByValue.size + sorted.length } strings to ${ path.relative( ROOT, OUT ) }` );
