# Changelog - WP Email Collector

Todos los cambios importantes del proyecto serán documentados en este archivo.

## [2.5.1-hotfix] - 2025-10-25

### 🎯 Arreglos Críticos
- **SOLUCIONADO**: Botón "Comprar" ahora aparece correctamente en Gmail
- **SOLUCIONADO**: Navegación (CABALLERO, DAMA, CAJAS) se centra automáticamente
- **SOLUCIONADO**: CSS inliner ya no corrompe la estructura HTML

### 🔧 Mejoras Técnicas
- **CSS Inliner mejorado**: Conversión a estilos inline puros antes del procesamiento
- **Centrado agresivo**: Sistema de centrado con `!important` y `margin: 0 auto`
- **Compatibilidad Gmail**: Procesamiento específico para Gmail y clientes estrictos
- **Reset de enlaces**: Normalización completa de estados de enlaces (`a:link`, `a:visited`, etc.)

### 📧 Sistema de Email
- **Vista previa optimizada**: Modo híbrido que preserva estilos para legibilidad
- **Envío real mejorado**: Inlining completo + resets para máxima compatibilidad
- **Botones responsive**: Display block automático para centrado perfecto
- **Navegación robusta**: Estilos inline forzados para elementos críticos

### 🎨 Características de Diseño
- **Botones rojos**: Estilo consistente `#D94949` con padding y tipografía correctos
- **Texto blanco**: Color `#ffffff` forzado en navegación sobre fondos oscuros
- **Fuentes seguras**: Arial/Helvetica con fallbacks del sistema
- **MSO compatibility**: Soporte específico para Outlook via MSO CSS

### ⚡ Rendimiento
- **Procesamiento optimizado**: Menos llamadas a debug, código más eficiente
- **Carga más rápida**: JavaScript y CSS inline optimizados
- **Memoria reducida**: Limpieza de variables de debug innecesarias

### 🛠️ Código Limpio
- **Debug removido**: Todos los `error_log` de desarrollo eliminados
- **Comentarios actualizados**: Documentación mejorada en funciones críticas
- **Estructura simplificada**: Código más legible y mantenible

---

## [2.5.0] - 2025-10-20

### ✨ Nuevas Características
- **Vista previa responsive**: Soporte para móvil, tablet, desktop y ancho libre
- **Sistema de campañas**: Envíos masivos con colas y programación
- **CSS Inliner**: Conversión automática de estilos para clientes de email
- **SMTP configurable**: Soporte para Gmail, Outlook y otros proveedores

### 📊 Sistema de Gestión
- **Custom Post Types**: Plantillas reutilizables de email
- **Base de datos**: Tablas para trabajos, items y suscriptores
- **WP-Cron integration**: Procesamiento automático en segundo plano
- **Unsubscribe**: Sistema completo de baja de suscripciones

### 🎯 Compatibilidad
- **Gmail optimizado**: Estilos inline específicos para Gmail
- **Outlook support**: MSO CSS y fallbacks
- **Apple Mail**: Estilos compatibles con iOS/macOS
- **Clientes móviles**: Layout responsive universal

---

## [2.4.0] - 2025-10-15

### 🔄 Refactoring Mayor
- **Arquitectura mejorada**: Separación clara entre vista previa y envío real
- **JavaScript optimizado**: ThickBox integration para modal de vista previa
- **CSS modular**: Estilos organizados por componente
- **Error handling**: Gestión robusta de errores y fallbacks

### 📱 UI/UX
- **Interface modernizada**: Diseño consistente con WordPress admin
- **Toolbar de vista previa**: Controles de tamaño integrados
- **Estados visuales**: Indicadores claros de éxito/error
- **Navegación mejorada**: Menús y submenús organizados

---

## [2.3.0] - 2025-10-10

### 🔐 Seguridad
- **Nonces verificados**: Protección CSRF en todas las acciones
- **Sanitización**: Limpieza de datos de entrada
- **Permisos**: Verificación de capacidades de usuario
- **SQL injection**: Prepared statements en todas las consultas

### 📧 Email Engine
- **PHPMailer integration**: Configuración SMTP avanzada
- **Content-Type**: Headers correctos para HTML
- **Character encoding**: UTF-8 forzado para caracteres especiales
- **Fallback rendering**: Graceful degradation para clientes limitados

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
