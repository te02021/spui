<?php

declare(strict_types=1);

namespace SPUI\EventSubscriber;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fija la zona horaria de PHP para SPUI.
 *
 * PHP corre en UTC por defecto (no hay date.timezone en el php.ini de WAMP),
 * pero la universidad está en UTC-3. Sin esto, `new DateTimeImmutable()` da la
 * hora UTC y toda comparación contra una fecha cargada por el operador se
 * desfasa 3 horas: una alerta que expiraba 11:05 se comparaba contra las 14:0x
 * y aparecía vencida apenas se creaba.
 *
 * Se hace acá y no en php.ini para que viaje con el proyecto (el servidor de
 * producción no necesita configuración manual), y no en el Kernel porque ese
 * es compartido con el resto de las apps de la intranet (viáticos, sgp, etc.)
 * y no corresponde cambiarles el comportamiento desde SPUI.
 *
 * Cubre los dos puntos de entrada: peticiones HTTP del CMS/API y los comandos
 * de consola (spui:mantenimiento, spui:mqtt:subscribe), que no disparan
 * KernelEvents::REQUEST y también comparan fechas.
 */
final class TimezoneSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%env(default:spui_timezone_default:SPUI_TIMEZONE)%')]
        private readonly string $timezone = '',
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Prioridad máxima: cualquier otro listener que lea la hora ya la
            // tiene que ver en la zona correcta.
            KernelEvents::REQUEST  => ['onKernelRequest', 10000],
            ConsoleEvents::COMMAND => ['onConsoleCommand', 10000],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $this->aplicar();
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->aplicar();
    }

    private function aplicar(): void
    {
        if ($this->timezone !== '') {
            date_default_timezone_set($this->timezone);
        }
    }
}
