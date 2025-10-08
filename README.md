# WP Email Collector

**WP Email Collector** es un plugin de WordPress para gestionar **plantillas de email**, crear **campañas** (con cola y lotes por minuto), **vista previa** responsive y **SMTP** configurable (con soporte para `.env`).

> ⚠️ WordPress.org parsea `readme.txt`. Este `README.md` es útil para GitHub o documentación interna.

---

## Características

- **Email Templates** (CPT `wec_email_tpl`) para editar tu HTML de campaña.
- **Vista previa** responsive (Móvil 360 / Tablet 600 / Desktop 800 / Ancho libre) en **Editor de plantilla** y **Campañas**.
- **Campañas** con dos modos de destinatarios:
  - **Escaneo** de todo el sitio (usuarios `wp_users` y emails de **comentarios aprobados**).
  - **Pegar correos** (uno por línea).
- **Lote por minuto** (`rate_per_minute`) para regular el envío por cron.
- **Cron**: usa `wp-cron` o un cron real del servidor.
- **SMTP** desde Ajustes del plugin o **.env** (si existe `programData/emailsWishList/.env`, tiene prioridad).
- Placeholders en HTML: `{{site_name}}`, `{{site_url}}`, `{{admin_email}}`, `{{date}}`.

---

## Requisitos

- WordPress **5.8+**
- PHP **7.4+**

---

## Instalación

1. Copia la carpeta del plugin en:  
   `wp-content/plugins/wp-email-collector/`
2. Activa en **Plugins → Activar**.
3. Crea una plantilla en **Email Manager → Email Templates**.
4. En **Email Manager → Campañas**, crea tu campaña.

---

## Configurar SMTP

### Opción A: Ajustes del plugin
En **Email Manager → Config. SMTP** completa:
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`
- `FROM_NAME`, `FROM_EMAIL`
- `SMTP_USE_SSL` (`tls`, `ssl` o vacío)

### Opción B: Archivo `.env` (prioritario)
Ruta:
```
/path/a/wordpress/programData/emailsWishList/.env
```
Contenido de ejemplo:
```
SMTP_HOST=smtp.tu-proveedor.com
SMTP_PORT=587
SMTP_USER=usuario
SMTP_PASS=contraseña
FROM_NAME=Relojes Curren México
FROM_EMAIL=ventas@tudominio.com
SMTP_USE_SSL=tls   # tls|ssl| (vacío)
```

---

## Cron

### WP-Cron (rápido)
En `wp-config.php`:
```php
define('DISABLE_WP_CRON', false);
```
Forzar ejecución:
```
https://tudominio.com/wp-cron.php?doing_wp_cron
```

### Cron real (recomendado)
**Linux (cada minuto)**
```
* * * * * /usr/bin/php /ruta/a/wordpress/wp-cron.php >/dev/null 2>&1
```

**Windows (XAMPP, Programador de tareas)**
```
php "C:\xampp\htdocs\tu-sitio\wp-cron.php"
```

---

## Uso rápido

1. Crea/edita la plantilla HTML.
2. En Campañas:
   - Elige plantilla y **Vista previa** si deseas.
   - Destinatarios: **Escaneo** o **Pegar** (uno por línea).
   - Opcional: **Inicio**. Si lo dejas vacío, arranca **inmediato**.
   - Define **Lote por minuto** (p. ej., 60–120).
3. Guarda y verifica el avance en “Campañas recientes”.

---

## Tablas de BD

- `wp_wec_jobs`: `id`, `tpl_id`, `status`, `start_at`, `total`, `sent`, `failed`, `rate_per_minute`, `created_at`
- `wp_wec_job_items`: `id`, `job_id`, `email`, `status`, `attempts`, `error`

---

## FAQ

**La campaña queda en “pending”.**  
Verifica cron (WP-Cron activo o cron real), SMTP correcto y que existan destinatarios válidos.

**¿Qué hace “Lote por minuto”?**  
Limita cuántos correos se procesan en cada pasada del cron; evita límites del proveedor SMTP.

**¿Traquea aperturas/clics?**  
No. Este plugin **no** trackea. Usa tu ESP/SMTP para métricas.

---

## Changelog

**2.1.0**
- Vista previa en Editor de plantilla y Campañas.
- UI de Campañas con “Escaneo / Pegar correos” + textarea.
- Campo **Lote por minuto** (persistente en DB).
- Soporte `.env` con prioridad sobre ajustes.
- Fix: nowdoc en inline JS para evitar conflicto de variables PHP.

---

## Licencia

GPLv2 o posterior.
