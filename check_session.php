<?php
require_once __DIR__ . '/db.php';

if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id'       => $_SESSION['user_id'],
            'email'    => $_SESSION['user_email'] ?? '',
            'fullname' => $_SESSION['user_name'] ?? '',
            'company'  => $_SESSION['user_company'] ?? ''
        ]
    ]);
} else {
    echo json_encode([
        'authenticated' => false
    ]);
}
