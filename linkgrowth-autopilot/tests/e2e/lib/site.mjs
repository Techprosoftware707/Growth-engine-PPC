/**
 * The LGA test site for the E2E suite (DESIGN.md §12.1, §12.5): finds its SQLite database, snapshots it (and the PHP
 * error log) before the run and puts both back afterwards, prepares the plugin state and the two E2E users
 * (tests/e2e/setup.php), and serves the site on 127.0.0.1:8793 and the fixture router on 127.0.0.1:8794 with
 * `nice -n 10 php -S`, the way tests/integration/run.php serves the site. Only servers this module started are stopped.
 *
 * A run that is killed before it can restore (SIGKILL, a machine that stops) leaves a marker next to the site's
 * WordPress folder (.lga-e2e-restore-pending.json, naming the snapshot); the next run puts that snapshot back before
 * it takes its own, so the site never stays in a run's seeded state.
 */
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import crypto from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';

export const SITE_PORT = 8793;
export const FIXTURE_PORT = 8794;
export const BASE = `http://localhost:${ SITE_PORT }`;

/** True when something accepts connections on 127.0.0.1:<port>. */
export function portOpen( port ) {
    return new Promise( ( resolve ) => {
        const sock = net.connect( { host: '127.0.0.1', port }, () => {
            sock.destroy();
            resolve( true );
        } );
        sock.on( 'error', () => resolve( false ) );
        sock.setTimeout( 500, () => {
            sock.destroy();
            resolve( false );
        } );
    } );
}

const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/**
 * The SQLite file of the site: wp-content/database/db-path.php when the drop-in wrote one (it returns the path), else
 * the only *.sqlite file under wp-content/database/.
 */
export function findDatabase( wpPath ) {
    const dir = path.join( wpPath, 'wp-content', 'database' );
    const pathFile = path.join( dir, 'db-path.php' );
    if ( fs.existsSync( pathFile ) ) {
        const r = spawnSync( 'php', [ '-r', 'echo (string) ( require $argv[1] );', pathFile ], { encoding: 'utf8' } );
        const p = String( r.stdout || '' ).trim();
        if ( r.status === 0 && p !== '' && fs.existsSync( p ) ) {
            return p;
        }
    }
    const found = [];
    const walk = ( d, depth ) => {
        if ( depth > 2 || ! fs.existsSync( d ) ) {
            return;
        }
        for ( const e of fs.readdirSync( d, { withFileTypes: true } ) ) {
            const p = path.join( d, e.name );
            if ( e.isDirectory() ) {
                walk( p, depth + 1 );
            } else if ( e.name.endsWith( '.sqlite' ) ) {
                found.push( p );
            }
        }
    };
    walk( dir, 0 );
    if ( found.length === 1 ) {
        return found[ 0 ];
    }
    throw new Error( found.length ? `several SQLite files under ${ dir }: ${ found.join( ', ' ) }` : `no SQLite database under ${ dir } (the LGA site of DESIGN.md §12.1 uses the SQLite drop-in)` );
}

/** The plugin's backup folders in the site's uploads (`lga-backups-<random>`, Database\Backup::DIR_PREFIX). */
export function backupDirs( wpPath ) {
    const uploads = path.join( wpPath, 'wp-content', 'uploads' );
    if ( ! fs.existsSync( uploads ) ) {
        return [];
    }
    return fs.readdirSync( uploads, { withFileTypes: true } ).filter( ( e ) => e.isDirectory() && e.name.startsWith( 'lga-backups-' ) ).map( ( e ) => e.name ).sort();
}

/**
 * Removes the backup folders a run created (the reset deletes the option naming the site's folder, so the dashboard's
 * first GET /settings makes a new one; the restored database names the old one again). Only folders that were not
 * there before and hold nothing but the guard files and dumps are removed. Returns their names.
 */
