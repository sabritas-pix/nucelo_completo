<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

$inputRaw = file_get_contents('php://input');
$inputData = json_decode($inputRaw, true);

if (!is_array($inputData)) {
    $inputData = $_POST;
}

$email = strtolower(trim($inputData['email'] ?? ''));
$password = $inputData['password'] ?? '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'field' => 'email', 'message' => 'Ingresa un correo electrónico válido.']);
    exit;
}

if (empty($password)) {
    echo json_encode(['success' => false, 'field' => 'password', 'message' => 'Ingresa tu contraseña.']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT id, fullname, company, email, password FROM usuarios WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'field' => 'email', 'message' => 'No encontramos ninguna cuenta vinculada a este correo.']);
        exit;
    }

    if (!password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'field' => 'password', 'message' => 'La contraseña es incorrecta.']);
        exit;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['fullname'];
    $_SESSION['user_company'] = $user['company'];

    echo json_encode([
        'success' => true,
        'message' => '¡Inicio de sesión exitoso! Bienvenido de nuevo.',
        'user' => [
            'id' => $user['id'],
            'fullname' => $user['fullname'],
            'company' => $user['company'],
            'email' => $user['email']
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error de autenticación: ' . $e->getMessage()]);
}