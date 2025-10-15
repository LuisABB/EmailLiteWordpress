# WP Email Collector – Changelog

---

### **v2.2.0 – Octubre 2025**
- 🛠️ Fix crítico: los estilos CSS de las plantillas ahora se conservan también en campañas ejecutadas por WP-Cron, evitando la sanitización que ocurría sin usuario con unfiltered_html.
- 📨 Compatibilidad de clientes: los correos ahora se envían envueltos en un documento HTML completo (<!doctype html><html>…</html>), lo que mejora la consistencia entre la vista previa y los correos reales.
- 🔗 [[UNSUB_URL]] se mantiene funcional tanto en pruebas como en campañas programadas.
- 🚀 Nueva tabla wp_wec_subscribers: sistema de baja automática con control de estado (subscribed / unsubscribed).
- 🔒 Filtro automático para excluir contactos desuscritos en futuras campañas.
-🧹 Reorganización del código interno: separación de lógica SMTP / Cola / Suscripción.
-📘 README.md reescrito con documentación más clara y sin changelog interno.

---

### **v2.1.0**
- Vista previa responsive en editor y campañas.
- Campo **Lote por minuto** persistente en BD.
- Modo **Escaneo / Pegar correos**.
- Soporte de `.env` para SMTP (prioritario).
- Fix de nowdoc JS para evitar conflictos con PHP.

---

### **v2.0.0**
- Soporte para colas de envío por cron.
- Envío segmentado por lotes.
- UI inicial de campañas.

---

### **v1.0.0**
- Versión inicial con envío básico de plantillas y configuración SMTP.
