<?php
require_once __DIR__ . '/db.php';

$inputRaw = file_get_contents('php://input');
$inputData = json_decode($inputRaw, true);

if (!is_array($inputData)) {
    $inputData = $_POST;
}

$fullname = trim($inputData['fullname'] ?? '');
$company  = trim($inputData['company'] ?? '');
$email    = strtolower(trim($inputData['email'] ?? ''));
$password = $inputData['password'] ?? '';

if (empty($fullname)) {
    echo json_encode(['success' => false, 'field' => 'fullname', 'message' => 'Ingresa tu nombre completo.']);
    exit;
}

if (empty($company)) {
    echo json_encode(['success' => false, 'field' => 'company', 'message' => 'Ingresa el nombre de tu empresa.']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'field' => 'email', 'message' => 'Ingresa un correo electrónico válido.']);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'field' => 'password', 'message' => 'La contraseña debe tener al menos 8 caracteres.']);
    exit;
}

try {

    $stmtCheck = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
    $stmtCheck->execute([':email' => $email]);
    if ($stmtCheck->fetch()) {
        echo json_encode(['success' => false, 'field' => 'email', 'message' => 'Este correo electrónico ya está registrado.']);
        exit;
    }

    
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

   
    $stmtInsert = $pdo->prepare('INSERT INTO usuarios (fullname, company, email, password) VALUES (:fullname, :company, :email, :password)');
    $stmtInsert->execute([
        ':fullname' => $fullname,
        ':company'  => $company,
        ':email'    => $email,
        ':password' => $hashedPassword
    ]);

    $userId = $pdo->lastInsertId();

   
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $fullname;
    $_SESSION['user_company'] = $company;

    echo json_encode([
        'success' => true,
        'message' => '¡Cuenta creada con éxito! Bienvenido a Núcleo CRM.',
        'user' => [
            'id' => $userId,
            'fullname' => $fullname,
            'company' => $company,
            'email' => $email
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error al registrar en la base de datos: ' . $e->getMessage()]);
}
