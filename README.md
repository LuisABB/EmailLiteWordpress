# WP Email Collector

**WP Email Collector** es un plugin de WordPress para gestionar **plantillas de email**, crear **campañas** (con cola y lotes por minuto), **vista previa** responsive y **SMTP** configurable (con soporte para `.env`).

> ⚠️ WordPress.org parsea `readme.txt`. Este `README.md` es útil para GitHub o documentación interna.

---

## Características principales

- **Email Templates** (`wec_email_tpl`) para diseñar HTML personalizados.
- **Vista previa responsive** (Móvil / Tablet / Desktop / Ancho libre).
- **Campañas automáticas** con dos modos:
  - Escaneo del sitio (usuarios y comentarios aprobados).
  - Pegado manual de correos (uno por línea).
- **Cola de envío** con “Lote por minuto” (`rate_per_minute`).
- **Envío mediante WP-Cron o cron real**.
- **Configuración SMTP** interna o mediante `.env` externo.
- **Sistema de suscripciones / desuscripciones automáticas:**
  - Tabla `wp_wec_subscribers`
  - Cada correo incluye `[[UNSUB_URL]]` (enlace único)
  - Los desuscritos (`status = unsubscribed`) **ya no reciben más correos**
- Placeholders disponibles:  
  `{{site_name}}`, `{{site_url}}`, `{{admin_email}}`, `{{date}}`

---

## Requisitos

- WordPress **5.8+**
- PHP **7.4+**

---

## Instalación

1. Copia la carpeta del plugin en  
   `wp-content/plugins/wp-email-collector/`
2. Activa el plugin en **Plugins → Activar**.
3. Crea una plantilla desde  
   **Email Manager → Email Templates**.
4. Crea una campaña desde  
   **Email Manager → Campañas**.

---

## Configuración SMTP

### Opción A. Panel del plugin

En **Email Manager → Config. SMTP** rellena:
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`
- `FROM_NAME`, `FROM_EMAIL`
- `SMTP_USE_SSL` (`tls`, `ssl` o vacío)

### Opción B. Archivo `.env` (prioritario)
Ruta esperada:
```
/path/a/wordpress/programData/emailsWishList/.env
```
Ejemplo de contenido:
```
SMTP_HOST=smtp.tu-proveedor.com
SMTP_PORT=587
SMTP_USER=usuario
SMTP_PASS=contraseña
FROM_NAME=Relojes Curren México
FROM_EMAIL=ventas@tudominio.com
SMTP_USE_SSL=tls
```

---

## Cron

### WP-Cron
Asegúrate de tener activo:
```php
define('DISABLE_WP_CRON', false);
```

Ejecutar manualmente:
```
https://tudominio.com/wp-cron.php?doing_wp_cron
```

### Cron real (recomendado)
Linux:
```
* * * * * /usr/bin/php /ruta/a/wordpress/wp-cron.php >/dev/null 2>&1
```

Windows (XAMPP):
```
php "C:\xampp\htdocs\tu-sitio\wp-cron.php"
```

---

## Base de datos

| Tabla | Descripción |
|-------|--------------|
| `wp_wec_jobs` | Información general de campañas |
| `wp_wec_job_items` | Correos individuales de cada campaña |
| `wp_wec_subscribers` | Correos suscritos o dados de baja |

---

## Ejemplo de flujo

1. Crea una plantilla HTML con el shortcode `[[UNSUB_URL]]` al final.  
2. Crea una campaña desde **Email Manager**.  
3. Si el destinatario se da de baja, su `status` cambia a `unsubscribed` y el sistema lo excluye automáticamente en futuras campañas.

---

## Licencia

GPLv2 o posterior.  
© Curren México
