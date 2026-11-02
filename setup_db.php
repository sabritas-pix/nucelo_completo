<?php

$host = 'localhost';
$dbname = 'nucleo';
$username = 'root'; 
$password = '';     

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


} catch (PDOException $e) {

    die("Error al conectar con phpMyAdmin / MySQL: " . $e->getMessage());
}
?>