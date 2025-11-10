<?php
declare(strict_types=1);

date_default_timezone_set('America/La_Paz');

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
            preferred_name TEXT,
            document_id TEXT,
            birth_date TEXT,
            age INTEGER,
            gender TEXT,
            marital_status TEXT,
            occupation TEXT,
            address TEXT,
            email TEXT,
            phone_primary TEXT,
            phone_secondary TEXT,
            referred_by TEXT,
            primary_physician TEXT,
            primary_physician_phone TEXT,
            insurance_provider TEXT,
            insurance_policy_number TEXT,
            representative_name TEXT,
            representative_document TEXT,
            representative_phone TEXT,
            emergency_contact TEXT,
            emergency_contact_relationship TEXT,
            emergency_contact_phone TEXT,
            profile_photo_path TEXT,
            profile_photo_zoom REAL DEFAULT 1.0,
            profile_photo_offset_x REAL DEFAULT 0.0,
            profile_photo_offset_y REAL DEFAULT 0.0,
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
            payment_method TEXT,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY(visit_id) REFERENCES visits(id) ON DELETE SET NULL
        )',

        // Paraclinical studies uploaded per patient.
        'CREATE TABLE IF NOT EXISTS patient_studies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            title TEXT,
            study_type TEXT,
            captured_at TEXT,
            notes TEXT,
            original_filename TEXT,
            file_path TEXT NOT NULL,
            mime_type TEXT,
            file_size INTEGER,
            uploaded_by TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE
        )',
        'CREATE INDEX IF NOT EXISTS patient_studies_patient_id_idx ON patient_studies(patient_id)',
        'CREATE INDEX IF NOT EXISTS patient_studies_captured_at_idx ON patient_studies(captured_at)'
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
    if (!isset($patientColumns['preferred_name'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN preferred_name TEXT');
    }
    if (!isset($patientColumns['marital_status'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN marital_status TEXT');
    }
    if (!isset($patientColumns['occupation'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN occupation TEXT');
    }
    if (!isset($patientColumns['referred_by'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN referred_by TEXT');
    }
    if (!isset($patientColumns['primary_physician'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN primary_physician TEXT');
    }
    if (!isset($patientColumns['primary_physician_phone'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN primary_physician_phone TEXT');
    }
    if (!isset($patientColumns['insurance_provider'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN insurance_provider TEXT');
    }
    if (!isset($patientColumns['insurance_policy_number'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN insurance_policy_number TEXT');
    }
    if (!isset($patientColumns['representative_document'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN representative_document TEXT');
    }
    if (!isset($patientColumns['emergency_contact_relationship'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN emergency_contact_relationship TEXT');
    }
    if (!isset($patientColumns['emergency_contact_phone'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN emergency_contact_phone TEXT');
    }
    if (!isset($patientColumns['profile_photo_path'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN profile_photo_path TEXT');
    }
    if (!isset($patientColumns['profile_photo_zoom'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN profile_photo_zoom REAL DEFAULT 1.0');
        $pdo->exec('UPDATE patients SET profile_photo_zoom = 1.0 WHERE profile_photo_zoom IS NULL');
    }
    if (!isset($patientColumns['profile_photo_offset_x'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN profile_photo_offset_x REAL DEFAULT 0.0');
        $pdo->exec('UPDATE patients SET profile_photo_offset_x = 0.0 WHERE profile_photo_offset_x IS NULL');
    }
    if (!isset($patientColumns['profile_photo_offset_y'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN profile_photo_offset_y REAL DEFAULT 0.0');
        $pdo->exec('UPDATE patients SET profile_photo_offset_y = 0.0 WHERE profile_photo_offset_y IS NULL');
    }
    if (!isset($patientColumns['registered_at'])) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN registered_at TEXT');
        $pdo->exec('UPDATE patients SET registered_at = created_at WHERE registered_at IS NULL');
    }

    // Attempt to normalize legacy emergency contact values to the new structured fields.
    $needsContactNormalization = $pdo->query(
        'SELECT COUNT(*) AS total FROM patients WHERE emergency_contact IS NOT NULL
            AND TRIM(emergency_contact) != \'\'
            AND (emergency_contact_phone IS NULL OR TRIM(emergency_contact_phone) = \'\')'
    )->fetchColumn();
    if ($needsContactNormalization) {
        $stmt = $pdo->query('SELECT id, emergency_contact, emergency_contact_phone FROM patients');
        $update = $pdo->prepare(
            'UPDATE patients
             SET emergency_contact = :contact_name,
                 emergency_contact_phone = COALESCE(emergency_contact_phone, :contact_phone),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contact = $row['emergency_contact'] !== null ? trim((string) $row['emergency_contact']) : '';
            $existingPhone = $row['emergency_contact_phone'] !== null ? trim((string) $row['emergency_contact_phone']) : '';
            if ($contact === '' || $existingPhone !== '') {
                continue;
            }
            $parts = preg_split('/\s*[-–—]\s*/u', $contact, 2);
            $contactName = trim($parts[0] ?? '');
            $contactPhone = trim($parts[1] ?? '');
            if ($contactName === '') {
                continue;
            }
            $update->execute([
                ':contact_name' => $contactName,
                ':contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                ':id' => $row['id'],
            ]);
        }
    }

    $activityColumns = tableColumns($pdo, 'treatment_activities');
    if (!isset($activityColumns['payment_method'])) {
        $pdo->exec('ALTER TABLE treatment_activities ADD COLUMN payment_method TEXT');
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

    $financeColumns = tableColumns($pdo, 'finance_entries');
    if ($financeColumns === []) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS finance_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entry_date TEXT NOT NULL,
                type TEXT NOT NULL,
                category TEXT,
                description TEXT NOT NULL,
                amount REAL NOT NULL DEFAULT 0,
                payment_method TEXT,
                notes TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS finance_entries_entry_date_idx ON finance_entries(entry_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS finance_entries_type_idx ON finance_entries(type)');
    } else {
        if (!isset($financeColumns['payment_method'])) {
            $pdo->exec('ALTER TABLE finance_entries ADD COLUMN payment_method TEXT');
        }
        if (!isset($financeColumns['notes'])) {
            $pdo->exec('ALTER TABLE finance_entries ADD COLUMN notes TEXT');
        }
        if (!isset($financeColumns['updated_at'])) {
            $pdo->exec('ALTER TABLE finance_entries ADD COLUMN updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
    }

    // Create odontogram_snapshots table if it doesn't exist
    $snapshotColumns = tableColumns($pdo, 'odontogram_snapshots');
    if ($snapshotColumns === []) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS odontogram_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                patient_id INTEGER NOT NULL,
                payload TEXT NOT NULL,
                is_blank INTEGER DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS odontogram_snapshots_patient_id_idx ON odontogram_snapshots(patient_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS odontogram_snapshots_created_at_idx ON odontogram_snapshots(created_at)');
    }

    $studyColumns = tableColumns($pdo, 'patient_studies');
    if ($studyColumns === []) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS patient_studies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                patient_id INTEGER NOT NULL,
                title TEXT,
                study_type TEXT,
                captured_at TEXT,
                notes TEXT,
                original_filename TEXT,
                file_path TEXT NOT NULL,
                mime_type TEXT,
                file_size INTEGER,
                uploaded_by TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS patient_studies_patient_id_idx ON patient_studies(patient_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS patient_studies_captured_at_idx ON patient_studies(captured_at)');
    } else {
        if (!isset($studyColumns['original_filename'])) {
            $pdo->exec('ALTER TABLE patient_studies ADD COLUMN original_filename TEXT');
        }
        if (!isset($studyColumns['mime_type'])) {
            $pdo->exec('ALTER TABLE patient_studies ADD COLUMN mime_type TEXT');
        }
        if (!isset($studyColumns['file_size'])) {
            $pdo->exec('ALTER TABLE patient_studies ADD COLUMN file_size INTEGER');
        }
        if (!isset($studyColumns['uploaded_by'])) {
            $pdo->exec('ALTER TABLE patient_studies ADD COLUMN uploaded_by TEXT');
        }
        if (!isset($studyColumns['created_at'])) {
            $pdo->exec('ALTER TABLE patient_studies ADD COLUMN created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS patient_studies_patient_id_idx ON patient_studies(patient_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS patient_studies_captured_at_idx ON patient_studies(captured_at)');
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
 * Normalizes the tooth payload structure before persisting snapshots or entries.
 *
 * @param array $payload Raw payload that may contain a 'teeth' key with tooth data.
 * @return array<string,array<string,mixed>>
 */
function normalizeSnapshotTeethPayload(array $payload): array
{
    $teeth = [];
    $source = $payload['teeth'] ?? [];
    if (!is_array($source)) {
        return $teeth;
    }

    foreach ($source as $toothCode => $toothData) {
        $code = trim((string) $toothCode);
        if ($code === '' || !is_array($toothData)) {
            continue;
        }

        $surfaces = [];
        if (isset($toothData['surfaces']) && is_array($toothData['surfaces'])) {
            $surfaces = $toothData['surfaces'];
        }

        $status = null;
        if (array_key_exists('status', $toothData) && $toothData['status'] !== null) {
            $statusCandidate = trim((string) $toothData['status']);
            if ($statusCandidate !== '') {
                $status = $statusCandidate;
            }
        }

        $notes = null;
        if (array_key_exists('notes', $toothData) && is_string($toothData['notes'])) {
            $noteCandidate = trim($toothData['notes']);
            if ($noteCandidate !== '') {
                $notes = $noteCandidate;
            }
        }

        if (empty($surfaces) && $status === null && $notes === null) {
            continue;
        }

        $teeth[$code] = [
            'surfaces' => $surfaces,
        ];
        if ($status !== null) {
            $teeth[$code]['status'] = $status;
        }
        if ($notes !== null) {
            $teeth[$code]['notes'] = $notes;
        }
    }

    return $teeth;
}

/**
 * Inserts a new odontogram snapshot row and returns its identifier.
 *
 * @param array $payload Structured payload with a 'teeth' key.
 */
function insertOdontogramSnapshot(PDO $pdo, int $patientId, array $payload, bool $isBlank = false): int
{
    $teeth = normalizeSnapshotTeethPayload($payload);
    $encodedPayload = json_encode(['teeth' => $teeth], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encodedPayload === false) {
        throw new RuntimeException('No se pudo serializar el odontograma.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO odontogram_snapshots (patient_id, payload, is_blank)
         VALUES (:patient_id, :payload, :is_blank)'
    );
    $stmt->execute([
        ':patient_id' => $patientId,
        ':payload' => $encodedPayload,
        ':is_blank' => $isBlank ? 1 : 0,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Removes all odontogram entries for the given patient.
 */
function clearOdontogramEntries(PDO $pdo, int $patientId): void
{
    $stmt = $pdo->prepare('DELETE FROM odontogram_entries WHERE patient_id = :patient_id');
    $stmt->execute([':patient_id' => $patientId]);
}

/**
 * Returns all paraclinical studies for the given patient, most recent first.
 *
 * @return array<int,array<string,mixed>>
 */
function getPatientStudies(PDO $pdo, int $patientId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM patient_studies
         WHERE patient_id = :patient_id
         ORDER BY id DESC'
    );
    $stmt->execute([':patient_id' => $patientId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Retrieves a single paraclinical study belonging to the patient.
 *
 * @return array<string,mixed>|null
 */
function findPatientStudy(PDO $pdo, int $patientId, int $studyId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM patient_studies WHERE id = :id AND patient_id = :patient_id LIMIT 1'
    );
    $stmt->execute([
        ':id' => $studyId,
        ':patient_id' => $patientId,
    ]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return $record !== false ? $record : null;
}

/**
 * Persists a new paraclinical study record.
 */
function insertPatientStudy(PDO $pdo, array $data): int
{
    $columns = array_keys($data);
    $placeholders = array_map(fn($col) => ':' . $col, $columns);
    $sql = sprintf(
        'INSERT INTO patient_studies (%s) VALUES (%s)',
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
 * Updates an existing paraclinical study record.
 */
function updatePatientStudy(PDO $pdo, int $patientId, int $studyId, array $data): bool
{
    $allowedColumns = [
        'title',
        'study_type',
        'captured_at',
        'notes',
        'uploaded_by',
    ];
    $setClauses = [];
    $params = [
        ':id' => $studyId,
        ':patient_id' => $patientId,
    ];

    foreach ($allowedColumns as $column) {
        if (array_key_exists($column, $data)) {
            $setClauses[] = $column . ' = :' . $column;
            $params[':' . $column] = $data[$column];
        }
    }

    if (!$setClauses) {
        return false;
    }

    $sql = 'UPDATE patient_studies SET ' . implode(', ', $setClauses) . ' WHERE id = :id AND patient_id = :patient_id';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $param => $value) {
        if ($param === ':id' || $param === ':patient_id') {
            $stmt->bindValue($param, (int) $value, PDO::PARAM_INT);
        } elseif ($value === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($param, $value);
        }
    }

    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        return true;
    }

    $checkStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM patient_studies WHERE id = :id AND patient_id = :patient_id'
    );
    $checkStmt->execute([
        ':id' => $studyId,
        ':patient_id' => $patientId,
    ]);

    return (int) $checkStmt->fetchColumn() > 0;
}

/**
 * Removes a paraclinical study record.
 */
function deletePatientStudy(PDO $pdo, int $patientId, int $studyId): bool
{
    $stmt = $pdo->prepare(
        'DELETE FROM patient_studies WHERE id = :id AND patient_id = :patient_id'
    );
    $stmt->execute([
        ':id' => $studyId,
        ':patient_id' => $patientId,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Returns the maximum number of studies allowed per patient.
 */
function getPatientStudyLimit(): int
{
    return 60;
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

/**
 * Returns the supported payment method keys mapped to display labels.
 *
 * @return array<string,string>
 */
function getPaymentMethodOptions(): array
{
    return [
        'cash' => 'Efectivo',
        'transfer' => 'Transferencia bancaria',
        'card' => 'Tarjeta',
        'mobile' => 'Pago móvil',
        'other' => 'Otro',
    ];
}

/**
 * Normalizes a payment method string to a consistent display label.
 *
 * @param mixed $value Raw payment method value from input or storage.
 */
function normalizePaymentMethodValue($value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_array($value)) {
        return null;
    }
    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return null;
    }

    $options = getPaymentMethodOptions();
    $lowerValue = function_exists('mb_strtolower')
        ? mb_strtolower($trimmed, 'UTF-8')
        : strtolower($trimmed);

    foreach ($options as $key => $label) {
        $keyLower = function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);
        $labelLower = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        if ($lowerValue === $keyLower || $lowerValue === $labelLower) {
            return $label;
        }
    }

    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $firstChar = mb_substr($trimmed, 0, 1, 'UTF-8');
        $rest = mb_substr($trimmed, 1, null, 'UTF-8');
        return mb_strtoupper($firstChar, 'UTF-8') . $rest;
    }

    $firstChar = substr($trimmed, 0, 1);
    $rest = substr($trimmed, 1);
    return strtoupper($firstChar) . $rest;
}

/**
 * Ensures PHP session is active for CSRF protection helpers.
 */
function ensureSessionStarted(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Returns a reusable CSRF token stored in the session.
 */
function getCsrfToken(): string
{
    ensureSessionStarted();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verifies the provided token against the session token.
 */
function verifyCsrfToken(?string $token): bool
{
    ensureSessionStarted();
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($stored) || $stored === '') {
        return false;
    }

    return is_string($token) && hash_equals($stored, $token);
}
