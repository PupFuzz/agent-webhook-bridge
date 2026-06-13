@echo off
REM start-claude.bat -- thin shim that launches the PowerShell reference launcher
REM (start-claude.ps1, sitting next to this file via %~dp0).
REM   -NoProfile              : skip the user's PS profile (fast, deterministic).
REM   -ExecutionPolicy Bypass : run the .ps1 without changing the machine policy (standard idiom).
REM   -File ... %*            : run the launcher, passing extra args through to Claude.
REM The .ps1 owns all logic (channel resolution, single-session guard, marker surfacing,
REM tunnel lifecycle, PID-tree teardown).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-claude.ps1" %*
