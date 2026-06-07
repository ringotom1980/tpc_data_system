@echo off
cd /d "%~dp0"
py -3 tpc_auto_uploader.py
if errorlevel 1 python tpc_auto_uploader.py
pause
