<?php
require_once __DIR__ . '/includes/database.php';

$pdo = db();

// Ver todas las tablas
echo "=== TABLAS EN LA BASE DE DATOS ===\n";
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "- $table\n";
}

// Ver estructura de patients
echo "\n=== COLUMNAS DE 'patients' ===\n";
$columns = $pdo->query("PRAGMA table_info(patients)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "{$col['name']} ({$col['type']})\n";
}

// Ver últimos pacientes
echo "\n=== ÚLTIMOS 5 PACIENTES ===\n";
$patients = $pdo->query("SELECT * FROM patients ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($patients as $p) {
    echo "ID: {$p['id']} - {$p['full_name']}\n";
    echo "  Document: {$p['document_id']}\n";
    echo "  Phone: {$p['phone_primary']}\n";
    echo "  Created: {$p['created_at']}\n\n";
}

// Ver odontogram_entries
echo "\n=== ÚLTIMAS 10 ENTRADAS DE ODONTOGRAMA ===\n";
$entries = $pdo->query("SELECT * FROM odontogram_entries ORDER BY updated_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($entries as $e) {
    echo "Patient ID: {$e['patient_id']} - Tooth: {$e['tooth_code']} - Status: {$e['status']}\n";
    if (!empty($e['surface_data'])) {
        echo "  Surface data: {$e['surface_data']}\n";
    }
    if (!empty($e['notes'])) {
        echo "  Notes: {$e['notes']}\n";
    }
}

// Ver odontogram_snapshots
echo "\n=== SNAPSHOTS DE ODONTOGRAMAS ===\n";
$snapshots = $pdo->query("SELECT * FROM odontogram_snapshots ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($snapshots as $s) {
    echo "ID: {$s['id']} - Patient: {$s['patient_id']} - Created: {$s['created_at']}\n";
    echo "  Is blank: {$s['is_blank']}\n";
    echo "  Payload preview: " . substr($s['payload'], 0, 100) . "...\n\n";
}

// Ver clinical_profiles
echo "\n=== PERFILES CLÍNICOS ===\n";
$profiles = $pdo->query("SELECT * FROM clinical_profiles LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($profiles as $pr) {
    echo "Patient ID: {$pr['patient_id']}\n";
    echo "  Diagnosis: {$pr['diagnosis']}\n";
    echo "  Medical alerts: {$pr['medical_alerts']}\n\n";
}

// Ver visits
echo "\n=== ÚLTIMAS VISITAS ===\n";
$visits = $pdo->query("SELECT * FROM visits ORDER BY visit_date DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($visits as $v) {
    echo "ID: {$v['id']} - Patient: {$v['patient_id']} - Date: {$v['visit_date']}\n";
    echo "  Subjective: {$v['subjective_notes']}\n";
    echo "  Assessment: {$v['assessment']}\n\n";
}

// Ver treatment_activities
echo "\n=== ÚLTIMAS ACTIVIDADES ===\n";
$activities = $pdo->query("SELECT * FROM treatment_activities ORDER BY activity_date DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($activities as $a) {
    echo "ID: {$a['id']} - Patient: {$a['patient_id']} - Date: {$a['activity_date']}\n";
    echo "  Description: {$a['description']}\n";
    echo "  Fee: {$a['fee']} - Payment: {$a['payment']} - Balance: {$a['balance']}\n\n";
}
