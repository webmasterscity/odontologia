<?php
/**
 * Script para verificar y restaurar la visualización de datos del odontograma
 * según lo que está guardado en la base de datos
 */

require_once __DIR__ . '/includes/database.php';

$pdo = db();
$patientId = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;

if ($patientId <= 0) {
    echo "Por favor especifica un patient_id en la URL. Ejemplo: ?patient_id=8\n";
    exit;
}

echo "=== VERIFICANDO DATOS DEL PACIENTE ID: $patientId ===\n\n";

// Obtener información del paciente
$patient = $pdo->query("SELECT * FROM patients WHERE id = $patientId")->fetch(PDO::FETCH_ASSOC);
if (!$patient) {
    echo "ERROR: Paciente no encontrado\n";
    exit;
}

echo "Paciente: {$patient['full_name']}\n";
echo "Documento: {$patient['document_id']}\n\n";

// Obtener entradas de odontograma
echo "=== DATOS DE ODONTOGRAMA EN LA BASE DE DATOS ===\n";
$entries = $pdo->query(
    "SELECT * FROM odontogram_entries
     WHERE patient_id = $patientId
     ORDER BY tooth_code"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($entries)) {
    echo "No hay datos de odontograma guardados para este paciente.\n\n";
} else {
    echo "Total de dientes con datos: " . count($entries) . "\n\n";

    $teethData = [];
    foreach ($entries as $entry) {
        echo "Diente {$entry['tooth_code']}:\n";
        echo "  Status: {$entry['status']}\n";

        if (!empty($entry['surface_data'])) {
            $surfaceData = json_decode($entry['surface_data'], true);
            echo "  Surface data: " . json_encode($surfaceData, JSON_PRETTY_PRINT) . "\n";

            // Construir el formato correcto para el payload
            $teethData[$entry['tooth_code']] = [
                'status' => $entry['status'],
                'surfaces' => isset($surfaceData['odontodiagrama']) ? $surfaceData['odontodiagrama'] : [],
                'notes' => $entry['notes'] ?? ''
            ];
        }

        if (!empty($entry['notes'])) {
            echo "  Notes: {$entry['notes']}\n";
        }
        echo "\n";
    }

    // Generar el JSON que debería tener el formulario
    echo "\n=== PAYLOAD CORRECTO PARA EL FORMULARIO ===\n";
    $payload = [
        'odontodiagrama' => $teethData
    ];
    $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo $payloadJson . "\n\n";

    // Guardar en un snapshot si no existe
    echo "=== CREANDO SNAPSHOT SI NO EXISTE ===\n";
    $existingSnapshot = $pdo->query(
        "SELECT * FROM odontogram_snapshots WHERE patient_id = $patientId LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$existingSnapshot) {
        $pdo->prepare(
            "INSERT INTO odontogram_snapshots (patient_id, payload, is_blank, created_at)
             VALUES (:patient_id, :payload, 0, datetime('now'))"
        )->execute([
            ':patient_id' => $patientId,
            ':payload' => json_encode($payload)
        ]);
        echo "✓ Snapshot creado exitosamente\n";
    } else {
        echo "Ya existe un snapshot para este paciente (ID: {$existingSnapshot['id']})\n";
        echo "Fecha: {$existingSnapshot['created_at']}\n";
    }
}

echo "\n=== RESUMEN ===\n";
echo "Para ver este paciente con los datos cargados, visita:\n";
echo "http://localhost:8080/patient.php?id=$patientId\n";
