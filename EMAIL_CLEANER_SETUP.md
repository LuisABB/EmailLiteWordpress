# Configuración de la Limpieza de Emails

Este documento explica cómo configurar y utilizar la funcionalidad de limpieza de emails en el plugin EmailLiteWordpress.

## ¿Qué es la limpieza de emails?
La limpieza de emails permite eliminar direcciones de correo electrónico inválidas, duplicadas o no deseadas de tu base de datos, mejorando la calidad de tus campañas y evitando problemas de entrega.

## Pasos para configurar la limpieza de emails

1. **Ubicación del archivo:**
   El script principal para la limpieza se encuentra en `includes/email-cleaner.php`.

2. **Acceso a la funcionalidad:**
   - Puedes ejecutar la limpieza manualmente desde el panel de administración de WordPress si el plugin lo permite.

3. **Configuración de parámetros:**
   - Revisa las opciones disponibles en el archivo `email-cleaner.php` para personalizar el comportamiento (por ejemplo, tipos de validación, listas blancas/negra, etc.).
   - Si el plugin ofrece una página de configuración, accede a ella desde el menú de administración de WordPress y ajusta las opciones según tus necesidades.


## Configuración y uso de la API de validación

La limpieza avanzada de emails utiliza la API de [EmailListVerify](https://emaillistverify.com/) para validar la validez de los correos electrónicos.

### 1. Obtener tu API KEY
1. Regístrate en [emaillistverify.com](https://emaillistverify.com/).
2. Accede a tu panel y copia tu API KEY personal.

### 2. Configurar la API KEY en el plugin
Puedes configurar la API KEY de dos formas:

- **Desde el panel de administración de WordPress:**
   - Ve a la página de limpieza de correos del plugin.
   - Haz clic en el botón "Configurar API KEY" o "Cambiar API KEY".
   - Pega tu clave y guárdala. Se almacenará cifrada en la base de datos.


### 3. ¿Cómo funciona la validación?
- Cuando ejecutes la limpieza, el plugin usará la API para validar cada correo electrónico.
- Si no tienes créditos suficientes en EmailListVerify, se mostrará un aviso y la validación se detendrá.
- Si la API KEY es incorrecta o falta, la validación avanzada no se realizará y los correos se marcarán como "subscribed" (suscritos) para evitar bloqueos.

### 4. Seguridad
- La API KEY se almacena cifrada si se guarda desde el panel.
- No compartas tu clave públicamente.

## Recomendaciones
- Realiza una copia de seguridad de tu base de datos antes de ejecutar la limpieza.
- Revisa los registros de actividad para asegurarte de que la limpieza se realizó correctamente.
- Ajusta la frecuencia de limpieza según el volumen de registros que manejes.

## Soporte
Si tienes dudas o problemas, revisa la documentación adicional o contacta al desarrollador del plugin.
