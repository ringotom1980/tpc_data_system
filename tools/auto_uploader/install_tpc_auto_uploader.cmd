@echo off
setlocal

set "APP_DIR=%LOCALAPPDATA%\TPCDataSystem\AutoUploader"
set "SCRIPT_URL=https://tpc.jinghong.pw/tpc_data_system/Public/tools/download_auto_uploader.php?file=script"
set "SCRIPT_PATH=%APP_DIR%\tpc_auto_uploader.py"
set "RUNNER_PATH=%APP_DIR%\run_tpc_auto_uploader.cmd"

echo TPC Auto Uploader 安裝程式
echo.

where py >nul 2>nul
if errorlevel 1 (
  where python >nul 2>nul
  if errorlevel 1 (
    echo 這台電腦尚未偵測到 Python。
    echo 請先安裝 Python 3 後再執行此安裝程式。
    start "" "https://www.python.org/downloads/windows/"
    pause
    exit /b 1
  )
)

if not exist "%APP_DIR%" mkdir "%APP_DIR%"

echo 正在下載本機工具...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri '%SCRIPT_URL%' -OutFile '%SCRIPT_PATH%'"
if errorlevel 1 (
  echo 下載失敗，請確認網路連線。
  pause
  exit /b 1
)

(
  echo @echo off
  echo cd /d "%%~dp0"
  echo py -3 tpc_auto_uploader.py
  echo if errorlevel 1 python tpc_auto_uploader.py
) > "%RUNNER_PATH%"

echo 正在啟動本機工具...
start "" "%RUNNER_PATH%"

echo.
echo 安裝完成。若設定視窗沒有出現，請稍等幾秒或重新執行：
echo %RUNNER_PATH%
echo.
pause
