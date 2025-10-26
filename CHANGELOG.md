# Changelog - WP Email Collector

Todos los cambios importantes del proyecto serán documentados en este archivo.

## [3.0.0] - 2025-10-26

### 🚀 Nuevas características
- **Cron Externo**: Sistema de cron automático vía URL externa con validación de seguridad
- **Timezone CDMX**: Soporte completo para zona horaria America/Mexico_City
- **Inliner CSS**: Sistema avanzado de CSS inlining para compatibilidad con Gmail
- **Vista Previa**: Modal responsive con múltiples tamaños de pantalla
- **Unsubscribe**: Sistema completo de baja de suscripciones con tokens seguros

### 🔧 Mejoras
- **Interfaz optimizada**: Eliminación de Panel, reorganización de menús
- **SMTP mejorado**: Config. SMTP incluye ahora pruebas de envío
- **Campaña automática**: Programación múltiple de crons para mayor confiabilidad
- **CSS Reset**: Estilos de email compatibles con todos los clientes
- **Error handling**: Gestión mejorada de errores con mensajes claros

### 🐛 Correcciones
- **Encoding**: Eliminación de caracteres CRLF de Windows
- **Métodos faltantes**: Implementación de parse_env_file y enforce_button_styles
- **Botones**: Forzado de visibilidad en Gmail y clientes estrictos
- **Links**: Reset completo de enlaces para máxima compatibilidad
- **Redirecciones**: Fix de URLs después de eliminar Panel

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
