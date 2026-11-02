<?php
// Configuración de encabezados para API REST
if (isset($_SERVER['REQUEST_METHOD'])) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$db_host = '127.0.0.1';
$db_port = '3306';
$db_name = 'nucleo';
$db_user = 'root';
$db_pass = '';

try {
    // 1. Conectar al servidor MySQL
    $pdo_init = new PDO("mysql:host=$db_host;port=$db_port;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 2. Crear base de datos si no existe
    $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo_init->exec("USE `$db_name`");

    $pdo = $pdo_init;

    // 3. Crear Tablas Principales si no existen
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `usuarios` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `fullname` VARCHAR(100) NOT NULL,
            `company` VARCHAR(100) NOT NULL,
            `email` VARCHAR(150) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `clientes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `usuario_id` INT NOT NULL,
            `nombre` VARCHAR(150) NOT NULL,
            `tipo_persona` ENUM('fisica', 'moral') NOT NULL DEFAULT 'fisica',
            `email` VARCHAR(150) NULL,
            `telefono` VARCHAR(50) NULL,
            `whatsapp` VARCHAR(50) NULL,
            `rfc` VARCHAR(20) NULL,
            `curp` VARCHAR(30) NULL,
            `direccion` TEXT NULL,
            `notas` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `polizas` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `usuario_id` INT NOT NULL,
            `cliente_id` INT NOT NULL,
            `numero_poliza` VARCHAR(100) NOT NULL,
            `aseguradora` VARCHAR(100) NOT NULL,
            `ramo` VARCHAR(100) NOT NULL,
            `vigencia_desde` DATE NOT NULL,
            `vigencia_hasta` DATE NOT NULL,
            `forma_pago` VARCHAR(50) NOT NULL DEFAULT 'Transferencia',
            `periodicidad` VARCHAR(50) NOT NULL DEFAULT 'Anual',
            `prima_neta` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `recargo_fraccionado` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `derecho_poliza` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `iva` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `prima_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `moneda` VARCHAR(10) NOT NULL DEFAULT 'MXN',
            `descripcion_cobertura` TEXT NULL,
            `estado_poliza` VARCHAR(50) NOT NULL DEFAULT 'activa',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`cliente_id`) REFERENCES `clientes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `cobranzas` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `usuario_id` INT NOT NULL,
            `poliza_id` INT NOT NULL,
            `numero_recibo` VARCHAR(100) NOT NULL,
            `monto` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `fecha_vencimiento` DATE NOT NULL,
            `estado` ENUM('pendiente', 'realizada', 'vencida') NOT NULL DEFAULT 'pendiente',
            `fecha_pago` DATETIME NULL,
            `metodo_pago` VARCHAR(50) NULL,
            `referencia_pago` VARCHAR(100) NULL,
            `comprobante_archivo` VARCHAR(255) NULL,
            `notas` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`poliza_id`) REFERENCES `polizas`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `documentos` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `usuario_id` INT NOT NULL,
            `cliente_id` INT NULL,
            `poliza_id` INT NULL,
            `tipo_documento` VARCHAR(50) NOT NULL,
            `titulo` VARCHAR(255) NOT NULL,
            `archivo_nombre_original` VARCHAR(255) NOT NULL,
            `archivo_ruta` VARCHAR(255) NOT NULL,
            `archivo_tamano` INT NOT NULL DEFAULT 0,
            `archivo_extension` VARCHAR(20) NOT NULL,
            `fecha_subida` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `notas` TEXT NULL,
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `equipos` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `usuario_id` INT NOT NULL,
            `nombre` VARCHAR(150) NOT NULL,
            `descripcion` TEXT NULL,
            `color` VARCHAR(30) NOT NULL DEFAULT '#10b981',
            `lider_nombre` VARCHAR(150) NULL,
            `lider_email` VARCHAR(150) NULL,
            `permisos_modulos` TEXT NULL,
            `compartir_cartera` ENUM('empresa', 'equipo') NOT NULL DEFAULT 'empresa',
            `estado` ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `equipo_miembros` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `equipo_id` INT NOT NULL,
            `nombre` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `telefono` VARCHAR(50) NULL,
            `rol` ENUM('admin', 'agente', 'auditor') NOT NULL DEFAULT 'agente',
            `estado` ENUM('activo', 'pendiente') NOT NULL DEFAULT 'activo',
            `fecha_ingreso` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`equipo_id`) REFERENCES `equipos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `tareas` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `usuario_id` INT NOT NULL,
            `cliente_id` INT NULL,
            `titulo` VARCHAR(255) NOT NULL,
            `fecha` DATE NOT NULL,
            `hora` TIME NULL,
            `completada` TINYINT(1) NOT NULL DEFAULT 0,
            `notas` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Migración dinámica de columnas (por si la tabla fue creada en una versión previa)
    $migration_queries = [
        // Usuarios
        "ALTER TABLE `usuarios` ADD COLUMN IF NOT EXISTS `rfc` VARCHAR(30) NULL",
        "ALTER TABLE `usuarios` ADD COLUMN IF NOT EXISTS `telefono` VARCHAR(50) NULL",
        "ALTER TABLE `usuarios` ADD COLUMN IF NOT EXISTS `moneda` VARCHAR(10) NOT NULL DEFAULT 'MXN'",
        // Clientes
        "ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `tipo_persona` ENUM('fisica', 'moral') NOT NULL DEFAULT 'fisica'",
        "ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `whatsapp` VARCHAR(50) NULL",
        "ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `curp` VARCHAR(30) NULL",
        "ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `direccion` TEXT NULL",
        "ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `notas` TEXT NULL",
        // Pólizas
        "ALTER TABLE `polizas` ADD COLUMN IF NOT EXISTS `forma_pago` VARCHAR(50) NOT NULL DEFAULT 'Transferencia'",
        "ALTER TABLE `polizas` ADD COLUMN IF NOT EXISTS `periodicidad` VARCHAR(50) NOT NULL DEFAULT 'Anual'",
        "ALTER TABLE `polizas` ADD COLUMN IF NOT EXISTS `prima_neta` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE `polizas` ADD COLUMN IF NOT EXISTS `recargo_fraccionado` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE `polizas` ADD COLUMN IF NOT EXISTS `derecho_poliza` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE `polizas` ADD COLUMN IF NOT EXISTS `iva` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE `polizas` ADD COLUMN IF NOT EXISTS `moneda` VARCHAR(10) NOT NULL DEFAULT 'MXN'",
        "ALTER TABLE `polizas` ADD COLUMN IF NOT EXISTS `descripcion_cobertura` TEXT NULL",
        "ALTER TABLE `polizas` MODIFY COLUMN `estado_poliza` VARCHAR(50) NOT NULL DEFAULT 'activa'",
        // Cobranzas
        "ALTER TABLE `cobranzas` ADD COLUMN IF NOT EXISTS `metodo_pago` VARCHAR(50) NULL",
        "ALTER TABLE `cobranzas` ADD COLUMN IF NOT EXISTS `referencia_pago` VARCHAR(100) NULL",
        "ALTER TABLE `cobranzas` ADD COLUMN IF NOT EXISTS `comprobante_archivo` VARCHAR(255) NULL",
        "ALTER TABLE `cobranzas` ADD COLUMN IF NOT EXISTS `fecha_inicio_recibo` DATE NULL",
        "ALTER TABLE `cobranzas` ADD COLUMN IF NOT EXISTS `fecha_limite_pago` DATE NULL"
    ];

    foreach ($migration_queries as $q) {
        try {
            $pdo->exec($q);
        } catch (Exception $e) {
            // Ignorar si ya existe o sintaxis de versión anterior de MariaDB
        }
    }

    // 5. Semilla inicial de equipos administrativos demostrativos si la tabla está vacía
    try {
        $countEquipos = (int)$pdo->query("SELECT COUNT(*) FROM `equipos`")->fetchColumn();
        if ($countEquipos === 0) {
            $firstUser = $pdo->query("SELECT id, fullname, email FROM `usuarios` ORDER BY id ASC LIMIT 1")->fetch();
            $adminUid = $firstUser ? (int)$firstUser['id'] : 1;
            $adminName = $firstUser ? $firstUser['fullname'] : 'Administrador Principal';
            $adminEmail = $firstUser ? $firstUser['email'] : 'admin@nucleo.com';

            // Equipo 1: Comercial
            $stmt = $pdo->prepare("INSERT INTO `equipos` (`usuario_id`, `nombre`, `descripcion`, `color`, `lider_nombre`, `lider_email`, `permisos_modulos`, `compartir_cartera`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $adminUid,
                'Equipo Comercial y Ventas',
                'Responsable de prospección, cotización y emisión de pólizas de Autos, Gastos Médicos y Vida.',
                '#10b981',
                $adminName,
                $adminEmail,
                json_encode(['dashboard', 'polizas', 'clientes', 'documentos']),
                'empresa'
            ]);
            $eq1 = $pdo->lastInsertId();

            $stmtM = $pdo->prepare("INSERT INTO `equipo_miembros` (`equipo_id`, `nombre`, `email`, `telefono`, `rol`, `estado`) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtM->execute([$eq1, $adminName, $adminEmail, '55 1234 5678', 'admin', 'activo']);
            $stmtM->execute([$eq1, 'Sofia Morales', 'sofia.morales@seguros.com', '55 8765 4321', 'agente', 'activo']);
            $stmtM->execute([$eq1, 'Andrea Ríos', 'andrea.rios@seguros.com', '55 4321 8765', 'agente', 'pendiente']);

            // Equipo 2: Cobranza
            $stmt->execute([
                $adminUid,
                'Cobranza y Control Financiero',
                'Control de recibos fraccionados, registro de pagos SPEI y seguimiento de pólizas en mora.',
                '#0284c7',
                'Valeria Pacheco',
                'valeria.pacheco@seguros.com',
                json_encode(['dashboard', 'cobranza', 'documentos']),
                'empresa'
            ]);
            $eq2 = $pdo->lastInsertId();
            $stmtM->execute([$eq2, 'Valeria Pacheco', 'valeria.pacheco@seguros.com', '55 3322 1100', 'admin', 'activo']);
            $stmtM->execute([$eq2, 'Roberto Salinas', 'roberto.salinas@seguros.com', '55 9988 7766', 'agente', 'activo']);

            // Equipo 3: Atención al Cliente & Siniestros
            $stmt->execute([
                $adminUid,
                'Atención al Cliente y Siniestros',
                'Acompañamiento integral a asegurados en reportes de siniestro y digitalización de expedientes.',
                '#8b5cf6',
                'Carlos Agente',
                'carlos.agente@seguros.com',
                json_encode(['dashboard', 'clientes', 'documentos', 'polizas']),
                'empresa'
            ]);
            $eq3 = $pdo->lastInsertId();
            $stmtM->execute([$eq3, 'Carlos Agente', 'carlos.agente@seguros.com', '55 9876 5432', 'admin', 'activo']);
            $stmtM->execute([$eq3, 'Fernando Garza', 'fernando.garza@seguros.com', '55 6655 4433', 'auditor', 'activo']);
        }
    } catch (Exception $e) {
        // Ignorar en caso de error de seeding
    }

} catch (PDOException $e) {
    if (isset($_SERVER['REQUEST_METHOD'])) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => 'Error al conectar con MySQL o inicializar la base de datos: ' . $e->getMessage()
    ]);
    exit;
}