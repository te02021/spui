# Contribuir a SPUI

Guía de flujo de trabajo del repositorio. No es documentación del sistema — para eso, empezar por `docs/README.md`.

## Ramas

```
main                    ← sólo recibe PRs desde dev. Representa el estado auditado y estable.
  └── dev               ← integración del día a día. Sólo recibe PRs desde ramas de trabajo.
        ├── feature/...
        ├── fix/...
        ├── chore/...
        └── docs/...
```

- **`main`**: protegida (ruleset de GitHub — PR obligatoria, sin force-push, sin borrado). Sólo se actualiza con una PR desde `dev`, típicamente cuando una tanda de trabajo pasó por una revisión seria (una auditoría, un repaso completo), no por calendario.
- **`dev`**: misma protección. Acumula el trabajo de a una tarea por vez, vía PR desde ramas de trabajo.
- **Ramas de trabajo**: una por tarea, no una por sesión. Prefijo según el tipo de cambio:
  - `feature/` — algo nuevo (`feature/alerta-sonora`)
  - `fix/` — corrige algo roto (`fix/audio-object-url-revoke`)
  - `chore/` — mantenimiento sin cambio de comportamiento (`chore/csrf-protegido-trait`)
  - `docs/` — sólo documentación

  kebab-case descriptivo después del prefijo. Un cambio de una línea puede ir directo a `dev` sin esta ceremonia; todo lo que constituye una unidad revisable, no.

Se mergea con **squash** — un commit limpio por PR, no el historial de WIP intermedios.

## Por qué no hay "Require approvals"

Con un solo colaborador, exigir aprobación en el ruleset te bloquea a vos mismo (GitHub no deja aprobar la propia PR). La revisión existe igual — la hace el agente de código antes de abrir o mergear la PR — sólo que el "aprobado" lo decide quien mergea, no un checkbox de GitHub.

## CI

Todavía no hay ningún workflow configurado. Este repo (`apps/spui`) no tiene `composer.json` propio: depende enteramente del monorepo Intranet (vendor/, kernel, config compartida) para correr, así que un CI acá sólo podría hacer chequeos de sintaxis hasta que exista alguna forma de traer ese contexto. Se retoma cuando haga falta, no es parte de esta ronda de reestructuración.

## Documentación (`*.md`)

Por default, ningún `.md` se trackea (ver `.gitignore`) — son notas de trabajo, contexto de sesión, borradores. La excepción es la documentación con autoridad verificada contra código:

- `docs/06_funcionamiento_del_sistema.md`
- `docs/07_manual_uso_cms.md`
- `docs/08_pendientes_vm.md`
- `docs/09_instalacion_raspberry.md`
- `docs/README.md`

Esos cinco sí se trackean porque se auditaron contra el código real (ver `docs/README.md` §1 para el criterio y el proceso de mantenerlos al día). Si un cambio de código vuelve desactualizado a alguno de estos, corregirlo es parte del mismo PR — no un TODO para después.

## CMS y pi-client, un solo repo

`src/`, `templates/`, `config/` (el CMS) y `pi-client/` (el cliente Python de la Raspberry) conviven en el mismo repositorio y el mismo historial — no hay planes de partirlo en dos. Para clonar sólo el cliente en otro lado (por ejemplo, directo en una Raspberry Pi) sin traer el CMS, ver `pi-client/README.md`.
