<?php
// Configuración de encabezados y CORS
if (isset($_SERVER['REQUEST_METHOD'])) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
    header("Content-Type: application/json; charset=UTF-8");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

require_once __DIR__ . '/db.php';

// Directorio de uploads
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

// Obtener payload JSON o POST/GET o CLI
$inputRaw = file_get_contents('php://input');
$jsonInput = json_decode($inputRaw, true) ?: [];
$requestData = array_merge($_GET, $_POST, $jsonInput);

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    parse_str(implode('&', array_slice($argv, 1)), $cliArgs);
    $requestData = array_merge($requestData, $cliArgs);
}

$action = $requestData['action'] ?? 'get_dashboard_analytics';

// Usuario de la sesión o usuario fallback
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    try {
        $stmt_u = $pdo->query("SELECT id FROM usuarios ORDER BY id ASC LIMIT 1");
        $u_row = $stmt_u->fetch();
        if ($u_row) {
            $user_id = $u_row['id'];
            $_SESSION['user_id'] = $user_id;
        } else {
            // Crear usuario administrador por defecto si no hay ninguno
            $hash = password_hash('admin123', PASSWORD_BCRYPT);
            $pdo->exec("INSERT INTO usuarios (fullname, company, email, password) VALUES ('Agente Principal', 'Núcleo Seguros', 'admin@nucleo.com', '$hash')");
            $user_id = $pdo->lastInsertId();
            $_SESSION['user_id'] = $user_id;
        }
    } catch (Exception $e) {}
}

// Auto-actualizar estados de recibos vencidos en la BD
try {
    $pdo->exec("UPDATE cobranzas SET estado = 'vencida' WHERE estado = 'pendiente' AND fecha_vencimiento < CURDATE()");
} catch (Exception $e) {}

// Auto-actualizar estado de pólizas (si tienen recibos vencidos no pagados o vigencia expiró)
try {
    // Si la vigencia ya terminó
    $pdo->exec("UPDATE polizas SET estado_poliza = 'vencida' WHERE estado_poliza = 'activa' AND vigencia_hasta < CURDATE()");
    
    // Si tiene recibos con estado 'vencida', la póliza pasa a 'vencida'
    $pdo->exec("
        UPDATE polizas p 
        SET estado_poliza = 'vencida' 
        WHERE estado_poliza = 'activa' 
        AND EXISTS (
            SELECT 1 FROM cobranzas c 
            WHERE c.poliza_id = p.id AND c.estado = 'vencida'
        )
    ");
} catch (Exception $e) {}


// ==========================================================================
// 1. DASHBOARD ANALYTICS
// ==========================================================================
if ($action === 'get_dashboard_analytics' || $action === 'get_summary') {
    try {
        $filtro_agente = trim($requestData['agente'] ?? '');
        $filtro_aseguradora = trim($requestData['aseguradora'] ?? '');
        $filtro_ramo = trim($requestData['ramo'] ?? '');
        $filtro_periodo = trim($requestData['periodo'] ?? '');
        $fecha_inicio = trim($requestData['fecha_inicio'] ?? '');
        $fecha_fin = trim($requestData['fecha_fin'] ?? '');

        $where_clauses = ["1=1"];
        $params = [];

        if (!empty($filtro_agente) && $filtro_agente !== 'todos') {
            if (is_numeric($filtro_agente)) {
                $where_clauses[] = "c.usuario_id = :agente_id";
                $params[':agente_id'] = $filtro_agente;
            }
        }

        if (!empty($filtro_aseguradora) && $filtro_aseguradora !== 'todas') {
            $where_clauses[] = "p.aseguradora = :aseguradora";
            $params[':aseguradora'] = $filtro_aseguradora;
        }

        if (!empty($filtro_ramo) && $filtro_ramo !== 'todos') {
            $where_clauses[] = "p.ramo = :ramo";
            $params[':ramo'] = $filtro_ramo;
        }

        if ($filtro_periodo === 'hoy') {
            $where_clauses[] = "c.fecha_vencimiento = CURDATE()";
        } elseif ($filtro_periodo === 'ultimos_7') {
            $where_clauses[] = "c.fecha_vencimiento >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($filtro_periodo === 'esta_semana') {
            $where_clauses[] = "YEARWEEK(c.fecha_vencimiento, 1) = YEARWEEK(CURDATE(), 1)";
        } elseif ($filtro_periodo === 'este_mes') {
            $where_clauses[] = "MONTH(c.fecha_vencimiento) = MONTH(CURDATE()) AND YEAR(c.fecha_vencimiento) = YEAR(CURDATE())";
        } elseif ($filtro_periodo === 'ultimos_30') {
            $where_clauses[] = "c.fecha_vencimiento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        } elseif ($filtro_periodo === 'personalizado' && !empty($fecha_inicio) && !empty($fecha_fin)) {
            $where_clauses[] = "c.fecha_vencimiento BETWEEN :f_inicio AND :f_fin";
            $params[':f_inicio'] = $fecha_inicio;
            $params[':f_fin'] = $fecha_fin;
        }

        $where_sql = implode(" AND ", $where_clauses);

        // Resumen Global de Cobranzas
        $sql_kpi = "
            SELECT 
                COALESCE(SUM(CASE WHEN c.estado = 'pendiente' THEN c.monto ELSE 0 END), 0) AS pendiente_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'pendiente' THEN 1 ELSE 0 END), 0) AS pendiente_cant,
                COALESCE(SUM(CASE WHEN c.estado = 'realizada' THEN c.monto ELSE 0 END), 0) AS realizada_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'realizada' THEN 1 ELSE 0 END), 0) AS realizada_cant,
                COALESCE(SUM(CASE WHEN c.estado = 'vencida' THEN c.monto ELSE 0 END), 0) AS vencida_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'vencida' THEN 1 ELSE 0 END), 0) AS vencida_cant,
                COALESCE(SUM(c.monto), 0) AS total_monto,
                COUNT(c.id) AS total_recibos
            FROM cobranzas c
            JOIN polizas p ON c.poliza_id = p.id
            WHERE $where_sql
        ";
        $stmt_kpi = $pdo->prepare($sql_kpi);
        $stmt_kpi->execute($params);
        $kpi = $stmt_kpi->fetch(PDO::FETCH_ASSOC);

        // Métricas Totales de Pólizas y Clientes
        $total_polizas = $pdo->query("SELECT COUNT(*) FROM polizas")->fetchColumn();
        $polizas_activas = $pdo->query("SELECT COUNT(*) FROM polizas WHERE estado_poliza = 'activa'")->fetchColumn();
        $polizas_vencidas = $pdo->query("SELECT COUNT(*) FROM polizas WHERE estado_poliza = 'vencida'")->fetchColumn();
        $total_clientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
        $total_documentos = $pdo->query("SELECT COUNT(*) FROM documentos")->fetchColumn();

        // Distribución por Ramo
        $sql_ramo = "
            SELECT 
                p.ramo,
                COUNT(c.id) AS total_recibos,
                COALESCE(SUM(c.monto), 0) AS total_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'pendiente' THEN c.monto ELSE 0 END), 0) AS pendiente_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'realizada' THEN c.monto ELSE 0 END), 0) AS realizada_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'vencida' THEN c.monto ELSE 0 END), 0) AS vencida_monto
            FROM cobranzas c
            JOIN polizas p ON c.poliza_id = p.id
            WHERE $where_sql
            GROUP BY p.ramo
            ORDER BY total_monto DESC
        ";
        $stmt_ramo = $pdo->prepare($sql_ramo);
        $stmt_ramo->execute($params);
        $ramos_metrics = $stmt_ramo->fetchAll(PDO::FETCH_ASSOC);

        // Métricas por Aseguradora
        $sql_aseg = "
            SELECT 
                p.aseguradora,
                COUNT(c.id) AS total_recibos,
                COALESCE(SUM(c.monto), 0) AS total_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'pendiente' THEN c.monto ELSE 0 END), 0) AS pendiente_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'realizada' THEN c.monto ELSE 0 END), 0) AS realizada_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'vencida' THEN c.monto ELSE 0 END), 0) AS vencida_monto
            FROM cobranzas c
            JOIN polizas p ON c.poliza_id = p.id
            WHERE $where_sql
            GROUP BY p.aseguradora
            ORDER BY total_monto DESC
            LIMIT 10
        ";
        $stmt_aseg = $pdo->prepare($sql_aseg);
        $stmt_aseg->execute($params);
        $aseguradoras_metrics = $stmt_aseg->fetchAll(PDO::FETCH_ASSOC);

        // Métricas por Agente
        $sql_agente = "
            SELECT 
                u.id AS agente_id,
                u.fullname AS agente_nombre,
                u.company AS agente_empresa,
                COUNT(c.id) AS total_recibos,
                COALESCE(SUM(c.monto), 0) AS total_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'pendiente' THEN c.monto ELSE 0 END), 0) AS pendiente_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'realizada' THEN c.monto ELSE 0 END), 0) AS realizada_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'vencida' THEN c.monto ELSE 0 END), 0) AS vencida_monto
            FROM cobranzas c
            JOIN polizas p ON c.poliza_id = p.id
            JOIN usuarios u ON c.usuario_id = u.id
            WHERE $where_sql
            GROUP BY u.id, u.fullname, u.company
            ORDER BY total_monto DESC
            LIMIT 10
        ";
        $stmt_agente = $pdo->prepare($sql_agente);
        $stmt_agente->execute($params);
        $agentes_metrics = $stmt_agente->fetchAll(PDO::FETCH_ASSOC);

        // Lista de Agentes para Selectores
        $stmt_list_u = $pdo->query("SELECT id, fullname, company, email FROM usuarios ORDER BY fullname ASC");
        $lista_agentes = $stmt_list_u->fetchAll(PDO::FETCH_ASSOC);

        // Lista de Aseguradoras encontradas
        $stmt_list_aseg = $pdo->query("SELECT DISTINCT aseguradora FROM polizas WHERE aseguradora != '' ORDER BY aseguradora ASC");
        $lista_aseguradoras = $stmt_list_aseg->fetchAll(PDO::FETCH_COLUMN);

        // Recibos próximos o vencidos urgentes (Alertas de Cobranza)
        // Regla: Recordatorios de pago 1 semana antes (<= 7 días) y el día que debe pagar (0 días)
        $sql_urgentes = "
            SELECT 
                c.id, c.numero_recibo, c.monto, c.fecha_vencimiento, c.estado,
                p.numero_poliza, p.aseguradora, p.ramo,
                cl.nombre AS cliente_nombre, cl.telefono, cl.whatsapp,
                DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_para_vencer,
                CASE 
                    WHEN c.fecha_vencimiento < CURDATE() THEN 'vencido'
                    WHEN c.fecha_vencimiento = CURDATE() THEN 'hoy'
                    WHEN DATEDIFF(c.fecha_vencimiento, CURDATE()) <= 7 THEN '1_semana'
                    ELSE 'proximo'
                END AS tipo_recordatorio
            FROM cobranzas c
            JOIN polizas p ON c.poliza_id = p.id
            JOIN clientes cl ON p.cliente_id = cl.id
            WHERE c.estado IN ('pendiente', 'vencida')
              AND (c.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) OR c.estado = 'vencida')
            ORDER BY c.fecha_vencimiento ASC
            LIMIT 10
        ";
        $alertas_cobranza = $pdo->query($sql_urgentes)->fetchAll(PDO::FETCH_ASSOC);

        // Renovaciones de Póliza: aparecen 1 mes antes (<= 30 días del fin de vigencia)
        $sql_renovaciones = "
            SELECT 
                p.id, p.numero_poliza, p.aseguradora, p.ramo, p.vigencia_hasta, p.prima_total,
                cl.nombre AS cliente_nombre, cl.telefono, cl.whatsapp,
                DATEDIFF(p.vigencia_hasta, CURDATE()) AS dias_para_vencer
            FROM polizas p
            JOIN clientes cl ON p.cliente_id = cl.id
            WHERE p.estado_poliza = 'activa'
              AND p.vigencia_hasta <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY p.vigencia_hasta ASC
            LIMIT 10
        ";
        $alertas_renovaciones = $pdo->query($sql_renovaciones)->fetchAll(PDO::FETCH_ASSOC);

        $total_cobrado = floatval($kpi['realizada_monto']);
        $total_general = floatval($kpi['total_monto']);
        $eficiencia = $total_general > 0 ? round(($total_cobrado / $total_general) * 100, 1) : 0;
        $ticket_promedio = intval($kpi['total_recibos']) > 0 ? round($total_general / intval($kpi['total_recibos']), 2) : 0;

        echo json_encode([
            'success' => true,
            'summary' => [
                'pendiente' => [
                    'monto' => floatval($kpi['pendiente_monto']),
                    'cantidad' => intval($kpi['pendiente_cant'])
                ],
                'realizada' => [
                    'monto' => floatval($kpi['realizada_monto']),
                    'cantidad' => intval($kpi['realizada_cant'])
                ],
                'vencida' => [
                    'monto' => floatval($kpi['vencida_monto']),
                    'cantidad' => intval($kpi['vencida_cant'])
                ],
                'total_monto' => $total_general,
                'total_recibos' => intval($kpi['total_recibos']),
                'eficiencia_porcentaje' => $eficiencia,
                'ticket_promedio' => $ticket_promedio,
                'total_polizas' => intval($total_polizas),
                'polizas_activas' => intval($polizas_activas),
                'polizas_vencidas' => intval($polizas_vencidas),
                'total_clientes' => intval($total_clientes),
                'total_documentos' => intval($total_documentos)
            ],
            'cobranza_por_ramo' => $ramos_metrics,
            'cobranza_por_aseguradora' => $aseguradoras_metrics,
            'cobranza_por_agente' => $agentes_metrics,
            'alertas_cobranza' => $alertas_cobranza,
            'alertas_renovaciones' => $alertas_renovaciones,
            'lista_agentes' => $lista_agentes,
            'lista_aseguradoras' => $lista_aseguradoras
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error en analítica de dashboard: ' . $e->getMessage()]);
    }
    exit;
}


