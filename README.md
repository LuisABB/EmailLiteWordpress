# WP Email Collector

**WP Email Collector** es un plugin de WordPress para gestionar **plantillas de email**, crear **campañas** (con cola y lotes por minuto), **vista previa** responsive y limpieza de correos falsos. Es compatible con plugins SMTP como WP Mail SMTP, pero no incluye configuración SMTP.

---

## Arquitectura del Plugin (v9.0+)

El plugin está organizado en **managers especializados** para máxima mantenibilidad:

### 📧 **WEC_Campaign_Manager**
- Gestión completa de campañas
- Procesamiento de cola y envíos masivos
- Segmentación por categorías
- Cron interno y externo
- Estados de campaña y monitoreo

### 🏷️ **WEC_Category_Manager** ✨ NUEVO en v9.0
- Sistema de categorías de suscriptores
- Segmentación para campañas específicas
- Gestión visual con color picker
- Asignación múltiple de categorías
- Consulta el archivo `CATEGORIES_SETUP.md` para guía completa

### 📤 **WEC_SMTP_Manager**
- Envío de emails de prueba
- Fallbacks robustos

### 📄 **WEC_Template_Manager**
- Custom Post Type para plantillas
- Vista previa responsive
- Sistema de variables dinámicas
- Validación de contenido

### 🧹 **Limpieza de Correos Falsos**
- Validación y limpieza de emails inválidos o falsos
- Integración con la API de EmailListVerify
- Panel de administración para gestionar y limpiar correos
- Consulta el archivo `EMAIL_CLEANER_SETUP.md` para instrucciones de configuración

### 🔧 **WEC_Email_Collector** (Core)
- Orquestación de managers
- Autoloader de clases
- Hooks principales de WordPress
- Configuración global

---


## Características principales

- **Email Templates** (`wec_email_tpl`) para diseñar HTML personalizados.
- **Vista previa responsive** (Móvil / Tablet / Desktop / Ancho libre).
- **Sistema de Categorías** ✨ NUEVO en v9.0:
  - Segmenta suscriptores en categorías personalizadas (Ofertas, Proveedores, etc.)
  - Campañas específicas por categoría o múltiples categorías
  - Gestión visual con colores y estadísticas
  - Consulta `CATEGORIES_SETUP.md` para guía completa
- **Campañas automáticas** con dos modos:
  - Escaneo de suscriptores (filtrado por categorías opcional)
  - Pegado manual de correos (uno por línea)
- **Cola de envío** con “Lote por minuto” (`rate_per_minute`).
- **Envío mediante WP-Cron o cron real**.
- **Compatible con plugins SMTP** (como WP Mail SMTP).
- **Limpieza de correos falsos o inválidos**:
  - Validación avanzada mediante la API de EmailListVerify
  - Panel de administración para gestionar y limpiar correos
  - Consulta el archivo `EMAIL_CLEANER_SETUP.md` para instrucciones
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
3. **(Opcional) Configura categorías** desde  
   **Email Manager → Categorías**.
4. Crea una plantilla desde  
   **Email Manager → Email Templates**.
5. Crea una campaña desde  
   **Email Manager → Campañas**.

**Nota**: Al activar el plugin se crean automáticamente 3 categorías predeterminadas: General, Ofertas y Proveedores.

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
| `wp_wec_jobs` | Información general de campañas (incluye `category_ids`) |
| `wp_wec_job_items` | Correos individuales de cada campaña |
| `wp_wec_subscribers` | Correos suscritos o dados de baja |
| `wp_wec_categories` | ✨ Categorías personalizadas de suscriptores |
| `wp_wec_subscriber_categories` | ✨ Relación many-to-many suscriptor-categoría |

---

## Ejemplo de flujo

1. **Configura categorías** (opcional):
   - Ve a **Email Manager → Categorías**
   - Crea categorías como "Ofertas", "VIP", "Proveedores"
   - Asigna colores para identificación visual
2. **Crea una plantilla** HTML con el shortcode `[[UNSUB_URL]]` al final.  
3. **Crea una campaña** desde **Email Manager → Campañas**:
   - Selecciona plantilla
   - Filtra por categorías específicas (opcional)
   - Configura fecha de inicio y lote por minuto
4. Si el destinatario se da de baja, su `status` cambia a `unsubscribed` y el sistema lo excluye automáticamente en futuras campañas.

---

## Licencia

GPLv2 o posterior.  
© Drexora
