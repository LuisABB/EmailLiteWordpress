# WP Email Collector – Changelog

---

### **v2.2.0 – Octubre 2025**
- 🚀 Nueva tabla `wp_wec_subscribers` con sistema de baja automática.
- 📨 Enlace dinámico `[[UNSUB_URL]]` en plantillas.
- 🔒 Filtro automático para excluir `unsubscribed` en futuras campañas.
- 🧹 Reorganización del código (separación lógica SMTP / Cola / Suscripción).
- 📘 `README.md` reescrito (sin changelog interno).

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
