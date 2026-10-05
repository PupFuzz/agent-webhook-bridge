<#
  start-claude.ps1 -- Windows launcher for agent-webhook-bridge live-wake (the Windows half
  of the canonical cross-platform launcher; the bash start-claude.sh is the Linux half).
  PowerShell, not cmd: native JSON, Get-NetTCPConnection guard, PID-tree tunnel teardown.

  WHERE IT RUNS FROM (card#11328): the client pack ships this file, and a bootstrapped seat
  runs it through <client root>\bin\start-claude.cmd -- a shim the client updater rewrites at
  every pack install (it bypasses ExecutionPolicy for this invocation only). The seat's own
  %USERPROFILE%\start-claude.bat / .ps1 are stable shims the provisioner writes
  (--write-launcher-shim). Never copy or edit this file on a seat: a copy does not update.
  examples/channel-servers/README.md, section "The seat's launcher", owns that contract.

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

  PER-DEPLOYMENT SETTINGS -- from the environment (a user env var, `setx NAME value`), since
  the copy that runs is the pack's and is replaced at every update:
    TUNNEL_HOST  -- the SSH endpoint, "<agent>@<bridge-host>"   (no generic default; must be set)
    SSH_KEY      -- path to the reverse-tunnel private key       (default: ~\.ssh\bridge-channel-tunnel,
                    else ~\.ssh\coord-channel-tunnel when only that one exists)
    REMOTE_PORT / LOCAL_PORT -- HTTP channel port (default 8790)
    SERVER_DIR   -- a COPIED channel-server dir, read only when NOT run from a client root
                    (default ~\agent-webhook-bridge-channel)
  No secrets live in this file -- the channel bearer token is in ~/.mcp.json, not here.

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

# ===== CONFIG (from the environment -- see the header) =====
# TUNNEL_HOST has NO usable generic default -- set $env:TUNNEL_HOST.
if (-not $TunnelHost) { $TunnelHost = if ($env:TUNNEL_HOST) { $env:TUNNEL_HOST } else { '<agent>@<bridge-host>' } }
if (-not $RemotePort) { $RemotePort = if ($env:REMOTE_PORT) { [int]$env:REMOTE_PORT } else { 8790 } }
if (-not $LocalPort)  { $LocalPort  = if ($env:LOCAL_PORT)  { [int]$env:LOCAL_PORT }  else { 8790 } }
if (-not $SshKey) {
  if ($env:SSH_KEY) { $SshKey = $env:SSH_KEY }
  else {
    # Two key names were documented (the bridge's and the coord framework's launcher copies);
    # the bridge's wins when both exist.
    $SshKey = Join-Path $env:USERPROFILE '.ssh\bridge-channel-tunnel'
    $coordKey = Join-Path $env:USERPROFILE '.ssh\coord-channel-tunnel'
    if (-not (Test-Path $SshKey) -and (Test-Path $coordKey)) { $SshKey = $coordKey }
  }
}

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

# ===== 2. single-session / stale-orphan guard (a LISTENING local port is held by a process: a
#          running session, or a channel server left behind by one -> probe to tell them apart,
#          reclaim only a dead orphan; never kill a possibly-live session). =====
function Test-ChannelServerResponding {
  param([int]$Port)
  for ($i = 0; $i -lt 3; $i++) {
    try {
      $null = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/" -UseBasicParsing -TimeoutSec 3
      return $true                                          # 2xx == alive
    } catch {
      # A real HTTP error response (e.g. 405 Method Not Allowed -- the bridge only accepts POST)
      # still proves it is alive and handling; only a connection-level failure means "not responding".
      if ($_.Exception.Response -and $_.Exception.Response.StatusCode) { return $true }
    }
    Start-Sleep -Milliseconds 500
  }
  return $false
}
function Show-PortHolder {
  param($Connections, [int]$Port)
  $Connections | Select-Object -ExpandProperty OwningProcess -Unique | ForEach-Object {
    $p = Get-CimInstance Win32_Process -Filter "ProcessId=$_" -ErrorAction SilentlyContinue
    if ($p) { Write-Host ("  port {0} held by PID {1} ({2}): {3}" -f $Port, $p.ProcessId, $p.Name, $p.CommandLine) }
  }
}
function Clear-StaleChannelServer {
  param([int]$Port, $Connections)
  $killedAny = $false
  foreach ($procId in ($Connections | Select-Object -ExpandProperty OwningProcess -Unique)) {
    if (-not $procId -or $procId -eq 0) { continue }
    $p = Get-CimInstance Win32_Process -Filter "ProcessId=$procId" -ErrorAction SilentlyContinue
    if (-not $p) { continue }
    # SAFETY: only ever kill a node process that is demonstrably the awb channel server -- a copied
    # server, or a client root's entry.mjs -- never an arbitrary listener. taskkill /T also reaps
    # any children of the orphan.
    if ($p.Name -eq 'node.exe' -and ($p.CommandLine -match 'agent-webhook-bridge-channel' -or
        $p.CommandLine -match 'agent-webhook-bridge[\\/]client[\\/][^\\/]+[\\/]entry\.mjs')) {
      Write-Warning ("Reclaiming port {0}: killing orphan channel server PID {1}." -f $Port, $procId)
      taskkill /PID $procId /T /F | Out-Null
      $killedAny = $true
    } else {
      Write-Warning ("Port {0} held by PID {1} ({2}) -- NOT an agent-webhook-bridge channel server; refusing to kill it." -f $Port, $procId, $p.Name)
    }
  }
  if (-not $killedAny) { return $false }
  for ($i = 0; $i -lt 20; $i++) {                           # wait up to ~5s for TCP teardown
    Start-Sleep -Milliseconds 250
    $still = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue |
             Where-Object { $_.LocalAddress -in '127.0.0.1', '::1', '0.0.0.0' }
    if (-not $still) { return $true }
  }
  return $false                                             # didn't release -> caller aborts
}

# Get-NetTCPConnection catches 127.0.0.1, ::1 and 0.0.0.0 binds (a netstat literal matches only one).
$listening = Get-NetTCPConnection -State Listen -LocalPort $LocalPort -ErrorAction SilentlyContinue |
             Where-Object { $_.LocalAddress -in '127.0.0.1', '::1', '0.0.0.0' }
if ($listening) {
  if (Test-ChannelServerResponding -Port $LocalPort) {
    # Responding -> a running session, or a live server left behind by one. Do NOT kill it.
    Write-Host "The channel port is already held by a process on port $LocalPort (a running session, or a channel server left behind by one) -- refusing to start a second."
    Show-PortHolder -Connections $listening -Port $LocalPort
    Write-Host "If that is actually a stale orphan (no Claude attached), stop it and relaunch:"
    Write-Host "  Get-NetTCPConnection -LocalPort $LocalPort | ForEach-Object { Stop-Process -Id `$_.OwningProcess -Force }"
    exit 1
  }
  # Held but NOT responding -> the stale orphan that causes deaf sessions. Reclaim and continue.
  Write-Warning "Port $LocalPort is held but not responding as a healthy bridge -- stale orphan from a prior session. Reclaiming."
  if (-not (Clear-StaleChannelServer -Port $LocalPort -Connections $listening)) {
    Write-Error "Could not reclaim port $LocalPort (holder is not a recognizable channel server, or the port did not release). Clear it manually, then relaunch."
    Show-PortHolder -Connections $listening -Port $LocalPort
    exit 1
  }
  Write-Host "Reclaimed port $LocalPort; continuing startup."
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

# ===== 4. tunnel key + one-time channel-server deps (a COPIED channel server only) =====
# A client root ships its node_modules in the pack: a launch through <root>\bin\start-claude.cmd
# (which sets AWB_LAUNCHER_CLIENT_ROOT) installs nothing and does not read SERVER_DIR.
if (-not (Test-Path $SshKey)) { Write-Error "tunnel key not found at $SshKey"; exit 1 }
if (-not $env:AWB_LAUNCHER_CLIENT_ROOT) {
  $ServerDir = if ($env:SERVER_DIR) { $env:SERVER_DIR } else { Join-Path $env:USERPROFILE 'agent-webhook-bridge-channel' }
  if (-not (Test-Path (Join-Path $ServerDir 'node_modules'))) {
    Write-Host "Installing channel-server dependencies (pinned via npm ci)..."
    if (-not (Test-Path $ServerDir)) { Write-Error "$ServerDir not found"; exit 1 }
    Push-Location $ServerDir
    try {
      npm ci
      if ($LASTEXITCODE -ne 0) { Write-Error "npm ci failed" ; exit 1 }
    } finally { Pop-Location }
  }
}
Remove-Item Env:AWB_LAUNCHER_CLIENT_ROOT -ErrorAction SilentlyContinue

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
  # Defensively reap our channel server if Claude did not (a hard exit can leave its child node
  # alive holding $LocalPort -> next launch comes up deaf). Only kills a verified awb node on our
  # port; this is the teardown half of the deaf-session fix (startup reclaim is the other half).
  $leftover = Get-NetTCPConnection -State Listen -LocalPort $LocalPort -ErrorAction SilentlyContinue |
              Where-Object { $_.LocalAddress -in '127.0.0.1', '::1', '0.0.0.0' }
  if ($leftover) { [void](Clear-StaleChannelServer -Port $LocalPort -Connections $leftover) }
}
