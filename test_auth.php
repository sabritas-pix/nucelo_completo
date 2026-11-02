<?php
require_once 'conexion.php'; 

echo "<h2>Prueba de Conexión y Autenticación de MySQL</h2>";

if (isset($pdo)) {
    echo "<p style='color: green;'>✔ Estado: Conexión establecida correctamente con la base de datos <strong>nucleo</strong>.</p>";
    
    try {
        $stmt = $pdo->query("SELECT DATABASE() AS db_actual, VERSION() AS version_mysql");
        $info = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "<ul>";
        echo "<li><strong>Base de datos activa:</strong> " . $info['db_actual'] . "</li>";
        echo "<li><strong>Versión de MySQL:</strong> " . $info['version_mysql'] . "</li>";
        echo "</ul>";

        $tablesStmt = $pdo->query("SHOW TABLES");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

        echo "<h3>Tablas encontradas en 'nucleo':</h3>";
        if (!empty($tables)) {
            echo "<ul>";
            foreach ($tables as $tabla) {
                echo "<li>" . htmlspecialchars($tabla) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>No hay tablas creadas aún en la base de datos 'nucleo'.</p>";
        }

    } catch (PDOException $e) {
        echo "<p style='color: red;'>✘ Error al ejecutar consulta de prueba: " . $e->getMessage() . "</p>";
    }

} else {
    echo "<p style='color: red;'>✘ Error: La variable de conexión \$pdo no está definida.</p>";
}
?>