# SPUI Pi Client -- Deploy desde Windows a la Raspberry Pi
#
# Copia el cliente a la Pi, lo reinstala y reinicia el servicio.
# Pensado para iterar rapido durante el desarrollo.
#
# Uso:
#   .\deploy.ps1 -PiHost 192.168.1.10
#   .\deploy.ps1 -PiHost 192.168.1.10 -PiUser mateo
#   .\deploy.ps1 -PiHost 192.168.1.10 -Logs        # queda mostrando el log al final
#
# Requiere: OpenSSH (viene con Windows 10/11) y acceso SSH a la Pi.
#
# NOTA: sin claves SSH configuradas, pide la contrasena 3 veces (scp + 2 ssh).
# Para evitarlo:  ssh-keygen -t ed25519    y luego copiar la clave publica
# al archivo ~/.ssh/authorized_keys de la Pi.

param(
    [Parameter(Mandatory = $true)]
    [string]$PiHost,

    [string]$PiUser = "mateo",

    # Deja mostrando journalctl -f al terminar
    [switch]$Logs,

    # Borra /opt/spui completo antes de instalar (incluido el venv).
    # Mas lento porque reinstala las dependencias, pero deja todo limpio.
    [switch]$Limpio
)

$ErrorActionPreference = "Stop"

$origen  = $PSScriptRoot
$destino = "${PiUser}@${PiHost}"

Write-Host "=== SPUI -- Deploy a $destino ===" -ForegroundColor Cyan
Write-Host "Origen: $origen"
Write-Host ""

# 1. Limpiar el destino antes de copiar.
#    Importante: "scp -r carpeta destino:~/carpeta" ANIDA la carpeta si el
#    destino ya existe (queda ~/pi-client/pi-client). Borrando primero se
#    evita ese problema y ademas quedan afuera archivos que se hayan borrado
#    del lado de Windows.
Write-Host "[1/4] Limpiando ~/pi-client en la Pi..." -ForegroundColor Yellow
ssh $destino "rm -rf ~/pi-client"
if ($LASTEXITCODE -ne 0) {
    throw "No se pudo conectar por SSH a $destino"
}

# 2. Copiar el cliente
Write-Host "[2/4] Copiando archivos..." -ForegroundColor Yellow
scp -r $origen "${destino}:~/pi-client"
if ($LASTEXITCODE -ne 0) {
    throw "Fallo la copia por scp"
}

# 3. Instalar
Write-Host "[3/4] Ejecutando install.sh en la Pi..." -ForegroundColor Yellow

$remoto = "cd ~/pi-client && chmod +x install.sh && ./install.sh"
if ($Limpio) {
    # El .env se conserva aparte: vive en /etc/spui, no en /opt/spui
    $remoto = "sudo rm -rf /opt/spui && $remoto"
}

ssh $destino $remoto
if ($LASTEXITCODE -ne 0) {
    throw "Fallo install.sh en la Pi"
}

# 4. Reiniciar el servicio
Write-Host "[4/4] Reiniciando el servicio spui..." -ForegroundColor Yellow
ssh $destino "sudo systemctl restart spui"

Write-Host ""
Write-Host "=== Deploy completo ===" -ForegroundColor Green
Write-Host ""
Write-Host "Ver los logs:" -ForegroundColor Cyan
Write-Host "  ssh $destino `"journalctl -u spui -f`""
Write-Host ""

if ($Logs) {
    Write-Host "Mostrando logs (Ctrl+C para salir)..." -ForegroundColor Cyan
    ssh $destino "journalctl -u spui -f"
}
