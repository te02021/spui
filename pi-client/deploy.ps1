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
# NOTA: sin claves SSH configuradas, pide la contrasena 4 veces (3 ssh + scp).
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

# 4. Reiniciar el servicio y comprobar que de verdad arranco de nuevo.
#    Antes este paso no se verificaba: si el ssh fallaba (una contrasena mal
#    escrita alcanza), igual decia "Deploy completo" y quedaba el codigo nuevo
#    en disco con el proceso viejo corriendo. Paso el 19/10/2026: los tests
#    pasaban (leen el disco) y el reproductor seguia con el comportamiento
#    anterior. Se compara el PID de antes y de despues, no la hora de arranque,
#    porque la Pi no tiene reloj propio y al bootear arranca con una hora vieja.
#    Va todo en un solo ssh para no pedir la contrasena una vez mas, y en
#    base64: Windows PowerShell rompe las comillas dobles de un argumento que
#    le pasa a un .exe, y este script tiene varias.
Write-Host "[4/4] Reiniciando el servicio spui..." -ForegroundColor Yellow

# Que corre en la Pi: commit y fecha, y si habia cambios sin commitear.
$version = "sin git"
try {
    $commit = git -C $origen log -1 --format="%h %cd" --date=format:"%d/%m/%Y %H:%M"
    if ($LASTEXITCODE -eq 0 -and $commit) {
        $version = $commit
        if (git -C $origen status --porcelain -- .) { $version = "$version + cambios sin commitear" }
    }
} catch { }

$script = @(
    "echo '$version' | sudo tee /opt/spui/VERSION > /dev/null",
    'antes=$(systemctl show spui -p MainPID --value)',
    'sudo systemctl restart spui || exit 10',
    'sleep 3',
    'despues=$(systemctl show spui -p MainPID --value)',
    'echo "PID antes=$antes despues=$despues estado=$(systemctl is-active spui)"',
    '[ "$(systemctl is-active spui)" = active ] || exit 11',
    '[ "$despues" != "$antes" ] && [ "$despues" != 0 ] || exit 12',
    'journalctl -u spui -o cat --no-pager -n 50 | grep "SPUI Client iniciado" | tail -1'
) -join "`n"
$b64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($script))

ssh $destino "echo $b64 | base64 -d | bash"
switch ($LASTEXITCODE) {
    0  { }
    10 { throw "No se pudo reiniciar el servicio. El codigo nuevo quedo copiado, pero sigue corriendo el anterior." }
    11 { throw "El servicio no quedo activo despues del reinicio. Ver: ssh $destino `"journalctl -u spui -n 50`"" }
    12 { throw "El servicio no se reinicio (mismo PID): sigue corriendo el codigo anterior." }
    default { throw "No se pudo conectar por SSH para reiniciar el servicio: sigue corriendo el codigo anterior." }
}

Write-Host ""
Write-Host "=== Deploy completo: corre la version $version ===" -ForegroundColor Green
Write-Host ""
Write-Host "Ver los logs:" -ForegroundColor Cyan
Write-Host "  ssh $destino `"journalctl -u spui -f`""
Write-Host ""

if ($Logs) {
    Write-Host "Mostrando logs (Ctrl+C para salir)..." -ForegroundColor Cyan
    ssh $destino "journalctl -u spui -f"
}