export function removeNewBackupDirs( wpPath, before ) {
    const uploads = path.join( wpPath, 'wp-content', 'uploads' );
    const removed = [];
    for ( const name of backupDirs( wpPath ) ) {
        if ( before.includes( name ) ) {
            continue;
        }
        const dir = path.join( uploads, name );
        const files = fs.readdirSync( dir );
        if ( files.every( ( f ) => [ 'index.php', '.htaccess' ].includes( f ) || /^[\w-]+\.sql\.gz$/.test( f ) ) ) {
            fs.rmSync( dir, { recursive: true, force: true } );
            removed.push( name );
        }
    }
    return removed;
}

export class Site {
    constructor( { wpPath, pluginRoot, outDir, log } ) {
        this.wpPath = wpPath;
        this.pluginRoot = pluginRoot;
        this.outDir = outDir;
        this.log = log;
        this.db = '';
        this.debugLog = path.join( wpPath, 'wp-content', 'debug.log' );
        this.snapshot = null;
        this.servers = [];
        this.passwords = {
            admin: crypto.randomBytes( 18 ).toString( 'base64url' ),
            submitter: crypto.randomBytes( 18 ).toString( 'base64url' ),
        };
        this.outboundLog = path.join( outDir, 'outbound.jsonl' );
        this.mailLog = path.join( outDir, 'mail.jsonl' );
        this.fixtureLog = path.join( outDir, 'fixture-submissions.log' );
        this.hostLog = path.join( outDir, 'http-hosts.jsonl' );
    }

    /** The marker of a snapshot that has not been put back yet (next to the site's WordPress folder). */
    get pendingMarker() {
        return path.join( path.dirname( path.resolve( this.wpPath ) ), '.lga-e2e-restore-pending.json' );
    }

    /**
     * A run that was killed before its restore left a marker: put its snapshot (database and debug.log) back first.
     * Returns true when it restored one.
     */
    recoverPending() {
        if ( ! fs.existsSync( this.pendingMarker ) ) {
            return false;
        }
        let m = null;
        try {
            m = JSON.parse( fs.readFileSync( this.pendingMarker, 'utf8' ) );
        } catch ( e ) {
            m = null;
        }
        if ( ! m || ! m.db || ! m.copy || ! fs.existsSync( m.copy ) ) {
            throw new Error( `an earlier run left ${ this.pendingMarker } but its snapshot is missing: restore the site database by hand, then delete the marker` );
        }
        for ( const side of [ '-journal', '-wal', '-shm' ] ) {
            if ( fs.existsSync( m.db + side ) ) {
                fs.rmSync( m.db + side );
            }
        }
        fs.copyFileSync( m.copy, m.db );
        const sha = crypto.createHash( 'sha256' ).update( fs.readFileSync( m.db ) ).digest( 'hex' );
        if ( sha !== m.sha ) {
            throw new Error( `the snapshot ${ m.copy } of an earlier run does not match its checksum: restore the site database by hand` );
        }
        if ( m.debugCopy && fs.existsSync( m.debugCopy ) ) {
            fs.copyFileSync( m.debugCopy, this.debugLog );
            fs.rmSync( m.debugCopy, { force: true } );
        } else if ( m.debugCopy === null && fs.existsSync( this.debugLog ) ) {
            fs.rmSync( this.debugLog );
        }
        if ( Array.isArray( m.backupDirs ) ) {
            removeNewBackupDirs( this.wpPath, m.backupDirs );
        }
        fs.rmSync( m.copy, { force: true } );
        fs.rmSync( this.pendingMarker, { force: true } );
        this.log( `an earlier run did not finish: its database snapshot (${ m.copy }, from ${ m.at }) was put back first` );
        return true;
    }

