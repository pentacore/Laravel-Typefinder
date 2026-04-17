import { spawn, exec } from 'node:child_process';
import { promisify } from 'node:util';
import picomatch from 'picomatch';
import { EventEmitter } from 'node:events';

const compileMatcher = (paths) => {
  if (paths.length === 0) {
    return () => false;
  }
  const globs = paths.map((p) => {
    const normalised = p.replace(/\\/g, "/").replace(/\/$/, "");
    return `${normalised}/**/*.php`;
  });
  const isMatch = picomatch(globs, { dot: true });
  return (file) => {
    const normalised = file.replace(/\\/g, "/");
    return isMatch(normalised);
  };
};

class Watcher extends EventEmitter {
  handshake;
  child;
  killTimeoutMs;
  buffer = "";
  inflight = null;
  nextBatch = null;
  nextResolvers = [];
  idCounter = 0;
  _dead = false;
  constructor(child, handshake, killTimeoutMs) {
    super();
    this.child = child;
    this.handshake = handshake;
    this.killTimeoutMs = killTimeoutMs;
    child.stdout?.on("data", (chunk) => {
      this.onStdout(typeof chunk === "string" ? chunk : chunk.toString("utf8"));
    });
    child.stderr?.on("data", (chunk) => {
      const text = (typeof chunk === "string" ? chunk : chunk.toString("utf8")).trimEnd();
      if (text !== "") {
        process.stderr.write(`[typefinder] ${text}
`);
      }
    });
    child.on("exit", (code, signal) => {
      this._dead = true;
      const reason = signal ? `signal ${signal}` : `exit code ${code ?? "null"}`;
      this.emit("died", reason);
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
  get dead() {
    return this._dead;
  }
  static async spawn(opts) {
    const spawnFn = opts.spawnFn ?? ((cmd, args, o) => spawn(cmd, args, o));
    const child = spawnFn(opts.command, [], { cwd: opts.cwd, shell: true });
    return new Promise((resolve, reject) => {
      let buffer = "";
      const onData = (chunk) => {
        buffer += typeof chunk === "string" ? chunk : chunk.toString("utf8");
        const nl = buffer.indexOf("\n");
        if (nl === -1) return;
        const line = buffer.slice(0, nl);
        buffer = buffer.slice(nl + 1);
        clearTimeout(timer);
        child.stdout?.off("data", onData);
        child.off("exit", onExit);
        try {
          const parsed = JSON.parse(line);
          if (parsed.type !== "ready") {
            throw new Error("expected ready, got " + parsed.type);
          }
          const watcher = new Watcher(child, parsed, opts.killTimeoutMs ?? 2e3);
          if (buffer.length > 0) {
            watcher.onStdout(buffer);
          }
          resolve(watcher);
        } catch (error) {
          child.kill("SIGTERM");
          reject(new Error(`malformed handshake: ${error.message}`));
        }
      };
      const onExit = (code, signal) => {
        clearTimeout(timer);
        child.stdout?.off("data", onData);
        reject(new Error(`watcher exited before handshake (code=${code}, signal=${signal})`));
      };
      const timer = setTimeout(() => {
        child.stdout?.off("data", onData);
        child.off("exit", onExit);
        child.kill("SIGTERM");
        reject(new Error(`watcher startup timeout after ${opts.startupTimeoutMs}ms`));
      }, opts.startupTimeoutMs);
      child.stdout?.on("data", onData);
      child.on("exit", onExit);
    });
  }
  async regen(paths) {
    if (this._dead) {
      throw new Error("watcher is dead");
    }
    if (!this.inflight) {
      return this.dispatch(paths);
    }
    if (!this.nextBatch) {
      this.nextBatch = /* @__PURE__ */ new Set();
    }
    for (const p of paths) {
      this.nextBatch.add(p);
    }
    return new Promise((resolve, reject) => {
      this.nextResolvers.push({ resolve, reject });
    });
  }
  dispatch(paths) {
    const id = `r${++this.idCounter}`;
    const req = { type: "regen", id, paths };
    this.child.stdin?.write(JSON.stringify(req) + "\n");
    return new Promise((resolve, reject) => {
      this.inflight = { id, resolve, reject };
    });
  }
  onStdout(chunk) {
    this.buffer += chunk;
    let nl;
    while ((nl = this.buffer.indexOf("\n")) !== -1) {
      const line = this.buffer.slice(0, nl);
      this.buffer = this.buffer.slice(nl + 1);
      if (line.trim() === "") continue;
      this.handleLine(line);
    }
  }
  handleLine(line) {
    let msg;
    try {
      msg = JSON.parse(line);
    } catch {
      process.stderr.write(`[typefinder] unparseable line: ${line}
`);
      return;
    }
    if (msg.type === "regen.done" || msg.type === "regen.error") {
      if (!this.inflight || "id" in msg && this.inflight.id !== msg.id) {
        return;
      }
      const pending = this.inflight;
      this.inflight = null;
      if (msg.type === "regen.done") {
        pending.resolve(msg);
      } else {
        pending.reject(new Error(msg.message));
      }
      this.drainQueued();
    }
  }
  drainQueued() {
    if (!this.nextBatch || this.nextResolvers.length === 0) return;
    const paths = Array.from(this.nextBatch);
    const resolvers = [...this.nextResolvers];
    this.nextBatch = null;
    this.nextResolvers = [];
    this.dispatch(paths).then(
      (r) => resolvers.forEach((h) => h.resolve(r)),
      (e) => resolvers.forEach((h) => h.reject(e))
    );
  }
  async kill() {
    if (this._dead) return;
    await new Promise((resolve) => {
      const timer = setTimeout(() => {
        if (!this._dead) this.child.kill("SIGKILL");
      }, this.killTimeoutMs);
      this.child.once("exit", () => {
        clearTimeout(timer);
        resolve();
      });
      this.child.kill("SIGTERM");
    });
  }
}

const execAsync = promisify(exec);
const typefinder = ({
  command = "php artisan typefinder:watch",
  buildCommand = "php artisan typefinder:generate --json",
  watch,
  debounceMs = 100,
  startupTimeoutMs = 1e4,
  killTimeoutMs = 2e3
} = {}) => {
  let resolvedRoot = process.cwd();
  let watcher = null;
  let matcher = () => false;
  const pending = /* @__PURE__ */ new Set();
  let timer;
  let isBuild = false;
  const scheduleRegen = () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      if (!watcher || watcher.dead) return;
      const batch = Array.from(pending);
      pending.clear();
      if (batch.length === 0) return;
      watcher.regen(batch).then((result) => {
        if (result.changed.length > 0) {
          process.stderr.write(
            `[typefinder] regen ${result.changed.length} file(s) in ${result.duration_ms}ms
`
          );
        }
        for (const warning of result.warnings) {
          process.stderr.write(`[typefinder] ${warning}
`);
        }
        for (const failure of result.failed) {
          process.stderr.write(`[typefinder] ${failure.path}: ${failure.message}
`);
        }
      }).catch((error) => {
        process.stderr.write(`[typefinder] regen failed: ${error.message}
`);
      });
    }, debounceMs);
  };
  return {
    name: "@pentacore/vite-plugin-laravel-typefinder",
    enforce: "pre",
    configResolved(config) {
      resolvedRoot = config.root;
      isBuild = config.command === "build";
    },
    async configureServer(server) {
      if (isBuild) return;
      watcher = await Watcher.spawn({
        command,
        cwd: resolvedRoot,
        startupTimeoutMs,
        killTimeoutMs
      });
      const paths = watch ?? flattenCategoryPaths(watcher.handshake.categories);
      matcher = compileMatcher(paths);
      watcher.on("died", (reason) => {
        process.stderr.write(
          `[typefinder] watcher exited: ${reason} \u2014 restart the dev server
`
        );
      });
      server.ws.on("typefinder:full-regen", () => {
        if (watcher && !watcher.dead) {
          watcher.regen([]).catch((error) => {
            process.stderr.write(`[typefinder] full regen failed: ${error.message}
`);
          });
        }
      });
      server.httpServer?.once("close", () => void watcher?.kill());
      process.once("exit", () => void watcher?.kill());
    },
    async buildStart() {
      if (isBuild) {
        if (buildCommand === false) {
          process.stderr.write("[typefinder] build-time generation disabled\n");
          return;
        }
        try {
          await execAsync(buildCommand, { cwd: resolvedRoot });
        } catch (error) {
          const message = error instanceof Error ? error.message : String(error);
          throw new Error(`[typefinder] build-time generation failed: ${message}`);
        }
        return;
      }
      if (watcher && !watcher.dead) {
        const result = await watcher.regen([]);
        if (result.changed.length > 0) {
          process.stderr.write(
            `[typefinder] initial regen: ${result.changed.length} file(s) in ${result.duration_ms}ms
`
          );
        }
      }
    },
    handleHotUpdate({ file }) {
      if (isBuild || !watcher || watcher.dead) return;
      if (!matcher(file)) return;
      pending.add(file);
      scheduleRegen();
    },
    async closeBundle() {
      if (watcher && !watcher.dead) {
        await watcher.kill();
      }
    }
  };
};
const flattenCategoryPaths = (categories) => {
  const out = [];
  for (const name of Object.keys(categories)) {
    const entry = categories[name];
    if (entry.enabled) {
      out.push(...entry.paths);
    }
  }
  return out;
};

export { typefinder as default, typefinder };
