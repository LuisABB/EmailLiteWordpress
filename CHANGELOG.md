# Changelog - WP Email Collector

Todos los cambios importantes del proyecto serán documentados en este archivo.

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
