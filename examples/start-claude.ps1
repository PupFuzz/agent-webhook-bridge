<#
  start-claude.ps1 -- Windows reference launcher for agent-webhook-bridge live-wake (the
  Windows half of the canonical cross-platform launcher; the bash start-channel-session.sh
  is the Linux half). PowerShell, not cmd: native JSON, Get-NetTCPConnection guard, PID-tree
  tunnel teardown. Launch via the sibling start-claude.bat shim (bypasses ExecutionPolicy
  for this invocation only).

  Windows agents use the HTTP transport over an SSH reverse tunnel (topology B/C); this
  launcher owns the tunnel lifecycle (an auto-reconnecting hidden side process, torn down
  by PID tree when Claude exits).

  Channel resolution (first hit wins, GENERIC -- no project name hardcoded):
    a) $env:BRIDGE_CHANNEL_NAME
    b) settings.local.json .env.BRIDGE_CHANNEL_NAME
    c) <namespace>-<agent> = (COORD_CONFIG).bridge.channel_namespace + .env.COORD_AGENT
       (COORD_CONFIG may be stored Git-Bash-style /c/...; converted to <drive>:/ before reading.)
  Mirrors the bash launcher's chain. settings.local.json is load-bearing for the same reason:
  the launcher shell does not inherit the env Claude Code injects into a session.

  -ResolveOnly : print the resolved channel and exit (no guard/tunnel/launch). For testing.
  -Tunnel ...  : internal self-reinvoke -- runs the auto-reconnecting reverse-tunnel loop.
#>
[CmdletBinding()]
param(
  [switch]$ResolveOnly,
  [switch]$Tunnel,
  [string]$Channel,
  [string]$SshKey,
  [int]$RemotePort,
  [int]$LocalPort,
  [string]$TunnelHost,
  [Parameter(ValueFromRemainingArguments = $true)]
  [string[]]$PassthroughArgs
)

$ErrorActionPreference = 'Stop'

# ===== internal: auto-reconnecting reverse-tunnel loop (own hidden process) =====
if ($Tunnel) {
  if ($Channel) { $Host.UI.RawUI.WindowTitle = "awb-tunnel-$Channel" }
  while ($true) {
    ssh -N -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes `
        -o StrictHostKeyChecking=accept-new -i $SshKey `
        -R "127.0.0.1:${RemotePort}:127.0.0.1:${LocalPort}" $TunnelHost
    Write-Host "Tunnel dropped; reconnecting in 5s... (auto-stops when Claude exits)"
    Start-Sleep -Seconds 5
  }
  return
}

# ===== CONFIG (fill in for your deployment, or override via env) =====
if (-not $TunnelHost) { $TunnelHost = if ($env:TUNNEL_HOST) { $env:TUNNEL_HOST } else { 'user@bridge-host.example.com' } }
if (-not $RemotePort) { $RemotePort = if ($env:REMOTE_PORT) { [int]$env:REMOTE_PORT } else { 8790 } }
if (-not $LocalPort)  { $LocalPort  = if ($env:LOCAL_PORT)  { [int]$env:LOCAL_PORT }  else { 8790 } }
if (-not $SshKey)     { $SshKey     = if ($env:SSH_KEY)     { $env:SSH_KEY }     else { Join-Path $env:USERPROFILE '.ssh\bridge-channel-tunnel' } }
$ServerDir = if ($env:SERVER_DIR) { $env:SERVER_DIR } else { Join-Path $env:USERPROFILE 'agent-webhook-bridge-channel' }

# ===== 1. resolve channel =====
function Resolve-Channel {
  if ($env:BRIDGE_CHANNEL_NAME) { return $env:BRIDGE_CHANNEL_NAME }            # (a)
  $s = Join-Path $env:USERPROFILE '.claude\settings.local.json'
  if (-not (Test-Path $s)) { return $null }
  $e = (Get-Content $s -Raw | ConvertFrom-Json).env
  if ($e.BRIDGE_CHANNEL_NAME) { return $e.BRIDGE_CHANNEL_NAME }                # (b)
  if ($e.COORD_AGENT -and $e.COORD_CONFIG) {                                   # (c)
    $c = $e.COORD_CONFIG
    if ($c -match '^/([A-Za-z])/(.*)') { $c = "$($Matches[1]):/$($Matches[2])" }  # MSYS /c/... -> C:/...
    if (Test-Path $c) {
      $ns = (Get-Content $c -Raw | ConvertFrom-Json).bridge.channel_namespace
      if ($ns) { return "$ns-$($e.COORD_AGENT)" }
    }
  }
  return $null
}
if (-not $Channel) { $Channel = Resolve-Channel }   # -Channel arg wins (mirrors bash --channel)
if (-not $Channel) {
  Write-Error "cannot resolve channel name -- set BRIDGE_CHANNEL_NAME, or settings.local.json .env.BRIDGE_CHANNEL_NAME, or .env.COORD_AGENT + COORD_CONFIG .bridge.channel_namespace."
  exit 1
}
if ($ResolveOnly) { Write-Output $Channel; exit 0 }

