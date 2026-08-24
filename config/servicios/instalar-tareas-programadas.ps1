# =============================================================================
# SPUI - Instala las tareas programadas de mantenimiento
# =============================================================================
#
# Dos tareas, con proposito y cadencia distintos:
#
#   spui-mantenimiento  (cada 1 min)
#       Marca como desconectados los reproductores sin heartbeat, desactiva
#       alertas vencidas y purga la telemetria cruda pasada la retencion.
#       Sin esta tarea, un equipo apagado figura "conectado" para siempre:
#       registrarHeartbeat() es lo unico que escribe ese estado y solo sabe
#       ponerlo en "conectado".
#
#   spui-rollup         (cada 1 hora, al minuto 5)
#       Consolida la telemetria cruda en resumenes horarios. Corre al minuto 5
#       para que la hora anterior ya este cerrada.
#
# ORDEN IMPORTANTE: el rollup debe correr antes de que la purga borre las
# lecturas. Con la retencion en dias y el rollup cada hora hay muchisimo
# margen, pero si alguna vez se baja la retencion a horas, revisar esto.
#
# Uso (PowerShell como Administrador):
#     .\instalar-tareas-programadas.ps1
#     .\instalar-tareas-programadas.ps1 -Desinstalar
#
# NOTA: archivo en ASCII puro a proposito. Windows PowerShell 5.1 lee los .ps1
# como ANSI salvo que lleven BOM, y un UTF-8 sin BOM le llega con los acentos
# rotos, produciendo errores de sintaxis en lineas correctas.
# =============================================================================

param(
    [string]$RaizIntranet = "C:\wamp64\www\intranet",
    [string]$Php = "",
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

$tareas = @("spui-mantenimiento", "spui-rollup")

# ---- Desinstalacion ---------------------------------------------------------
if ($Desinstalar) {
    foreach ($t in $tareas) {
        if (Get-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $t -Confirm:$false
            Write-Host "Tarea '$t' eliminada." -ForegroundColor Green
        } else {
            Write-Host "La tarea '$t' no existe." -ForegroundColor Yellow
        }
    }
    return
}

# ---- Validaciones -----------------------------------------------------------
if ([string]::IsNullOrWhiteSpace($Php)) {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if (-not $cmd) { throw "No se encontro php.exe en el PATH. Pasar la ruta con -Php." }
    $Php = $cmd.Source
    Write-Host "  PHP detectado: $Php" -ForegroundColor DarkGray
}
if (-not (Test-Path $Php)) { throw "No se encontro PHP en '$Php'." }

$consola = Join-Path $RaizIntranet "bin\console"
if (-not (Test-Path $consola)) { throw "No se encontro '$consola'." }

# ---- Alta de las tareas -----------------------------------------------------
function Nueva-Tarea {
    param(
        [string]$Nombre,
        [string]$Comando,
        [string]$Descripcion,
        $Disparador
    )

    if (Get-ScheduledTask -TaskName $Nombre -ErrorAction SilentlyContinue) {
        Write-Host "  La tarea '$Nombre' ya existe: se reemplaza." -ForegroundColor Yellow
        Unregister-ScheduledTask -TaskName $Nombre -Confirm:$false
    }

    # -WindowStyle Hidden evita que aparezca una consola cada minuto sobre lo
    # que el usuario este haciendo.
    $accion = New-ScheduledTaskAction -Execute $Php `
        -Argument "`"$consola`" $Comando --id=spui" `
        -WorkingDirectory $RaizIntranet

    # SYSTEM para que corra sin sesion iniciada. El comando solo toca la base
    # y el broker, no necesita perfil de usuario.
    $principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

    # StartWhenAvailable recupera las ejecuciones perdidas si la maquina estuvo
    # apagada. MultipleInstances IgnoreNew evita que dos pasadas se pisen si
    # una tarda mas de lo previsto.
    $opciones = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -StartWhenAvailable `
        -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

    # Sin -ErrorAction Stop, un fallo de Register-ScheduledTask solo escribe en
    # el stream de errores y el script sigue como si nada, informando exito.
    try {
        Register-ScheduledTask -TaskName $Nombre `
            -Action $accion `
            -Trigger $Disparador `
            -Principal $principal `
            -Settings $opciones `
            -Description $Descripcion `
            -ErrorAction Stop | Out-Null
    } catch {
        Write-Host "  ERROR registrando '$Nombre': $($_.Exception.Message)" -ForegroundColor Red
        throw
    }

    # Se relee del sistema en vez de confiar en que el comando no tiro error:
    # es la unica confirmacion de que la tarea existe de verdad.
    if (-not (Get-ScheduledTask -TaskName $Nombre -ErrorAction SilentlyContinue)) {
        throw "La tarea '$Nombre' no aparece registrada despues de crearla."
    }

    Write-Host "  Tarea '$Nombre' registrada." -ForegroundColor Green
}

Write-Host "Registrando tareas programadas..." -ForegroundColor Cyan

# Cada minuto, indefinidamente. El disparador arranca ahora mismo.
#
# Se OMITE -RepetitionDuration a proposito: su valor por defecto ya es
# "indefinidamente". Pasar [TimeSpan]::MaxValue produce P99999999DT23H59M59S,
# que el Programador de tareas rechaza por estar fuera de rango.
$cadaMinuto = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes 1)

Nueva-Tarea -Nombre "spui-mantenimiento" `
    -Comando "spui:mantenimiento" `
    -Descripcion "SPUI: marca reproductores caidos, desactiva alertas vencidas y purga telemetria cruda." `
    -Disparador $cadaMinuto

# Cada hora al minuto 5: la hora anterior ya cerro y sus lecturas estan completas.
$cadaHora = New-ScheduledTaskTrigger -Once -At (Get-Date).Date.AddMinutes(5) `
    -RepetitionInterval (New-TimeSpan -Hours 1)

Nueva-Tarea -Nombre "spui-rollup" `
    -Comando "spui:telemetria:rollup" `
    -Descripcion "SPUI: consolida la telemetria cruda en resumenes horarios." `
    -Disparador $cadaHora

Write-Host ""
Write-Host "  Listo. Verificar con:" -ForegroundColor DarkGray
Write-Host "    Get-ScheduledTask spui-* | Select-Object TaskName, State" -ForegroundColor DarkGray
Write-Host ""
Write-Host "  Forzar una ejecucion ahora:" -ForegroundColor DarkGray
Write-Host "    Start-ScheduledTask spui-mantenimiento" -ForegroundColor DarkGray
Write-Host ""
Write-Host "  Ver el resultado de la ultima corrida:" -ForegroundColor DarkGray
Write-Host "    Get-ScheduledTaskInfo spui-mantenimiento" -ForegroundColor DarkGray
