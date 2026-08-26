## Qué cambia

<!-- Una o dos oraciones: qué hace esta PR, no cómo lo hace (el diff ya muestra el cómo). -->

## Por qué

<!-- Qué problema resuelve o qué pedido atiende. Si viene de un hallazgo de auditoría, una tarea del backlog, o un bug reportado, decirlo acá. -->

## Cómo se probó

<!-- Comandos corridos, pantallas verificadas a mano, casos límite cubiertos.
     "Lo probé" sin detalle no alcanza — especificar qué y cómo. -->

## Checklist

- [ ] `php -l` / sintaxis limpia en los archivos tocados
- [ ] `php bin/console lint:container --id=spui` sin errores (si tocó DI/servicios)
- [ ] `php bin/console lint:twig apps/spui/templates --id=spui` sin errores (si tocó templates)
- [ ] Si tocó `docs/06-09` o `docs/README.md`: siguen reflejando el código real
- [ ] Si tocó algo del pi-client: no rompe el modo simulación (sin VLC/hardware real)
