// Pure helpers for the reference channel MCP server (agent-webhook-bridge-channel.mjs).
//
// These functions are SIDE-EFFECT-FREE: they take every input as an argument and
// return a value with no I/O, no process.exit, no filesystem/network access, no
// process.env reads, and no closure over the server's startup constants. They are
// split out into this sibling module ONLY so they can be unit-tested directly —
// the main server self-executes on import (it binds a real transport and calls
// process.exit on refuse paths), so importing IT to reach these helpers is not an
// option. The imperative startup body, and any helper that reads env / does I/O /
// closes over startup state (resolveToolsToken, shouldAdvertiseTools, markerPath),
// stay in the main file unchanged.
//
// Consumers copy the WHOLE examples/channel-servers/ directory (see README), so
// this file travels with the entry point; the entry imports it with a relative
// `./channel-lib.mjs` specifier — no build step, plain ESM.

// Strip obvious credential substrings from a raw-body snippet before it is relayed
// into a tool result (a PHP trace could echo an Authorization/Bearer line).
export function scrubSnippet(body) {
  return String(body)
    .replace(/(authorization|bearer)[^\n]*/gi, '[redacted]')
    .slice(0, 500);
}

// The ONE relay contract for BOTH transports (DR2-2, canon #5 — fixed at the second
// caller). Accumulate the FULL body (DR2-9), then JSON.parse-or-isError. The success
// signal is LEG-SUPPLIED (res.ok / clean ssh exit), NEVER inferred from the body — a
// 200 (or exit 0) with a php-warning-prepended body is a CORRUPT result, so it is
// isError:true, not a silently-broken isError:false. On parse failure a truncated,
// credential-scrubbed snippet keeps a non-JSON 502 page diagnosable.
export function relayBridgeResponse(rawBody, legSuccess, sourceLabel) {
  try {
    JSON.parse(rawBody);
  } catch {
    return {
      isError: true,
      content: [
        {
          type: 'text',
          text: `non-JSON response from the bridge (${sourceLabel}): ${scrubSnippet(rawBody)}`,
        },
      ],
    };
  }
  return {
    isError: !legSuccess,
    content: [{ type: 'text', text: rawBody }],
  };
}

// Derive the channel `meta` keys from a raw request body. Best-effort JSON parse:
// a non-JSON body (or a body without an object `intent`) yields an empty meta,
// never a throw — the caller always gets a usable object.
export function deriveMeta(body) {
  const meta = {};
  try {
    const parsed = JSON.parse(body);
    const intent = parsed && typeof parsed === 'object' ? parsed.intent : null;
    if (intent && typeof intent === 'object') {
      if (typeof intent.kind === 'string') {
        meta.kind = intent.kind;
      }
      if (typeof intent.target_id === 'string') {
        meta.target_id = intent.target_id;
      }
    }
  } catch {
    // body is not JSON; meta stays empty
  }
  return meta;
}