# ===== 2. single-session / stale-listener guard (a LISTENING local port == a session is up) =====
# Get-NetTCPConnection catches 127.0.0.1, ::1 and 0.0.0.0 binds (a netstat literal matches only one).
$listening = Get-NetTCPConnection -State Listen -LocalPort $LocalPort -ErrorAction SilentlyContinue |
             Where-Object { $_.LocalAddress -in '127.0.0.1', '::1', '0.0.0.0' }
if ($listening) {
  Write-Host "A channel server is already listening on port $LocalPort -- a session is up. Aborting."
  exit 1
}

# ===== 3. surface (NEVER delete) a prior deaf-session marker (DL-154/155) =====
# The server's HTTP markerPath() base is os.tmpdir() (channel-server example >= 0.4.2, DL-156),
# which on Windows is %TEMP% -- so this $env:TEMP lookup matches the path the server writes.
# Like the bash launcher, surface only; the server clears it on the next successful bind.
$marker = Join-Path $env:TEMP "agent-webhook-bridge-channel-$Channel.http-$LocalPort.FAILED"
if (Test-Path $marker) {
  Write-Warning "a previous '$Channel' session came up DEAF to live-wake:"
  Get-Content $marker | Write-Host
}

# ===== 4. tunnel key + one-time channel-server deps =====
if (-not (Test-Path $SshKey)) { Write-Error "tunnel key not found at $SshKey"; exit 1 }
if (-not (Test-Path (Join-Path $ServerDir 'node_modules'))) {
  Write-Host "Installing channel-server dependencies (pinned via npm ci)..."
  if (-not (Test-Path $ServerDir)) { Write-Error "$ServerDir not found"; exit 1 }
  Push-Location $ServerDir
  try {
    npm ci
    if ($LASTEXITCODE -ne 0) { Write-Error "npm ci failed" ; exit 1 }
  } finally { Pop-Location }
}

# ===== 5. bring up the reverse tunnel in a HIDDEN side process (lifecycle == session) =====
# -WindowStyle Hidden, NOT Minimized: minimized = SW_SHOWMINIMIZED (2) ACTIVATES the window;
# a delayed child then grabs focus seconds after launch and the user's first keystroke restores
# it. Hidden has no taskbar window to steal/restore; teardown is by PID below.
Write-Host "Starting reverse tunnel ($RemotePort)..."
$tunnelArgs = @(
  '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath,
  '-Tunnel', '-Channel', $Channel, '-SshKey', $SshKey,
  '-RemotePort', "$RemotePort", '-LocalPort', "$LocalPort", '-TunnelHost', $TunnelHost
)
$tunnelProc = Start-Process -FilePath 'powershell.exe' -ArgumentList $tunnelArgs -WindowStyle Hidden -PassThru

# ===== 6. export the resolved identity so the channel server binds what we guarded =====
# Mirrors the bash launcher's step 7: the server (.mjs) derives its port + marker from these,
# so exporting the resolved values guarantees it binds the endpoint this launcher guarded.
$env:BRIDGE_CHANNEL_NAME      = $Channel
$env:BRIDGE_CHANNEL_TRANSPORT = 'http'
$env:BRIDGE_CHANNEL_PORT      = "$LocalPort"

# ===== 7. launch Claude Code with the channel; 8. tear the tunnel down (by PID tree) on exit =====
try {
  Set-Location $env:USERPROFILE
  & claude --dangerously-load-development-channels "server:$Channel" @PassthroughArgs
} finally {
  Write-Host "Claude exited; stopping tunnel..."
  if ($tunnelProc -and -not $tunnelProc.HasExited) {
    taskkill /PID $tunnelProc.Id /T /F | Out-Null   # PID tree-kill: robust vs a WINDOWTITLE match
  }
}