    /**
     * Refuses to start when a port is taken or the database is in use; puts back an unfinished run's snapshot; then
     * copies the database and debug.log aside and leaves the marker until restore().
     */
    async takeSnapshot() {
        if ( await portOpen( SITE_PORT ) ) {
            throw new Error( `port ${ SITE_PORT } is already in use: stop that server first (the suite serves the site itself)` );
        }
        this.recoverPending();
        this.db = findDatabase( this.wpPath );
        for ( const side of [ '-journal', '-wal', '-shm' ] ) {
            if ( fs.existsSync( this.db + side ) ) {
                throw new Error( `the site database has a ${ side } file: another process is using it` );
            }
        }
        const copy = path.join( this.outDir, 'site-db-before.sqlite' );
        fs.copyFileSync( this.db, copy );
        const sha = crypto.createHash( 'sha256' ).update( fs.readFileSync( this.db ) ).digest( 'hex' );
        const debug = fs.existsSync( this.debugLog ) ? fs.readFileSync( this.debugLog ) : null;
        const debugCopy = debug ? path.join( this.outDir, 'site-debug-before.log' ) : null;
        if ( debugCopy ) {
            fs.writeFileSync( debugCopy, debug );
        }
        const dirs = backupDirs( this.wpPath );
        this.snapshot = { copy, sha, debug, debugCopy, debugSize: debug ? debug.length : 0, backupDirs: dirs };
        fs.writeFileSync( this.pendingMarker, JSON.stringify( { db: this.db, copy, sha, debugCopy, backupDirs: dirs, at: new Date().toISOString() } ) + '\n' );
        this.log( `site database snapshot: ${ copy } (sha256 ${ sha.slice( 0, 12 ) }…)` );
        return this.snapshot;
    }

    /** The lines the run added to the site's PHP error log. */
    newDebugLines() {
        if ( ! this.snapshot || ! fs.existsSync( this.debugLog ) ) {
            return [];
        }
        const now = fs.readFileSync( this.debugLog );
        const added = now.length >= this.snapshot.debugSize ? now.subarray( this.snapshot.debugSize ) : now;
        return added.toString( 'utf8' ).split( '\n' ).filter( ( l ) => l.trim() !== '' );
    }

    /** Puts the database (and debug.log) back as they were before the run; returns true when the checksum matches. */
    restore() {
        if ( ! this.snapshot ) {
            return true;
        }
        for ( const side of [ '-journal', '-wal', '-shm' ] ) {
            if ( fs.existsSync( this.db + side ) ) {
                fs.rmSync( this.db + side );
            }
        }
        fs.copyFileSync( this.snapshot.copy, this.db );
        if ( this.snapshot.debug ) {
            fs.writeFileSync( this.debugLog, this.snapshot.debug );
        } else if ( fs.existsSync( this.debugLog ) ) {
            fs.rmSync( this.debugLog );
        }
        const sha = crypto.createHash( 'sha256' ).update( fs.readFileSync( this.db ) ).digest( 'hex' );
        const ok = sha === this.snapshot.sha;
        const removed = removeNewBackupDirs( this.wpPath, this.snapshot.backupDirs || [] );
        this.log( `site database restored (${ ok ? 'checksum matches' : 'CHECKSUM DIFFERS' }); ${ removed.length } backup folder(s) the run created removed from uploads` );
        if ( ok ) {
            fs.rmSync( this.snapshot.copy, { force: true } );
            if ( this.snapshot.debugCopy ) {
                fs.rmSync( this.snapshot.debugCopy, { force: true } );
            }
            fs.rmSync( this.pendingMarker, { force: true } );
        }
        this.snapshot = null;
        return ok;
    }

