@echo off
REM start-claude.bat -- COMPATIBILITY STUB (card#11328, DL-463): the launcher moved to
REM channel-servers\bin\. This file only runs it, so a seat that calls this path keeps launching.
call "%~dp0channel-servers\bin\start-claude.bat" %*
exit /b %ERRORLEVEL%
