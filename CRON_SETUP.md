# 🚀 Configuración de Cron Real para WP Email Collector

## 📋 **¿Qué es el Cron Externo?**

El sistema de **Cron Externo** permite que tus campañas de email se ejecuten automáticamente sin depender de visitantes al sitio web.

### **Ventajas:**
✅ **Ejecución precisa** cada minuto  
✅ **Independiente de tráfico** del sitio  
✅ **Perfecto para campañas programadas** días antes  
✅ **Logs detallados** de ejecución  
✅ **Seguro** con validación de clave secreta  

---

## 🔧 **Configuración Paso a Paso**

### **Paso 1: Configurar Clave Secreta (Opcional)**

Agrega en tu `wp-config.php`:

```php
// Clave secreta para cron externo (cambia por una clave única)
define('WEC_CRON_SECRET', 'mi_clave_super_secreta_2024');
```

**Si no la configuras**, usa la clave por defecto: `curren_email_cron_2024`

### **Paso 2: Probar el Endpoint**

1. Ve a **Campañas → Mostrar Estado del Sistema**
2. Haz clic en **"🔗 Probar Cron Externo"**
3. Deberías ver una respuesta como:

```
✅ WEC CRON EJECUTADO CORRECTAMENTE
⏱️  Tiempo: 45.23ms
📧 Trabajos pendientes: 0
📋 Items en cola: 0
🕐 Hora: 2025-10-26 11:30:15 (America/Mexico_City)
🏠 IP: 127.0.0.1
```

### **Paso 3: Configurar Cron del Sistema**

#### **Opción A: cPanel / Hosting Compartido**

1. Accede a tu **cPanel**
2. Busca **"Cron Jobs"** o **"Tareas Cron"**
3. Agrega una nueva tarea:
   - **Comando**: `* * * * *`
   - **URL/Script**: 
     ```
     /usr/bin/curl -s "https://tu-sitio.com/?wec_cron=true&secret=tu_clave_secreta" >/dev/null 2>&1
     ```

#### **Opción B: Servidor VPS/Dedicado**

```bash
# Editar crontab
crontab -e

# Agregar esta línea (cada minuto)
* * * * * /usr/bin/curl -s "https://tu-sitio.com/?wec_cron=true&secret=tu_clave_secreta" >/dev/null 2>&1
```

#### **Opción C: Servicios Externos**

**UptimeRobot** (Gratis):
1. Crear monitor HTTP(S)
2. URL: `https://tu-sitio.com/?wec_cron=true&secret=tu_clave_secreta`
3. Intervalo: 1 minuto

**Pingdom** o **StatusCake**: Similar configuración

### **Paso 4: Desactivar WP-Cron (Recomendado)**

Agrega en `wp-config.php`:

```php
// Desactivar WP-Cron (usar cron real)
define('DISABLE_WP_CRON', true);
```

---

## 🔍 **Verificación y Monitoreo**

### **Ver Logs de Ejecución**

Los logs aparecen en:
- **Error log de WordPress** (`/wp-content/debug.log`)
- **Error log del servidor** (ubicación según hosting)

### **Respuestas del Endpoint**

| Código | Mensaje | Significado |
|--------|---------|-------------|
| 200 | ✅ CRON EJECUTADO CORRECTAMENTE | Todo bien |
| 403 | ❌ ERROR 403: Clave secreta incorrecta | Clave inválida |
| 500 | ❌ ERROR EN CRON | Error interno |

### **Monitoreo de Campañas**

1. **Campañas → Mostrar Estado del Sistema**
2. Verificar:
   - **Trabajos pendientes**: Debería ser 0 si no hay campañas programadas
   - **Próximo cron programado**: Debería mostrar "Ninguno programado" si usas cron externo

---

## 🛠️ **Solución de Problemas**

### **Error 403: Clave incorrecta**
- Verifica que la clave en la URL coincida con `WEC_CRON_SECRET`
- Si no definiste la clave, usa: `curren_email_cron_2024`

### **No se ejecutan las campañas**
1. Verifica que el cron del sistema esté funcionando
2. Prueba el endpoint manualmente
3. Revisa los logs del servidor
4. Asegúrate de que las campañas estén programadas para el futuro

### **Campaña se ejecuta muy tarde**
- El cron externo ejecuta cada minuto
- Delay máximo normal: 1-2 minutos
- Si es mayor, revisa la configuración del cron del sistema

---

## 🎯 **URL Completa de Ejemplo**

```
https://tu-sitio.com/?wec_cron=true&secret=curren_email_cron_2024
```

**Cambia:**
- `tu-sitio.com` por tu dominio real
- `curren_email_cron_2024` por tu clave secreta si la configuraste

---

## 🎉 **¡Listo!**

Una vez configurado, tus campañas se ejecutarán automáticamente sin necesidad de:
- ❌ Visitantes al sitio
- ❌ Activar "Cron Automático" manualmente  
- ❌ Procesar cola manualmente

**¡Programa campañas días antes y olvídate del resto!** 🚀
