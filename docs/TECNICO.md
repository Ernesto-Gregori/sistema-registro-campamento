# ⚙️ Documentación Técnica

---

## Base de Datos — Tablas Principales

| Tabla | Filas aprox. | Descripción |
|-------|-------------|-------------|
| `usuarios` | < 50 | Cuentas de acceso con roles |
| `acampantes` | 100-500/semana | Datos completos del acampante |
| `cabanas` | 10-20 | Cabañas con género y equipo |
| `semanas_campamento` | 3-6/año | Semanas con fechas y tipo |
| `sesiones_consejeria` | 500-2000/semana | Registro de consejerías |
| `temas_consejeria` | < 30 | Catálogo de temas |

---

## Relaciones Clave

semanas_campamento (1) ──── (N) acampantes
cabanas            (1) ──── (N) acampantes
acampantes         (1) ──── (N) sesiones_consejeria
usuarios           (1) ──── (N) sesiones_consejeria  [consejero_id]
temas_consejeria   (1) ──── (N) sesiones_consejeria

---

## Flujo de Autenticación

```php
// includes/functions.php
verificarLogin()      // Verifica sesión activa
esAdministrador()     // Rol = 'administrador'
esApoyo()             // Rol = 'apoyo'
esConsejero()         // Rol = 'consejero'