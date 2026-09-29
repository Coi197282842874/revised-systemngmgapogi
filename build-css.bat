@echo off
rem Builds assets\site.css (the styles of the homepage) from assets\tailwind.css.
rem
rem Run it after changing Tailwind classes in index.php, or after changing assets\tailwind.css:
rem     build-css            builds once
rem     build-css watch      builds again on every change, until Ctrl+C
rem
rem tools\tailwindcss.exe is the Tailwind CSS v4 standalone program: no Node.js needed.
rem It is not in git (it is large). If it is missing, download tailwindcss-windows-x64.exe
rem from https://github.com/tailwindlabs/tailwindcss/releases and save it as tools\tailwindcss.exe.
rem
rem Upload assets\site.css to the website; assets\tailwind.css and this file stay here.

cd /d "%~dp0"

if not exist tools\tailwindcss.exe (
    echo tools\tailwindcss.exe is missing. See the notes at the top of build-css.bat.
    exit /b 1
)

if /i "%~1"=="watch" (
    tools\tailwindcss.exe -i assets\tailwind.css -o assets\site.css --minify --watch
) else (
    tools\tailwindcss.exe -i assets\tailwind.css -o assets\site.css --minify
)