// ==========================================================================
// 2. MÓDULO DE PÓLIZAS
// ==========================================================================
if ($action === 'get_polizas') {
    try {
        $q = trim($requestData['q'] ?? '');
        $estado = trim($requestData['estado'] ?? '');
        $ramo = trim($requestData['ramo'] ?? '');
        $aseguradora = trim($requestData['aseguradora'] ?? '');
        $cliente_id = trim($requestData['cliente_id'] ?? '');
        $agente_id = trim($requestData['agente_id'] ?? '');

        $where = ["1=1"];
        $params = [];

        if (!empty($q)) {
            $where[] = "(p.numero_poliza LIKE :q OR cl.nombre LIKE :q OR p.aseguradora LIKE :q)";
            $params[':q'] = "%$q%";
        }

        if (!empty($estado) && $estado !== 'todos') {
            $where[] = "p.estado_poliza = :estado";
            $params[':estado'] = $estado;
        }

        if (!empty($ramo) && $ramo !== 'todos') {
            $where[] = "p.ramo = :ramo";
            $params[':ramo'] = $ramo;
        }

        if (!empty($aseguradora) && $aseguradora !== 'todas') {
            $where[] = "p.aseguradora = :aseguradora";
            $params[':aseguradora'] = $aseguradora;
        }

        if (!empty($cliente_id) && is_numeric($cliente_id)) {
            $where[] = "p.cliente_id = :cliente_id";
            $params[':cliente_id'] = $cliente_id;
        }

        if (!empty($agente_id) && is_numeric($agente_id)) {
            $where[] = "p.usuario_id = :agente_id";
            $params[':agente_id'] = $agente_id;
        }

        $where_sql = implode(" AND ", $where);

        $sql = "
            SELECT 
                p.*,
                cl.nombre AS cliente_nombre,
                cl.email AS cliente_email,
                cl.telefono AS cliente_telefono,
                cl.whatsapp AS cliente_whatsapp,
                cl.rfc AS cliente_rfc,
                u.fullname AS agente_nombre,
                u.company AS agente_empresa,
                (SELECT COUNT(*) FROM cobranzas c WHERE c.poliza_id = p.id) AS recibos_totales,
                (SELECT COUNT(*) FROM cobranzas c WHERE c.poliza_id = p.id AND c.estado = 'realizada') AS recibos_pagados,
                (SELECT COUNT(*) FROM cobranzas c WHERE c.poliza_id = p.id AND c.estado = 'pendiente') AS recibos_pendientes,
                (SELECT COUNT(*) FROM cobranzas c WHERE c.poliza_id = p.id AND c.estado = 'vencida') AS recibos_vencidos,
                (SELECT COUNT(*) FROM documentos d WHERE d.poliza_id = p.id) AS documentos_total
            FROM polizas p
            JOIN clientes cl ON p.cliente_id = cl.id
            JOIN usuarios u ON p.usuario_id = u.id
            WHERE $where_sql
            ORDER BY p.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $polizas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcular estatus dinámico para cada póliza
        foreach ($polizas as &$pol) {
            $vencida_por_fecha = (!empty($pol['vigencia_hasta']) && strtotime($pol['vigencia_hasta']) < strtotime(date('Y-m-d')));
            $tiene_adeudo = intval($pol['recibos_vencidos']) > 0;

            if ($pol['estado_poliza'] === 'cancelada') {
                $pol['estatus_visual'] = 'Cancelada';
                $pol['badge_class'] = 'badge-vencida';
            } elseif ($vencida_por_fecha) {
                $pol['estatus_visual'] = 'Vigencia Vencida';
                $pol['badge_class'] = 'badge-vencida';
            } elseif ($tiene_adeudo) {
                $pol['estatus_visual'] = 'Con Recibo Vencido';
                $pol['badge_class'] = 'badge-vencida';
            } else {
                $pol['estatus_visual'] = 'Vigente';
                $pol['badge_class'] = 'badge-realizada';
            }
        }

        echo json_encode(['success' => true, 'polizas' => $polizas]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener pólizas: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_poliza') {
    try {
        $id = intval($requestData['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de póliza inválido.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                cl.nombre AS cliente_nombre,
                cl.email AS cliente_email,
                cl.telefono AS cliente_telefono,
                cl.whatsapp AS cliente_whatsapp,
                cl.rfc AS cliente_rfc,
                cl.curp AS cliente_curp,
                cl.direccion AS cliente_direccion,
                u.fullname AS agente_nombre,
                u.company AS agente_empresa
            FROM polizas p
            JOIN clientes cl ON p.cliente_id = cl.id
            JOIN usuarios u ON p.usuario_id = u.id
            WHERE p.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $poliza = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$poliza) {
            echo json_encode(['success' => false, 'message' => 'Póliza no encontrada.']);
            exit;
        }

        // Obtener Recibos de Cobranza de la póliza ordenados cronológicamente
        $stmt_c = $pdo->prepare("SELECT * FROM cobranzas WHERE poliza_id = :id ORDER BY fecha_inicio_recibo ASC, fecha_vencimiento ASC, id ASC");
        $stmt_c->execute([':id' => $id]);
        $recibos = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

        $monto_pagado = 0;
        $monto_pendiente = 0;
        $cuotas_pagadas = 0;
        foreach ($recibos as &$r) {
            if (empty($r['fecha_inicio_recibo'])) $r['fecha_inicio_recibo'] = $r['fecha_vencimiento'];
            if (empty($r['fecha_limite_pago'])) $r['fecha_limite_pago'] = $r['fecha_vencimiento'];
            if ($r['estado'] === 'realizada') {
                $monto_pagado += floatval($r['monto']);
                $cuotas_pagadas++;
            } else {
                $monto_pendiente += floatval($r['monto']);
            }
        }

        $resumen_pagos = [
            'total_cuotas' => count($recibos),
            'cuotas_pagadas' => $cuotas_pagadas,
            'monto_total' => floatval($poliza['prima_total']),
            'monto_pagado' => $monto_pagado,
            'monto_pendiente' => $monto_pendiente
        ];

        // Obtener Documentos Adjuntos
        $stmt_d = $pdo->prepare("SELECT * FROM documentos WHERE poliza_id = :id ORDER BY id DESC");
        $stmt_d->execute([':id' => $id]);
        $documentos = $stmt_d->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'poliza' => $poliza,
            'recibos' => $recibos,
            'resumen_pagos' => $resumen_pagos,
            'documentos' => $documentos
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener póliza: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_poliza') {
    try {
        $id = intval($requestData['id'] ?? 0);
        $cliente_id = intval($requestData['cliente_id'] ?? 0);
        $agente_id = intval($requestData['usuario_id'] ?? $user_id);
        $numero_poliza = trim($requestData['numero_poliza'] ?? '');
        $aseguradora = trim($requestData['aseguradora'] ?? '');
        $ramo = trim($requestData['ramo'] ?? '');
        $vigencia_desde = trim($requestData['vigencia_desde'] ?? '');
        $vigencia_hasta = trim($requestData['vigencia_hasta'] ?? '');
        $forma_pago = trim($requestData['forma_pago'] ?? 'Transferencia');
        $periodicidad = trim($requestData['periodicidad'] ?? 'Anual');

        $prima_neta = floatval($requestData['prima_neta'] ?? 0);
        $recargo_fraccionado = floatval($requestData['recargo_fraccionado'] ?? 0);
        $derecho_poliza = floatval($requestData['derecho_poliza'] ?? 0);
        $iva = floatval($requestData['iva'] ?? 0);
        $prima_total = floatval($requestData['prima_total'] ?? 0);
        $moneda = trim($requestData['moneda'] ?? 'MXN');
        $descripcion_cobertura = trim($requestData['descripcion_cobertura'] ?? '');
        $estado_poliza = trim($requestData['estado_poliza'] ?? 'activa');

        // Validaciones obligatorias
        if (empty($numero_poliza)) {
            echo json_encode(['success' => false, 'message' => 'Ingresa el número de póliza.']);
            exit;
        }
        if ($cliente_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Selecciona o registra el cliente/contratante.']);
            exit;
        }
        if (empty($aseguradora)) {
            echo json_encode(['success' => false, 'message' => 'Indica la compañía aseguradora.']);
            exit;
        }
        if (empty($ramo)) {
            echo json_encode(['success' => false, 'message' => 'Selecciona el tipo de ramo de la póliza.']);
            exit;
        }
        if (empty($vigencia_desde) || empty($vigencia_hasta)) {
            echo json_encode(['success' => false, 'message' => 'Debes especificar la fecha de inicio y la vigencia.']);
            exit;
        }

        // Si la prima_total no se especificó o es cero, calcular suma
        if ($prima_total <= 0) {
            $prima_total = $prima_neta + $recargo_fraccionado + $derecho_poliza + $iva;
        }

        if ($id > 0) {
            // Actualizar póliza existente
            $stmt = $pdo->prepare("
                UPDATE polizas SET 
                    usuario_id = :usuario_id,
                    cliente_id = :cliente_id,
                    numero_poliza = :numero_poliza,
                    aseguradora = :aseguradora,
                    ramo = :ramo,
                    vigencia_desde = :vigencia_desde,
                    vigencia_hasta = :vigencia_hasta,
                    forma_pago = :forma_pago,
                    periodicidad = :periodicidad,
                    prima_neta = :prima_neta,
                    recargo_fraccionado = :recargo_fraccionado,
                    derecho_poliza = :derecho_poliza,
                    iva = :iva,
                    prima_total = :prima_total,
                    moneda = :moneda,
                    descripcion_cobertura = :descripcion_cobertura,
                    estado_poliza = :estado_poliza
                WHERE id = :id
            ");
            $stmt->execute([
                ':usuario_id' => $agente_id,
                ':cliente_id' => $cliente_id,
                ':numero_poliza' => $numero_poliza,
                ':aseguradora' => $aseguradora,
                ':ramo' => $ramo,
                ':vigencia_desde' => $vigencia_desde,
                ':vigencia_hasta' => $vigencia_hasta,
                ':forma_pago' => $forma_pago,
                ':periodicidad' => $periodicidad,
                ':prima_neta' => $prima_neta,
                ':recargo_fraccionado' => $recargo_fraccionado,
                ':derecho_poliza' => $derecho_poliza,
                ':iva' => $iva,
                ':prima_total' => $prima_total,
                ':moneda' => $moneda,
                ':descripcion_cobertura' => $descripcion_cobertura,
                ':estado_poliza' => $estado_poliza,
                ':id' => $id
            ]);
            $poliza_id = $id;
            $msg = 'Póliza actualizada exitosamente.';
        } else {
            // Crear nueva póliza
            $stmt = $pdo->prepare("
                INSERT INTO polizas (
                    usuario_id, cliente_id, numero_poliza, aseguradora, ramo,
                    vigencia_desde, vigencia_hasta, forma_pago, periodicidad,
                    prima_neta, recargo_fraccionado, derecho_poliza, iva,
                    prima_total, moneda, descripcion_cobertura, estado_poliza
                ) VALUES (
                    :usuario_id, :cliente_id, :numero_poliza, :aseguradora, :ramo,
                    :vigencia_desde, :vigencia_hasta, :forma_pago, :periodicidad,
                    :prima_neta, :recargo_fraccionado, :derecho_poliza, :iva,
                    :prima_total, :moneda, :descripcion_cobertura, :estado_poliza
                )
            ");
            $stmt->execute([
                ':usuario_id' => $agente_id,
                ':cliente_id' => $cliente_id,
                ':numero_poliza' => $numero_poliza,
                ':aseguradora' => $aseguradora,
                ':ramo' => $ramo,
                ':vigencia_desde' => $vigencia_desde,
                ':vigencia_hasta' => $vigencia_hasta,
                ':forma_pago' => $forma_pago,
                ':periodicidad' => $periodicidad,
                ':prima_neta' => $prima_neta,
                ':recargo_fraccionado' => $recargo_fraccionado,
                ':derecho_poliza' => $derecho_poliza,
                ':iva' => $iva,
                ':prima_total' => $prima_total,
                ':moneda' => $moneda,
                ':descripcion_cobertura' => $descripcion_cobertura,
                ':estado_poliza' => $estado_poliza
            ]);
            $poliza_id = $pdo->lastInsertId();
            $msg = 'Póliza registrada exitosamente.';

            // Generar calendario de recibos de cobranza según la periodicidad
            $num_cuotas = 1;
            $interval_months = 12;

            if ($periodicidad === 'Semestral') {
                $num_cuotas = 2;
                $interval_months = 6;
            } elseif ($periodicidad === 'Trimestral') {
                $num_cuotas = 4;
                $interval_months = 3;
            } elseif ($periodicidad === 'Mensual') {
                $num_cuotas = 12;
                $interval_months = 1;
            }

            $monto_cuota = round($prima_total / $num_cuotas, 2);
            $startDate = new DateTime($vigencia_desde);

            $stmt_recibo = $pdo->prepare("
                INSERT INTO cobranzas (
                    usuario_id, poliza_id, numero_recibo, monto,
                    fecha_inicio_recibo, fecha_limite_pago, fecha_vencimiento, estado
                ) VALUES (
                    :usuario_id, :poliza_id, :numero_recibo, :monto,
                    :fecha_inicio_recibo, :fecha_limite_pago, :fecha_vencimiento, :estado
                )
            ");

            for ($i = 1; $i <= $num_cuotas; $i++) {
                $recibo_date = clone $startDate;
                if ($i > 1) {
                    $recibo_date->modify('+' . (($i - 1) * $interval_months) . ' month');
                }
                $f_inicio_cuota = $recibo_date->format('Y-m-d');

                // Fecha límite de pago normalmente es un mes después
                $limite_date = clone $recibo_date;
                $limite_date->modify('+1 month');
                $f_limite = $limite_date->format('Y-m-d');

                $num_rec = "$i/$num_cuotas";

                // Si la fecha límite ya pasó, se marca vencida; si no, pendiente
                $est_rec = (strtotime($f_limite) < strtotime(date('Y-m-d'))) ? 'vencida' : 'pendiente';

                $stmt_recibo->execute([
                    ':usuario_id' => $agente_id,
                    ':poliza_id' => $poliza_id,
                    ':numero_recibo' => $num_rec,
                    ':monto' => $monto_cuota,
                    ':fecha_inicio_recibo' => $f_inicio_cuota,
                    ':fecha_limite_pago' => $f_limite,
                    ':fecha_vencimiento' => $f_limite,
                    ':estado' => $est_rec
                ]);
            }
        }

        // Manejar subida de archivo de póliza si se adjuntó
        if (isset($_FILES['archivo_poliza']) && $_FILES['archivo_poliza']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['archivo_poliza'];
            $origName = basename($file['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $newFileName = 'poliza_' . $poliza_id . '_' . time() . '.' . $ext;
            $destPath = $uploadDir . '/' . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $stmt_doc = $pdo->prepare("
                    INSERT INTO documentos (usuario_id, cliente_id, poliza_id, tipo_documento, titulo, archivo_nombre_original, archivo_ruta, archivo_tamano, archivo_extension)
                    VALUES (:usuario_id, :cliente_id, :poliza_id, 'poliza', :titulo, :orig_name, :ruta, :tamano, :ext)
                ");
                $stmt_doc->execute([
                    ':usuario_id' => $agente_id,
                    ':cliente_id' => $cliente_id,
                    ':poliza_id' => $poliza_id,
                    ':titulo' => 'Carátula de Póliza - ' . $numero_poliza,
                    ':orig_name' => $origName,
                    ':ruta' => 'uploads/' . $newFileName,
                    ':tamano' => $file['size'],
                    ':ext' => $ext
                ]);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'poliza_id' => $poliza_id
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al guardar la póliza: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_poliza') {
    try {
        $id = intval($requestData['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        // Obtener archivos adjuntos para borrarlos físicamente
        $stmt_docs = $pdo->prepare("SELECT archivo_ruta FROM documentos WHERE poliza_id = :id");
        $stmt_docs->execute([':id' => $id]);
        $files = $stmt_docs->fetchAll(PDO::FETCH_COLUMN);
        foreach ($files as $filePath) {
            $abs = __DIR__ . '/' . $filePath;
            if (is_file($abs)) @unlink($abs);
        }

        $stmt = $pdo->prepare("DELETE FROM polizas WHERE id = :id");
        $stmt->execute([':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Póliza y cobranzas asociadas eliminadas con éxito.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar póliza: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'renovar_poliza') {
    try {
        $poliza_id = intval($requestData['poliza_id'] ?? 0);
        if ($poliza_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Póliza a renovar inválida.']);
            exit;
        }

        // Obtener póliza original
        $stmt = $pdo->prepare("SELECT * FROM polizas WHERE id = :id");
        $stmt->execute([':id' => $poliza_id]);
        $orig = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$orig) {
            echo json_encode(['success' => false, 'message' => 'Póliza original no encontrada.']);
            exit;
        }

        // Datos para la renovación
        $nuevo_numero = trim($requestData['numero_poliza'] ?? '');
        if (empty($nuevo_numero)) {
            $nuevo_numero = $orig['numero_poliza'] . '-R' . date('Y');
        }

        $nueva_vigencia_desde = trim($requestData['vigencia_desde'] ?? $orig['vigencia_hasta']);
        $nueva_vigencia_hasta = trim($requestData['vigencia_hasta'] ?? date('Y-m-d', strtotime('+1 year', strtotime($nueva_vigencia_desde))));
        $prima_total = floatval($requestData['prima_total'] ?? $orig['prima_total']);
        $prima_neta = floatval($requestData['prima_neta'] ?? $orig['prima_neta']);
        $recargo_fraccionado = floatval($requestData['recargo_fraccionado'] ?? $orig['recargo_fraccionado']);
        $derecho_poliza = floatval($requestData['derecho_poliza'] ?? $orig['derecho_poliza']);
        $iva = floatval($requestData['iva'] ?? $orig['iva']);
        $forma_pago = trim($requestData['forma_pago'] ?? $orig['forma_pago']);
        $periodicidad = trim($requestData['periodicidad'] ?? $orig['periodicidad']);
        $agente_id = intval($requestData['usuario_id'] ?? $orig['usuario_id']);
        $cliente_id = intval($orig['cliente_id']);
        $aseguradora = $orig['aseguradora'];
        $ramo = $orig['ramo'];
        $moneda = $orig['moneda'] ?: 'MXN';
        $descripcion = trim($requestData['descripcion_cobertura'] ?? ("Renovación de póliza " . $orig['numero_poliza']));

        // Insertar nueva póliza renovada
        $stmt_ins = $pdo->prepare("
            INSERT INTO polizas (
                usuario_id, cliente_id, numero_poliza, aseguradora, ramo,
                vigencia_desde, vigencia_hasta, forma_pago, periodicidad,
                prima_neta, recargo_fraccionado, derecho_poliza, iva,
                prima_total, moneda, descripcion_cobertura, estado_poliza
            ) VALUES (
                :usuario_id, :cliente_id, :numero_poliza, :aseguradora, :ramo,
                :vigencia_desde, :vigencia_hasta, :forma_pago, :periodicidad,
                :prima_neta, :recargo_fraccionado, :derecho_poliza, :iva,
                :prima_total, :moneda, :descripcion_cobertura, 'activa'
            )
        ");
        $stmt_ins->execute([
            ':usuario_id' => $agente_id,
            ':cliente_id' => $cliente_id,
            ':numero_poliza' => $nuevo_numero,
            ':aseguradora' => $aseguradora,
            ':ramo' => $ramo,
            ':vigencia_desde' => $nueva_vigencia_desde,
            ':vigencia_hasta' => $nueva_vigencia_hasta,
            ':forma_pago' => $forma_pago,
            ':periodicidad' => $periodicidad,
            ':prima_neta' => $prima_neta,
            ':recargo_fraccionado' => $recargo_fraccionado,
            ':derecho_poliza' => $derecho_poliza,
            ':iva' => $iva,
            ':prima_total' => $prima_total,
            ':moneda' => $moneda,
            ':descripcion_cobertura' => $descripcion
        ]);
        $nueva_poliza_id = $pdo->lastInsertId();

        // Generar recibos para la nueva póliza
        $num_cuotas = 1;
        $interval_months = 12;
        if ($periodicidad === 'Semestral') { $num_cuotas = 2; $interval_months = 6; }
        elseif ($periodicidad === 'Trimestral') { $num_cuotas = 4; $interval_months = 3; }
        elseif ($periodicidad === 'Mensual') { $num_cuotas = 12; $interval_months = 1; }

        $monto_cuota = round($prima_total / $num_cuotas, 2);
        $startDate = new DateTime($nueva_vigencia_desde);

        $stmt_recibo = $pdo->prepare("
            INSERT INTO cobranzas (
                usuario_id, poliza_id, numero_recibo, monto,
                fecha_inicio_recibo, fecha_limite_pago, fecha_vencimiento, estado
            ) VALUES (
                :usuario_id, :poliza_id, :numero_recibo, :monto,
                :fecha_inicio_recibo, :fecha_limite_pago, :fecha_vencimiento, :estado
            )
        ");

        for ($i = 1; $i <= $num_cuotas; $i++) {
            $recibo_date = clone $startDate;
            if ($i > 1) {
                $recibo_date->modify('+' . (($i - 1) * $interval_months) . ' month');
            }
            $f_inicio_cuota = $recibo_date->format('Y-m-d');
            $limite_date = clone $recibo_date;
            $limite_date->modify('+1 month');
            $f_limite = $limite_date->format('Y-m-d');
            $num_rec = "$i/$num_cuotas";
            $est_rec = (strtotime($f_limite) < strtotime(date('Y-m-d'))) ? 'vencida' : 'pendiente';

            $stmt_recibo->execute([
                ':usuario_id' => $agente_id,
                ':poliza_id' => $nueva_poliza_id,
                ':numero_recibo' => $num_rec,
                ':monto' => $monto_cuota,
                ':fecha_inicio_recibo' => $f_inicio_cuota,
                ':fecha_limite_pago' => $f_limite,
                ':fecha_vencimiento' => $f_limite,
                ':estado' => $est_rec
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => '¡Póliza renovada exitosamente! Se generó el nuevo ciclo de vigencia y recibos.',
            'nueva_poliza_id' => $nueva_poliza_id
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al renovar póliza: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'agregar_recibo_manual') {
    try {
        $poliza_id = intval($requestData['poliza_id'] ?? 0);
        $monto = floatval($requestData['monto'] ?? 0);
        $numero_recibo = trim($requestData['numero_recibo'] ?? 'Extra');
        $fecha_inicio = trim($requestData['fecha_inicio_recibo'] ?? date('Y-m-d'));
        $fecha_limite = trim($requestData['fecha_limite_pago'] ?? date('Y-m-d', strtotime('+1 month')));
        $notas = trim($requestData['notas'] ?? '');

        if ($poliza_id <= 0 || $monto <= 0) {
            echo json_encode(['success' => false, 'message' => 'Datos inválidos para el recibo.']);
            exit;
        }

        $stmt_pol = $pdo->prepare("SELECT usuario_id FROM polizas WHERE id = :id");
        $stmt_pol->execute([':id' => $poliza_id]);
        $agente_id = $stmt_pol->fetchColumn() ?: $user_id;

        $est_rec = (strtotime($fecha_limite) < strtotime(date('Y-m-d'))) ? 'vencida' : 'pendiente';

        $stmt = $pdo->prepare("
            INSERT INTO cobranzas (
                usuario_id, poliza_id, numero_recibo, monto,
                fecha_inicio_recibo, fecha_limite_pago, fecha_vencimiento, estado, notas
            ) VALUES (
                :usuario_id, :poliza_id, :numero_recibo, :monto,
                :fecha_inicio, :fecha_limite, :fecha_limite, :estado, :notas
            )
        ");
        $stmt->execute([
            ':usuario_id' => $agente_id,
            ':poliza_id' => $poliza_id,
            ':numero_recibo' => $numero_recibo,
            ':monto' => $monto,
            ':fecha_inicio' => $fecha_inicio,
            ':fecha_limite' => $fecha_limite,
            ':estado' => $est_rec,
            ':notas' => $notas
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Recibo extra agregado correctamente.',
            'recibo_id' => $pdo->lastInsertId()
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al agregar recibo: ' . $e->getMessage()]);
    }
    exit;
}


// ==========================================================================
// 3. MÓDULO DE COBRANZA Y PAGOS
// ==========================================================================
if ($action === 'get_cobranzas') {
    try {
        $estado = trim($requestData['estado'] ?? '');
        $agente_id = trim($requestData['agente_id'] ?? '');
        $aseguradora = trim($requestData['aseguradora'] ?? '');
        $ramo = trim($requestData['ramo'] ?? '');
        $periodo = trim($requestData['periodo'] ?? '');
        $fecha_inicio = trim($requestData['fecha_inicio'] ?? '');
        $fecha_fin = trim($requestData['fecha_fin'] ?? '');
        $q = trim($requestData['q'] ?? '');

        $where = ["1=1"];
        $params = [];

        if (!empty($estado) && $estado !== 'todos') {
            $where[] = "c.estado = :estado";
            $params[':estado'] = $estado;
        }

        if (!empty($agente_id) && is_numeric($agente_id)) {
            $where[] = "c.usuario_id = :agente_id";
            $params[':agente_id'] = $agente_id;
        }

        if (!empty($aseguradora) && $aseguradora !== 'todas') {
            $where[] = "p.aseguradora = :aseguradora";
            $params[':aseguradora'] = $aseguradora;
        }

        if (!empty($ramo) && $ramo !== 'todos') {
            $where[] = "p.ramo = :ramo";
            $params[':ramo'] = $ramo;
        }

        if ($periodo === 'hoy') {
            $where[] = "c.fecha_vencimiento = CURDATE()";
        } elseif ($periodo === 'esta_semana') {
            $where[] = "YEARWEEK(c.fecha_vencimiento, 1) = YEARWEEK(CURDATE(), 1)";
        } elseif ($periodo === 'este_mes') {
            $where[] = "MONTH(c.fecha_vencimiento) = MONTH(CURDATE()) AND YEAR(c.fecha_vencimiento) = YEAR(CURDATE())";
        } elseif ($periodo === 'personalizado' && !empty($fecha_inicio) && !empty($fecha_fin)) {
            $where[] = "c.fecha_vencimiento BETWEEN :f_inicio AND :f_fin";
            $params[':f_inicio'] = $fecha_inicio;
            $params[':f_fin'] = $fecha_fin;
        }

        if (!empty($q)) {
            $where[] = "(p.numero_poliza LIKE :q OR cl.nombre LIKE :q OR c.numero_recibo LIKE :q OR p.aseguradora LIKE :q)";
            $params[':q'] = "%$q%";
        }

        $where_sql = implode(" AND ", $where);

        $sql = "
            SELECT 
                c.*,
                p.numero_poliza,
                p.aseguradora,
                p.ramo,
                p.forma_pago,
                p.periodicidad,
                cl.id AS cliente_id,
                cl.nombre AS cliente_nombre,
                cl.telefono AS cliente_telefono,
                cl.whatsapp AS cliente_whatsapp,
                cl.email AS cliente_email,
                u.fullname AS agente_nombre,
                DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_para_vencer
            FROM cobranzas c
            JOIN polizas p ON c.poliza_id = p.id
            JOIN clientes cl ON p.cliente_id = cl.id
            JOIN usuarios u ON c.usuario_id = u.id
            WHERE $where_sql
            ORDER BY c.fecha_vencimiento ASC, c.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $cobranzas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'cobranzas' => $cobranzas]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener cobranzas: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'marcar_pago') {
    try {
        $recibo_id = intval($requestData['recibo_id'] ?? 0);
        $metodo_pago = trim($requestData['metodo_pago'] ?? 'Transferencia');
        $referencia_pago = trim($requestData['referencia_pago'] ?? '');
        $fecha_pago = trim($requestData['fecha_pago'] ?? date('Y-m-d H:i:s'));
        $notas = trim($requestData['notas'] ?? '');

        if ($recibo_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de recibo no válido.']);
            exit;
        }

        // Obtener datos del recibo y póliza
        $stmt_r = $pdo->prepare("SELECT * FROM cobranzas WHERE id = :id");
        $stmt_r->execute([':id' => $recibo_id]);
        $recibo = $stmt_r->fetch(PDO::FETCH_ASSOC);

        if (!$recibo) {
            echo json_encode(['success' => false, 'message' => 'Recibo de cobranza no encontrado.']);
            exit;
        }

        $comprobante_archivo = $recibo['comprobante_archivo'];

        // Manejar archivo de comprobante si se subió
        if (isset($_FILES['comprobante_archivo']) && $_FILES['comprobante_archivo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['comprobante_archivo'];
            $origName = basename($file['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $newFileName = 'pago_' . $recibo_id . '_' . time() . '.' . $ext;
            $destPath = $uploadDir . '/' . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $comprobante_archivo = 'uploads/' . $newFileName;

                // Registrar en la tabla documentos
                $stmt_doc = $pdo->prepare("
                    INSERT INTO documentos (usuario_id, poliza_id, tipo_documento, titulo, archivo_nombre_original, archivo_ruta, archivo_tamano, archivo_extension)
                    VALUES (:usuario_id, :poliza_id, 'comprobante_pago', :titulo, :orig_name, :ruta, :tamano, :ext)
                ");
                $stmt_doc->execute([
                    ':usuario_id' => $recibo['usuario_id'],
                    ':poliza_id' => $recibo['poliza_id'],
                    ':titulo' => 'Comprobante de Pago Recibo #' . $recibo['numero_recibo'],
                    ':orig_name' => $origName,
                    ':ruta' => $comprobante_archivo,
                    ':tamano' => $file['size'],
                    ':ext' => $ext
                ]);
            }
        }

        // Actualizar recibo
        $stmt_up = $pdo->prepare("
            UPDATE cobranzas SET 
                estado = 'realizada',
                fecha_pago = :fecha_pago,
                metodo_pago = :metodo_pago,
                referencia_pago = :referencia_pago,
                comprobante_archivo = :comprobante_archivo,
                notas = :notas
            WHERE id = :id
        ");
        $stmt_up->execute([
            ':fecha_pago' => $fecha_pago,
            ':metodo_pago' => $metodo_pago,
            ':referencia_pago' => $referencia_pago,
            ':comprobante_archivo' => $comprobante_archivo,
            ':notas' => $notas,
            ':id' => $recibo_id
        ]);

        // Verificar si la póliza ya está al corriente (sin recibos vencidos)
        $poliza_id = $recibo['poliza_id'];
        $stmt_check = $pdo->prepare("
            SELECT COUNT(*) FROM cobranzas 
            WHERE poliza_id = :poliza_id AND estado = 'vencida'
        ");
        $stmt_check->execute([':poliza_id' => $poliza_id]);
        $vencidos_restantes = $stmt_check->fetchColumn();

        if ($vencidos_restantes == 0) {
            // Verificar que la fecha de vigencia no esté vencida
            $stmt_pol = $pdo->prepare("SELECT vigencia_hasta FROM polizas WHERE id = :id");
            $stmt_pol->execute([':id' => $poliza_id]);
            $vig_hasta = $stmt_pol->fetchColumn();

            if (strtotime($vig_hasta) >= strtotime(date('Y-m-d'))) {
                $pdo->prepare("UPDATE polizas SET estado_poliza = 'activa' WHERE id = :id")->execute([':id' => $poliza_id]);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => '¡Pago registrado exitosamente! La póliza y las métricas han sido actualizadas.',
            'recibo_id' => $recibo_id
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al marcar pago: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'anular_pago') {
    try {
        $recibo_id = intval($requestData['recibo_id'] ?? 0);
        if ($recibo_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        $stmt_r = $pdo->prepare("SELECT poliza_id, fecha_vencimiento FROM cobranzas WHERE id = :id");
        $stmt_r->execute([':id' => $recibo_id]);
        $recibo = $stmt_r->fetch(PDO::FETCH_ASSOC);

        if (!$recibo) {
            echo json_encode(['success' => false, 'message' => 'Recibo no encontrado.']);
            exit;
        }

        // Si la fecha ya venció, pasa a 'vencida', si no a 'pendiente'
        $nuevo_estado = (strtotime($recibo['fecha_vencimiento']) < strtotime(date('Y-m-d'))) ? 'vencida' : 'pendiente';

        $stmt_rev = $pdo->prepare("
            UPDATE cobranzas SET 
                estado = :estado,
                fecha_pago = NULL,
                metodo_pago = NULL,
                referencia_pago = NULL
            WHERE id = :id
        ");
        $stmt_rev->execute([
            ':estado' => $nuevo_estado,
            ':id' => $recibo_id
        ]);

        if ($nuevo_estado === 'vencida') {
            $pdo->prepare("UPDATE polizas SET estado_poliza = 'vencida' WHERE id = :id")->execute([':id' => $recibo['poliza_id']]);
        }

        echo json_encode(['success' => true, 'message' => 'Pago anulado. El recibo volvió a estado ' . $nuevo_estado . '.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al anular pago: ' . $e->getMessage()]);
    }
    exit;
}


// ==========================================================================
// 4. MÓDULO DE CLIENTES (CONTRATANTES)
// ==========================================================================
if ($action === 'get_clientes') {
    try {
        $q = trim($requestData['q'] ?? '');
        $where = ["1=1"];
        $params = [];

        if (!empty($q)) {
            $where[] = "(c.nombre LIKE :q OR c.email LIKE :q OR c.telefono LIKE :q OR c.whatsapp LIKE :q OR c.rfc LIKE :q)";
            $params[':q'] = "%$q%";
        }

        $where_sql = implode(" AND ", $where);

        $sql = "
            SELECT 
                c.*,
                (SELECT COUNT(*) FROM polizas p WHERE p.cliente_id = c.id) AS total_polizas,
                (SELECT COUNT(*) FROM polizas p WHERE p.cliente_id = c.id AND p.estado_poliza = 'activa') AS polizas_activas,
                (SELECT COUNT(*) FROM polizas p WHERE p.cliente_id = c.id AND p.estado_poliza = 'vencida') AS polizas_vencidas,
                (SELECT COALESCE(SUM(cob.monto), 0) FROM cobranzas cob JOIN polizas p ON cob.poliza_id = p.id WHERE p.cliente_id = c.id AND cob.estado IN ('pendiente', 'vencida')) AS saldo_pendiente,
                (SELECT COUNT(*) FROM documentos d WHERE d.cliente_id = c.id) AS total_documentos
            FROM clientes c
            WHERE $where_sql
            ORDER BY c.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Limpiar formato de teléfono y WhatsApp para enlaces directos
        foreach ($clientes as &$cli) {
            $cleanWa = preg_replace('/[^0-9]/', '', $cli['whatsapp'] ?? $cli['telefono'] ?? '');
            if (!empty($cleanWa) && strlen($cleanWa) === 10) {
                // Prefijo default México (+52) si tiene 10 dígitos
                $cleanWa = '52' . $cleanWa;
            }
            $cli['whatsapp_link'] = !empty($cleanWa) ? 'https://wa.me/' . $cleanWa : '';
        }

        echo json_encode(['success' => true, 'clientes' => $clientes]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener clientes: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_cliente') {
    try {
        $id = intval($requestData['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de cliente inválido.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cliente) {
            echo json_encode(['success' => false, 'message' => 'Cliente no encontrado.']);
            exit;
        }

        // WhatsApp link limpio
        $cleanWa = preg_replace('/[^0-9]/', '', $cliente['whatsapp'] ?? $cliente['telefono'] ?? '');
        if (!empty($cleanWa) && strlen($cleanWa) === 10) $cleanWa = '52' . $cleanWa;
        $cliente['whatsapp_link'] = !empty($cleanWa) ? 'https://wa.me/' . $cleanWa : '';

        // Pólizas del Cliente
        $stmt_pol = $pdo->prepare("SELECT * FROM polizas WHERE cliente_id = :id ORDER BY id DESC");
        $stmt_pol->execute([':id' => $id]);
        $polizas = $stmt_pol->fetchAll(PDO::FETCH_ASSOC);

        // Documentos del Cliente (INE, CSF, etc.)
        $stmt_doc = $pdo->prepare("SELECT * FROM documentos WHERE cliente_id = :id ORDER BY id DESC");
        $stmt_doc->execute([':id' => $id]);
        $documentos = $stmt_doc->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'cliente' => $cliente,
            'polizas' => $polizas,
            'documentos' => $documentos
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener cliente: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_cliente') {
    try {
        $id = intval($requestData['id'] ?? 0);
        $nombre = trim($requestData['nombre'] ?? '');
        $tipo_persona = trim($requestData['tipo_persona'] ?? 'fisica');
        $email = trim($requestData['email'] ?? '');
        $telefono = trim($requestData['telefono'] ?? '');
        $whatsapp = trim($requestData['whatsapp'] ?? '');
        $rfc = strtoupper(trim($requestData['rfc'] ?? ''));
        $curp = strtoupper(trim($requestData['curp'] ?? ''));
        $direccion = trim($requestData['direccion'] ?? '');
        $notas = trim($requestData['notas'] ?? '');

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'message' => 'Ingresa el nombre o razón social del cliente.']);
            exit;
        }

        if (empty($whatsapp) && !empty($telefono)) {
            $whatsapp = $telefono;
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE clientes SET 
                    nombre = :nombre,
                    tipo_persona = :tipo_persona,
                    email = :email,
                    telefono = :telefono,
                    whatsapp = :whatsapp,
                    rfc = :rfc,
                    curp = :curp,
                    direccion = :direccion,
                    notas = :notas
                WHERE id = :id
            ");
            $stmt->execute([
                ':nombre' => $nombre,
                ':tipo_persona' => $tipo_persona,
                ':email' => $email,
                ':telefono' => $telefono,
                ':whatsapp' => $whatsapp,
                ':rfc' => $rfc,
                ':curp' => $curp,
                ':direccion' => $direccion,
                ':notas' => $notas,
                ':id' => $id
            ]);
            $cliente_id = $id;
            $msg = 'Cliente actualizado con éxito.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO clientes (
                    usuario_id, nombre, tipo_persona, email, telefono, whatsapp, rfc, curp, direccion, notas
                ) VALUES (
                    :usuario_id, :nombre, :tipo_persona, :email, :telefono, :whatsapp, :rfc, :curp, :direccion, :notas
                )
            ");
            $stmt->execute([
                ':usuario_id' => $user_id,
                ':nombre' => $nombre,
                ':tipo_persona' => $tipo_persona,
                ':email' => $email,
                ':telefono' => $telefono,
                ':whatsapp' => $whatsapp,
                ':rfc' => $rfc,
                ':curp' => $curp,
                ':direccion' => $direccion,
                ':notas' => $notas
            ]);
            $cliente_id = $pdo->lastInsertId();
            $msg = 'Cliente registrado con éxito.';
        }

        // Subir documentos si se incluyeron en la petición (Identificación o CSF)
        $subidas = [
            'archivo_ine' => ['tipo' => 'identificacion', 'titulo' => 'Identificación Oficial (INE/Pasaporte)'],
            'archivo_csf' => ['tipo' => 'csf', 'titulo' => 'Constancia de Situación Fiscal (CSF)']
        ];

        foreach ($subidas as $fieldKey => $info) {
            if (isset($_FILES[$fieldKey]) && $_FILES[$fieldKey]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$fieldKey];
                $origName = basename($file['name']);
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $newFileName = $info['tipo'] . '_cli_' . $cliente_id . '_' . time() . '.' . $ext;
                $destPath = $uploadDir . '/' . $newFileName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $stmt_doc = $pdo->prepare("
                        INSERT INTO documentos (usuario_id, cliente_id, tipo_documento, titulo, archivo_nombre_original, archivo_ruta, archivo_tamano, archivo_extension)
                        VALUES (:usuario_id, :cliente_id, :tipo_documento, :titulo, :orig_name, :ruta, :tamano, :ext)
                    ");
                    $stmt_doc->execute([
                        ':usuario_id' => $user_id,
                        ':cliente_id' => $cliente_id,
                        ':tipo_documento' => $info['tipo'],
                        ':titulo' => $info['titulo'] . ' - ' . $nombre,
                        ':orig_name' => $origName,
                        ':ruta' => 'uploads/' . $newFileName,
                        ':tamano' => $file['size'],
                        ':ext' => $ext
                    ]);
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'cliente_id' => $cliente_id
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al guardar cliente: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_cliente') {
    try {
        $id = intval($requestData['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        // Borrar archivos físicos del cliente
        $stmt_docs = $pdo->prepare("SELECT archivo_ruta FROM documentos WHERE cliente_id = :id");
        $stmt_docs->execute([':id' => $id]);
        $files = $stmt_docs->fetchAll(PDO::FETCH_COLUMN);
        foreach ($files as $filePath) {
            $abs = __DIR__ . '/' . $filePath;
            if (is_file($abs)) @unlink($abs);
        }

        $stmt = $pdo->prepare("DELETE FROM clientes WHERE id = :id");
        $stmt->execute([':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Cliente y sus pólizas eliminados correctamente.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar cliente: ' . $e->getMessage()]);
    }
    exit;
}


// ==========================================================================
// 5. MÓDULO DE EXPEDIENTE DIGITAL / DOCUMENTOS
// ==========================================================================
if ($action === 'get_documentos') {
    try {
        $cliente_id = trim($requestData['cliente_id'] ?? '');
        $poliza_id = trim($requestData['poliza_id'] ?? '');
        $tipo_documento = trim($requestData['tipo_documento'] ?? '');
        $q = trim($requestData['q'] ?? '');

        $where = ["1=1"];
        $params = [];

        if (!empty($cliente_id) && is_numeric($cliente_id)) {
            $where[] = "d.cliente_id = :cliente_id";
            $params[':cliente_id'] = $cliente_id;
        }

        if (!empty($poliza_id) && is_numeric($poliza_id)) {
            $where[] = "d.poliza_id = :poliza_id";
            $params[':poliza_id'] = $poliza_id;
        }

        if (!empty($tipo_documento) && $tipo_documento !== 'todos') {
            $where[] = "d.tipo_documento = :tipo_doc";
            $params[':tipo_doc'] = $tipo_documento;
        }

        if (!empty($q)) {
            $where[] = "(d.titulo LIKE :q OR d.archivo_nombre_original LIKE :q OR cl.nombre LIKE :q OR p.numero_poliza LIKE :q)";
            $params[':q'] = "%$q%";
        }

        $where_sql = implode(" AND ", $where);

        $sql = "
            SELECT 
                d.*,
                cl.nombre AS cliente_nombre,
                p.numero_poliza,
                p.aseguradora,
                u.fullname AS subido_por
            FROM documentos d
            LEFT JOIN clientes cl ON d.cliente_id = cl.id
            LEFT JOIN polizas p ON d.poliza_id = p.id
            JOIN usuarios u ON d.usuario_id = u.id
            WHERE $where_sql
            ORDER BY d.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'documentos' => $documentos]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener documentos: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'upload_documento') {
    try {
        $cliente_id = !empty($requestData['cliente_id']) ? intval($requestData['cliente_id']) : null;
        $poliza_id = !empty($requestData['poliza_id']) ? intval($requestData['poliza_id']) : null;
        $tipo_documento = trim($requestData['tipo_documento'] ?? 'otro');
        $titulo = trim($requestData['titulo'] ?? '');
        $notas = trim($requestData['notas'] ?? '');

        if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No se seleccionó un archivo válido para subir.']);
            exit;
        }

        $file = $_FILES['archivo'];
        $origName = basename($file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // Extensiones permitidas
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'xml'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Formato de archivo no permitido. Solo PDF, imágenes y documentos de oficina.']);
            exit;
        }

        if (empty($titulo)) {
            $titulo = pathinfo($origName, PATHINFO_FILENAME);
        }

        $uniqueName = 'doc_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $destPath = $uploadDir . '/' . $uniqueName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['success' => false, 'message' => 'Error al guardar el archivo en el servidor.']);
            exit;
        }

        $relPath = 'uploads/' . $uniqueName;

        $stmt = $pdo->prepare("
            INSERT INTO documentos (
                usuario_id, cliente_id, poliza_id, tipo_documento, titulo,
                archivo_nombre_original, archivo_ruta, archivo_tamano, archivo_extension, notas
            ) VALUES (
                :usuario_id, :cliente_id, :poliza_id, :tipo_documento, :titulo,
                :orig_name, :ruta, :tamano, :ext, :notas
            )
        ");
        $stmt->execute([
            ':usuario_id' => $user_id,
            ':cliente_id' => $cliente_id,
            ':poliza_id' => $poliza_id,
            ':tipo_documento' => $tipo_documento,
            ':titulo' => $titulo,
            ':orig_name' => $origName,
            ':ruta' => $relPath,
            ':tamano' => $file['size'],
            ':ext' => $ext,
            ':notas' => $notas
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Documento subido con éxito al expediente.',
            'documento_id' => $pdo->lastInsertId(),
            'archivo_ruta' => $relPath
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al subir documento: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_documento') {
    try {
        $id = intval($requestData['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT archivo_ruta FROM documentos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $filePath = $stmt->fetchColumn();

        if ($filePath) {
            $abs = __DIR__ . '/' . $filePath;
            if (is_file($abs)) @unlink($abs);
        }

        $pdo->prepare("DELETE FROM documentos WHERE id = :id")->execute([':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Documento eliminado exitosamente.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar documento: ' . $e->getMessage()]);
    }
    exit;
}


// ==========================================================================
// 6. LISTA DE AGENTES / PRODUCTORES
// ==========================================================================
if ($action === 'get_agentes') {
    try {
        $stmt = $pdo->query("SELECT id, fullname, company, email FROM usuarios ORDER BY fullname ASC");
        echo json_encode(['success' => true, 'agentes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener agentes: ' . $e->getMessage()]);
    }
    exit;
}


// ==========================================================================
// 7. EQUIPOS ADMINISTRATIVOS Y COLABORACIÓN (SETTINGS CRM)
// ==========================================================================
if ($action === 'get_equipos') {
    try {
        // Obtener equipos
        $stmt_eq = $pdo->query("SELECT * FROM equipos ORDER BY id ASC");
        $equipos = $stmt_eq->fetchAll(PDO::FETCH_ASSOC);

        $total_miembros = 0;
        $invitaciones_pendientes = 0;

        foreach ($equipos as &$eq) {
            $stmt_m = $pdo->prepare("SELECT * FROM equipo_miembros WHERE equipo_id = :eid ORDER BY rol ASC, id ASC");
            $stmt_m->execute([':eid' => $eq['id']]);
            $miembros = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

            $eq['miembros'] = $miembros;
            $eq['miembros_count'] = count($miembros);
            $total_miembros += count($miembros);

            foreach ($miembros as $m) {
                if ($m['estado'] === 'pendiente') {
                    $invitaciones_pendientes++;
                }
            }

            // Decodificar permisos_modulos
            $decoded = json_decode($eq['permisos_modulos'] ?? '', true);
            $eq['permisos_modulos'] = is_array($decoded) ? $decoded : ['dashboard', 'polizas', 'cobranza', 'clientes', 'documentos'];
        }
        unset($eq);

        // Obtener todos los miembros consolidados
        $stmt_all_m = $pdo->query("
            SELECT m.*, e.nombre AS equipo_nombre, e.color AS equipo_color 
            FROM equipo_miembros m 
            JOIN equipos e ON m.equipo_id = e.id 
            ORDER BY m.id DESC
        ");
        $todos_miembros = $stmt_all_m->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'equipos' => $equipos,
            'todos_miembros' => $todos_miembros,
            'resumen' => [
                'total_equipos' => count($equipos),
                'total_miembros' => $total_miembros,
                'invitaciones_pendientes' => $invitaciones_pendientes,
                'cartera_compartida' => true
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener equipos: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_equipo') {
    try {
        $id = intval($requestData['id'] ?? 0);
        $nombre = trim($requestData['nombre'] ?? '');
        $descripcion = trim($requestData['descripcion'] ?? '');
        $color = trim($requestData['color'] ?? '#10b981');
        $lider_nombre = trim($requestData['lider_nombre'] ?? '');
        $lider_email = trim($requestData['lider_email'] ?? '');
        $compartir_cartera = trim($requestData['compartir_cartera'] ?? 'empresa');

        $permisos_raw = $requestData['permisos_modulos'] ?? [];
        if (is_string($permisos_raw)) {
            $permisos_modulos = $permisos_raw;
        } else {
            $permisos_modulos = json_encode($permisos_raw ?: ['dashboard', 'polizas', 'cobranza', 'clientes', 'documentos']);
        }

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'message' => 'El nombre del equipo es obligatorio.']);
            exit;
        }

        if ($id > 0) {
            // Actualizar
            $stmt = $pdo->prepare("
                UPDATE equipos 
                SET nombre = :nombre, descripcion = :descripcion, color = :color, 
                    lider_nombre = :lider_nombre, lider_email = :lider_email, 
                    permisos_modulos = :permisos_modulos, compartir_cartera = :compartir_cartera
                WHERE id = :id
            ");
            $stmt->execute([
                ':nombre' => $nombre,
                ':descripcion' => $descripcion,
                ':color' => $color,
                ':lider_nombre' => $lider_nombre,
                ':lider_email' => $lider_email,
                ':permisos_modulos' => $permisos_modulos,
                ':compartir_cartera' => $compartir_cartera,
                ':id' => $id
            ]);
            $msg = 'Equipo administrativo actualizado exitosamente.';
            $equipo_id = $id;
        } else {
            // Crear nuevo
            $stmt = $pdo->prepare("
                INSERT INTO equipos (usuario_id, nombre, descripcion, color, lider_nombre, lider_email, permisos_modulos, compartir_cartera)
                VALUES (:usuario_id, :nombre, :descripcion, :color, :lider_nombre, :lider_email, :permisos_modulos, :compartir_cartera)
            ");
            $stmt->execute([
                ':usuario_id' => $user_id,
                ':nombre' => $nombre,
                ':descripcion' => $descripcion,
                ':color' => $color,
                ':lider_nombre' => $lider_nombre,
                ':lider_email' => $lider_email,
                ':permisos_modulos' => $permisos_modulos,
                ':compartir_cartera' => $compartir_cartera
            ]);
            $equipo_id = $pdo->lastInsertId();
            $msg = '¡Equipo administrativo creado con éxito!';

            // Si se asignó un líder con nombre y correo, agregarlo de inmediato como miembro líder
            if (!empty($lider_nombre) && !empty($lider_email)) {
                $stmtLead = $pdo->prepare("
                    INSERT INTO equipo_miembros (equipo_id, nombre, email, rol, estado)
                    VALUES (:eid, :nom, :em, 'admin', 'activo')
                ");
                $stmtLead->execute([
                    ':eid' => $equipo_id,
                    ':nom' => $lider_nombre,
                    ':em' => $lider_email
                ]);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'equipo_id' => $equipo_id
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al guardar el equipo: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_equipo') {
    try {
        $id = intval($requestData['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de equipo inválido.']);
            exit;
        }

        $pdo->prepare("DELETE FROM equipos WHERE id = :id")->execute([':id' => $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Equipo administrativo eliminado correctamente.'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar equipo: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'invitar_miembro') {
    try {
        $equipo_id = intval($requestData['equipo_id'] ?? 0);
        $nombre = trim($requestData['nombre'] ?? '');
        $email = strtolower(trim($requestData['email'] ?? ''));
        $telefono = trim($requestData['telefono'] ?? '');
        $rol = trim($requestData['rol'] ?? 'agente');
        $estado = trim($requestData['estado'] ?? 'activo');

        if ($equipo_id <= 0) {
            $firstEq = $pdo->query("SELECT id FROM equipos ORDER BY id ASC LIMIT 1")->fetch();
            if ($firstEq) {
                $equipo_id = (int)$firstEq['id'];
            } else {
                $stmt_new_eq = $pdo->prepare("INSERT INTO equipos (usuario_id, nombre, descripcion) VALUES (?, 'Equipo Principal', 'Colaboradores con acceso al CRM')");
                $stmt_new_eq->execute([$user_id]);
                $equipo_id = (int)$pdo->lastInsertId();
            }
        }

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'message' => 'Ingresa el nombre completo del colaborador.']);
            exit;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Ingresa un correo electrónico institucional válido.']);
            exit;
        }

        // Verificar si ya está registrado
        $stmt_chk = $pdo->prepare("SELECT id FROM equipo_miembros WHERE email = :email");
        $stmt_chk->execute([':email' => $email]);
        if ($stmt_chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Este correo ya forma parte del equipo.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO equipo_miembros (equipo_id, nombre, email, telefono, rol, estado)
            VALUES (:eid, :nombre, :email, :tel, :rol, :estado)
        ");
        $stmt->execute([
            ':eid' => $equipo_id,
            ':nombre' => $nombre,
            ':email' => $email,
            ':tel' => $telefono,
            ':rol' => $rol,
            ':estado' => $estado
        ]);

        echo json_encode([
            'success' => true,
            'message' => '¡Colaborador invitado con éxito! Se ha habilitado su acceso al CRM de la empresa.',
            'miembro_id' => $pdo->lastInsertId()
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al invitar al colaborador: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'eliminar_miembro') {
    try {
        $id = intval($requestData['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        $pdo->prepare("DELETE FROM equipo_miembros WHERE id = :id")->execute([':id' => $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Colaborador removido del equipo exitosamente.'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar miembro: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'cambiar_estado_miembro') {
    try {
        $id = intval($requestData['id'] ?? 0);
        $estado = trim($requestData['estado'] ?? 'activo');
        if (!in_array($estado, ['activo', 'pendiente'])) {
            $estado = 'activo';
        }

        $pdo->prepare("UPDATE equipo_miembros SET estado = :estado WHERE id = :id")->execute([
            ':estado' => $estado,
            ':id' => $id
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Estado del miembro actualizado a ' . $estado . '.'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar estado: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================================================
// CONTACTOS DE APOYO (directorio de personas externas: aseguradoras, ejecutivos, etc.)
// ==========================================================================
if (in_array($action, ['get_contactos_apoyo', 'save_contacto_apoyo', 'delete_contacto_apoyo'])) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS contactos_apoyo (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NULL,
                nombre VARCHAR(150) NOT NULL,
                email VARCHAR(150) NULL,
                telefono VARCHAR(50) NULL,
                aseguradora VARCHAR(120) NULL,
                area VARCHAR(100) NULL,
                notas TEXT NULL,
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al preparar contactos: ' . $e->getMessage()]);
        exit;
    }
}

if ($action === 'get_contactos_apoyo') {
    try {
        $stmt = $pdo->query("SELECT * FROM contactos_apoyo ORDER BY nombre ASC");
        echo json_encode(['success' => true, 'contactos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener contactos: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_contacto_apoyo') {
    try {
        $id = intval($requestData['id'] ?? 0);
        $nombre = trim($requestData['nombre'] ?? '');
        $email = trim($requestData['email'] ?? '');
        $telefono = trim($requestData['telefono'] ?? '');
        $aseguradora = trim($requestData['aseguradora'] ?? '');
        $area = trim($requestData['area'] ?? '');
        $notas = trim($requestData['notas'] ?? '');

        if ($nombre === '') {
            echo json_encode(['success' => false, 'message' => 'El nombre del contacto es obligatorio.']);
            exit;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'El correo electrónico no es válido.']);
            exit;
        }

        $params = [
            ':nombre' => $nombre, ':email' => $email, ':telefono' => $telefono,
            ':aseguradora' => $aseguradora, ':area' => $area, ':notas' => $notas
        ];
        if ($id > 0) {
            $params[':id'] = $id;
            $pdo->prepare("UPDATE contactos_apoyo SET nombre = :nombre, email = :email, telefono = :telefono, aseguradora = :aseguradora, area = :area, notas = :notas WHERE id = :id")->execute($params);
            $msg = 'Contacto actualizado correctamente.';
        } else {
            $params[':uid'] = $user_id;
            $pdo->prepare("INSERT INTO contactos_apoyo (usuario_id, nombre, email, telefono, aseguradora, area, notas) VALUES (:uid, :nombre, :email, :telefono, :aseguradora, :area, :notas)")->execute($params);
            $msg = 'Contacto agregado al directorio.';
        }
        echo json_encode(['success' => true, 'message' => $msg]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al guardar contacto: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_contacto_apoyo') {
    try {
        $id = intval($requestData['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }
        $pdo->prepare("DELETE FROM contactos_apoyo WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Contacto eliminado.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar contacto: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_empresa_settings') {
    try {
        $stmt = $pdo->prepare("SELECT id, fullname, company, email, rfc, telefono, moneda FROM usuarios WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $user = [
                'fullname' => 'Agente Principal',
                'company' => 'Núcleo Seguros México',
                'email' => 'admin@nucleo.com',
                'rfc' => 'NUC240101XYZ',
                'telefono' => '55 1234 5678',
                'moneda' => 'MXN'
            ];
        }

        echo json_encode([
            'success' => true,
            'empresa' => $user
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al obtener datos de la empresa: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_empresa_settings') {
    try {
        $company = trim($requestData['company'] ?? '');
        $fullname = trim($requestData['fullname'] ?? '');
        $email = strtolower(trim($requestData['email'] ?? ''));
        $rfc = strtoupper(trim($requestData['rfc'] ?? ''));
        $telefono = trim($requestData['telefono'] ?? '');
        $moneda = trim($requestData['moneda'] ?? 'MXN');

        if (empty($company)) {
            echo json_encode(['success' => false, 'message' => 'Ingresa el nombre de la empresa / agencia.']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE usuarios 
            SET company = :comp, fullname = :fn, email = :em, rfc = :rfc, telefono = :tel, moneda = :mon 
            WHERE id = :id
        ");
        $stmt->execute([
            ':comp' => $company,
            ':fn' => $fullname,
            ':em' => $email,
            ':rfc' => $rfc,
            ':tel' => $telefono,
            ':mon' => $moneda,
            ':id' => $user_id
        ]);

        $_SESSION['user_company'] = $company;
        $_SESSION['user_name'] = $fullname;
        $_SESSION['user_email'] = $email;

        echo json_encode([
            'success' => true,
            'message' => 'Información de la empresa y preferencias del CRM actualizadas correctamente.'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al guardar configuración: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================================================
// 8. CALENDARIO, TAREAS Y RECORDATORIOS
// ==========================================================================
if ($action === 'get_calendario_eventos') {
    try {
        $mes = intval($requestData['mes'] ?? date('n'));
        $anio = intval($requestData['anio'] ?? date('Y'));
        $cliente_id = !empty($requestData['cliente_id']) && $requestData['cliente_id'] !== 'todos' ? intval($requestData['cliente_id']) : null;

        $startDate = sprintf('%04d-%02d-01', $anio, $mes);
        $endDate = date('Y-m-t', strtotime($startDate));

        // 1. Tareas programadas en el mes
        $whereTareas = ["t.fecha BETWEEN :start AND :end"];
        $paramsTareas = [':start' => $startDate, ':end' => $endDate];
        if ($cliente_id) {
            $whereTareas[] = "t.cliente_id = :cid";
            $paramsTareas[':cid'] = $cliente_id;
        }
        $sqlTareas = "
            SELECT t.*, cl.nombre AS cliente_nombre, cl.telefono, cl.whatsapp
            FROM tareas t
            LEFT JOIN clientes cl ON t.cliente_id = cl.id
            WHERE " . implode(' AND ', $whereTareas) . "
            ORDER BY t.fecha ASC, t.hora ASC
        ";
        $stmtT = $pdo->prepare($sqlTareas);
        $stmtT->execute($paramsTareas);
        $tareas = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        // 2. Recordatorios de Cobranza (1 semana antes y el día que debe pagar)
        $whereCob = ["c.estado IN ('pendiente', 'vencida')", "(c.fecha_vencimiento BETWEEN :start1 AND :end1 OR DATE_SUB(c.fecha_vencimiento, INTERVAL 7 DAY) BETWEEN :start2 AND :end2)"];
        $paramsCob = [
            ':start1' => $startDate, ':end1' => $endDate,
            ':start2' => $startDate, ':end2' => $endDate
        ];
        if ($cliente_id) {
            $whereCob[] = "p.cliente_id = :cid";
            $paramsCob[':cid'] = $cliente_id;
        }
        $sqlCob = "
            SELECT 
                c.id, c.numero_recibo, c.monto, c.fecha_vencimiento, c.estado,
                DATE_SUB(c.fecha_vencimiento, INTERVAL 7 DAY) AS fecha_recordatorio_7d,
                p.numero_poliza, p.aseguradora, p.ramo,
                cl.id AS cliente_id, cl.nombre AS cliente_nombre, cl.telefono, cl.whatsapp
            FROM cobranzas c
            JOIN polizas p ON c.poliza_id = p.id
            JOIN clientes cl ON p.cliente_id = cl.id
            WHERE " . implode(' AND ', $whereCob) . "
            ORDER BY c.fecha_vencimiento ASC
        ";
        $stmtC = $pdo->prepare($sqlCob);
        $stmtC->execute($paramsCob);
        $cobranzasRaw = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $cobranzaEventos = [];
        foreach ($cobranzasRaw as $c) {
            // Evento: Aviso 1 semana antes
            $fRecordatorio = $c['fecha_recordatorio_7d'];
            if ($fRecordatorio >= $startDate && $fRecordatorio <= $endDate) {
                $cobranzaEventos[] = [
                    'tipo' => 'recordatorio_pago_7d',
                    'fecha' => $fRecordatorio,
                    'recibo_id' => $c['id'],
                    'titulo' => 'Aviso: Pago en 7 días - ' . $c['cliente_nombre'],
                    'monto' => $c['monto'],
                    'fecha_vencimiento' => $c['fecha_vencimiento'],
                    'numero_poliza' => $c['numero_poliza'],
                    'numero_recibo' => $c['numero_recibo'],
                    'aseguradora' => $c['aseguradora'],
                    'whatsapp' => $c['whatsapp'],
                    'telefono' => $c['telefono'],
                    'cliente_nombre' => $c['cliente_nombre'],
                    'cliente_id' => $c['cliente_id'],
                    'estado' => $c['estado']
                ];
            }

            // Evento: Día que debe pagar
            $fVence = $c['fecha_vencimiento'];
            if ($fVence >= $startDate && $fVence <= $endDate) {
                $cobranzaEventos[] = [
                    'tipo' => 'pago_dia_vencimiento',
                    'fecha' => $fVence,
                    'recibo_id' => $c['id'],
                    'titulo' => 'Día de Pago: ' . $c['cliente_nombre'] . ' ($' . number_format($c['monto'], 2) . ')',
                    'monto' => $c['monto'],
                    'fecha_vencimiento' => $c['fecha_vencimiento'],
                    'numero_poliza' => $c['numero_poliza'],
                    'numero_recibo' => $c['numero_recibo'],
                    'aseguradora' => $c['aseguradora'],
                    'whatsapp' => $c['whatsapp'],
                    'telefono' => $c['telefono'],
                    'cliente_nombre' => $c['cliente_nombre'],
                    'cliente_id' => $c['cliente_id'],
                    'estado' => $c['estado']
                ];
            }
        }

        // 3. Renovaciones de Póliza (aparecen 1 mes antes y fin de vigencia)
        $wherePol = ["p.estado_poliza = 'activa'", "(p.vigencia_hasta BETWEEN :start1 AND :end1 OR DATE_SUB(p.vigencia_hasta, INTERVAL 30 DAY) BETWEEN :start2 AND :end2)"];
        $paramsPol = [
            ':start1' => $startDate, ':end1' => $endDate,
            ':start2' => $startDate, ':end2' => $endDate
        ];
        if ($cliente_id) {
            $wherePol[] = "p.cliente_id = :cid";
            $paramsPol[':cid'] = $cliente_id;
        }
        $sqlPol = "
            SELECT 
                p.id, p.numero_poliza, p.aseguradora, p.ramo, p.vigencia_hasta, p.prima_total,
                DATE_SUB(p.vigencia_hasta, INTERVAL 30 DAY) AS fecha_aviso_renovacion,
                cl.id AS cliente_id, cl.nombre AS cliente_nombre, cl.telefono, cl.whatsapp
            FROM polizas p
            JOIN clientes cl ON p.cliente_id = cl.id
            WHERE " . implode(' AND ', $wherePol) . "
            ORDER BY p.vigencia_hasta ASC
        ";
        $stmtP = $pdo->prepare($sqlPol);
        $stmtP->execute($paramsPol);
        $polizasRaw = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        $renovacionEventos = [];
        foreach ($polizasRaw as $p) {
            // Evento: Aviso 1 mes antes
            $fAviso = $p['fecha_aviso_renovacion'];
            if ($fAviso >= $startDate && $fAviso <= $endDate) {
                $renovacionEventos[] = [
                    'tipo' => 'aviso_renovacion_1mes',
                    'fecha' => $fAviso,
                    'poliza_id' => $p['id'],
                    'titulo' => 'Renovación en 1 mes: ' . $p['numero_poliza'] . ' (' . $p['cliente_nombre'] . ')',
                    'vigencia_hasta' => $p['vigencia_hasta'],
                    'numero_poliza' => $p['numero_poliza'],
                    'ramo' => $p['ramo'],
                    'aseguradora' => $p['aseguradora'],
                    'cliente_nombre' => $p['cliente_nombre'],
                    'cliente_id' => $p['cliente_id'],
                    'prima_total' => $p['prima_total']
                ];
            }

            // Evento: Fin de vigencia
            $fHasta = $p['vigencia_hasta'];
            if ($fHasta >= $startDate && $fHasta <= $endDate) {
                $renovacionEventos[] = [
                    'tipo' => 'fin_vigencia_poliza',
                    'fecha' => $fHasta,
                    'poliza_id' => $p['id'],
                    'titulo' => 'Vence Póliza: ' . $p['numero_poliza'] . ' (' . $p['cliente_nombre'] . ')',
                    'vigencia_hasta' => $p['vigencia_hasta'],
                    'numero_poliza' => $p['numero_poliza'],
                    'ramo' => $p['ramo'],
                    'aseguradora' => $p['aseguradora'],
                    'cliente_nombre' => $p['cliente_nombre'],
                    'cliente_id' => $p['cliente_id'],
                    'prima_total' => $p['prima_total']
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'mes' => $mes,
            'anio' => $anio,
            'tareas' => $tareas,
            'recordatorios_cobranza' => $cobranzaEventos,
            'renovaciones' => $renovacionEventos
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error en calendario: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_tarea') {
    try {
        $id = !empty($requestData['id']) ? intval($requestData['id']) : null;
        $cliente_id = !empty($requestData['cliente_id']) && $requestData['cliente_id'] !== 'todos' ? intval($requestData['cliente_id']) : null;
        $titulo = trim($requestData['titulo'] ?? '');
        $fecha = trim($requestData['fecha'] ?? '');
        $hora = !empty($requestData['hora']) ? trim($requestData['hora']) : null;
        $notas = trim($requestData['notas'] ?? '');

        if (empty($titulo)) {
            echo json_encode(['success' => false, 'message' => 'Ingresa el título o descripción de la tarea.']);
            exit;
        }

        if (empty($fecha)) {
            echo json_encode(['success' => false, 'message' => 'Selecciona la fecha de la tarea.']);
            exit;
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE tareas SET cliente_id = :cid, titulo = :tit, fecha = :fec, hora = :hor, notas = :not WHERE id = :id AND usuario_id = :uid");
            $stmt->execute([
                ':cid' => $cliente_id,
                ':tit' => $titulo,
                ':fec' => $fecha,
                ':hor' => $hora,
                ':not' => $notas,
                ':id' => $id,
                ':uid' => $user_id
            ]);
            $msg = 'Tarea actualizada correctamente.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO tareas (usuario_id, cliente_id, titulo, fecha, hora, notas) VALUES (:uid, :cid, :tit, :fec, :hor, :not)");
            $stmt->execute([
                ':uid' => $user_id,
                ':cid' => $cliente_id,
                ':tit' => $titulo,
                ':fec' => $fecha,
                ':hor' => $hora,
                ':not' => $notas
            ]);
            $msg = 'Tarea creada exitosamente en el calendario.';
        }

        echo json_encode(['success' => true, 'message' => $msg]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al guardar tarea: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'toggle_tarea') {
    try {
        $id = intval($requestData['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE tareas SET completada = 1 - completada WHERE id = :id AND usuario_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $user_id]);
        echo json_encode(['success' => true, 'message' => 'Estado de tarea actualizado.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar tarea: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_tarea') {
    try {
        $id = intval($requestData['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM tareas WHERE id = :id AND usuario_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $user_id]);
        echo json_encode(['success' => true, 'message' => 'Tarea eliminada del calendario.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar tarea: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Acción no reconocida: ' . htmlspecialchars($action)]);
