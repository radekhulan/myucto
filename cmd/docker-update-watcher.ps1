# MyUcto.cz — Docker upgrade watcher (Windows / PowerShell verze).
#
# Sleduje storage/upgrade-requested.json **uvnitř** kontejneru (přes
# `docker compose exec`) a když ho UI vytvoří (POST /api/admin/update/
# trigger), spustí docker-update.ps1 a výsledek zapíše zpět do kontejneru
# do storage/upgrade-result.json. UI to v Systém → Aktualizace zobrazí
# jako „aplikováno / selhalo".
#
# Storage je Docker named volume (ne bind-mount), takže host watcher
# musí na flag soubor sahat přes `exec`. Tohle je oprava bugu v3.0.0/3.0.1
# kdy watcher na hostu neviděl flag uvnitř volume.
#
# Provoz:
#   - Pust jako Scheduled Task (Trigger: At startup). Action spusť tím PowerShell
#     hostem, který na stroji MÁŠ, a ukaž na SVOU instalační cestu:
#       PowerShell 7+:  pwsh       -NoProfile -ExecutionPolicy Bypass -File <cesta>\cmd\docker-update-watcher.ps1
#       Windows PS 5.1: powershell -NoProfile -ExecutionPolicy Bypass -File <cesta>\cmd\docker-update-watcher.ps1
#     s "Run whether user is logged in or not" + "Run with highest privileges".
#   - Sám si vlastní update spouští TÍMŽE hostem (pwsh/powershell), pod kterým běží,
#     a cesty řeší z $PSScriptRoot — funguje tedy z libovolného adresáře.
#
# Idempotent — flag se zpracovává jednou (rename před spuštěním).
[CmdletBinding()]
param()

$ErrorActionPreference = 'Continue'   # nevalíme se na non-fatal errorech v poll smyčce

# PS 7.3+ vypne "stderr z native commandů = error stream" — `docker compose pull`
# zapisuje download progress do stderr, který by jinak PS označoval červeně
# jako NativeCommandError, i když exit code je 0 (čistě kosmetický šum).
$PSNativeCommandUseErrorActionPreference = $false

$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

# Spustitelný soubor PowerShell hosta, pod kterým watcher běží (pwsh.exe NEBO
# powershell.exe). Update se musí volat TÍMŽE hostem — natvrdo `powershell` by na
# strojích jen s PowerShell 7 (pwsh) selhalo (issue #153). `(Get-Process …).Path`
# vrací plnou cestu k aktuálnímu hostu v PS 5.1 i 7; fallback dle verze.
$PwshExe = (Get-Process -Id $PID).Path
if (-not $PwshExe) {
    $PwshExe = if ($PSVersionTable.PSVersion.Major -ge 6) { 'pwsh' } else { 'powershell' }
}

$intervalS = if ($env:MYINVOICE_WATCHER_INTERVAL) { [int]$env:MYINVOICE_WATCHER_INTERVAL } else { 30 }

# Auto-detect compose file — preferuj production.yml pokud běží.
#
# Ptáme se rovnou na container id místo matchování člověkem čitelné tabulky:
# starší Compose v2 tiskl ve sloupci STATUS "running", novější tisknou
# docker-style "Up 2 weeks", takže match tiše přestal fungovat a watcher vždy
# spadl na výchozí compose soubor.
$composeArgs = @()
if ((Test-Path 'docker-compose.production.yml')) {
    $prodCid = (& docker compose -f docker-compose.production.yml ps --status running -q app 2>$null | Out-String).Trim()
    if ($LASTEXITCODE -eq 0 -and $prodCid) {
        $composeArgs = @('-f', 'docker-compose.production.yml')
    }
}

function Invoke-DC {
    param([Parameter(ValueFromRemainingArguments=$true)] [string[]]$Args)
    & docker compose @composeArgs @Args
}

$composeFileLabel = if ($composeArgs.Count -gt 0) { $composeArgs[1] } else { '<default docker-compose.yml>' }
Write-Host "[watcher] start, polling upgrade-requested.json inside container every $intervalS s"
Write-Host "[watcher] compose: $composeFileLabel"

# Storage cesta v kontejneru - od 3.6.0 single-volume default je `/data/storage`,
# starsi 3-volume layout pouziva WORKDIR-relative `storage`. Detekujeme pres ENV.
function Get-StorageDirInContainer {
    $dataDir = (& docker compose @composeArgs exec -T app printenv MYINVOICE_DATA_DIR 2>$null | Out-String).Trim()
    if ($dataDir) {
        return "$dataDir/storage"
    }
    return 'storage'
}

$storageDir = ''

