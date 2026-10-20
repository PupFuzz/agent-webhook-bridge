<#
  The launcher's hidden tunnel process gets each argument whole when a value holds a space
  (card#12112): a profile under C:\Users\First Last puts one in the script path and the key path.

  Runs the REAL Start-TunnelProcess and ConvertTo-WindowsCommandLine, lifted out of
  ..\bin\start-claude.ps1 by the parser (the launcher itself is not run: it would open an ssh
  tunnel). The script the child runs is a probe made of the launcher's own param() block, so the
  names it binds are the launcher's; it writes what it received to $env:AWB_TUNNEL_PROBE_OUT.

  A control first starts the same probe with the unquoted array the launcher used to pass, and
  requires the values NOT to arrive whole: if it ever passes, the probe no longer shows the defect.

  Windows only (Start-Process -FilePath powershell.exe). Run: powershell -NoProfile -File <this>.
  Exits 0 when every case passes, 1 otherwise.
#>
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2

$launcher = Join-Path $PSScriptRoot '..\bin\start-claude.ps1'
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile((Resolve-Path $launcher).Path, [ref]$tokens, [ref]$errors)
if ($errors) { throw "start-claude.ps1 does not parse: $($errors[0].Message)" }
foreach ($name in @('ConvertTo-WindowsCommandLine', 'Start-TunnelProcess')) {
  $fn = $ast.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name }, $true)
  if (-not $fn) { throw "start-claude.ps1 defines no function $name" }
  . ([scriptblock]::Create($fn.Extent.Text))
}

$base = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [IO.Path]::GetTempPath() }
$dir = Join-Path $base ("awb tunnel args " + [guid]::NewGuid().ToString('N').Substring(0, 8) + '\First Last')
New-Item -ItemType Directory -Path $dir -Force | Out-Null
$probe = Join-Path $dir 'start claude probe.ps1'
$record = Join-Path $dir 'received.json'
Set-Content -LiteralPath $probe -Encoding UTF8 -Value @(
  $ast.ParamBlock.Extent.Text
  '$rest = if ($PassthroughArgs) { @($PassthroughArgs) } else { @() }'
  '[ordered]@{ ScriptPath = $PSCommandPath; Tunnel = [bool]$Tunnel; Channel = $Channel; SshKey = $SshKey;'
  '  RemotePort = $RemotePort; LocalPort = $LocalPort; TunnelHost = $TunnelHost; Rest = ($rest -join "|"); RestCount = $rest.Count } |'
  '  ConvertTo-Json | Set-Content -LiteralPath $env:AWB_TUNNEL_PROBE_OUT -Encoding UTF8'
)
$env:AWB_TUNNEL_PROBE_OUT = $record

function Wait-Probe($proc) {
  if (-not $proc.WaitForExit(60000)) { $proc.Kill(); throw 'the probe did not exit within 60s' }
  if (-not (Test-Path -LiteralPath $record)) { return $null }
  $got = Get-Content -LiteralPath $record -Raw | ConvertFrom-Json
  Remove-Item -LiteralPath $record
  return $got
}

$failures = @()
$cases = @(
  @{ Name = 'a profile with a space'; Channel = 'pm-agent'; TunnelHost = 'agent@bridge-host'
     SshKey = 'C:\Users\First Last\.ssh\bridge-channel-tunnel' }
  @{ Name = 'spaces in every value, a trailing backslash and an embedded quote'; Channel = 'chan with space'
     TunnelHost = 'agent@bridge host "x"'; SshKey = (Join-Path $dir 'key dir\') }
)

# Control: the pre-card#12112 construction, an array straight to -ArgumentList.
$c = $cases[0]
$naive = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $probe, '-Tunnel', '-Channel', $c.Channel,
           '-SshKey', $c.SshKey, '-RemotePort', '8790', '-LocalPort', '18790', '-TunnelHost', $c.TunnelHost)
$got = Wait-Probe (Start-Process -FilePath 'powershell.exe' -ArgumentList $naive -WindowStyle Hidden -PassThru)
if ($got -and $got.ScriptPath -eq $probe -and $got.SshKey -eq $c.SshKey -and $got.RestCount -eq 0) {
  $failures += 'control: an unquoted array reached the probe whole, so this probe cannot show the defect'
} else {
  Write-Host 'control: an unquoted array does not reach the probe whole (expected)'
}

foreach ($c in $cases) {
  $proc = Start-TunnelProcess -ScriptPath $probe -Channel $c.Channel -SshKey $c.SshKey -RemotePort 8790 -LocalPort 18790 -TunnelHost $c.TunnelHost
  $got = Wait-Probe $proc
  if (-not $got) { $failures += "$($c.Name): the probe wrote nothing (its script path did not reach powershell.exe whole)"; continue }
  $want = [ordered]@{ ScriptPath = $probe; Tunnel = $true; Channel = $c.Channel; SshKey = $c.SshKey
                      RemotePort = 8790; LocalPort = 18790; TunnelHost = $c.TunnelHost; RestCount = 0 }
  $bad = @($want.Keys | Where-Object { $got.$_ -ne $want[$_] })
  if ($bad) {
    foreach ($k in $bad) { $failures += "$($c.Name): $k received [$($got.$k)], want [$($want[$k])]" }
    if ($got.RestCount) { $failures += "$($c.Name): stray arguments [$($got.Rest)]" }
  } else {
    Write-Host "ok: $($c.Name)"
  }
}

Remove-Item -LiteralPath (Split-Path $dir) -Recurse -Force -ErrorAction SilentlyContinue
if ($failures) { $failures | ForEach-Object { Write-Host "FAIL: $_" }; exit 1 }
Write-Host "all cases pass under $($PSVersionTable.PSEdition) $($PSVersionTable.PSVersion)"
exit 0
