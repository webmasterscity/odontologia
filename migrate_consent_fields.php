<?php
/**
 * Migración: Agregar campos de firma digital y CI al consentimiento informado
 */

$dbPath = __DIR__ . '/data/clinic.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Conectado a la base de datos.\n";

    // Verificar si las columnas ya existen
    $result = $pdo->query("PRAGMA table_info(clinical_profiles)");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);

    $hasSignature = false;
    $hasCI = false;

    foreach ($columns as $column) {
        if ($column['name'] === 'consent_signature') {
            $hasSignature = true;
        }
        if ($column['name'] === 'consent_ci') {
            $hasCI = true;
        }
    }

    // Agregar columna consent_signature si no existe
    if (!$hasSignature) {
        echo "Agregando columna consent_signature...\n";
        $pdo->exec("ALTER TABLE clinical_profiles ADD COLUMN consent_signature TEXT");
        echo "✓ Columna consent_signature agregada.\n";
    } else {
        echo "✓ La columna consent_signature ya existe.\n";
    }

    // Agregar columna consent_ci si no existe
    if (!$hasCI) {
        echo "Agregando columna consent_ci...\n";
        $pdo->exec("ALTER TABLE clinical_profiles ADD COLUMN consent_ci TEXT");
        echo "✓ Columna consent_ci agregada.\n";
    } else {
        echo "✓ La columna consent_ci ya existe.\n";
    }

    echo "\n✓ Migración completada exitosamente.\n";

} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
