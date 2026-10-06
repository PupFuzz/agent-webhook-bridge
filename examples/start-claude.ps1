# start-claude.ps1 -- COMPATIBILITY STUB (card#11328, DL-463): the launcher moved to
# channel-servers\bin\start-claude.ps1. This file only runs it, so a seat that calls this path
# keeps launching after a pull.
& (Join-Path $PSScriptRoot 'channel-servers\bin\start-claude.ps1') @args
exit $LASTEXITCODE
