// `node --test` runs files concurrently. A port taken from a `listen(0)` that was then closed goes
// back to the kernel's ephemeral pool, and any other file's `listen(0)` can be handed it before the
// child process uses it. `listen(0)` never hands out a port below the ephemeral range, so a child
// given one cannot lose it to a concurrent `listen(0)`.
import fs from 'node:fs';
import net from 'node:net';
import { spawnSync } from 'node:child_process';

const FLOOR = 10000;
const ATTEMPTS = 300;

// Read from the kernel on Linux and macOS; elsewhere assumed to be 49152, the IANA dynamic range's
// start and Windows' default.
function ephemeralLow() {
  if (process.platform === 'linux') {
    return Number(fs.readFileSync('/proc/sys/net/ipv4/ip_local_port_range', 'utf8').trim().split(/\s+/)[0]);
  }
  if (process.platform === 'darwin') {
    const r = spawnSync('sysctl', ['-n', 'net.inet.ip.portrange.first'], { encoding: 'utf8' });
    const out = r.status === 0 ? r.stdout.trim() : '';
    if (out === '') {
      throw new Error(`could not read net.inet.ip.portrange.first: ${r.error?.message || r.stderr?.trim() || `sysctl exited ${r.status}`}`);
    }
    return Number(out);
  }
  return 49152;
}

// No host: the wildcard bind conflicts with a holder on any address, which is what the launcher's
// `ss "sport = :<port>"` sees. EACCES: a Windows excluded range (Hyper-V, WSL) refuses the bind
// with no holder. Resolves null when the port bound, else the refusing error code.
function refusal(port) {
  return new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', (err) => (['EADDRINUSE', 'EACCES'].includes(err.code) ? resolve(err.code) : reject(err)));
    probe.listen(port, () => probe.close(() => resolve(null)));
  });
}

/** A port below the ephemeral range that no address held when probed. */
export async function unusedPort() {
  const low = ephemeralLow();
  if (!(low > FLOOR)) {
    throw new Error(`the ephemeral port range starts at ${low}, leaving no ports in [${FLOOR}, ${low}) to pick from`);
  }
  let last;
  for (let i = 0; i < ATTEMPTS; i++) {
    const port = FLOOR + Math.floor(Math.random() * (low - FLOOR));
    last = await refusal(port);
    if (last === null) return port;
  }
  throw new Error(`no bindable port in [${FLOOR}, ${low}) after ${ATTEMPTS} attempts; the last refusal was ${last}`);
}