function Write-ResultIntoContainer {
    param(
        [string]$Status,
        [string]$Target,
        [string]$Message,
        [string]$StorageDir
    )
    $payload = @{
        status         = $Status
        target_version = $Target
        applied_at     = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        message        = $Message
    }
    $json = $payload | ConvertTo-Json -Depth 4 -Compress
    $json | & docker compose @composeArgs exec -T app sh -c "cat > $StorageDir/upgrade-result.json" 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "[watcher] nelze zapsat upgrade-result.json"
    }
}

while ($true) {
    # Lazy-init storage path - kontejner nemusi bezet hned pri startu watcheru.
    if (-not $storageDir) {
        $storageDir = Get-StorageDirInContainer
        if ($storageDir) { Write-Host "[watcher] storage dir in container: $storageDir" }
    }

    # Test, jestli flag soubor existuje uvnitr kontejneru.
    & docker compose @composeArgs exec -T app test -f "$storageDir/upgrade-requested.json" 2>$null
    if ($LASTEXITCODE -eq 0) {
        $flagJson = & docker compose @composeArgs exec -T app cat "$storageDir/upgrade-requested.json" 2>$null

        $target = 'latest'
        if ($flagJson) {
            try {
                $payload = $flagJson | ConvertFrom-Json -ErrorAction Stop
                if ($payload.target_version) { $target = [string]$payload.target_version }
            } catch {
                Write-Warning "[watcher] nelze parsnout flag JSON: $_"
            }
        }

        $ts = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
        Write-Host "[watcher] $((Get-Date).ToUniversalTime().ToString('s'))Z upgrade requested → $target"

        # Lock - prejmenuj uvnitr kontejneru, at ho dalsi iterace nevezme znovu.
        & docker compose @composeArgs exec -T app mv -f "$storageDir/upgrade-requested.json" "$storageDir/upgrade-inflight.json" 2>$null

        $log = Join-Path $env:TEMP "myucto-upgrade-$ts.log"
        try {
            # Update běží jako SAMOSTATNÝ proces (izolace — docker-update.ps1 má
            # `exit`/Write-Error s EAP=Stop, které by jinak shodily watcher smyčku),
            # ale TÍMŽE PS hostem jako watcher ($PwshExe = pwsh/powershell), ne natvrdo
            # `powershell` (issue #153). 2>&1 mergne stderr → success stream.
            # Cílová verze z tlačítka: docker-update.ps1 jí přepíše připnutou verzi v compose.
            $env:MYINVOICE_TARGET_VERSION = $target
            & $PwshExe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ProjectRoot 'cmd\docker-update.ps1') 2>&1 | Tee-Object -FilePath $log | Out-Host
            if ($LASTEXITCODE -eq 0) {
                $status  = 'applied'
                $message = "Upgrade dokoncen. Log na hostu: $log"
                Write-Host "[watcher] OK"
            } else {
                $rc      = $LASTEXITCODE
                $status  = 'failed'
                # Do UI posilame i DUVOD, ne jen cestu k logu na hostu (do ktere se
                # uzivatel z prohlizece stejne nedostane) - jinak vypada selhani jako
                # "nedokoncena aktualizace" bez vysvetleni (issue #14).
                $reason = ''
                if (Test-Path -LiteralPath $log) {
                    $lines = @(Get-Content -LiteralPath $log -ErrorAction SilentlyContinue |
                        Where-Object { $_ -match 'ERROR' } | Select-Object -Last 3)
                    if ($lines.Count -eq 0) {
                        $lines = @(Get-Content -LiteralPath $log -Tail 5 -ErrorAction SilentlyContinue)
                    }
                    $reason = (($lines -join ' | ') -replace '[\x00-\x1f]', ' ')
                    if ($reason.Length -gt 400) { $reason = $reason.Substring(0, 400) }
                    if ($reason) { $reason = "$reason " }
                }
                $message = "Upgrade selhal (rc=$rc). ${reason}Log na hostu: $log"
                Write-Host "[watcher] FAILED (rc=$rc). Viz $log"
            }
        } catch {
            $status  = 'failed'
            $message = "Watcher exception: $_. Log: $log"
            Write-Host "[watcher] EXCEPTION: $_"
        }

        # Po update se kontejner restartuje — počkej, až bude zase responzivní.
        for ($i = 1; $i -le 30; $i++) {
            & docker compose @composeArgs exec -T app true 2>$null
            if ($LASTEXITCODE -eq 0) { break }
            Start-Sleep -Seconds 2
        }

        # Po recreate kontejneru se mohla zmenit storage cesta (3.5.x -> 3.6.0 auto-migrate).
        # Re-detekuj pred zapisem vysledku.
        $storageDir = Get-StorageDirInContainer

        Write-ResultIntoContainer -Status $status -Target $target -Message $message -StorageDir $storageDir
        & docker compose @composeArgs exec -T app rm -f "$storageDir/upgrade-inflight.json" 2>$null
    }
    Start-Sleep -Seconds $intervalS
}
