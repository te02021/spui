# =============================================================================
# SPUI - Genera la CA propia y el certificado del broker para TLS (tarea 1.0.c)
# =============================================================================
#
# Decision tomada (ver plan de comunicacion, 2026-08-12): CA propia, CN por
# hostname en vez de IP, vigencia de 10 anios para no tener que renovar nada
# durante el proyecto.
#
# Por que CN por hostname y no por IP: la IP del host cambia por DHCP (ver
# docs/README.md #4). Si el certificado tuviera la IP en el CN, cambiar de red
# obligaria a reemitirlo. Con un hostname fijo (spui-broker.unraf.local) que
# cada Pi resuelve via su /etc/hosts, cambiar de IP es editar una linea en
# cada Pi, no tocar el certificado.
#
# Que genera (en certs/, junto a este script):
#   ca.key      - clave privada de la CA. NUNCA se distribuye. NUNCA a git.
#   ca.crt      - certificado publico de la CA. Se copia a cada Pi.
#   server.key  - clave privada del broker. Se queda en el servidor. NUNCA a git.
#   server.crt  - certificado del broker, firmado por la CA de arriba.
#
# Uso:
#   .\generar-certificados.ps1
#   .\generar-certificados.ps1 -Hostname spui-broker.unraf.local -Ip 10.0.24.103
#
# -Ip es opcional: agrega esa IP como SAN adicional, util para probar con
# clientes que todavia conectan por IP en vez del hostname. No hace falta
# regenerar el certificado si la IP cambia despues, siempre que los clientes
# usen el hostname (que es el objetivo).
#
# Requiere OpenSSL en el PATH (viene con Git for Windows: git.exe trae
# openssl.exe en Git\usr\bin).
# =============================================================================

param(
    [string]$Hostname = "spui-broker.unraf.local",
    [string]$Ip = "",
    [int]$DiasVigencia = 3650,
    [string]$DirCerts = (Join-Path $PSScriptRoot "certs")
)

$ErrorActionPreference = "Stop"

# PowerShell no hereda el PATH de Git Bash: openssl.exe viene instalado con
# Git for Windows (Git\usr\bin) pero esa carpeta rara vez esta en el PATH de
# PowerShell, aunque si lo este en el de bash. Se prueba el PATH primero y,
# si falta, las ubicaciones tipicas de Git for Windows antes de rendirse.
$openssl = (Get-Command openssl -ErrorAction SilentlyContinue).Source
if (-not $openssl) {
    $candidatos = @(
        "$env:ProgramFiles\Git\usr\bin\openssl.exe",
        "${env:ProgramFiles(x86)}\Git\usr\bin\openssl.exe"
    )
    $openssl = $candidatos | Where-Object { Test-Path $_ } | Select-Object -First 1
}
if (-not $openssl) {
    throw "No se encontro openssl.exe (ni en el PATH ni en Git for Windows). Instalar Git for Windows o 'choco install openssl'."
}
Write-Host "  openssl: $openssl" -ForegroundColor DarkGray

New-Item -ItemType Directory -Force $DirCerts | Out-Null

$caKey      = Join-Path $DirCerts "ca.key"
$caCrt      = Join-Path $DirCerts "ca.crt"
$serverKey  = Join-Path $DirCerts "server.key"
$serverCsr  = Join-Path $DirCerts "server.csr"
$serverCrt  = Join-Path $DirCerts "server.crt"
$sanConf    = Join-Path $DirCerts "san.cnf"

if ((Test-Path $caCrt) -or (Test-Path $serverCrt)) {
    Write-Host "Ya existen certificados en '$DirCerts'." -ForegroundColor Yellow
    $resp = Read-Host "Sobreescribir? Esto invalida los certificados que ya tengan las Pis instaladas (s/N)"
    if ($resp -ne "s" -and $resp -ne "S") {
        Write-Host "Cancelado." -ForegroundColor Yellow
        exit 0
    }
}

