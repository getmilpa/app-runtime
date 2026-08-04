<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# milpa/app-runtime

**El runtime del agente que una app Milpa *instala*, en vez de copiar.**

Aquí viven las piezas que gobiernan lo que un agente puede hacer dentro de tu app: la compuerta que
decide si una llamada procede, la delegación a sub-agentes con su techo de autonomía, el presupuesto
del árbol, el vigía del bucle estéril y el puente que lleva lo que pasa a las superficies en vivo.

## Por qué existe este paquete

Porque estaba dentro de la plantilla, y eso significaba que **no llegaba a nadie**.

`milpa/framework` es `type: project`: cuando corres `composer create-project`, su `src/` se copia a tu
app y desde ese momento es tuyo. Perfecto para el plugin de ejemplo que vas a borrar; pésimo para el
runtime del agente, que mejora cada semana y que nadie edita nunca.

El síntoma que lo destapó, medido: una app creada un día antes **no recibía** los botones de la
pregunta de permiso, ni el indicador que late con cada hecho, ni `agent:board` — aunque actualizara
todo. Y el caso peor era el sutil: recibía la versión nueva de `milpa/live-tui`, que sabe pintar de
distinto color lo que dijo el sistema y lo que dijo el modelo, **y no veía ningún cambio**, porque su
copia de la pantalla no emitía los marcadores que disparan ese pintado.

> La regla que salió de ahí, y que este paquete aplica: **se copia lo que vas a editar; se instala lo
> que vas a usar.** Una plantilla que copia archivos que nadie va a tocar es un paquete disfrazado —
> con el costo de un paquete y ninguno de sus beneficios.

## Qué trae

| pieza | qué decide |
|---|---|
| `SessionToolGate` | si una llamada procede: permiso, contrato de intención, bucle estéril, orden |
| `SubAgentSpawner` | delegar a una sesión hija y retomarla — con contexto fresco, no re-delegando |
| `TreeBudget` | cuántos pasos gasta el ÁRBOL, no cada hijo: acotar al hijo no acota al árbol |
| `SterileLoopGuard` | no repetir una llamada que ya falló dos veces igual |
| `PrerequisiteGate` | una obligación de orden ejecutada: hasta que lo obligado corra, el resto no procede |
| `SessionOptionTable` | retirar una herramienta del catálogo de una sesión — prohibir, no pedir |
| `BroadcastingEventStore` · `SurfaceBroadcaster` · `MercureBroadcaster` | que lo que pasa llegue a las superficies mientras pasa |
| `SessionBookkeeping` · `SessionPlanBoard` | el plan y los pendientes de la sesión, atados a SU id |

Casi todas existen porque una medición dijo que hacían falta, no porque parecieran buena idea. Los
cierres viven en el monorepo (`docs/library/settlement-q-*.md`) y los docblocks citan cuál.

## Instalación

```bash
composer require milpa/app-runtime
```

Un host lo compone: este paquete no arranca nada por su cuenta y no conoce tu app. Recibe el almacén
de sesiones, el catálogo de operaciones y la credencial del modelo de quien lo construye — que es
quien tiene el kernel.

## Licencia

Apache-2.0 · © Rodrigo Vicente — TeamX Agency

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=app-runtime)**.
