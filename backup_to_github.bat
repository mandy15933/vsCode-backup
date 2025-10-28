@echo off
:: ====== GitHub 自動備份批次檔 ======
:: 作者：ChatGPT (for mandy15933)
:: 時間會自動記錄在 commit 訊息中

cd /d "C:\xampp\htdocs"   :: ← 請改成你的專案資料夾路徑

:: 檢查 Git 狀態
git status

:: 新增全部修改
git add .

:: 自動建立 commit，包含當前時間
for /f "tokens=1-4 delims=/ " %%a in ('date /t') do set mydate=%%a-%%b-%%c-%%d
for /f "tokens=1-2 delims=: " %%a in ('time /t') do set mytime=%%a%%b
git commit -m "Auto backup on %mydate%_%mytime%"

:: 上傳到 GitHub
git push

echo.
echo ✅ 專案已成功備份到 GitHub！
pause
