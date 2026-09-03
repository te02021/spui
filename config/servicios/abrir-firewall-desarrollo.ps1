<#
.SYNOPSIS
    Abre el puerto 80 del firewall de Windows a la red local, para probar en
    desarrollo desde otro dispositivo (el celular que escanea un QR).

.DESCRIPTION
    El firewall de Windows descarta las conexiones entrantes al puerto 80 que
    no vengan de un rango explicitamente permitido. Sin esto, un celular que
    escanea un QR ni siquiera llega a Apache: el navegador muestra
    ERR_ADDRESS_UNREACHABLE, que parece un problema de red y no lo es.

    La subred NO se escribe a mano: se deduce de la interfaz que tiene la ruta
    por defecto, con su mascara real. Escribirla de memoria es como se llega a
    abrir el rango equivocado -- esta wifi es una /19 (10.0.0.0 - 10.0.31.255)
    aunque la PC caiga en 10.0.31.x, asi que un /24 deja afuera a los celulares
    que reciben 10.0.1.x.

    OJO CON EL ALCANCE: el bloque <Directory> del vhost de Apache lo comparten
    todas las apps del monolito, asi que junto con su "Require ip" esto deja
    alcanzable desde toda la red el login de viaticos, sgp y sistemasat ademas
    de SPUI. Todo sigue detras del OAuth de Google, pero es configuracion de
    DESARROLLO: conviene sacarla al terminar de probar (-Quitar).

.PARAMETER Simular
    Muestra la subred detectada y lo que haria, sin tocar el firewall.
    No necesita permisos de administrador.

.PARAMETER Quitar
    Elimina la regla creada por este script.

.EXAMPLE
    .\abrir-firewall-desarrollo.ps1 -Simular
    .\abrir-firewall-desarrollo.ps1
    .\abrir-firewall-desarrollo.ps1 -Quitar

.NOTES
    Salvo -Simular, hay que correrlo en una PowerShell COMO ADMINISTRADOR.
#>
[CmdletBinding()]
param(
    [switch] $Simular,
    [switch] $Quitar
)

$ErrorActionPreference = 'Stop'
$NOMBRE_REGLA = 'SPUI - Apache desde la red local (desarrollo)'
$PUERTO       = 80

function Get-SubredLocal {
    # La interfaz buena es la que tiene la ruta por defecto: asi se descartan
    # solas la de loopback y la del adaptador host-only de VirtualBox.
    $ruta = Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' |
            Sort-Object RouteMetric |
            Select-Object -First 1
    if (-not $ruta) { throw 'No hay ruta por defecto: el equipo no esta en ninguna red.' }

    $dir = Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $ruta.InterfaceIndex |
           Where-Object { $_.IPAddress -notlike '169.254.*' } |
           Select-Object -First 1
    if (-not $dir) { throw 'La interfaz con ruta por defecto no tiene una IPv4 utilizable.' }

    # Direccion de red = IP AND mascara, en aritmetica de 32 bits.
    $bytes = ([System.Net.IPAddress]::Parse($dir.IPAddress)).GetAddressBytes()
    [Array]::Reverse($bytes)
    $addr   = [BitConverter]::ToUInt32($bytes, 0)
    $prefix = [int] $dir.PrefixLength
    $mask   = [uint32] ((([uint64]1 -shl 32) - ([uint64]1 -shl (32 - $prefix))))
    $red    = [uint32] ($addr -band $mask)

    $nb = [BitConverter]::GetBytes($red)
    [Array]::Reverse($nb)

    [pscustomobject]@{
        Interfaz = $dir.InterfaceAlias
        Ip       = $dir.IPAddress
        Prefijo  = $prefix
        Subred   = '{0}/{1}' -f ([System.Net.IPAddress] $nb).IPAddressToString, $prefix
    }
}

function Test-Administrador {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    (New-Object Security.Principal.WindowsPrincipal $id).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

# ── Quitar ──────────────────────────────────────────────────────────────────
if ($Quitar) {
    if (-not (Test-Administrador)) {
        Write-Warning 'Abri PowerShell como administrador para poder quitar la regla.'
        exit 1
    }
    $regla = Get-NetFirewallRule -DisplayName $NOMBRE_REGLA -ErrorAction SilentlyContinue
    if (-not $regla) {
        Write-Host 'La regla no existe, no hay nada que quitar.' -ForegroundColor Yellow
        exit 0
    }
    $regla | Remove-NetFirewallRule
    Write-Host 'Regla eliminada. El puerto 80 vuelve a estar cerrado para la red.' -ForegroundColor Green
    exit 0
}

# ── Detectar y mostrar ──────────────────────────────────────────────────────
$red = Get-SubredLocal

Write-Host ''
Write-Host 'Red detectada' -ForegroundColor Cyan
Write-Host ('  Interfaz : {0}' -f $red.Interfaz)
Write-Host ('  IP de la PC : {0}/{1}' -f $red.Ip, $red.Prefijo)
Write-Host ('  Subred a permitir : {0}' -f $red.Subred)
Write-Host ''
Write-Host ('Esa subred tiene que incluir la IP del celular. Si no la incluye, ' +
            'el celular esta en otra red y no hay nada que abrir aca.') -ForegroundColor DarkGray
Write-Host ''

if ($Simular) {
    Write-Host 'Modo -Simular: no se toco el firewall.' -ForegroundColor Yellow
    Write-Host ('Se crearia la regla "{0}" (TCP {1} entrante desde {2}).' -f $NOMBRE_REGLA, $PUERTO, $red.Subred)
    exit 0
}

if (-not (Test-Administrador)) {
    Write-Warning 'Abri PowerShell como administrador: crear una regla de firewall lo necesita.'
    exit 1
}

# ── Crear (idempotente) ─────────────────────────────────────────────────────
$existente = Get-NetFirewallRule -DisplayName $NOMBRE_REGLA -ErrorAction SilentlyContinue
if ($existente) {
    # Si ya estaba, se le corrige la subred en vez de duplicar la regla: al
    # cambiar de wifi la vieja queda apuntando a un rango que ya no aplica.
    $existente | Set-NetFirewallRule -RemoteAddress $red.Subred -Enabled True
    Write-Host ('Regla ya existente, actualizada a {0}.' -f $red.Subred) -ForegroundColor Green
} else {
    $parametros = @{
        DisplayName   = $NOMBRE_REGLA
        Description   = 'Solo para desarrollo. Ver apps/spui/docs/README.md seccion 3.3.'
        Direction     = 'Inbound'
        Protocol      = 'TCP'
        LocalPort     = $PUERTO
        RemoteAddress = $red.Subred
        Action        = 'Allow'
        Profile       = 'Any'
    }
    New-NetFirewallRule @parametros | Out-Null
    Write-Host ('Regla creada: TCP {0} entrante desde {1}.' -f $PUERTO, $red.Subred) -ForegroundColor Green
}

Write-Host ''
Write-Host 'Falta todavia, del lado de Apache:' -ForegroundColor Cyan
Write-Host ('  Require ip {0}' -f $red.Subred)
Write-Host '  dentro del <Directory> de "c:/wamp64/www/intranet/public/" en'
Write-Host '  conf\extra\httpd-vhosts.conf, y reiniciar WAMP.'
Write-Host ''
Write-Host 'Al terminar de probar: .\abrir-firewall-desarrollo.ps1 -Quitar' -ForegroundColor DarkGray
