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
      4b. cliente-pi    sintaxis del cliente Python con 3.10 y sus tests (pi-client/tests) con el
                        venv de D:\tools\spui-python (py -3.14 -m venv + requests paho-mqtt psutil Pillow)
      5. assets.php     ¿el navegador recibe el JS/CSS actual? Si no, no falla: avisa que hay que
                        correr RebuildAssets.exe y las capturas se sacan con las fuentes (--fuentes)
      6. capturas.mjs   navegador real, escritorio y celular: errores de JS, 4xx/5xx,
                        [hidden] visibles, Guardar vacío sin errores; deja PNG en capturas\

    Requisitos: WAMP arriba (Apache + MySQL) y, para el paso 6, Playwright. En la PC de
    Mateo está instalado fijo en D:\tools\spui-playwright, que se usa por defecto. Para
    otra ubicación: -PlaywrightDir o $env:SPUI_PLAYWRIGHT_DIR. Para instalarlo de cero:
        cd <carpeta> ; npm i playwright@1
        $env:PLAYWRIGHT_BROWSERS_PATH = "<carpeta>\browsers" ; npx playwright install chromium-headless-shell
    Chromium se busca en $env:PLAYWRIGHT_BROWSERS_PATH (por defecto <PlaywrightDir>\browsers).

.EXAMPLE
    .\tools\verificacion\verificar.ps1
    .\tools\verificacion\verificar.ps1 -Solo pantallas,contenidos
    .\tools\verificacion\verificar.ps1 -SinCapturas
#>
param(
    [string]   $PlaywrightDir = $env:SPUI_PLAYWRIGHT_DIR,
    [string]   $PythonVenv = 'D:\tools\spui-python\Scripts\python.exe',
    [string[]] $Solo,
    [switch]   $SinCapturas,
    [switch]   $Seguir
)

$ErrorActionPreference = 'Stop'
if (-not $PlaywrightDir -and (Test-Path 'D:\tools\spui-playwright\node_modules\playwright')) {
    $PlaywrightDir = 'D:\tools\spui-playwright'
}
$aqui     = $PSScriptRoot
$monorepo = (Resolve-Path (Join-Path $aqui '..\..\..\..')).Path
$resultados = @()

function Paso([string] $nombre, [scriptblock] $accion) {
    Write-Host "`n=== $nombre" -ForegroundColor Cyan
    $inicio = Get-Date
    # Un programa externo se juzga por su código de salida, no por lo que
    # escriba en stderr: con 'Stop', PowerShell 5.1 corta ante cualquier línea
    # de stderr (un aviso de log de un test, por ejemplo).
    $ErrorActionPreference = 'Continue'
    & $accion 2>&1 | ForEach-Object { "$_" }
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
    if ($script:resultados.Resultado -contains 'PEDIR RebuildAssets.exe') {
        Write-Host 'El build de assets está viejo: avisarle a Mateo que corra RebuildAssets.exe antes de probar (las capturas usaron las fuentes).' -ForegroundColor Yellow
    }
}

Push-Location $monorepo
try {
    Paso 'lint:container' { php bin/console lint:container --id=spui }
    Paso 'lint:twig'      { php bin/console lint:twig apps/spui/templates --id=spui }
    Paso 'smoke'          { php (Join-Path $aqui 'smoke.php') }
    Paso 'formularios'    { php (Join-Path $aqui 'formularios.php') }

    # Cliente de la Pi: sintaxis con el Python más viejo disponible (3.10: si
    # compila ahí vale en el 3.11 de Raspberry Pi OS Bookworm) y tests con el
    # venv fijo de D:\tools\spui-python. Sin escribir .pyc en pi-client/, que
    # deploy.ps1 copiaría a la Pi.
    $env:PYTHONDONTWRITEBYTECODE = '1'
    $cliente = (Resolve-Path (Join-Path $aqui '..\..\pi-client')).Path
    Paso 'cliente-pi sintaxis' {
        py -3.10 -c "import ast, pathlib, sys; [ast.parse(p.read_text(encoding='utf-8'), str(p)) for p in pathlib.Path(sys.argv[1]).rglob('*.py')]; print('sintaxis OK')" $cliente
    }
    Paso 'cliente-pi tests' {
        Push-Location $cliente
        try { & $PythonVenv -m unittest discover -s tests } finally { Pop-Location }
    }
    # Un build viejo no es un error del código: el navegador de Mateo va a
    # recibir JS/CSS anteriores hasta que corra RebuildAssets.exe. Se avisa y
    # las capturas se sacan con las fuentes actuales (--fuentes).
    Write-Host "`n=== assets" -ForegroundColor Cyan
    php (Join-Path $aqui 'assets.php')
    $buildViejo = ($LASTEXITCODE -ne 0)
    $script:resultados += [pscustomobject]@{ Paso = 'assets'; Resultado = $(if ($buildViejo) { 'PEDIR RebuildAssets.exe' } else { 'OK' }); Segundos = 0 }

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
        if ($buildViejo) { $argsCapturas += '--fuentes' }
        Paso 'capturas' { node @argsCapturas }
    }
} finally {
    Pop-Location
}
Resumen
