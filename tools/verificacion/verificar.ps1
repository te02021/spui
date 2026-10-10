<#
.SYNOPSIS
    Suite de verificación del CMS de SPUI. Correrla antes de dar por terminado
    cualquier cambio (ver "Definición de terminado" en apps/spui/CLAUDE.md).

.DESCRIPTION
    Pasos, en orden (se detiene en el primero que falla salvo -Seguir):
      1. lint:container --id=spui      cableado de servicios
      2. lint:twig      (templates de SPUI)
      3. smoke.php      todas las rutas GET del CMS por HTTP real + API sin clave → 401
      4. formularios.php cada FormType con un campo vacío por vez: nunca una excepción
      5. assets.php     ¿el navegador recibe el JS/CSS actual? (si no: RebuildAssets.exe)
      6. capturas.mjs   navegador real, escritorio y celular: errores de JS, 4xx/5xx,
                        [hidden] visibles, Guardar vacío sin errores; deja PNG en capturas\

    Requisitos: WAMP arriba (Apache + MySQL) y, para el paso 6, Playwright:
        npm i playwright@1 ; npx playwright install chromium-headless-shell
    en una carpeta cualquiera, apuntada por -PlaywrightDir o $env:SPUI_PLAYWRIGHT_DIR.
    Chromium se busca en $env:PLAYWRIGHT_BROWSERS_PATH (por defecto <PlaywrightDir>\browsers).

.EXAMPLE
    .\tools\verificacion\verificar.ps1
    .\tools\verificacion\verificar.ps1 -Solo pantallas,contenidos
    .\tools\verificacion\verificar.ps1 -SinCapturas
#>
param(
    [string]   $PlaywrightDir = $env:SPUI_PLAYWRIGHT_DIR,
    [string[]] $Solo,
    [switch]   $SinCapturas,
    [switch]   $Seguir
)

$ErrorActionPreference = 'Stop'
$aqui     = $PSScriptRoot
$monorepo = (Resolve-Path (Join-Path $aqui '..\..\..\..')).Path
$resultados = @()

function Paso([string] $nombre, [scriptblock] $accion) {
    Write-Host "`n=== $nombre" -ForegroundColor Cyan
    $inicio = Get-Date
    & $accion
    $ok = ($LASTEXITCODE -eq 0)
    $seg = [math]::Round(((Get-Date) - $inicio).TotalSeconds)
    $script:resultados += [pscustomobject]@{ Paso = $nombre; Resultado = $(if ($ok) { 'OK' } else { 'FALLA' }); Segundos = $seg }
    if (-not $ok -and -not $Seguir) { Resumen; exit 1 }
}

function Resumen {
    Write-Host "`n=== Resumen" -ForegroundColor Cyan
    $script:resultados | Format-Table -AutoSize | Out-String | Write-Host
    if ($script:resultados.Resultado -contains 'FALLA') { Write-Host 'Hay fallas: no entregar el cambio.' -ForegroundColor Red }
    else { Write-Host 'Todo en verde. Falta revisar a ojo las capturas de las pantallas afectadas.' -ForegroundColor Green }
}

Push-Location $monorepo
try {
    Paso 'lint:container' { php bin/console lint:container --id=spui }
    Paso 'lint:twig'      { php bin/console lint:twig apps/spui/templates --id=spui }
    Paso 'smoke'          { php (Join-Path $aqui 'smoke.php') }
    Paso 'formularios'    { php (Join-Path $aqui 'formularios.php') }
    Paso 'assets'         { php (Join-Path $aqui 'assets.php') }

    if (-not $SinCapturas) {
        if (-not $PlaywrightDir -or -not (Test-Path (Join-Path $PlaywrightDir 'node_modules\playwright'))) {
            Write-Host "`nNo encuentro Playwright (-PlaywrightDir / `$env:SPUI_PLAYWRIGHT_DIR). Ver la ayuda: Get-Help $PSCommandPath" -ForegroundColor Red
            $script:resultados += [pscustomobject]@{ Paso = 'capturas'; Resultado = 'FALLA'; Segundos = 0 }
            Resumen; exit 1
        }
        $env:SPUI_PLAYWRIGHT_DIR = $PlaywrightDir
        if (-not $env:PLAYWRIGHT_BROWSERS_PATH) { $env:PLAYWRIGHT_BROWSERS_PATH = Join-Path $PlaywrightDir 'browsers' }
        $argsCapturas = @((Join-Path $aqui 'capturas.mjs'))
        if ($Solo) { $argsCapturas += "--solo=$($Solo -join ',')" }
        Paso 'capturas' { node @argsCapturas }
    }
} finally {
    Pop-Location
}
Resumen
