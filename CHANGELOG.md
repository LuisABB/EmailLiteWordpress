# Changelog - WP Email Collector


Todos los cambios importantes del proyecto serán documentados en este archivo.

## [9.1.0] - 2025-12-28 - Optimización para Webempresa

### ⚡ Optimizaciones de Rendimiento
- **Límite de envío optimizado para Webempresa:**
  - Configuración automática de 8 emails por minuto (480 emails/hora)
  - Cumple con el límite de Webempresa Hosting Profesional 6GB (500 emails/hora)
  - Margen de seguridad de 20 emails/hora para evitar limitaciones
  - Constante `WEC_MAX_EMAILS_PER_MINUTE` definida en `class-wec-campaign-manager.php`

### 🐛 Correcciones
- **Corrección de zona horaria en fecha de finalización:**
  - Las campañas ahora registran correctamente la hora de finalización en CDMX
  - Antes: `finished_at` usaba `current_time('mysql')` que no convertía correctamente a UTC
  - Ahora: Conversión explícita CDMX → UTC usando `DateTime` y `DateTimeZone`
  - Afecta a campañas completadas (`done`) y campañas con error (`failed`)
  - Logs mejorados con marca de tiempo UTC para debugging

### 🔧 Mejoras Técnicas
- **Conversión de zonas horarias consistente:**
  - Método unificado para convertir CDMX a UTC en todas las operaciones de finalización
  - Mismo patrón usado en campañas expiradas ahora aplicado a campañas completadas
  - Logs detallados incluyen timestamp UTC para trazabilidad

### 📊 Rendimiento Esperado
- **Con 900 suscriptores:**
  - Tiempo estimado de campaña: ~113 minutos (1h 53min)
  - Emails por hora: 480 (dentro del límite de 500)
  - Sin riesgo de limitación por parte del hosting

### 🎯 Compatibilidad
- **100% compatible con versión 9.0.0**
- Sin cambios en base de datos
- Sin cambios en API existente
- Actualización transparente

---

## [9.0.0] - 2025-12-23 - Segmentación por Categorías y Limpieza de Emails

### ✨ Nuevas funcionalidades principales
- **Sistema de Categorías de Suscriptores:**
  - Nueva tabla `wp_wec_categories` para gestionar categorías personalizadas
  - Nueva tabla `wp_wec_subscriber_categories` para relación many-to-many
  - Gestor completo `WEC_Category_Manager` con patrón Singleton
  - Interfaz de administración para crear, editar y eliminar categorías
  - Selector de color para identificación visual de categorías
  - Asignación múltiple: un suscriptor puede pertenecer a varias categorías

### 🔧 Mejoras en Módulo Limpieza de Emails
- **Recolección no destructiva**: La función "Recolectar Correos" ahora mantiene todos los emails existentes en `wp_wec_subscribers` sin modificarlos ni eliminarlos
- **Solo inserción de nuevos**: El sistema únicamente añade emails nuevos que no existan previamente en la base de datos
- **Preservación de estados**: Los emails existentes conservan su estado actual (`subscribed`, `disposable`, `invalid`, etc.) sin ser alterados
- **Mensajes informativos mejorados**: 
  - Muestra cantidad de emails nuevos añadidos
  - Indica total anterior y total final después de la recolección
  - Mensajes más claros y descriptivos en español
- **Normalización a minúsculas**: Prevención de duplicados mediante comparación case-insensitive
- **Fuentes de recolección**: Usuarios registrados de WordPress (`wp_users`) y comentarios aprobados (`wp_comments`)
- **Estado inicial**: Nuevos emails se añaden con estado vacío (`''`) listos para validación posterior
- **Información de respuesta**: AJAX devuelve datos detallados (`inserted`, `previous_total`, `final_total`)

### 🎯 Segmentación en Campañas
- **Envío de campañas por categorías:**
  - Sistema completamente refactorizado: ahora las campañas se envían por categorías en lugar de por correos individuales
  - Selector interactivo de categorías en formulario de crear campaña
  - Checkbox "Enviar a TODAS las categorías" que calcula automáticamente el total de destinatarios
  - Selección múltiple de categorías específicas con contador en tiempo real
  - Visualización de categorías con badges de colores en lista de campañas
  - Columna `category_ids` (JSON) en tabla `wp_wec_jobs` para almacenar las categorías seleccionadas
  - Método `gather_emails_by_categories()` para filtrado eficiente por categorías
  - Contador dinámico de destinatarios con validación visual (verde/amarillo)
  - Deshabilitación automática de opciones cuando "todas" está seleccionado

