<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Gestión Odontológica';
}
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$assetPrefix = ($basePath === '' ? '.' : $basePath) . '/assets';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetPrefix) ?>/css/style.css?v=1.0">
</head>
<body>
<header class="site-header">
    <div class="brand">
        <span class="brand-logo">🦷</span>
        <div>
            <h1>Consultorio Odontológico</h1>
            <p class="subtitle">Historia clínica digital y evolución de pacientes</p>
        </div>
    </div>
    <nav class="main-nav">
        <a href="index.php" class="<?= basename($_SERVER['SCRIPT_NAME']) === 'index.php' ? 'active' : '' ?>">Pacientes</a>
        <a href="patient_form.php">Registrar paciente</a>
        <a href="index.php#respaldo">Respaldo</a>
    </nav>
</header>
<main class="page">
