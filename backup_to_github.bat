@echo off
:: ====== GitHub �۰ʳƥ��妸�� (�c�餤�媩�ץ���) ======
:: �N�H�U���|�令�A���M�׸�Ƨ�
cd /d "C:\xampp\htdocs"

:: �ˬd Git ���A
git status

:: �s�W�����ק�
git add .

:: �۰ʫإ� commit�A�ϥΦw���榡���ɶ��W
setlocal enabledelayedexpansion
for /f "tokens=1-3 delims=/- " %%a in ('date /t') do (
    set mydate=%%a-%%b-%%c
)
for /f "tokens=1-2 delims=: " %%a in ('time /t') do (
    set mytime=%%a%%b
)
git commit -m "Auto backup on %mydate%_%mytime%"

:: �W�Ǩ� GitHub
git push

:: �]�i��^�W�ǫ�۰ʶ}�� GitHub ����
start https://github.com/mandy15933/vsCode-backup

echo.
echo ? �M�פw���\�ƥ��� GitHub�I
pause
