# Changelogs — Sistema de Asistencia de Catequesis

Historial de los cambios que se han hecho al sistema, en orden cronológico. Cada archivo explica **qué cambió, por qué y cómo se comprobó que funciona**, en lenguaje normal.

| Versión | Tema |
|---|---|
| [v0.1](v0.1_sistema-base.md) | Primera versión: grupos, catequizandos, asistencia, reportes y panel |
| [v0.2](v0.2_sin-login-y-primer-rediseno.md) | Se quita el inicio de sesión y la fecha de nacimiento; primer rediseño |
| [v0.3](v0.3_rediseno-vino-oro.md) | Rediseño completo: barra lateral, colores vino y oro, opciones tipo botón |
| [v0.4](v0.4_quitar-archivar.md) | Se elimina la función de archivar |
| [v0.5](v0.5_auditoria-y-ordenamiento.md) | Primera revisión de errores y orden por columnas estilo Snowflake |
| [v0.6](v0.6_sistema-de-puntos.md) | Asistencia por puntos (misa y catequesis) y la opción "Solo Misa" |
| [v0.7](v0.7_editar-asistencia.md) | Editar la asistencia de una sesión ya tomada |
| [v0.8](v0.8_exportacion-excel.md) | Exportación a Excel real |
| [v0.9](v0.9_nombre-primero-y-actividades.md) | Orden "Nombre Apellido" y actividades extracurriculares |
| [v1.0](v1.0_auditoria-con-agentes.md) | Revisión profunda con tres agentes y corrección de un error crítico |
| [v1.1](v1.1_borrar-catequizando.md) | Borrar catequizandos con confirmación |
| [v1.2](v1.2_modo-celular.md) | Modo celular y tablet para tomar asistencia |
| [v1.3](v1.3_respaldo-alertas-meta-promocion-boletin.md) | Respaldo, alertas con WhatsApp, meta de puntos, pasar de grupo y boletín |
| [v1.4](v1.4_rediseno-del-panel.md) | Rediseño de la página principal (Panel) |
| [v1.5](v1.5_rediseno-de-actividades.md) | Rediseño del módulo de Actividades, avisos flotantes y mejoras de accesibilidad |
| [v1.6](v1.6_rediseno-tomar-asistencia-y-edicion-protegida.md) | Rediseño de Tomar asistencia, botones nuevos y edición protegida con confirmación |
| [v1.7](v1.7_auditoria-y-arreglo-de-reportes.md) | Auditoría general, arreglo del cuadro de Reportes y corrección de un fallo que podía romper varias pantallas |

## Cómo agregar un nuevo registro

1. Crea un archivo nuevo con el número de versión y un título corto, por ejemplo "v1.8_tema.md".
2. Escribe qué cambia, qué se comprobó y cualquier incidente. Usa lenguaje normal, sin código.
3. Agrégalo a la tabla de arriba.

## Notas generales

- El sistema funciona en la computadora con WampServer (PHP y MySQL), sin programas ni librerías externas.
- Se abre en el navegador en la dirección http://localhost/asistencia_catequesis/
- Las fechas de las primeras versiones son aproximadas: se trabajaron en varias sesiones entre agosto y septiembre de 2026.
- Esta carpeta contiene únicamente archivos de texto en formato Markdown (.md).