# $ErrorActionPreference=Stop convierte CUALQUIER linea de stderr de un exe
# nativo en un error terminante, aunque sea informativa (openssl reporta
# "Certificate request self-signature ok" por stderr y no es un fallo). Por
# eso estas llamadas van con su propio manejo de errores por codigo de
# salida, no por excepcion.
function Invoke-Openssl {
    param([string[]]$OpenSslArgs, [string]$Paso)
    $prevEap = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    & $openssl @OpenSslArgs 2>&1 | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkGray }
    $ErrorActionPreference = $prevEap
    if ($LASTEXITCODE -ne 0) {
        throw "openssl fallo en '$Paso' (codigo $LASTEXITCODE)."
    }
}

Write-Host "1/4 - Generando CA propia (vigencia $DiasVigencia dias)..." -ForegroundColor Cyan
Invoke-Openssl -Paso "generar clave de CA" -OpenSslArgs @("genrsa", "-out", $caKey, "4096")
Invoke-Openssl -Paso "generar certificado de CA" -OpenSslArgs @(
    "req", "-x509", "-new", "-nodes", "-key", $caKey, "-sha256", "-days", $DiasVigencia,
    "-out", $caCrt, "-subj", "/CN=SPUI-CA/O=UNRaf/OU=SPUI"
)

Write-Host "2/4 - Generando clave y CSR del broker ($Hostname)..." -ForegroundColor Cyan
Invoke-Openssl -Paso "generar clave del broker" -OpenSslArgs @("genrsa", "-out", $serverKey, "2048")

# SAN (Subject Alternative Name): DNS por hostname siempre, IP solo si se
# paso -Ip. openssl exige un archivo de config para SAN en vez de un flag
# suelto en la version que trae Git for Windows.
$sanLines = @("[req]", "distinguished_name = dn", "req_extensions = ext", "prompt = no", "", "[dn]", "CN = $Hostname", "", "[ext]", "subjectAltName = @alt")
$altLines = @("[alt]", "DNS.1 = $Hostname")
if ($Ip -ne "") {
    $altLines += "IP.1 = $Ip"
}
($sanLines + $altLines) | Set-Content -Path $sanConf -Encoding ASCII

Invoke-Openssl -Paso "generar CSR" -OpenSslArgs @("req", "-new", "-key", $serverKey, "-out", $serverCsr, "-config", $sanConf)

Write-Host "3/4 - Firmando el certificado del broker con la CA..." -ForegroundColor Cyan
# -extfile de nuevo para que el SAN sobreviva a la firma: sin esto, el
# certificado firmado pierde el subjectAltName aunque el CSR lo tuviera, y
# los clientes que validan el hostname (no solo el CN) rechazan la conexion.
Invoke-Openssl -Paso "firmar certificado" -OpenSslArgs @(
    "x509", "-req", "-in", $serverCsr, "-CA", $caCrt, "-CAkey", $caKey, "-CAcreateserial",
    "-out", $serverCrt, "-days", $DiasVigencia, "-sha256", "-extfile", $sanConf, "-extensions", "ext"
)

Write-Host "4/4 - Verificando..." -ForegroundColor Cyan
Invoke-Openssl -Paso "verificar cadena de confianza" -OpenSslArgs @("verify", "-CAfile", $caCrt, $serverCrt)

Remove-Item $serverCsr, $sanConf -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "  Listo. Archivos en $DirCerts" -ForegroundColor Green
Write-Host "    ca.crt      -> copiar a cada Pi (pi-client/config.py la busca via MQTT_CA_CERT)" -ForegroundColor DarkGray
Write-Host "    server.crt  -> queda en este servidor, lo usa mosquitto.conf" -ForegroundColor DarkGray
Write-Host "    server.key  -> queda en este servidor, NUNCA se distribuye" -ForegroundColor DarkGray
Write-Host "    ca.key      -> guardar aparte, NUNCA se distribuye. Sin ella no se pueden emitir mas certificados." -ForegroundColor Yellow
Write-Host ""
Write-Host "  Cada Pi necesita '$Hostname' resuelto en su /etc/hosts (ver docs/09_instalacion_raspberry.md)." -ForegroundColor DarkGray