### 🏗️ Categorías Predeterminadas
- **Categorías iniciales automáticas:**
  - General (#95a5a6) - Suscriptores sin categoría específica
  - Ofertas (#e74c3c) - Interesados en promociones
  - Proveedores (#3498db) - Contactos B2B

### 📊 Funcionalidades de Gestión
- **Panel de administración completo:**
  - Contador de suscriptores por categoría
  - Edición inline de categorías existentes
  - Protección de categoría "General" (no eliminable)
  - Validación de slugs únicos
  - Generación automática de slugs desde nombres
  - Color picker integrado para personalización visual

### 🔧 Mejoras Técnicas
- **Arquitectura modular:**
  - Clase `WEC_Category_Manager` independiente y reutilizable
  - Integración con `WEC_Campaign_Manager` mediante interfaces
  - Función `gather_emails_by_categories()` para filtrado eficiente de destinatarios por categorías seleccionadas
  - Método `get_subscribers_by_categories()` con JOIN optimizado para consultas rápidas
  - AJAX para operaciones asíncronas (guardar, eliminar, asignar)
  - JavaScript dinámico para contador de destinatarios en tiempo real
  - Método `get_job_categories_html()` para renderizar badges de categorías en campañas
  - Sistema de fallback: si no hay categorías seleccionadas, usa escaneo completo (`gather_emails_full_scan()`)
- **Migración automática de suscriptores:**
  - Función `wec_migrate_subscribers_to_general()` ejecutada al activar el plugin
  - Migra automáticamente todos los suscriptores con `status = 'subscribed'` sin categoría a "General"
  - Bandera `wec_subscribers_migrated_to_general` para evitar ejecuciones duplicadas
  - Logs detallados del proceso de migración para troubleshooting
- **Asignación automática a "General":**
  - Correos recolectados con "Recolectar Correos" se asignan automáticamente a categoría "General"
  - Inserción directa en tabla `wp_wec_subscriber_categories` al crear nuevos suscriptores
  - Simplificación del contador de categorías: eliminadas subconsultas complejas con `NOT IN`
  - Conteo directo desde `wp_wec_subscriber_categories` con JOIN a `wp_wec_subscribers`

### 🎨 Mejoras de UI/UX
- **Interfaz visual mejorada:**
  - Badges de colores para categorías en lista de campañas
  - Estadísticas en tiempo real de suscriptores por categoría en formulario de creación
  - Formulario unificado para crear/editar categorías
  - Confirmaciones y validaciones en tiempo real
  - Estilos CSS inline optimizados
  - Contador visual dinámico de destinatarios con colores según cantidad (0 = amarillo, >0 = verde)
  - Efectos hover en opciones de categorías para mejor experiencia de usuario
  - Deshabilitación visual (opacidad 0.5) de categorías cuando "todas" está seleccionado
  - Mensajes de ayuda y enlaces directos para crear categorías si no existen

### 🔐 Seguridad
- **Validación y sanitización:**
  - Nonces para todas las operaciones AJAX
  - Sanitización de inputs de categorías
  - Validación de permisos `manage_options`
  - Escape de outputs HTML
  - Validación de JSON en category_ids

### 📦 Compatibilidad
- **Retrocompatibilidad total:**
  - Campañas sin categorías siguen funcionando (valor "all" por defecto)
  - Migración automática de base de datos a versión 4
  - Fallback a escaneo completo (`gather_emails_full_scan()`) si no hay categorías o Category Manager no disponible
  - Sin breaking changes en API existente
  - Campo `category_ids` opcional en tabla `wp_wec_jobs` (JSON)
  - Método `create_campaign_in_db()` acepta parámetro opcional `$category_ids` con valor por defecto `array('all')`
- **Migración automática en instalación/actualización:**
  - Al activar el plugin, todos los suscriptores existentes con `status = 'subscribed'` sin categoría se migran automáticamente a "General"
  - Proceso transparente sin intervención manual del usuario
  - No afecta suscriptores que ya tienen categorías asignadas
  - Ejecución única mediante bandera `wec_subscribers_migrated_to_general`

### 🐛 Correcciones
- Actualización de versión de plugin a 9.0.0
- Actualización de versión de base de datos a 4
- Creación automática de tablas de categorías en activación
- **Eliminada lógica de borrado**: Ya no se eliminan emails de la tabla de suscriptores durante la recolección
- **Nombre de botón clarificado**: "Validar TODOS" en realidad solo valida emails con `status = ''` (pendientes/nuevos), no re-valida emails ya procesados

### 🎯 Comportamiento del Sistema de Limpieza
- **"Recolectar Correos"**: Añade nuevos emails sin tocar existentes
- **"Validar TODOS"**: Solo valida emails con estado vacío (no re-valida `subscribed`, `disposable`, etc.)
- **Validación individual**: Cada email puede ser validado manualmente mediante botón específico
- **Protección de datos**: Los emails existentes permanecen intactos durante todo el proceso

---

## [8.1.0] - 2025-11-29 - Expiración de campañas y limpieza de logs

### ✨ Cambios principales
- **Expiración de campañas solo si son de días anteriores (CDMX):**
  - Ahora las campañas solo se marcan como expiradas si la fecha (en CDMX) es de un día anterior al actual, sin importar la hora programada.
  - Se utiliza `DATE(CONVERT_TZ(...))` en las consultas MySQL para comparar correctamente la fecha local de CDMX contra la fecha actual.
- **Conversión robusta de zonas horarias:**
  - Todas las comparaciones de fechas y horas relevantes usan conversión explícita entre UTC y 'America/Mexico_City' para evitar expiraciones prematuras.
- **Eliminación de logs de depuración:**
  - Se eliminaron todas las llamadas a `error_log` y mensajes de depuración del código de producción para mayor limpieza y rendimiento.

### 🐛 Correcciones
- Las campañas ya no expiran antes de tiempo por diferencias de hora entre UTC y CDMX.
- El código está libre de logs de depuración y listo para producción.

---

## [8.0.0] - 2025-11-23 - Limpieza Emails y Seguridad

### ✨ Nuevas funcionalidades y cambios
- **Menú Limpieza Emails**: El submenú "Limpieza Emails" ahora se registra correctamente en el panel de administración y muestra la interfaz de gestión de correos sin errores 404.
- **Escaneo solo de suscriptores validados**: El modo "Usar escaneo de todo el sitio" ahora solo toma emails de la tabla `wp_wec_subscribers` donde `status = 'subscribed'`. Ya no se escanean usuarios ni comentarios.
- **Seguridad API KEY**: La clave de la API de EmailListVerify se almacena cifrada en la base de datos y solo se descifra cuando es necesario.
- **Mejoras de UI**: Los botones de validación se desactivan si falta la API KEY y se añadió un botón para configurar o cambiar la clave en cualquier momento.

### 🐛 Correcciones
- El botón "Validar TODOS" ahora solo valida correos con status vacío.

### 🔒 Notas técnicas
- El registro del submenú y su callback se realiza desde el archivo principal para mayor robustez.
- Eliminada la lógica de escaneo de usuarios y comentarios en campañas masivas.
- Refactorización de hooks y AJAX para mayor claridad y seguridad.

---

## [7.1.0] - 2025-11-23 - Limpieza y optimización SMTP

### 🛠️ Mejoras principales
- **Eliminación total de lógica SMTP propia**: Se eliminó todo el código, UI y lógica de configuración SMTP y .env del plugin. Ahora el envío SMTP depende exclusivamente de WP Mail SMTP u otros plugins externos.
- **Refactorización y limpieza**: El código del gestor de plantillas y pruebas de envío fue optimizado, eliminando parámetros, hooks y métodos obsoletos. Solo permanece la gestión de plantillas y el formulario de prueba.
- **Changelog actualizado**: Documentación de la transición a dependencia exclusiva de WP Mail SMTP para el envío de correos.

### 🐛 Correcciones
- Corrección de conflictos SMTP con otros plugins.
- Reparación automática de índices únicos en la base de datos de suscriptores.
- Eliminados errores fatales por métodos huérfanos tras la limpieza de SMTP.
- Validación de sintaxis y funcionamiento tras la refactorización.

### 🔧 Notas técnicas
- Se refactorizó la inicialización de PHPMailer para sobrescribir cualquier otro handler SMTP.
- Se añadió función de reparación de índices (`wec_repair_subscribers_indexes`) y verificación en la instalación/upgrade.
- El plugin ya no contiene lógica SMTP propia ni dependencias de .env.
- El formulario de prueba solo permite seleccionar plantilla y destinatario.

---

## [7.0.0] - 2025-11-16 - Refactorización Campaign Manager

### 🔄 Arquitectura de Campañas
- **Separación completa**: Nueva clase `WEC_Campaign_Manager` independiente para gestión de campañas
- **Autoloader compatible**: Carga automática del manager de campañas sin cambios en estructura existente
- **Patrón arquitectónico consistente**: Siguiendo el mismo diseño de SMTP y Template managers
- **Interfaces desacopladas**: Sistema de interfaces para comunicación entre managers

### 🎯 Funcionalidades Migradas
- **Creación y edición** de campañas completa
- **Procesamiento de cola** y envíos masivos
- **Gestión de destinatarios** (escaneo + manual)
- **Cron interno y externo** con validación de seguridad
- **Estados de campaña** (pending, running, done, expired)
- **Zona horaria CDMX** con conversión UTC automática

### ⚡ Beneficios Técnicos
- **Mantenibilidad**: Archivo principal reducido de ~2,150 a ~1,400 líneas
- **Responsabilidades claras**: Campaign Manager enfocado exclusivamente en campañas
- **Extensibilidad**: Base para futuras funcionalidades de campañas avanzadas
- **Testabilidad**: Componentes aislados más fáciles de probar

### 🛠️ Funcionalidades Específicas
- **Interfaz unificada**: UI consistente con otros managers del sistema
- **Validación robusta**: Sistema completo de validación de plantillas y datos
- **Fallback systems**: Sistemas de respaldo para compatibilidad con versiones anteriores
- **Debug avanzado**: Logs detallados y endpoint de monitoreo mejorado

### 🔐 Seguridad Mejorada
- **Interfaces tipadas**: Validación estricta de tipos entre componentes
- **Wrapper patterns**: Adaptadores seguros para compatibilidad hacia atrás
- **Cron endpoint discreto**: Respuestas mínimas en producción para evitar exposición de métricas internas
- **Filtros scoped**: wp_mail_content_type solo activo durante envío de campañas específicas
- **Sanitización completa**: Validación de todos los inputs de campañas
- **Token management**: Sistema seguro para cron externo y unsubscribe

### 📦 Compatibilidad
- **100% retrocompatible**: Mismas opciones BD, hooks y estructura .env
- **API consistency**: Métodos públicos mantienen misma signatura
- **Plugin upgrade**: Actualización transparente sin pérdida de datos
- **Manager integration**: Comunicación fluida entre todos los managers

## [6.0.0] - 2025-11-13 - Refactorización SMTP

### 🔄 Arquitectura SMTP
- **Separación SMTP**: Nueva clase `WEC_SMTP_Manager` independiente para configuración SMTP
- **Autoloader compatible**: Carga automática del manager SMTP sin cambios en estructura existente
- **Singleton pattern**: Gestión única y eficiente de la configuración SMTP

### 🔧 Mejoras de Configuración
- **UI mejorada**: Formularios SMTP con descriptions, placeholders y validaciones
- **Mejor .env support**: Detección automática y mensajes informativos sobre modo .env
- **Validaciones robustas**: Checks de seguridad y manejo de errores mejorado

### ⚡ Código Limpio
- **Responsabilidades separadas**: SMTP aislado del código principal (1,200+ líneas menos)
- **100% retrocompatible**: Mismas opciones BD, hooks y estructura .env
- **Extensible**: Base para agregar nuevos providers SMTP

### 🛠️ Funcionalidades Técnicas
- **Debug utilities**: Función `get_config_status()` para troubleshooting
- **Config optimization**: Setup PHPMailer optimizado con timeouts y charset UTF-8
- **Better error handling**: Mensajes claros y redirects seguros en tests SMTP

## [5.0.0] - 2025-11-11 - Refactorización y Mejoras UX

### 🔄 Refactoring Arquitectura
- **Separación de clases**: Creación del archivo `class-wec-template-manager.php` independiente para gestión de plantillas
- **Template Manager**: Nueva clase `WEC_Template_Manager` con responsabilidades específicas del sistema de plantillas
- **Autoloader mejorado**: Sistema automático de carga de clases WEC para mejor organización del código
- **Singleton pattern**: Implementación de patrón Singleton para el Template Manager

### 🔧 Mejoras del Sistema de Plantillas
- **Metaboxes organizados**: 
  - Asunto del correo (con placeholders disponibles)
  - Vista previa (con estadísticas de contenido)
  - Información de la plantilla (uso, fechas, estado)
- **Columnas personalizadas**: Lista de plantillas con columnas de Asunto, Uso y Acciones
- **Estadísticas en tiempo real**: Contador de palabras y caracteres que se actualiza al escribir
- **Validación completa**: Sistema robusto de validación antes de usar plantillas

### ⚡ Optimizaciones de Performance
- **Carga condicional**: Assets JavaScript/CSS solo se cargan en páginas relevantes
- **Separación de responsabilidades**: Template Manager independiente reduce la carga del archivo principal
- **Modal optimizado**: Sistema de vista previa más eficiente con menos conflictos
- **Consultas BD optimizadas**: Verificación de existencia de tablas antes de consultas

### 🔐 Mejoras de Seguridad
- **Nonces específicos**: Sistema de nonces independiente para plantillas (`wec_prev_iframe`)
- **Capacidades validadas**: Verificación de permisos específicos para plantillas
- **Sanitización mejorada**: Procesamiento seguro de datos de plantillas
- **Autoloading seguro**: Validación de clases antes de cargar archivos

### 🛠️ Funcionalidades Técnicas Nuevas
- **Sistema de plantillas por defecto**: Contenido automático para plantillas vacías
- **Variables de plantilla**: Sistema expandido de placeholders (site_name, current_year, etc.)
- **Contador de uso**: Tracking de cuántas campañas usan cada plantilla
- **Estados visuales**: Indicadores claros de publicado/borrador con estilos distintivos
- **Ejemplo integrado**: Función para crear plantillas de muestra automáticamente

## [4.0.0] - 2025-11-09 - Corrección de Instalación

### 🐛 Correcciones
- **Instalación del plugin**: Cambio de `create_tables()` a `maybe_install_tables()`. Fallaba porque el plugin WP Email Collector intentaba ejecutar un método que ya no existía (create_tables()), y eso provocaba el error fatal.
### 🐛 Correcciones Críticas
- **Bug de ejecución diaria**: Corrección del problema donde las campañas programadas se ejecutaban todos los días a la misma hora en lugar de solo en la fecha específica programada
- **Validación de fecha específica**: Implementación de validación que asegura que las campañas se ejecuten únicamente en el día programado (no solo cuando la hora haya pasado)
- **Sistema de expiración**: Las campañas pendientes de días anteriores se marcan automáticamente como "expiradas" para prevenir ejecuciones incorrectas

### 🔧 Mejoras del Sistema
- **Nuevo estado 'expired'**: Las campañas que no se ejecutaron en su fecha programada se marcan como expiradas
- **Limpieza automática**: Eliminación automática de campañas expiradas de más de 30 días para mantener la base de datos limpia
- **Logging mejorado**: Registro detallado de operaciones de limpieza y marcado de campañas expiradas
- **Consultas optimizadas**: Mejora en las queries SQL para incluir validación de rangos de fecha específicos (UTC)

### 🎨 Mejoras de Interfaz
- **Estados visuales**: Iconos y colores distintivos para cada estado de campaña
  - ⏳ **Pendiente** (naranja)
  - ▶️ **Ejecutando** (azul con animación pulsante)
  - ✅ **Completada** (verde)
  - ⚠️ **Expirada** (rojo)
- **CSS mejorado**: Estilos para diferenciación visual de estados con animaciones sutiles

### 📊 Monitoreo Mejorado
- **Endpoint externo**: El cron externo ahora reporta también el número de campañas expiradas
- **Dashboard actualizado**: La página de campañas muestra estados más claros y descriptivos
- **Debugging avanzado**: Mejor información para troubleshooting de problemas de timing

### 🔐 Validaciones de Seguridad
- **Timezone handling**: Conversión correcta entre CDMX y UTC para todas las validaciones de fecha
- **Prevención de re-ejecución**: Sistema robusto que previene la ejecución accidental de campañas antiguas
- **Limpieza de historial**: Mantenimiento automático de la base de datos sin perder datos importantes

### ⚡ Optimizaciones
- **Queries más eficientes**: Consultas SQL optimizadas con rangos de fecha específicos
- **Menor carga de BD**: Eliminación automática de registros antiguos innecesarios
- **Mejor performance**: Reducción de procesamiento innecesario de campañas expiradas

## [3.0.0] - 2025-10-26 - Sistema Completo y Optimizado

### 🚀 Nuevas características
- **Cron Externo**: Sistema de cron automático vía URL externa con validación de seguridad
- **Timezone CDMX**: Soporte completo para zona horaria America/Mexico_City
- **Inliner CSS**: Sistema avanzado de CSS inlining para compatibilidad con Gmail
- **Vista Previa**: Modal responsive con múltiples tamaños de pantalla
- **Unsubscribe**: Sistema completo de baja de suscripciones con tokens seguros y placeholders `[[UNSUB_URL]]`

### 🔧 Mejoras
- **Interfaz optimizada**: Eliminación de Panel, reorganización de menús
- **SMTP mejorado**: Config. SMTP incluye ahora pruebas de envío
- **Campaña automática**: Programación múltiple de crons para mayor confiabilidad
- **CSS Reset**: Estilos de email compatibles con todos los clientes
- **Error handling**: Gestión mejorada de errores con mensajes claros
- **Procesamiento individual**: Cada email se procesa con su URL única de unsubscribe

### 🐛 Correcciones
- **Encoding**: Eliminación de caracteres CRLF de Windows
- **Métodos faltantes**: Implementación de parse_env_file y enforce_button_styles
- **Botones**: Forzado de visibilidad en Gmail y clientes estrictos
- **Links**: Reset completo de enlaces para máxima compatibilidad
- **Redirecciones**: Fix de URLs después de eliminar Panel
- **Unsubscribe URLs**: Fix completo de placeholders que aparecían como `%5B%5BUNSUB_URL%5D%5D`

### 🔐 Seguridad
- **Cron externo**: Validación con clave secreta configurable
- **Tokens**: Sistema seguro para enlaces de baja
- **Sanitización**: Limpieza mejorada de inputs de usuario
- **Permisos**: Validación estricta de capacidades de WordPress

### ⚡ Rendimiento
- **Logs optimizados**: Eliminación de trazas de debug en producción
- **CSS inlining**: Procesamiento optimizado para emails masivos
- **Base de datos**: Queries optimizadas para grandes volúmenes
- **Cron persistente**: Sistema robusto de procesamiento en background

### 🎨 Estilo/UI
- **Menú limpio**: Estructura simplificada (Campañas → Config. SMTP → Templates)
- **Modal preview**: Interfaz moderna para vista previa de emails
- **Responsive**: Adaptación perfecta a móviles y tablets
- **Botones**: Diseño consistente y compatible con todos los clientes

### 📚 Documentación
- **Comentarios**: Código completamente documentado
- **Funciones**: Descripción detallada de métodos críticos
- **Timezone**: Documentación del sistema CDMX/UTC
- **Cron**: Guía de configuración de cron externo

---

## [2.2.0] - 2025-10-05

### 🎨 Sistema de Plantillas
- **Editor WYSIWYG**: Integration con editor nativo de WordPress
- **Metaboxes**: Configuración de asunto y vista previa
- **Placeholders**: Variables dinámicas para personalización
- **Validación**: Verificación de HTML válido

### 📈 Analytics y Monitoreo
- **Estado de envíos**: Tracking de enviados/fallidos
- **Logs detallados**: Sistema de debugging configurable
- **Performance metrics**: Tiempo de procesamiento y memoria
- **Queue monitoring**: Estado de colas en tiempo real

---

## [2.1.0] - 2025-09-30

### 🔧 Funcionalidades Base
- **Plugin foundation**: Estructura base del plugin WordPress
- **Admin menu**: Páginas de administración
- **Asset management**: CSS y JavaScript organizados
- **Database schema**: Diseño inicial de tablas

### 📦 Infraestructura
- **Activation hooks**: Instalación automática de tablas
- **Upgrade system**: Migración de versiones
- **Constants**: Configuración centralizada
- **Class structure**: Arquitectura orientada a objetos

---

## [2.0.0] - 2025-09-25

### 🚀 Lanzamiento Inicial
- **Core functionality**: Envío básico de emails
- **Template system**: Plantillas simples
- **SMTP support**: Configuración básica de SMTP
- **WordPress integration**: Compatibilidad inicial

---

## Guía de Versioning

Este proyecto sigue [Semantic Versioning](https://semver.org/):

- **MAJOR**: Cambios incompatibles de API
- **MINOR**: Funcionalidad nueva compatible con versiones anteriores  
- **PATCH**: Correcciones de bugs compatibles

### Tipos de Cambios

- 🚀 **Nuevas características**
- 🔧 **Mejoras**
- 🐛 **Correcciones**
- 🔐 **Seguridad**
- 📚 **Documentación**
- ⚡ **Rendimiento**
- 🎨 **Estilo/UI**
- 🔄 **Refactoring**
