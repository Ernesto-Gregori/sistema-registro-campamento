# 🏕️ Sistema de Gestión de Campamento — Palabra de Vida SV

Sistema web para el registro, asignación y seguimiento de acampantes,
consejeros y sesiones de consejería durante las semanas de campamento.

---

## 📋 Descripción General

| Dato | Detalle |
|------|---------|
| Versión | 1.0.0 |
| Lenguaje | PHP 8.x + MySQL |
| Frontend | Bootstrap 5 + FontAwesome |
| Hosting | Hostinger (Shared Hosting) |
| País | El Salvador |
| Última actualización | Abril 2026 |

---

## 🚀 Módulos del Sistema

| Módulo | Ruta | Descripción |
|--------|------|-------------|
| Administrador | `/admin/` | Panel principal de gestión |
| Apoyo | `/apoyo/` | Registro y asignación de acampantes |
| Consejero | `/consejero/` | Seguimiento espiritual por cabaña |

---

## 👥 Roles de Usuario

| Rol | Acceso | Descripción |
|-----|--------|-------------|
| `administrador` | Todo el sistema | Gestiona semanas, cabañas, usuarios, reportes |
| `apoyo` | Módulo apoyo | Registra acampantes según género asignado |
| `consejero` | Módulo consejero | Ve y documenta sesiones de su cabaña |

---

## 📁 Estructura de Carpetas
/
├── admin/              # Panel administrador
├── apoyo/              # Panel apoyo de consejeros
├── consejero/          # Panel consejeros
├── config/
│   ├── database.php    # Conexión a base de datos
│   └── pais.php        # Configuración de país/departamentos
├── includes/
│   ├── header.php      # Cabecera HTML común
│   ├── footer.php      # Pie de página común
│   └── functions.php   # Funciones globales
├── assets/
│   ├── css/            # Estilos personalizados
│   ├── js/             # Scripts
│   └── uploads/        # Fotos de acampantes
├── docs/               # Documentación
└── reportes/           # Scripts de reportes automáticos

---

## ⚙️ Requisitos del Servidor

- PHP >= 8.0
- MySQL >= 5.7 / MariaDB >= 10.4
- Extensiones PHP: `pdo`, `pdo_mysql`, `gd`, `mbstring`
- Módulo Apache: `mod_rewrite`

---

## 🔧 Instalación Rápida

1. Sube los archivos al servidor vía FTP
2. Crea la base de datos en phpMyAdmin
3. Importa el archivo `docs/database.sql`
4. Edita `config/database.php` con tus credenciales
5. Edita `config/pais.php` si cambias de país
6. Accede al sistema en tu dominio

---

## 📞 Soporte

Para reportar bugs o solicitar cambios, contactar al desarrollador.