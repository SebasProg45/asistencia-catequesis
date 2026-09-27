-- Sistema de Asistencia de Catequesis
-- Ejecutar este script completo en phpMyAdmin (o mysql CLI) para crear la base de datos.

CREATE DATABASE IF NOT EXISTS asistencia_catequesis
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE asistencia_catequesis;

-- Grupos / niveles de catequesis (ej: Primera Comunión Nivel 1, Confirmación...)
CREATE TABLE grupos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) NULL,
  dia_semana VARCHAR(20) NULL,
  meta_puntos DECIMAL(5,1) NULL, -- puntos mínimos a alcanzar (ej. para el sacramento); NULL = sin meta
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Ajustes del sistema (umbrales de alertas, código de país para WhatsApp)
CREATE TABLE configuracion (
  clave VARCHAR(50) PRIMARY KEY,
  valor VARCHAR(100) NOT NULL
) ENGINE=InnoDB;
INSERT INTO configuracion (clave, valor) VALUES
('alerta_racha', '3'),        -- sábados seguidos sin asistir para disparar la alerta
('alerta_porcentaje', '60'),  -- % de asistencia por debajo del cual se alerta
('codigo_pais', '507');       -- prefijo para los links de WhatsApp

-- Catequizandos (estudiantes)
CREATE TABLE estudiantes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_id INT NOT NULL,
  nombres VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  nombre_encargado VARCHAR(150) NULL,
  telefono_encargado VARCHAR(30) NULL,
  observaciones VARCHAR(255) NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (grupo_id) REFERENCES grupos(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Sesiones de clase (una fecha de encuentro para un grupo)
CREATE TABLE sesiones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_id INT NOT NULL,
  fecha DATE NOT NULL,
  tema VARCHAR(200) NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (grupo_id) REFERENCES grupos(id) ON DELETE RESTRICT,
  UNIQUE KEY uniq_grupo_fecha (grupo_id, fecha)
) ENGINE=InnoDB;

-- Registro de asistencia por estudiante y sesión.
-- categoria: 'completo' = misa y catequesis (1.0 punto), 'catequesis' = solo catequesis (0.5),
--            'misa' = solo misa (0.5), 'ausente' = no fue a ninguna (0.0).
-- puntos se calcula solo a partir de categoria, no se edita directamente.
CREATE TABLE asistencias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sesion_id INT NOT NULL,
  estudiante_id INT NOT NULL,
  categoria ENUM('completo','catequesis','misa','ausente') NOT NULL DEFAULT 'completo',
  puntos DECIMAL(2,1) GENERATED ALWAYS AS (
    CASE categoria
      WHEN 'completo' THEN 1.0
      WHEN 'catequesis' THEN 0.5
      WHEN 'misa' THEN 0.5
      ELSE 0.0
    END
  ) STORED,
  nota VARCHAR(255) NULL,
  FOREIGN KEY (sesion_id) REFERENCES sesiones(id) ON DELETE CASCADE,
  FOREIGN KEY (estudiante_id) REFERENCES estudiantes(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_sesion_estudiante (sesion_id, estudiante_id)
) ENGINE=InnoDB;

-- Actividades extracurriculares/parroquiales (retiros, procesiones, misas especiales...).
-- No son los sábados de catequesis regular y NO afectan los puntos de asistencia:
-- son un registro aparte, solo para llevar control de participación parroquial.
CREATE TABLE actividades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_id INT NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  fecha DATE NOT NULL,
  descripcion VARCHAR(255) NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (grupo_id) REFERENCES grupos(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE actividad_asistencias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actividad_id INT NOT NULL,
  estudiante_id INT NOT NULL,
  asistio TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (actividad_id) REFERENCES actividades(id) ON DELETE CASCADE,
  FOREIGN KEY (estudiante_id) REFERENCES estudiantes(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_actividad_estudiante (actividad_id, estudiante_id)
) ENGINE=InnoDB;

-- Datos de ejemplo (puedes borrarlos luego)
INSERT INTO grupos (nombre, descripcion, dia_semana) VALUES
('Primera Comunión - Nivel 1', 'Grupo de iniciación', 'Sábado'),
('Confirmación - Nivel 2', 'Grupo de confirmación', 'Domingo');
