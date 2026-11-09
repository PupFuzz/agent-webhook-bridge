// `node --test` runs files concurrently, and any file's `listen(0)` can be handed a port another
// file just read off a closed `listen(0)`. A port below the kernel's ephemeral range is never handed
// out by `listen(0)`, so a child given one cannot lose it to a concurrent `listen(0)`.
import fs from 'node:fs';
import net from 'node:net';
import { spawnSync } from 'node:child_process';

const FLOOR = 10000;

// 49152: the IANA dynamic range's start, and Windows' default.
function ephemeralLow() {
  if (process.platform === 'linux') {
    return Number(fs.readFileSync('/proc/sys/net/ipv4/ip_local_port_range', 'utf8').trim().split(/\s+/)[0]);
  }
  if (process.platform === 'darwin') {
    return Number(spawnSync('sysctl', ['-n', 'net.inet.ip.portrange.first'], { encoding: 'utf8' }).stdout.trim());
  }
  return 49152;
}

// EACCES: a port inside a Windows excluded range (Hyper-V, WSL) refuses the bind with no holder.
function bindable(port) {
  return new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', (err) => (['EADDRINUSE', 'EACCES'].includes(err.code) ? resolve(false) : reject(err)));
    probe.listen(port, '127.0.0.1', () => probe.close(() => resolve(true)));
  });
}

/** A loopback port below the ephemeral range that was free when probed. */
export async function unusedPort() {
  const low = ephemeralLow();
  if (!(low > FLOOR)) {
    throw new Error(`the ephemeral port range starts at ${low}, leaving no ports in [${FLOOR}, ${low}) to pick from`);
  }
  for (;;) {
    const port = FLOOR + Math.floor(Math.random() * (low - FLOOR));
    if (await bindable(port)) return port;
  }
}
