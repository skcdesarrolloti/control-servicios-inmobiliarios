# Control de Servicios Inmobiliarios

## Tareas operativas de auditoría

La lectura del panel de auditoría no ejecuta migraciones ni sincronizaciones.
Ejecuta estos comandos durante el despliegue y desde cron/cola, respectivamente:

```bash
php bin/migrate-canon-insurance-audit.php
php bin/migrate-collection-management.php
php bin/sync-canon-increment-changes.php
php bin/warm-dashboard-performance.php filters
php bin/warm-dashboard-performance.php metrics
php bin/warm-dashboard-performance.php maintenance
```

Para que la primera visita y la primera apertura de Mantenimiento siempre usen cache caliente, ejecutar estos comandos desde cron cada 5 minutos. Las vistas filtradas y las paginas siguientes continúan consultando los datos en tiempo real.

Aplicación PHP para administrar tickets, mantenimientos, PQR, revisiones preventivas y notificaciones de servicios inmobiliarios.

## Requisitos

- PHP 8.2 o superior.
- Extensiones `pdo_mysql`, `json`, `fileinfo` y `mbstring`.
- Apache con `mod_rewrite` y `mod_headers`, o reglas equivalentes en el servidor web.
- MySQL/MariaDB y acceso al esquema existente de la aplicación.
- Composer para instalar herramientas de desarrollo.

## Instalación

1. Instala las dependencias:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. Copia `.env.example` como `.env` y completa las variables. `APP_SECRET` debe ser aleatorio y tener al menos 32 caracteres.

3. Configura el *document root* del sitio apuntando a la carpeta `public/`, nunca a la raíz del repositorio.

4. Concede al usuario de PHP permisos de escritura únicamente sobre:

   ```text
   storage/data
   storage/logs
   storage/uploads
   ```

5. Antes de habilitar el panel nuevo, importa el JSON anterior en la tabla central de configuración:

   ```bash
   php bin/migrate-settings-to-database.php /ruta/al/proyecto/anterior/data/settings.json
   ```

   El comando es idempotente, conserva el JSON de origen y guarda un documento versionado en la fila `control_servicios_config` de `wp_jet_cct_confi_sistema`.

6. Si reemplazas una instalación anterior, migra los archivos sin sobrescribir los que ya existan:

   ```bash
   php bin/migrate-legacy-storage.php /ruta/al/proyecto/anterior
   ```

7. Programa el worker de notificaciones desde CLI, por ejemplo cada minuto:

   ```bash
   php bin/queue-worker.php 40
   ```

8. Verifica las tablas del control de cartera antes de habilitar la pestaña Gestiones de cobro:

   ```bash
   php bin/migrate-collection-management.php
   ```

   El comando es idempotente. El auxiliar 1380 se carga después desde el panel; no se importa ningún saldo durante el despliegue.

La integración de notificaciones compartidas se localiza mediante `SHARED_NOTIFICATIONS_PATH`. Si está vacía, se intenta usar el proyecto hermano histórico.

## Estructura

```text
bin/                    comandos CLI y migración
bootstrap/              arranque de la aplicación
config/                 configuración basada en entorno
public/                 única raíz pública y assets del navegador
resources/emails/       plantillas de correo
src/
  App/                  fachada y orquestación del panel
  Core/                 autenticación, sesión, base de datos y configuración
  Http/                 controladores, router y respuestas HTTP
  Modules/              casos de uso por dominio
  Repositories/         consultas y enriquecimiento de datos
  Support/              archivos, colas y utilidades
  Views/                 renderizado de interfaces
storage/                datos variables; no se versionan
tests/                  pruebas automatizadas
tools/                  verificaciones de desarrollo
```

Las clases fachada grandes se conservaron para mantener compatibilidad con la base y los flujos existentes, pero su comportamiento está separado en `Concerns` cohesivos. Esto reduce el riesgo de una reescritura total y deja límites claros para migrar gradualmente a servicios independientes.

## Seguridad y operación

- No se guardan credenciales, secretos, datos, logs ni adjuntos en Git.
- Las contraseñas con hash de PHP se validan con `password_verify()`. `AUTH_ALLOW_LEGACY_PASSWORDS=true` permite validar las contraseñas antiguas en texto plano sin modificar el valor almacenado, para conservar la compatibilidad con las demás aplicaciones que comparten la tabla de funcionarios.
- Los adjuntos nuevos se validan por MIME/tamaño, se guardan fuera de `public/` y se sirven con firma HMAC.
- La ruta `/uploads/*` existe solo para compatibilidad con enlaces históricos. Los adjuntos nuevos no deben usarla.
- El login tiene límite de intentos y las operaciones autenticadas usan CSRF.
- `SESSION_IDLE_TIMEOUT` define la expiración por inactividad en segundos (valor predeterminado: `7200`, equivalente a 2 horas). Mientras el usuario está activo, el panel verifica la sesión cada 4 minutos; al vencer, bloquea la interfaz, conserva borradores de formularios y redirige al login.
- La configuración funcional vive como JSON en `wp_jet_cct_confi_sistema`; solo las acciones autenticadas del panel escriben en ella. El bot es consumidor de solo lectura.
- Rota las credenciales y secretos utilizados por cualquier despliegue anterior antes de publicar esta versión.

## Verificación

```bash
composer check
```

El comando ejecuta:

- sintaxis de todos los archivos PHP del proyecto;
- PHPStan nivel 5 sobre el núcleo y la infraestructura nueva tipada;
- PHPUnit para tokens firmados, rate limiting, entorno y persistencia SQL de configuración.

Los assets JavaScript también pueden validarse con `node --check public/assets/js/<archivo>.js`.

## Despliegue

Para el formulario nativo de revisión y configuración de servicios públicos, consultar [flujo y checklist de producción](docs/REVISION-SERVICIOS-PUBLICOS.md) y ejecutar `php bin/check-public-services-review.php` (solo lectura, no envía correos).

No copies `.env` desde otro entorno sin revisar sus valores. Conserva `storage/` entre versiones y despliega el código de forma atómica. La migración de configuración debe ejecutarse antes de dirigir tráfico al panel nuevo. Después del despliegue ejecuta `composer install --no-dev --optimize-autoloader` y verifica que el worker CLI pueda iniciar.

El inventario completo de cambios y deuda restante está en [docs/REFACTORIZACION.md](docs/REFACTORIZACION.md).
