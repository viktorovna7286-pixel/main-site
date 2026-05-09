@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>&1
if errorlevel 1 (
  echo PHP not in PATH. Install from https://windows.php.net/download/
  pause
  exit /b 1
)
set "PORT=%~1"
if "%PORT%"=="" set "PORT=8080"
echo.
echo http://127.0.0.1:%PORT%/
echo http://127.0.0.1:%PORT%/catalog/admin.php
echo.
if not exist "catalog\admin-config.php" (
  echo [!] Copy catalog\admin-config.example.php to catalog\admin-config.php
  echo.
)
php -S 127.0.0.1:%PORT% router.php
pause
