<?php
if (isset($_SERVER['REQUEST_METHOD'])) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Content-Type: application/json; charset=UTF-8");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

require_once __DIR__ . '/db.php';


$inputRaw = file_get_contents('php://input');
$jsonInput = json_decode($inputRaw, true) ?: [];

$action = $_GET['action'] ?? $_POST['action'] ?? $jsonInput['action'] ?? 'get_dashboard_analytics';


$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    try {
        $stmt_u = $pdo->query("SELECT id FROM usuarios ORDER BY id ASC LIMIT 1");
        $u_row = $stmt_u->fetch();
        if ($u_row) {
            $user_id = $u_row['id'];
            $_SESSION['user_id'] = $user_id;
        }
    } catch (Exception $e) {}
}


try {
    $pdo->exec("UPDATE cobranzas SET estado = 'vencida' WHERE estado = 'pendiente' AND fecha_vencimiento < CURDATE()");
} catch (Exception $e) {}


$filtro_agente = trim($_GET['agente'] ?? $jsonInput['agente'] ?? '');
$filtro_aseguradora = trim($_GET['aseguradora'] ?? $jsonInput['aseguradora'] ?? '');
$filtro_ramo = trim($_GET['ramo'] ?? $jsonInput['ramo'] ?? '');
$filtro_periodo = trim($_GET['periodo'] ?? $jsonInput['periodo'] ?? '');
$fecha_inicio = trim($_GET['fecha_inicio'] ?? $jsonInput['fecha_inicio'] ?? '');
$fecha_fin = trim($_GET['fecha_fin'] ?? $jsonInput['fecha_fin'] ?? '');


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

if ($action === 'get_dashboard_analytics' || $action === 'get_summary') {
    try {
        // 1. Resumen Global de KPIs (Solo Cobranza Pendiente, Realizada y Vencida)
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

        // 2. Cobranza por Ramo (Gastos Médicos Mayores, Vida, Daños, Auto)
        $ramos_permitidos = ['Gastos Médicos Mayores', 'Vida', 'Daños', 'Auto'];
        $sql_ramo = "
            SELECT 
                p.ramo,
                COALESCE(SUM(c.monto), 0) AS total_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'pendiente' THEN c.monto ELSE 0 END), 0) AS pendiente_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'realizada' THEN c.monto ELSE 0 END), 0) AS realizada_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'vencida' THEN c.monto ELSE 0 END), 0) AS vencida_monto
            FROM cobranzas c
            JOIN polizas p ON c.poliza_id = p.id
            WHERE $where_sql
            GROUP BY p.ramo
        ";
        $stmt_ramo = $pdo->prepare($sql_ramo);
        $stmt_ramo->execute($params);
        $raw_ramos = $stmt_ramo->fetchAll(PDO::FETCH_ASSOC);

        // Mapear y asegurar los 4 ramos exactos
        $ramos_map = [];
        foreach ($raw_ramos as $r) {
            $r_name = $r['ramo'];
            if (in_array($r_name, ['Autos', 'Auto'])) $r_name = 'Auto';
            if (in_array($r_name, ['Gastos Médicos', 'Gastos Médicos Mayores'])) $r_name = 'Gastos Médicos Mayores';
            $ramos_map[$r_name] = floatval($r['total_monto']);
        }

        $ramos_metrics = [];
        foreach ($ramos_permitidos as $rm) {
            $ramos_metrics[] = [
                'ramo' => $rm,
                'monto' => $ramos_map[$rm] ?? 0.00
            ];
        }

        // 3. Cobranza por Aseguradora
        $sql_aseg = "
            SELECT 
                p.aseguradora,
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

        // 4. Cobranza por Agente
        $sql_agente = "
            SELECT 
                u.fullname AS agente_nombre,
                COALESCE(SUM(c.monto), 0) AS total_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'pendiente' THEN c.monto ELSE 0 END), 0) AS pendiente_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'realizada' THEN c.monto ELSE 0 END), 0) AS realizada_monto,
                COALESCE(SUM(CASE WHEN c.estado = 'vencida' THEN c.monto ELSE 0 END), 0) AS vencida_monto
            FROM cobranzas c
            JOIN polizas p ON c.poliza_id = p.id
            JOIN usuarios u ON c.usuario_id = u.id
            WHERE $where_sql
            GROUP BY u.id, u.fullname
            ORDER BY total_monto DESC
            LIMIT 10
        ";
        $stmt_agente = $pdo->prepare($sql_agente);
        $stmt_agente->execute($params);
        $agentes_metrics = $stmt_agente->fetchAll(PDO::FETCH_ASSOC);

        // Lista de agentes para el filtro select
        $stmt_list_u = $pdo->query("SELECT id, fullname, company FROM usuarios ORDER BY fullname ASC");
        $lista_agentes = $stmt_list_u->fetchAll(PDO::FETCH_ASSOC);

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
                ]
            ],
            'cobranza_por_ramo' => $ramos_metrics,
            'cobranza_por_aseguradora' => $aseguradoras_metrics,
            'cobranza_por_agente' => $agentes_metrics,
            'lista_agentes' => $lista_agentes
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error al calcular analítica de gráficas: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Acción no especificada.']);
