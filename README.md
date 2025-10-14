# Consultorio Odontológico · Historia Clínica Digital

Aplicación web minimalista en PHP + SQLite para gestionar fichas clínicas odontológicas, odontogramas y la evolución de pacientes basada en los formularios manuales suministrados.

## Requisitos

- PHP 8.1+ con extensiones `pdo_sqlite` y `sqlite3`.
- Servidor web (Apache, Nginx) o el servidor embebido de PHP.
- Permisos de escritura en el directorio `data/`.

## Estructura principal

```
index.php               → Panel principal, listado de pacientes y respaldo
patient_form.php        → Alta y edición de pacientes
patient.php             → Historia clínica, visitas, odontograma y actividades
includes/database.php   → Conexión y bootstrap de la base SQLite
assets/css/style.css    → Estilos minimalistas del tablero
assets/js/app.js        → Comportamiento dinámico (edad, odontograma, finanzas)
data/clinic.sqlite      → Base de datos (se crea automáticamente)
```

## Puesta en marcha rápida

```bash
# Desde la carpeta del proyecto
php -S localhost:8080
```

Visita `http://localhost:8080` en tu navegador. Se crea un paciente de demostración si la base de datos está vacía.

## Respaldo y restauración

- **Respaldo**: botón “Descargar respaldo” en el panel principal genera un `.sqlite`.
- **Restauración**: carga un archivo `.sqlite` existente. El sistema guarda una copia del archivo anterior en `data/backups/` antes de reemplazarlo.

También se puede copiar manualmente `data/clinic.sqlite` para respaldos rápidos.

## Evolución y odontograma

- Registra visitas con estructura SOAP, signos vitales y próxima cita.
- El odontograma permite seleccionar piezas permanentes y temporales, asignar estado y notas.
- El módulo de actividades replica la hoja “Actividad Realizada” con honorarios, abonos y saldos.

## Personalización

- Ajusta estados de piezas dentales en `patient.php` (`$odontogramStatuses`) y estilos en `assets/css/style.css`.
- Se pueden añadir nuevos campos clínicos modificando `clinical_profiles` en `includes/database.php`.

## Mantenimiento

- Mantén el archivo `data/clinic.sqlite` bajo copia periódica.
- Para resetear la base, elimina `data/clinic.sqlite` (se recreará vacía al volver a ingresar).
