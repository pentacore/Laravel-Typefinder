import { spawn as nodeSpawn } from 'node:child_process';
import type { ChildProcess } from 'node:child_process';
import { EventEmitter } from 'node:events';
import type { Handshake, IncomingMessage, RegenRequest, RegenResponse } from './types';

export interface WatcherSpawnOptions {
    command: string;
    cwd: string;
    startupTimeoutMs: number;
    killTimeoutMs?: number;
    spawnFn?: (cmd: string, args: string[], opts: { cwd: string; shell: boolean }) => ChildProcess;
}

export class Watcher extends EventEmitter {
    readonly handshake: Handshake;
    private readonly child: ChildProcess;
    private readonly killTimeoutMs: number;
    private buffer = '';
    private inflight: {
        id: string;
        resolve: (r: RegenResponse) => void;
        reject: (e: Error) => void;
    } | null = null;
    private nextBatch: Set<string> | null = null;
    private nextResolvers: Array<{
        resolve: (r: RegenResponse) => void;
        reject: (e: Error) => void;
    }> = [];
    private idCounter = 0;
    private _dead = false;

    private constructor(child: ChildProcess, handshake: Handshake, killTimeoutMs: number) {
        super();
        this.child = child;
        this.handshake = handshake;
        this.killTimeoutMs = killTimeoutMs;

        child.stdout?.on('data', (chunk: Buffer | string) => {
            this.onStdout(typeof chunk === 'string' ? chunk : chunk.toString('utf8'));
        });

        child.stderr?.on('data', (chunk: Buffer | string) => {
            const text = (typeof chunk === 'string' ? chunk : chunk.toString('utf8')).trimEnd();
            if (text !== '') {
                process.stderr.write(`[typefinder] ${text}\n`);
            }
        });

        child.on('exit', (code, signal) => {
            this._dead = true;
            const reason = signal ? `signal ${signal}` : `exit code ${code ?? 'null'}`;
            this.emit('died', reason);
            if (this.inflight) {
                this.inflight.reject(new Error(`watcher died (${reason})`));
                this.inflight = null;
            }
            for (const r of this.nextResolvers) {
                r.reject(new Error(`watcher died (${reason})`));
            }
            this.nextResolvers = [];
            this.nextBatch = null;
        });
    }

    get dead(): boolean {
        return this._dead;
    }

    static async spawn(opts: WatcherSpawnOptions): Promise<Watcher> {
        const spawnFn = opts.spawnFn ?? ((cmd, args, o) => nodeSpawn(cmd, args, o));
        const child = spawnFn(opts.command, [], { cwd: opts.cwd, shell: true });

        return new Promise<Watcher>((resolve, reject) => {
            let buffer = '';

            const onData = (chunk: Buffer | string) => {
                buffer += typeof chunk === 'string' ? chunk : chunk.toString('utf8');
                const nl = buffer.indexOf('\n');
                if (nl === -1) return;
                const line = buffer.slice(0, nl);
                buffer = buffer.slice(nl + 1);
                clearTimeout(timer);
                child.stdout?.off('data', onData);
                child.off('exit', onExit);

                try {
                    const parsed = JSON.parse(line) as IncomingMessage;
                    if (parsed.type !== 'ready') {
                        throw new Error('expected ready, got ' + parsed.type);
                    }
                    const watcher = new Watcher(child, parsed as Handshake, opts.killTimeoutMs ?? 2_000);
                    if (buffer.length > 0) {
                        watcher.onStdout(buffer);
                    }
                    resolve(watcher);
                } catch (error) {
                    child.kill('SIGTERM');
                    reject(new Error(`malformed handshake: ${(error as Error).message}`));
                }
            };

            const onExit = (code: number | null, signal: NodeJS.Signals | null) => {
                clearTimeout(timer);
                child.stdout?.off('data', onData);
                reject(new Error(`watcher exited before handshake (code=${code}, signal=${signal})`));
            };

            const timer = setTimeout(() => {
                child.stdout?.off('data', onData);
                child.off('exit', onExit);
                child.kill('SIGTERM');
                reject(new Error(`watcher startup timeout after ${opts.startupTimeoutMs}ms`));
            }, opts.startupTimeoutMs);

            child.stdout?.on('data', onData);
            child.on('exit', onExit);
        });
    }

    async regen(paths: string[]): Promise<RegenResponse> {
        if (this._dead) {
            throw new Error('watcher is dead');
        }

        if (!this.inflight) {
            return this.dispatch(paths);
        }

        if (!this.nextBatch) {
            this.nextBatch = new Set();
        }
        for (const p of paths) {
            this.nextBatch.add(p);
        }

        return new Promise<RegenResponse>((resolve, reject) => {
            this.nextResolvers.push({ resolve, reject });
        });
    }

    private dispatch(paths: string[]): Promise<RegenResponse> {
        const id = `r${++this.idCounter}`;
        const req: RegenRequest = { type: 'regen', id, paths };
        this.child.stdin?.write(JSON.stringify(req) + '\n');

        return new Promise<RegenResponse>((resolve, reject) => {
            this.inflight = { id, resolve, reject };
        });
    }

    private onStdout(chunk: string): void {
        this.buffer += chunk;
        let nl: number;
        while ((nl = this.buffer.indexOf('\n')) !== -1) {
            const line = this.buffer.slice(0, nl);
            this.buffer = this.buffer.slice(nl + 1);
            if (line.trim() === '') continue;
            this.handleLine(line);
        }
    }

    private handleLine(line: string): void {
        let msg: IncomingMessage;
        try {
            msg = JSON.parse(line) as IncomingMessage;
        } catch {
            process.stderr.write(`[typefinder] unparseable line: ${line}\n`);
            return;
        }

        if (msg.type === 'regen.done' || msg.type === 'regen.error') {
            if (!this.inflight || ('id' in msg && this.inflight.id !== msg.id)) {
                return;
            }
            const pending = this.inflight;
            this.inflight = null;

            if (msg.type === 'regen.done') {
                pending.resolve(msg as RegenResponse);
            } else {
                pending.reject(new Error(msg.message));
            }

            this.drainQueued();
        }
    }

    private drainQueued(): void {
        if (!this.nextBatch || this.nextResolvers.length === 0) return;

        const paths = Array.from(this.nextBatch);
        const resolvers = [...this.nextResolvers];
        this.nextBatch = null;
        this.nextResolvers = [];

        this.dispatch(paths).then(
            (r) => resolvers.forEach((h) => h.resolve(r)),
            (e) => resolvers.forEach((h) => h.reject(e)),
        );
    }

    async kill(): Promise<void> {
        if (this._dead) return;
        await new Promise<void>((resolve) => {
            const timer = setTimeout(() => {
                if (!this._dead) this.child.kill('SIGKILL');
            }, this.killTimeoutMs);
            this.child.once('exit', () => {
                clearTimeout(timer);
                resolve();
            });
            this.child.kill('SIGTERM');
        });
    }
}
