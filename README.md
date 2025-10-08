=== WP Email Collector ===
Contributors: currenmexico
Tags: email, smtp, campaigns, cron, templates, preview
Requires at least: 5.8
Tested up to: 6.8.3
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin para gestionar **plantillas de email**, crear **campañas** (con cola y lotes por minuto), **vista previa** responsive y **SMTP** configurable (con soporte para `.env`).

== Descripción ==

**WP Email Collector** añade un panel "Email Manager" al admin de WordPress con:

- **Email Templates** (CPT `wec_email_tpl`) para editar tu HTML de campaña.
- **Vista previa** con modal responsive (Móvil 360 / Tablet 600 / Desktop 800 / Ancho libre) tanto en el **Editor de plantilla** como en **Campañas**.
- **Campañas** con dos modos de destinatarios:
  - **Escaneo** de todo el sitio (usuarios `wp_users` y emails de **comentarios aprobados**).
  - **Pegar correos** (uno por línea).
- **Lote por minuto** (`rate_per_minute`) para regular el envío por cron.
- **Cron**: usa `wp-cron` o un cron real del servidor.
- **SMTP** desde Ajustes del plugin o **.env** (si existe `programData/emailsWishList/.env`, tiene prioridad).

**Placeholders** disponibles dentro del HTML de plantilla:
- `{{site_name}}`, `{{site_url}}`, `{{admin_email}}`, `{{date}}`

**Tablas de base de datos**
- `{$wpdb->prefix}wec_jobs` — campañas (campos: `id`, `tpl_id`, `status`, `start_at`, `total`, `sent`, `failed`, `rate_per_minute`, `created_at`).
- `{$wpdb->prefix}wec_job_items` — elementos por campaña (campos: `id`, `job_id`, `email`, `status`, `attempts`, `error`).

**Capacidades / Seguridad**
- Todas las pantallas requieren `manage_options` (administradores).

== Instalación ==

1. Copia la carpeta del plugin en `wp-content/plugins/wp-email-collector/`.
2. Activa el plugin en **Plugins → Activar**.
3. Ve a **Email Manager → Email Templates** y crea tu plantilla HTML.
4. En **Email Manager → Campañas**, crea una campaña, elige plantilla, define destinatarios y (opcional) fecha de inicio.
5. Configura **SMTP** en **Email Manager → Config. SMTP** (o coloca un `.env`).

**.env (opcional)**
Crea el archivo en:
```
/path/a/tu/wordpress/programData/emailsWishList/.env
```
Contenido sugerido:
```
SMTP_HOST=smtp.tu-proveedor.com
SMTP_PORT=587
SMTP_USER=usuario
SMTP_PASS=contraseña
FROM_NAME=Relojes Curren México
FROM_EMAIL=ventas@tudominio.com
SMTP_USE_SSL=tls   ; tls|ssl| (vacío)
```

== Uso del cron ==

**Opción A: WP-Cron**  
Asegúrate de NO tener desactivado `wp-cron`:
```php
// en wp-config.php
define('DISABLE_WP_CRON', false);
```
Visitar tu sitio dispara tareas. También puedes abrir manualmente:
```
https://tudominio.com/wp-cron.php?doing_wp_cron
```

**Opción B: Cron real del servidor (recomendado)**  
- *Linux (cada minuto):*
```
* * * * * /usr/bin/php /ruta/a/wordpress/wp-cron.php >/dev/null 2>&1
```
- *cURL (alternativa):*
```
* * * * * /usr/bin/curl -s https://tudominio.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```
- *Windows (XAMPP, Programador de tareas):*
```
php "C:\xampp\htdocs\tu-sitio\wp-cron.php"
```

== Preguntas frecuentes ==

= No se envían correos y la campaña queda en “pending”. =
- Verifica que `wp-cron` esté activo o tengas un cron real.
- En **Config. SMTP** envía una prueba con otro plugin (WP Mail SMTP/Post SMTP) para confirmar credenciales.
- Revisa que existan destinatarios (si usas escaneo, que haya usuarios o comentarios con email).

= ¿Qué pasa si el campo “Inicio” lo dejo vacío? =
- La campaña se programa **inmediatamente**.

= ¿Para qué sirve “Lote por minuto”? =
- Controla cuántos correos se procesan por minuto (throttling). Útil para evitar límites del proveedor SMTP.

= ¿Qué estados existen? =
- Campaña: `pending` → `running` → `done`
- Item: `queued` → `sent` | `failed`

= ¿Se registran aperturas o clics? =
- No. Este plugin envía correos, no trackea eventos. Usa tu SMTP/ESP para métricas.

== Capturas ==
1. Editor de plantilla con vista previa.
2. Crear campaña (scan/pegar) + lote por minuto.
3. Campañas recientes (Editar/Eliminar).
4. Configuración SMTP con .env activo.

== Changelog ==

= 2.1.0 =
* Vista previa de plantilla en Editor de CPT y pantalla de Campañas.
* UI de Campañas con **Usar escaneo** / **Pegar correos** y textarea.
* Campo **Lote por minuto** + persistencia en DB (`rate_per_minute`).
* Soporte **.env** (`programData/emailsWishList/.env`) con prioridad sobre ajustes.
* Corrección: inline JS con **nowdoc** para evitar interpolación (`$frame`) en PHP.
* Tablas creadas/actualizadas vía `dbDelta`; añade columna `rate_per_minute` si falta.

== Notas de actualización ==

= 2.1.0 =
Actualiza y visita la pantalla del plugin para que se aplique la migración que añade `rate_per_minute`. Revisa tu configuración SMTP y el cron.

== Limitaciones ==
- Sin tracking de apertura/clic.
- Sin plantillas drag & drop (usa tu propio HTML).
- No elimina tablas al desinstalar (política de retención).

== Privacidad ==
Este plugin almacena emails de usuarios/comentarios y el estado de envío por campaña en tu base de datos. Asegúrate de tener una base legal para contactar a tus destinatarios (GDPR/leyes locales).

== Soporte ==
Uso interno. Si necesitas extenderlo (filtros, hooks o endpoints), puedes forkar el plugin y agregar tu propia lógica.
