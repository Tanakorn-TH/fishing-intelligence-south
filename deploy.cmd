@echo off
rem Deploy the site to fishing.yru.ac.th.
rem Double-click this file, or run .\deploy.cmd from PowerShell.
rem Options pass straight through to ops/deploy.sh (--yes, --setup).
rem
rem PowerShell on Windows cannot find "bash" (or finds WSL's), so this
rem wrapper calls Git Bash directly. All the real work is in ops/deploy.sh.
setlocal
chcp 65001 >nul

set "GITBASH=%ProgramFiles%\Git\bin\bash.exe"
if not exist "%GITBASH%" set "GITBASH=%LocalAppData%\Programs\Git\bin\bash.exe"
if not exist "%GITBASH%" (
  echo Git Bash not found. Install Git for Windows first: winget install Git.Git
  set "RC=1"
  goto done
)

cd /d "%~dp0"
"%GITBASH%" ops/deploy.sh %*
set "RC=%ERRORLEVEL%"

:done
rem Keep the window open when started by double-click so the result stays readable.
echo %CMDCMDLINE% | find /i "%~nx0" >nul && pause
exit /b %RC%
