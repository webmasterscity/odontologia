<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Gestión Odontológica';
}
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$assetPrefix = ($basePath === '' ? '.' : $basePath) . '/assets';
$currentScript = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef5ff',
                            100: '#dbe8ff',
                            200: '#b9d1ff',
                            300: '#8ab0fe',
                            400: '#5b8af9',
                            500: '#3a67f1',
                            600: '#2d50d6',
                            700: '#2540ad',
                            800: '#21388d',
                            900: '#1f3273',
                            950: '#0f1c45'
                        }
                    },
                    boxShadow: {
                        soft: '0 18px 40px -20px rgba(37, 80, 214, 0.35)'
                    },
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetPrefix) ?>/css/style.css?v=2.6">
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/80 backdrop-blur">
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 sm:px-6 lg:px-8 md:flex-row md:items-center md:justify-between">
    <a href="<?= htmlspecialchars(($basePath ?: '') . '/index.php') ?>" class="flex items-center gap-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 rounded-2xl no-underline hover:no-underline">
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-tr from-brand-500 to-brand-600 text-2xl text-white shadow-soft ring-1 ring-inset ring-brand-300/60">🦷</span>
            <div class="space-y-0.5">
                <h1 class="text-xl font-semibold text-slate-900">Consultorio Odontológico</h1>
                <p class="text-sm text-slate-500">Historia clínica digital y evolución de pacientes</p>
            </div>
        </a>
        <nav class="flex flex-wrap items-center gap-3 text-sm font-medium">
            <?php
            $links = [
                ['href' => 'index.php', 'label' => 'Pacientes', 'match' => ['index.php'], 'icon' => '👥'],
                ['href' => 'patient_form.php', 'label' => 'Registrar paciente', 'match' => ['patient_form.php'], 'icon' => '➕'],
                ['href' => 'finances.php', 'label' => 'Finanzas', 'match' => ['finances.php'], 'icon' => '💰'],
                ['href' => 'index.php#respaldo', 'label' => 'Respaldo y copias', 'match' => ['index.php'], 'icon' => '💾'],
            ];
            foreach ($links as $link) {
                $isActive = in_array($currentScript, $link['match'], true);
                $classes = 'inline-flex items-center gap-2 rounded-xl px-4 py-2.5 transition-all duration-200 font-semibold';
                $classes .= $isActive
                    ? ' bg-gradient-to-r from-brand-600 to-brand-500 text-white shadow-lg shadow-brand-500/30 hover:shadow-xl hover:shadow-brand-500/40 hover:scale-105'
                    : ' bg-white text-slate-700 shadow-sm ring-1 ring-slate-200 hover:ring-brand-300 hover:text-brand-600 hover:shadow-md hover:scale-105';
                echo '<a href="' . htmlspecialchars($link['href']) . '" class="' . $classes . '">';
                echo '<span class="text-base">' . $link['icon'] . '</span>';
                echo '<span>' . htmlspecialchars($link['label']) . '</span>';
                echo '</a>';
            }
            ?>
        </nav>
    </div>
</header>
<main class="flex-1 w-full max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8 space-y-10">
