# Guía de Uso: Sistema de Categorías de Suscriptores

## 📋 Introducción

El sistema de categorías te permite **segmentar tus suscriptores** en grupos personalizados para enviar campañas más específicas y relevantes.

Por ejemplo:
- 📧 **Ofertas** - Clientes interesados en promociones
- 🏢 **Proveedores** - Contactos comerciales B2B
- 👥 **General** - Suscriptores sin categoría específica

---

## 🚀 Primeros Pasos

### 1. Acceder al Gestor de Categorías

Desde el panel de WordPress:
1. Ve a **Email Manager → Categorías**
2. Verás las 3 categorías predeterminadas que se crearon automáticamente

### 2. Crear una Nueva Categoría

1. En la sección **"Agregar/Editar Categoría"**, completa:
   - **Nombre**: Nombre descriptivo (ej: "VIP Premium")
   - **Slug**: Se genera automáticamente (ej: "vip-premium")
   - **Descripción**: Opcional, describe el propósito de la categoría
   - **Color**: Elige un color para identificar visualmente

2. Haz clic en **"Guardar Categoría"**

### 3. Editar una Categoría Existente

1. En la lista de categorías, haz clic en **"Editar"**
2. El formulario se llenará automáticamente
3. Modifica los campos que desees
4. Haz clic en **"Guardar Categoría"**

**Nota**: La categoría "General" no se puede eliminar, pero sí editar.

---

## 📨 Crear Campañas Segmentadas

### Opción 1: Enviar a Todas las Categorías

Al crear una campaña, por defecto está seleccionada **"Todas las categorías"**. Esto enviará el email a todos los suscriptores activos.

### Opción 2: Filtrar por Categorías Específicas

1. Ve a **Email Manager → Campañas**
2. En el formulario de crear campaña, busca la sección **"Filtrar por Categorías"**
3. Desmarca "Todas las categorías"
4. Selecciona solo las categorías que desees incluir:
   - ✅ Ofertas
   - ✅ VIP Premium
   - ⬜ Proveedores (no seleccionado)

5. La campaña se enviará **solo** a los suscriptores que pertenezcan a las categorías seleccionadas

### Visualización en Campañas

En la lista de campañas, verás:
- Una columna **"Categorías"** que muestra badges de colores
- Cada badge indica qué categorías se usaron en esa campaña
- Si dice "Todas", significa que se envió a todos los suscriptores

---

## 👥 Asignar Categorías a Suscriptores

### Método 1: Asignación Manual (Próximamente)

En futuras versiones podrás asignar categorías directamente desde:
- La lista de suscriptores
- Al importar emails desde CSV
- Formularios de registro personalizados

### Método 2: Asignación Automática (Próximamente)

Se podrán configurar reglas automáticas:
- Nuevos registros → Categoría "General"
- Compras de WooCommerce → Categoría "Clientes"
- Formularios específicos → Categoría personalizada

---

## 💡 Casos de Uso Recomendados

### E-commerce
```
- 🛍️ Compradores VIP (>$1000)
- 🎁 Ofertas y Promociones
- 📦 Clientes Inactivos
- 🆕 Nuevos Registros
```

### B2B / Servicios
```
- 🏢 Proveedores
- 👔 Clientes Corporativos
- 📧 Newsletter General
- 🎯 Leads Calificados
```

### Blogs / Contenido
```
- 📰 Noticias Semanales
- 🎓 Cursos y Tutoriales
- 🎉 Eventos Especiales
- 💬 Comunidad Activa
```

---

## ⚙️ Características Técnicas

### Asignación Múltiple
Un suscriptor puede pertenecer a **múltiples categorías** simultáneamente.

**Ejemplo**:
- juan@ejemplo.com está en:
  - ✅ General
  - ✅ Ofertas
  - ✅ VIP Premium

### Filtrado Inteligente
Cuando creas una campaña con categorías **A y B**, se envía a todos los suscriptores que estén en **A o B** (unión de conjuntos).

### Colores Personalizados
Los colores ayudan a identificar rápidamente las categorías en:
- Lista de campañas
- Reportes de envío
- Panel de administración

---

## 🔧 Configuración Avanzada

### Categoría por Defecto

Si un suscriptor **no tiene categorías asignadas**, automáticamente se le asigna la categoría **"General"**.

### Eliminar Categorías

Al eliminar una categoría:
1. Se elimina de todos los suscriptores asignados
2. Las campañas antiguas mantienen el registro histórico
3. **La categoría "General" no se puede eliminar** (protegida)

### Slugs Únicos

Los slugs deben ser únicos. Si intentas crear una categoría con un slug existente, recibirás un error.

**Recomendación**: Deja que el sistema genere el slug automáticamente a partir del nombre.

---

## 📊 Estadísticas

En el gestor de categorías verás:
- **Número de suscriptores** por categoría en tiempo real
- Ejemplo: **"Ofertas: 152 suscriptores"**

Esto te ayuda a:
- Conocer el tamaño de cada segmento
- Decidir qué categorías usar para campañas
- Identificar categorías sin uso

---

## ❓ Preguntas Frecuentes

### ¿Puedo cambiar el color de una categoría después de crearla?
✅ Sí, edita la categoría y elige un nuevo color.

### ¿Qué pasa si elimino una categoría usada en campañas antiguas?
✅ Las campañas antiguas mantendrán el registro histórico, solo se eliminará la categoría de suscriptores actuales.

### ¿Puedo renombrar la categoría "General"?
✅ Sí, pero no puedes eliminarla ni cambiar su slug.

### ¿Cuántas categorías puedo crear?
✅ Sin límite. Crea todas las que necesites para tu negocio.

### ¿Un suscriptor puede estar en varias categorías?
✅ Sí, un suscriptor puede pertenecer a múltiples categorías simultáneamente.

---

## 🆘 Soporte

Si tienes problemas o sugerencias:
1. Revisa el archivo `CHANGELOG.md` para ver las últimas actualizaciones
2. Consulta la documentación técnica en `README.md`
3. Contacta al equipo de soporte

---

**Versión**: 9.0.0  
**Fecha**: Diciembre 2024  
**Autor**: Drexora Team
