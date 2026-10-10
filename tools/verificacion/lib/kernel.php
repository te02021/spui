<?php
/**
 * Arranque compartido de la suite de verificación del CMS de SPUI.
 *
 * Levanta el kernel del monorepo con la app `spui` en entorno dev, igual que
 * `php bin/console --id=spui`, y ofrece helpers para los scripts de la suite.
 * Ninguno de estos scripts forma parte de la aplicación: se corren a mano o
 * desde verificar.ps1, nunca desde Apache.
 */

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

const SPUI_RAIZ_MONOREPO = __DIR__ . '/../../../../..';

require SPUI_RAIZ_MONOREPO . '/vendor/autoload.php';

// Las rutas relativas del monorepo (var/, config/) se resuelven desde la raíz.
chdir(SPUI_RAIZ_MONOREPO);
(new Dotenv())->bootEnv(SPUI_RAIZ_MONOREPO . '/.env');

/**
 * Kernel nuevo y booteado. Conviene uno por paso cuando se escribe y después
 * se lee: compartir el entity manager entre las dos cosas da falsos negativos.
 */
function spuiKernel(): Shared\Kernel
{
    $kernel = new Shared\Kernel('dev', true, 'spui');
    $kernel->boot();
    return $kernel;
}

/**
 * Usuario de la intranet con el que corre la suite. Por defecto el de Mateo;
 * se cambia con la variable de entorno SPUI_VERIFICACION_EMAIL.
 */
function spuiUsuarioVerificacion(Shared\Kernel $kernel): Shared\Entity\User
{
    $email = getenv('SPUI_VERIFICACION_EMAIL') ?: 'mateo.rivero@unraf.edu.ar';
    $user  = $kernel->getContainer()->get('doctrine')->getManager('Shared')
        ->getRepository(Shared\Entity\User::class)->findOneBy(['email' => $email]);

    if ($user === null) {
        fwrite(STDERR, "No existe el usuario $email en la base de la intranet.\n");
        exit(2);
    }
    $roles = $user->getRoles();
    if (!in_array('ROLE_SPUI', $roles, true) && !in_array('ROLE_SUPERADMIN', $roles, true)) {
        fwrite(STDERR, "El usuario $email no tiene ROLE_SPUI ni ROLE_SUPERADMIN.\n");
        exit(2);
    }
    return $user;
}

/** Token igual al que deja el login con Google (Passport → PostAuthenticationToken). */
function spuiTokenSerializado(Shared\Entity\User $user): string
{
    return serialize(new Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken(
        $user, 'main', $user->getRoles()
    ));
}