    /** tests/e2e/setup.php: clean plugin state and the two users. */
    prepare() {
        const r = spawnSync( 'nice', [ '-n', '10', 'php', path.join( this.pluginRoot, 'tests', 'e2e', 'setup.php' ) ], {
            encoding: 'utf8',
            env: { ...process.env, LGA_WP_PATH: this.wpPath, LGA_E2E_ADMIN_PASS: this.passwords.admin, LGA_E2E_SUBMITTER_PASS: this.passwords.submitter },
            timeout: 120000,
        } );
        const line = String( r.stdout || '' ).trim().split( '\n' ).pop() || '';
        let out = null;
        try {
            out = JSON.parse( line );
        } catch ( e ) {
            out = null;
        }
        if ( r.status !== 0 || ! out || ! out.ok ) {
            throw new Error( `site preparation failed (exit ${ r.status }): ${ ( out && out.error ) || String( r.stderr || r.stdout || '' ).trim().slice( 0, 800 ) }` );
        }
        return out;
    }

    async startProcess( name, port, args, env ) {
        const logFile = path.join( this.outDir, `${ name }.log` );
        const fd = fs.openSync( logFile, 'w' );
        const proc = spawn( 'nice', [ '-n', '10', 'php', ...args ], { env: { ...process.env, ...env }, stdio: [ 'ignore', fd, fd ] } );
        const entry = { name, port, proc };
        this.servers.push( entry );
        for ( let i = 0; i < 100 && ! ( await portOpen( port ) ); i++ ) {
            if ( proc.exitCode !== null ) {
                break;
            }
            await wait( 100 );
        }
        if ( ! ( await portOpen( port ) ) ) {
            throw new Error( `the ${ name } server did not start on port ${ port } (see ${ logFile })` );
        }
        this.log( `${ name } server on 127.0.0.1:${ port } (pid ${ proc.pid })` );
    }

    /** The fixture router (when 8794 is free; a router already running there is used as it is) and the site. */
    async startServers() {
        for ( const f of [ this.outboundLog, this.mailLog, this.hostLog ] ) {
            fs.writeFileSync( f, '' );
        }
        if ( await portOpen( FIXTURE_PORT ) ) {
            this.log( `port ${ FIXTURE_PORT } is in use: using the fixture router already running there` );
        } else {
            await this.startProcess( 'fixture', FIXTURE_PORT, [ '-S', `127.0.0.1:${ FIXTURE_PORT }`, path.join( this.pluginRoot, 'tests', 'fixtures', 'router.php' ) ], { LGA_FIXTURE_LOG: this.fixtureLog } );
        }
        // Several workers: the plugin requests its own pages (site audit, render check) while a request is open.
        await this.startProcess( 'site', SITE_PORT, [ '-d', `auto_prepend_file=${ path.join( this.pluginRoot, 'tests', 'e2e', 'server-prepend.php' ) }`, '-S', `127.0.0.1:${ SITE_PORT }`, '-t', this.wpPath ], {
            PHP_CLI_SERVER_WORKERS: '3',
            LGA_E2E_OUTBOUND_LOG: this.outboundLog,
            LGA_E2E_MAIL_LOG: this.mailLog,
            LGA_HTTP_HOST_LOG: this.hostLog,
            LGA_FIXTURE_LOG: this.fixtureLog,
        } );
    }

    async stopServers() {
        for ( const s of this.servers.reverse() ) {
            if ( s.proc.exitCode === null ) {
                s.proc.kill( 'SIGTERM' );
                for ( let i = 0; i < 30 && s.proc.exitCode === null; i++ ) {
                    await wait( 100 );
                }
                if ( s.proc.exitCode === null ) {
                    s.proc.kill( 'SIGKILL' );
                }
            }
        }
        this.servers = [];
    }

    /** Synchronous last resort (process exit handlers). */
    killServersNow() {
        for ( const s of this.servers ) {
            if ( s.proc.exitCode === null ) {
                try {
                    s.proc.kill( 'SIGKILL' );
                } catch ( e ) {
                    // Already gone.
                }
            }
        }
    }

    /** JSON lines of one of the server logs. */
    readJsonLines( file ) {
        if ( ! fs.existsSync( file ) ) {
            return [];
        }
        return fs.readFileSync( file, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( l ) => {
            try {
                return JSON.parse( l );
            } catch ( e ) {
                return { raw: l };
            }
        } );
    }
}
