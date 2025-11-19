#requires -Version 5.1
<#
.SYNOPSIS
    Automatiza la actualización del sistema odontológico en equipos Windows.
.DESCRIPTION
    - Verifica/instala git y sqlite3 (usando winget si está disponible).
    - Crea respaldos seguros de la base de datos SQLite.
    - Protege el archivo de base frente a pulls (skip-worktree).
    - Ejecuta git fetch/pull del branch indicado.
    - Aplica migraciones idempotentes para columnas de firma digital.
.PARAMETER DatabasePath
    Ruta al archivo SQLite que contiene la data del cliente.
.PARAMETER Remote
    Nombre del remoto git a sincronizar (por defecto origin).
.PARAMETER Branch
    Branch remoto con los últimos cambios (por defecto andrea).
.PARAMETER DryRun
    Muestra los comandos críticos sin ejecutarlos (salvo validaciones).
.PARAMETER SkipGitPull
    Salta la parte de git (útil cuando solo se desea ejecutar migraciones).
#>

param(
    [string]$DatabasePath = "data/clinic.sqlite",
    [string]$Remote = "origin",
    [string]$Branch = "andrea",
    [switch]$DryRun,
    [switch]$SkipGitPull
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Step($Message) {
    Write-Host "`n=== $Message ===" -ForegroundColor Green
}

function Write-Info($Message) {
    Write-Host "[INFO] $Message" -ForegroundColor Cyan
}

function Write-Action($Message) {
    Write-Host " -> $Message" -ForegroundColor Yellow
}

function Confirm-Action($Prompt) {
    $answer = Read-Host "$Prompt (y/N)"
    return $answer -match '^(y|yes)$'
}

function Test-CommandExists {
    param([Parameter(Mandatory)][string]$Name)
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Install-IfPossible {
    param(
        [Parameter(Mandatory)][string]$ToolName,
        [Parameter(Mandatory)][string]$WingetId,
        [Parameter(Mandatory)][string]$FriendlyName
    )

    if (Test-CommandExists -Name $ToolName) {
        return $true
    }

    if (-not (Test-CommandExists -Name "winget")) {
        Write-Warning "$FriendlyName no está instalado y winget no está disponible; instálalo manualmente."
        return $false
    }

    Write-Info "$FriendlyName no encontrado. Intentando instalarlo con winget ($WingetId)..."
    $installArgs = @("install", "--id", $WingetId, "--exact", "--silent", "--accept-package-agreements", "--accept-source-agreements")
    $process = Start-Process -FilePath "winget" -ArgumentList $installArgs -Wait -PassThru -WindowStyle Hidden
    if ($process.ExitCode -ne 0) {
        Write-Warning "No fue posible instalar $FriendlyName automáticamente (código $($process.ExitCode)). Instálalo manualmente y vuelve a ejecutar."
        return $false
    }

    return (Test-CommandExists -Name $ToolName)
}

function Ensure-Tools {
    $requirements = @(
        @{ Tool="git"; Winget="Git.Git"; Label="Git" },
        @{ Tool="sqlite3"; Winget="SQLite.SQLite"; Label="SQLite CLI" }
    )

    foreach ($req in $requirements) {
        if (-not (Install-IfPossible -ToolName $req.Tool -WingetId $req.Winget -FriendlyName $req.Label)) {
            throw "No se puede continuar sin $($req.Label)."
        }
    }
}

function Invoke-LoggedCommand {
    param(
        [Parameter(Mandatory)][string]$Command,
        [Parameter()][string[]]$Arguments = @(),
        [switch]$AllowFailure
    )

    Write-Action "$Command $($Arguments -join ' ')"
    if ($DryRun) {
        Write-Info "Dry-run habilitado: comando no ejecutado."
        return
    }

    $process = Start-Process -FilePath $Command -ArgumentList $Arguments -Wait -PassThru
    if ($process.ExitCode -ne 0 -and -not $AllowFailure) {
        throw "El comando '$Command' falló con código $($process.ExitCode)."
    }
}

function Ensure-RepoRoot {
    if (-not (Test-Path ".git")) {
        throw "No se encontró la carpeta .git. Ejecuta este script desde la raíz del proyecto."
    }
}

function Check-WorkingTree {
    $status = & git status --porcelain
    if ([string]::IsNullOrWhiteSpace($status)) {
        Write-Info "No hay cambios sin commit."
        return
    }

    Write-Warning "Hay cambios sin commit:"
    $status | ForEach-Object { Write-Host "   $_" }
    if (-not (Confirm-Action "Continuar de todos modos")) {
        throw "Operación cancelada por el usuario."
    }
}

function Backup-Database {
    param([string]$DbPath)

    if (-not (Test-Path $DbPath)) {
        throw "No se encontró la base de datos en '$DbPath'."
    }

    $backupDir = Join-Path -Path (Get-Location) -ChildPath "backups"
    if (-not (Test-Path $backupDir)) {
        New-Item -ItemType Directory -Path $backupDir | Out-Null
    }

    $timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
    $leaf = [IO.Path]::GetFileNameWithoutExtension($DbPath)
    $ext = [IO.Path]::GetExtension($DbPath)
    $backupName = "$($leaf)_$timestamp$ext"
    $backupPath = Join-Path $backupDir $backupName

    Write-Info "Creando respaldo en $backupPath"
    if (-not $DryRun) {
        Copy-Item -Path $DbPath -Destination $backupPath -ErrorAction Stop
    }

    return $backupPath
}

function Protect-DatabaseFromGit {
    param([string]$DbPath)

    $args = @("ls-files", "--error-unmatch", $DbPath)
    $tracked = $false
    try {
        & git @args 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            $tracked = $true
        }
    } catch {
        $tracked = $false
    }

    if (-not $tracked) {
        Write-Info "La base no está rastreada por git. Nada que proteger."
        return
    }

    Write-Info "Marcando $DbPath con skip-worktree para que git no lo sobrescriba."
    if (-not $DryRun) {
        & git update-index --skip-worktree $DbPath
    }
}

function Update-Codebase {
    param([string]$RemoteName, [string]$BranchName)

    Invoke-LoggedCommand -Command "git" -Arguments @("fetch", $RemoteName, "--prune")
    Invoke-LoggedCommand -Command "git" -Arguments @("pull", "--rebase", $RemoteName, $BranchName)
}

function Ensure-Migrations {
    param([string]$DbPath)

    $migrations = @(
        @{
            Table="clinical_profiles"
            Column="consent_signature"
            Sql="ALTER TABLE clinical_profiles ADD COLUMN consent_signature TEXT"
            Description="Firma digital (canvas)"
        },
        @{
            Table="clinical_profiles"
            Column="consent_ci"
            Sql="ALTER TABLE clinical_profiles ADD COLUMN consent_ci TEXT"
            Description="Cédula asociada a la firma"
        }
    )

    foreach ($migration in $migrations) {
        $table = $migration.Table
        $column = $migration.Column
        $present = & sqlite3 $DbPath "PRAGMA table_info($table);" | ForEach-Object {
            ($_ -split '\|')[1]
        } | Where-Object { $_ -eq $column }

        if ($present) {
            Write-Info "Columna $table.$column ya existe. Saltando."
            continue
        }

        Write-Info "Aplicando migración: $($migration.Description)"
        if (-not $DryRun) {
            & sqlite3 $DbPath $migration.Sql
        } else {
            Write-Info "Dry-run: NO se ejecutó '$($migration.Sql)'"
        }
    }
}

try {
    $logDir = Join-Path -Path (Get-Location) -ChildPath "logs"
    if (-not (Test-Path $logDir)) {
        New-Item -ItemType Directory -Path $logDir | Out-Null
    }
    $logFile = Join-Path $logDir ("update_client_{0}.log" -f (Get-Date -Format "yyyyMMdd_HHmmss"))
    try {
        Start-Transcript -Path $logFile | Out-Null
    } catch {
        Write-Warning "No se pudo iniciar el log de transcripción: $($_.Exception.Message)"
    }

    Write-Step "Validaciones iniciales"
    Ensure-RepoRoot
    Ensure-Tools
    Check-WorkingTree

    Write-Step "Respaldo de base de datos"
    $backup = Backup-Database -DbPath $DatabasePath
    Write-Info "Respaldo creado: $backup"

    Write-Step "Protección de base frente a git"
    Protect-DatabaseFromGit -DbPath $DatabasePath

    if (-not $SkipGitPull) {
        Write-Step "Actualización de código (git $Branch)"
        Update-Codebase -RemoteName $Remote -BranchName $Branch
    } else {
        Write-Info "Se omitió git pull por indicación del usuario."
    }

    Write-Step "Migraciones de base de datos"
    Ensure-Migrations -DbPath $DatabasePath

    Write-Step "Completado"
    Write-Info "Operación finalizada. Revisa el log en $logFile"
}
catch {
    Write-Error $_
    Write-Host "`nSe produjo un error. Revisa el log para más detalles." -ForegroundColor Red
    exit 1
}
finally {
    if ($Host -and $Host.Name -ne "ServerRemoteHost") {
        try { Stop-Transcript | Out-Null } catch {}
    }
}
