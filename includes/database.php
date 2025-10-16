<?php
declare(strict_types=1);

$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

/**
 * Returns a singleton PDO connection and ensures schema exists.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = __DIR__ . '/../data/clinic.sqlite';
    $needsBootstrap = !file_exists($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($needsBootstrap) {
        bootstrapSchema($pdo);
    }

    ensureSchemaUpgrades($pdo);

    return $pdo;
}

/**
 * Creates the initial SQLite schema for the dental records system.
 */
function bootstrapSchema(PDO $pdo): void
{
    $schemaStatements = [
        // Patient master record.
        'CREATE TABLE IF NOT EXISTS patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            document_id TEXT,
            birth_date TEXT,
            age INTEGER,
            gender TEXT,
            address TEXT,
            email TEXT,
            phone_primary TEXT,
            phone_secondary TEXT,
            representative_name TEXT,
            representative_document TEXT,
            representative_phone TEXT,
            emergency_contact TEXT,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        // Clinical profile capturing medical history and exam.
        'CREATE TABLE IF NOT EXISTS clinical_profiles (
            patient_id INTEGER PRIMARY KEY,
            consultation_reason TEXT,
            current_condition TEXT,
            medical_alerts TEXT,
            medications TEXT,
            hospitalizations TEXT,
            family_history TEXT,
            extraoral_exam TEXT,
            intraoral_exam TEXT,
            periodontal_status TEXT,
            diagnosis TEXT,
            treatment_plan TEXT,
            consent_signed INTEGER DEFAULT 0,
            consent_signed_at TEXT,
            consent_notes TEXT,
            antecedent_cardiovascular INTEGER DEFAULT 0,
            antecedent_respiratory INTEGER DEFAULT 0,
            antecedent_gastrointestinal INTEGER DEFAULT 0,
            antecedent_endocrine INTEGER DEFAULT 0,
            antecedent_renal INTEGER DEFAULT 0,
            antecedent_ent INTEGER DEFAULT 0,
            antecedent_hepatic INTEGER DEFAULT 0,
            antecedent_neurologic INTEGER DEFAULT 0,
            antecedent_allergy INTEGER DEFAULT 0,
            antecedent_neoplastic INTEGER DEFAULT 0,
            antecedent_hematologic INTEGER DEFAULT 0,
            antecedent_viral INTEGER DEFAULT 0,
            antecedent_gynecologic INTEGER DEFAULT 0,
            antecedent_covid INTEGER DEFAULT 0,
            physical_exam_bp TEXT,
            pain_level INTEGER,
            habits TEXT,
            risk_assessment TEXT,
            last_updated TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE
        )',

        // Odontogram entries per tooth.
        'CREATE TABLE IF NOT EXISTS odontogram_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            tooth_code TEXT NOT NULL,
            status TEXT,
            surface_data TEXT,
            notes TEXT,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(patient_id, tooth_code),
            FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE
        )',

        // Clinical visits capturing SOAP-style notes.
        'CREATE TABLE IF NOT EXISTS visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            visit_date TEXT NOT NULL,
            subjective_notes TEXT,
            objective_notes TEXT,
            assessment TEXT,
            plan TEXT,
            vitals_bp TEXT,
            vitals_hr TEXT,
            vitals_temp TEXT,
            vitals_oxygen TEXT,
            next_appointment TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE
        )',

        // Financial and procedural activity log.
        'CREATE TABLE IF NOT EXISTS treatment_activities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            visit_id INTEGER,
            activity_date TEXT NOT NULL,
            description TEXT NOT NULL,
            fee REAL DEFAULT 0,
            payment REAL DEFAULT 0,
            balance REAL DEFAULT 0,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY(visit_id) REFERENCES visits(id) ON DELETE SET NULL
        )'
    ];

    foreach ($schemaStatements as $sql) {
        $pdo->exec($sql);
    }
}

/**
 * Applies non-destructive schema upgrades when new fields are introduced.
 */
function ensureSchemaUpgrades(PDO $pdo): void
{
    $patientColumns = tableColumns($pdo, 'patients');
    if (!isset($patientColumns['representative_document'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN representative_document TEXT');
    }

    $profileColumns = tableColumns($pdo, 'clinical_profiles');
    if (!isset($profileColumns['antecedent_ent'])) {
        $pdo->exec('ALTER TABLE clinical_profiles ADD COLUMN antecedent_ent INTEGER DEFAULT 0');
    }
    if (!isset($profileColumns['antecedent_hepatic'])) {
        $pdo->exec('ALTER TABLE clinical_profiles ADD COLUMN antecedent_hepatic INTEGER DEFAULT 0');
    }
    if (!isset($profileColumns['physical_exam_bp'])) {
        $pdo->exec('ALTER TABLE clinical_profiles ADD COLUMN physical_exam_bp TEXT');
    }

    $odontogramColumns = tableColumns($pdo, 'odontogram_entries');
    if (!isset($odontogramColumns['surface_data'])) {
        $pdo->exec('ALTER TABLE odontogram_entries ADD COLUMN surface_data TEXT');
    }
}

/**
 * Returns the column metadata for the given table keyed by column name.
 *
 * @return array<string,array<string,mixed>>
 */
function tableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['name']] = $column;
    }

    return $columns;
}

/**
 * Applies a simple key => value style update statement.
 */
function updateById(PDO $pdo, string $table, array $data, int $id): void
{
    $columns = array_keys($data);
    $setClauses = array_map(fn($col) => "$col = :$col", $columns);
    $sql = sprintf(
        'UPDATE %s SET %s, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        $table,
        implode(', ', $setClauses)
    );
    $stmt = $pdo->prepare($sql);
    foreach ($data as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}

/**
 * Handles insert operations returning the new identifier.
 */
function insertRow(PDO $pdo, string $table, array $data): int
{
    $columns = array_keys($data);
    $placeholders = array_map(fn($col) => ':' . $col, $columns);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $columns),
        implode(', ', $placeholders)
    );
    $stmt = $pdo->prepare($sql);
    foreach ($data as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();

    return (int) $pdo->lastInsertId();
}

/**
 * Upserts odontogram entry for a specific tooth.
 */
function upsertOdontogramEntry(PDO $pdo, int $patientId, string $toothCode, array $data): void
{
    $sql = 'INSERT INTO odontogram_entries (patient_id, tooth_code, status, surface_data, notes)
            VALUES (:patient_id, :tooth_code, :status, :surface_data, :notes)
            ON CONFLICT(patient_id, tooth_code)
            DO UPDATE SET status = excluded.status,
                          surface_data = COALESCE(excluded.surface_data, odontogram_entries.surface_data),
                          notes = excluded.notes,
                          updated_at = CURRENT_TIMESTAMP';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':patient_id' => $patientId,
        ':tooth_code' => $toothCode,
        ':status' => $data['status'] ?? null,
        ':surface_data' => $data['surface_data'] ?? null,
        ':notes' => $data['notes'] ?? null,
    ]);
}

/**
 * Small helper to safely read a value from $_POST with optional default.
 */
function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

/**
 * Small helper to sanitize checkbox values (returns 1 or 0).
 */
function postCheckbox(string $key): int
{
    return isset($_POST[$key]) && $_POST[$key] ? 1 : 0;
}

/**
 * Returns a normalized date string (YYYY-MM-DD) or null.
 */
function normalizeDate(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d', $timestamp);
}
