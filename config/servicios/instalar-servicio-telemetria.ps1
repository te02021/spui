# =============================================================================
# SPUI - Instala el ingestor de telemetria como servicio de Windows
# =============================================================================
#
# El comando spui:mqtt:subscribe es un proceso de larga duracion: se suscribe a
# spui/telemetria/+ y persiste cada mensaje en la base. Sin el, los
# reproductores publican al broker y nadie escucha: la telemetria se pierde en
# silencio, sin ningun error visible en el CMS.
#
# Se instala como servicio para que arranque solo con Windows, igual que Apache
# o MySQL. Nadie tiene que acordarse de levantarlo.
#
# Uso (PowerShell como Administrador):
#     .\instalar-servicio-telemetria.ps1
#     .\instalar-servicio-telemetria.ps1 -Desinstalar
#
# Requiere NSSM:  choco install nssm -y
#
# NOTA: este archivo se mantiene deliberadamente en ASCII puro, sin acentos ni
# guiones largos. Windows PowerShell 5.1 lee los .ps1 como ANSI salvo que
# lleven BOM, y un archivo UTF-8 sin BOM le llega con los acentos rotos: los
# bytes extra descolocan el parser y produce errores de sintaxis en lineas que
# estan perfectamente escritas.
# =============================================================================

param(
    [string]$RaizIntranet = "C:\wamp64\www\intranet",
    # Vacio = se autodetecta el php.exe que este en el PATH, que es el que WAMP
    # tiene seleccionado. Hardcodear una version rompe el script en cuanto se
    # cambia de PHP desde el menu de WAMP.
    [string]$Php = "",
    [string]$NombreServicio = "spui-telemetria",
    [switch]$Desinstalar
)

$ErrorActionPreference = "Stop"

function Assert-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $pr = New-Object Security.Principal.WindowsPrincipal($id)
    if (-not $pr.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw "Este script necesita PowerShell como Administrador."
    }
}

Assert-Admin

if (-not (Get-Command nssm -ErrorAction SilentlyContinue)) {
    throw "NSSM no esta instalado o no esta en el PATH. Instalarlo con: choco install nssm -y"
}

# ---- Desinstalacion ---------------------------------------------------------
if ($Desinstalar) {
    if (Get-Service $NombreServicio -ErrorAction SilentlyContinue) {
        nssm stop   $NombreServicio
        nssm remove $NombreServicio confirm
        Write-Host "Servicio '$NombreServicio' eliminado." -ForegroundColor Green
    } else {
        Write-Host "El servicio '$NombreServicio' no existe." -ForegroundColor Yellow
    }
    return
}

# ---- Validaciones previas ---------------------------------------------------
# Se comprueban antes de crear el servicio: NSSM acepta rutas inexistentes sin
# quejarse y el fallo recien aparece al arrancar, como un servicio que se apaga
# solo y sin explicacion.
if ([string]::IsNullOrWhiteSpace($Php)) {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if (-not $cmd) {
        throw "No se encontro php.exe en el PATH. Pasar la ruta con -Php."
    }
    $Php = $cmd.Source
    Write-Host "  PHP detectado: $Php" -ForegroundColor DarkGray
}
if (-not (Test-Path $Php)) {
    throw "No se encontro PHP en '$Php'. Pasar la ruta correcta con -Php."
}

$consola = Join-Path $RaizIntranet "bin\console"
if (-not (Test-Path $consola)) {
    throw "No se encontro '$consola'. Pasar la raiz correcta con -RaizIntranet."
}

$logDir    = "C:\ProgramData\spui"
$logSalida = Join-Path $logDir "telemetria.log"
$logError  = Join-Path $logDir "telemetria-error.log"
New-Item -ItemType Directory -Force $logDir | Out-Null

if (Get-Service $NombreServicio -ErrorAction SilentlyContinue) {
    Write-Host "El servicio ya existe: se reinstala." -ForegroundColor Yellow
    nssm stop   $NombreServicio
    nssm remove $NombreServicio confirm
    Start-Sleep -Seconds 2
}

# ---- Instalacion ------------------------------------------------------------
Write-Host "Instalando '$NombreServicio'..." -ForegroundColor Cyan

nssm install $NombreServicio $Php

# Los argumentos van como UNA sola cadena y con la ruta entrecomillada.
# NSSM no cita nada por su cuenta: pasarlos sueltos parte la ruta en el primer
# espacio y el proceso muere al arrancar, dejando el servicio en 'Paused'.
$argumentos = '"{0}" spui:mqtt:subscribe --id=spui' -f $consola
nssm set $NombreServicio AppParameters $argumentos

nssm set $NombreServicio AppDirectory $RaizIntranet
nssm set $NombreServicio DisplayName  "SPUI - Ingestor de telemetria MQTT"
nssm set $NombreServicio Description  "Suscribe a spui/telemetria/+ y persiste la telemetria de los reproductores."
nssm set $NombreServicio Start        SERVICE_AUTO_START

# Salida a archivo: si el proceso falla al arrancar, el motivo queda aca.
# Sin esto, un servicio que no levanta no deja ninguna pista.
nssm set $NombreServicio AppStdout $logSalida
nssm set $NombreServicio AppStderr $logError

# Rotacion: es un daemon que corre meses y el log creceria sin techo.
nssm set $NombreServicio AppRotateFiles 1
nssm set $NombreServicio AppRotateBytes 10485760

# Reinicio ante caida, con espera. El broker puede no estar listo cuando
# arranca Windows: sin esto el servicio se rendiria en el primer intento.
nssm set $NombreServicio AppExit Default Restart
nssm set $NombreServicio AppRestartDelay 10000

# El servicio depende de que MySQL este arriba: sin base no puede persistir.
# Mosquitto no se pone como dependencia porque el comando reintenta la conexion.
#
# Se elige entre los que estan CORRIENDO: en esta maquina conviven
# wampmysqld64 (detenido) y MySQL93 (activo), y tomar el primero por nombre
# elegiria el equivocado, dejando el servicio esperando una dependencia muerta.
$mysql = Get-Service -Name "wampmysqld*", "MySQL*" -ErrorAction SilentlyContinue |
         Where-Object { $_.Status -eq 'Running' } | Select-Object -First 1
if ($mysql) {
    nssm set $NombreServicio DependOnService $mysql.Name
    Write-Host "  Dependencia configurada: $($mysql.Name)" -ForegroundColor DarkGray
} else {
    Write-Host "  Sin servicio MySQL activo: no se configura dependencia." -ForegroundColor Yellow
}

Write-Host "Arrancando..." -ForegroundColor Cyan
nssm start $NombreServicio
Start-Sleep -Seconds 3

$estado = (Get-Service $NombreServicio).Status

if ($estado -eq "Running") {
    Write-Host ""
    Write-Host "  Servicio '$NombreServicio' corriendo." -ForegroundColor Green
    Write-Host "  Log: $logSalida" -ForegroundColor DarkGray
    Write-Host ""
    Write-Host "  Verificar que entren filas (esperar ~60 s):" -ForegroundColor DarkGray
    Write-Host '    php bin/console dbal:run-sql --connection=spui --id=spui "SELECT COUNT(*) FROM telemetria"' -ForegroundColor DarkGray
} else {
    Write-Host ""
    Write-Host "  El servicio quedo en estado '$estado'." -ForegroundColor Red
    Write-Host "  Revisar: $logError" -ForegroundColor Yellow
}
