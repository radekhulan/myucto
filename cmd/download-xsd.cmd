@echo off
REM Stahne XSD schemata do api/xsd/ (Windows verze): EPO MFCR vykazy (DPH/KH/SH/
REM DPFO/DPPO) + ISDOC 6.0.2 + CSSZ OSVC + JMHZ balicky z portalu MPSV.
REM Default jsou commitnuta v repo - skript pouzij jen pro upgrade na nove rocniky.
REM
REM Pouziti:
REM   cmd\download-xsd.cmd           - stahne vsechna schemata (EPO + ISDOC + CSSZ + JMHZ)
REM   cmd\download-xsd.cmd dphkh1    - stahne jen jedno EPO schema
REM   cmd\download-xsd.cmd isdoc     - stahne jen ISDOC schema
REM   cmd\download-xsd.cmd osvc25    - stahne jen CSSZ prehled OSVC (annual)
REM   cmd\download-xsd.cmd jmhz      - stahne 6 pripnutych JMHZ XSD balicku
REM
REM Pozn.: CSSZ meni nazev souboru per rocnik (OSVC24/OSVC25/...) i dokument-ID v URL,
REM proto je URL napevno nize - pri novem rocniku aktualizuj CSSZ_OSVC_URL i cilovy
REM nazev (form_code osvcYY). Zdrojova stranka: viz api/xsd/README.md.

setlocal EnableDelayedExpansion

set "DIR=%~dp0..\api\xsd"
set "BASE=https://adisspr.mfcr.cz/adis/jepo/schema"
set "ISDOC_URL=https://isdoc.cz/6.0.2/xsd/isdoc-invoice-6.0.2.xsd"
set "CSSZ_OSVC_URL=https://www.cssz.gov.cz/documents/20143/3201321/OSVC25.xsd/5d467add-4c11-0e56-4d54-d455b56c15c9"

if not exist "%DIR%" mkdir "%DIR%"

if "%~1"=="" (
    set "FORMS=dphdp3 dphkh1 dphshv dpfdp5 dppdp9 dpzvd6 dpsvd2 dpzmb1 dpzdb1 isdoc osvc25 jmhz"
) else (
    set "FORMS=%*"
)

for %%F in (%FORMS%) do (
    if /I "%%F"=="jmhz" (
        echo -^> jmhz: 6 oficialnich XSD balicku MPSV
        call "%~dp0download-jmhz-xsd.cmd"
        if errorlevel 1 (
            endlocal
            exit /b 1
        )
    ) else if /I "%%F"=="isdoc" (
        echo -^> isdoc: %ISDOC_URL%
        powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '%ISDOC_URL%' -OutFile '%DIR%\isdoc-invoice-6.0.2.xsd' -UseBasicParsing; Write-Host '  OK' } catch { Write-Host '  FAIL:' $_.Exception.Message }"
    ) else (
        if /I "%%F"=="osvc25" (
            echo -^> osvc25 ^(CSSZ prehled OSVC^): %CSSZ_OSVC_URL%
            powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '%CSSZ_OSVC_URL%' -OutFile '%DIR%\osvc25.xsd' -UseBasicParsing; Write-Host '  OK' } catch { Write-Host '  FAIL:' $_.Exception.Message }"
        ) else (
            echo -^> %%F: %BASE%/%%F_epo2.xsd
            powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '%BASE%/%%F_epo2.xsd' -OutFile '%DIR%\%%F.xsd' -UseBasicParsing; Write-Host '  OK' } catch { Write-Host '  FAIL:' $_.Exception.Message }"
        )
    )
)

echo.
echo Hotovo. Schemata v: %DIR%
endlocal
