<?php
/**
 * Script d'installation de l'application Planning Bénévoles.
 * 
 * Ce script permet de :
 * 1. Configurer la connexion à la base de données et créer le fichier .env
 * 2. Exécuter les migrations Phinx pour créer le schéma de la base
 * 3. Créer le compte administrateur initial
 * 
 * ⚠️ SUPPRIMER CE FICHIER APRÈS L'INSTALLATION
 */

// Empêcher l'exécution si .env existe déjà et que la BDD indique que c'est installé
require_once __DIR__ . '/vendor/autoload.php';

$envFile = __DIR__ . '/.env';
$isAlreadyInstalled = false;

if (file_exists($envFile)) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();

        if (!empty($_ENV['DB_NAME']) && !empty($_ENV['DB_USER'])) {
            $dbHost = $_ENV['DB_HOST'] ?? 'localhost';
            $dbName = $_ENV['DB_NAME'];
            $dbUser = $_ENV['DB_USER'];
            $dbPass = $_ENV['DB_PASS'] ?? '';
            $dbPrefix = $_ENV['DB_PREFIX'] ?? '';

            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Vérifier si le paramètre 'installed' existe dans la table 'settings'
            $stmt = $pdo->prepare("SELECT value FROM `{$dbPrefix}settings` WHERE name = 'installed'");
            $stmt->execute();
            $installedSetting = $stmt->fetchColumn();

            if ($installedSetting === '1') {
                http_response_code(403);
                die("<h1>Accès interdit</h1><p>L'application est déjà installée et le script d'installation a été verrouillé pour des raisons de sécurité.</p>");
            }
        }
        $isAlreadyInstalled = true;
    } catch (\PDOException $e) {
        // La base de données ou la table n'existe pas encore, c'est normal au premier lancement
        $isAlreadyInstalled = true;
    }
}

// Étape courante
$step = $_POST['step'] ?? ($_GET['step'] ?? '1');
$error = null;
$success = null;
$migrationOutput = '';

// === TRAITEMENT DES FORMULAIRES ===

// Étape 1 → 2 : Configuration de la base de données
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '1') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbPrefix = trim($_POST['db_prefix'] ?? '');
    $appBasepath = trim($_POST['app_basepath'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');

    if (empty($dbName) || empty($dbUser)) {
        $error = "Le nom de la base de données et l'utilisateur sont obligatoires.";
        $step = '1';
    } else {
        // Tester la connexion
        try {
            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Générer une clé de maintenance aléatoire
            $maintenanceSecret = bin2hex(random_bytes(16));

            // Écrire le fichier .env
            $envContent = <<<ENV
APP_ENV=production
DB_HOST="{$dbHost}"
DB_NAME="{$dbName}"
DB_USER="{$dbUser}"
DB_PASS="{$dbPass}"
DB_PREFIX="{$dbPrefix}"
APP_BASEPATH="{$appBasepath}"
APP_MAINTENANCE=false
APP_MAINTENANCE_SECRET="{$maintenanceSecret}"
CONTACT_EMAIL="{$contactEmail}"
ENV;

            file_put_contents($envFile, $envContent . "\n");
            $step = '2';
        } catch (PDOException $e) {
            $error = "Impossible de se connecter à la base de données : " . $e->getMessage();
            $step = '1';
        }
    }
}

// Étape 2 : Exécution des migrations Phinx
if ($step === '2' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migrate'])) {
    require_once __DIR__ . '/vendor/autoload.php';

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();

    try {
        $app = new \Phinx\Console\PhinxApplication();
        $app->setAutoExit(false);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $exitCode = $app->run(
            new \Symfony\Component\Console\Input\ArrayInput([
                'command' => 'migrate',
                '--configuration' => __DIR__ . '/phinx.php',
                '--environment' => 'production',
            ]),
            $output
        );

        $migrationOutput = $output->fetch();

        if ($exitCode === 0) {
            $step = '3';
        } else {
            $error = "Les migrations ont échoué (code $exitCode).";
        }
    } catch (\Exception $e) {
        $error = "Erreur lors des migrations : " . $e->getMessage();
    }
}

// Étape 3 : Création du compte admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '3') {
    $adminUser = trim($_POST['admin_username'] ?? '');
    $adminPass = $_POST['admin_password'] ?? '';
    $adminPassConfirm = $_POST['admin_password_confirm'] ?? '';
    $adminFirstname = trim($_POST['admin_firstname'] ?? 'Admin');
    $adminName = trim($_POST['admin_name'] ?? 'Admin');

    if (empty($adminUser) || empty($adminPass)) {
        $error = "Le nom d'utilisateur et le mot de passe sont obligatoires.";
        $step = '3';
    } elseif ($adminPass !== $adminPassConfirm) {
        $error = "Les mots de passe ne correspondent pas.";
        $step = '3';
    } elseif (strlen($adminPass) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";
        $step = '3';
    } else {
        require_once __DIR__ . '/vendor/autoload.php';
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();

        $prefix = $_ENV['DB_PREFIX'] ?? '';

        try {
            $pdo = new PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
                $_ENV['DB_USER'],
                $_ENV['DB_PASS'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $role = 'admin';
            $now = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO {$prefix}users (name, firstname, username, password, role, lastModifiedPassword) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$adminName, $adminFirstname, $adminUser, $hash, $role, $now]);

            // Enregistrer installed = 1 dans la table settings
            $stmtSettings = $pdo->prepare("INSERT INTO `{$prefix}settings` (name, value) VALUES ('installed', '1') ON DUPLICATE KEY UPDATE value = '1'");
            $stmtSettings->execute();

            $step = '4';
        } catch (PDOException $e) {
            $error = "Erreur lors de la création du compte admin : " . $e->getMessage();
            $step = '3';
        }
    }
}

// === AFFICHAGE ===
$steps = [
    '1' => 'Base de données',
    '2' => 'Migrations',
    '3' => 'Compte admin',
    '4' => 'Terminé'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - Planning Bénévoles</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 30px 15px; }
        .install-card { background: #fff; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 600px; width: 100%; overflow: hidden; }
        .install-header { background: #1e293b; color: #f8fafc; padding: 25px 30px; text-align: center; }
        .install-header h1 { margin: 0 0 5px 0; font-size: 22px; font-weight: 700; }
        .install-header p { margin: 0; opacity: 0.7; font-size: 13px; }
        .install-body { padding: 30px; }
        .step-indicator { display: flex; justify-content: center; gap: 8px; margin-bottom: 25px; }
        .step-dot { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #94a3b8; background: #e2e8f0; transition: all 0.3s; }
        .step-dot.active { background: #6366f1; color: #fff; }
        .step-dot.done { background: #22c55e; color: #fff; }
        .step-connector { width: 30px; height: 2px; background: #e2e8f0; align-self: center; }
        .form-group label { font-weight: 600; color: #334155; font-size: 13px; }
        .form-control { border-radius: 6px; border: 1.5px solid #e2e8f0; height: 40px; }
        .form-control:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
        textarea.form-control { height: auto; }
        .btn-install { background: #6366f1; border: none; color: #fff; font-weight: 600; border-radius: 6px; padding: 10px 24px; font-size: 14px; }
        .btn-install:hover { background: #4f46e5; color: #fff; }
        .help-text { font-size: 12px; color: #94a3b8; margin-top: 3px; }
        .migration-log { background: #0f172a; color: #22c55e; font-family: monospace; font-size: 12px; padding: 15px; border-radius: 6px; max-height: 200px; overflow-y: auto; white-space: pre-wrap; }
        .success-icon { font-size: 64px; color: #22c55e; margin-bottom: 15px; }
    </style>
</head>
<body>
<div class="install-card">
    <div class="install-header">
        <h1><i class="fa-solid fa-calendar-check"></i> Planning Bénévoles</h1>
        <p>Assistant d'installation</p>
    </div>

    <div class="install-body">
        <!-- Indicateur d'étapes -->
        <div class="step-indicator">
            <?php $stepKeys = array_keys($steps); foreach ($stepKeys as $i => $key): ?>
                <?php
                    $class = 'step-dot';
                    if ($key === $step) $class .= ' active';
                    elseif (array_search($key, $stepKeys) < array_search($step, $stepKeys)) $class .= ' done';
                ?>
                <div class="<?= $class ?>"><?= $key === '4' ? '✓' : $key ?></div>
                <?php if ($i < count($stepKeys) - 1): ?><div class="step-connector"></div><?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="border-radius: 6px; font-size: 13px;">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- ==================== ÉTAPE 1 : Base de données ==================== -->
        <?php if ($step === '1'): ?>
            <?php if ($isAlreadyInstalled): ?>
                <div class="alert alert-warning" style="border-radius: 6px; font-size: 13px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> Un fichier <code>.env</code> existe déjà. Continuer écrasera la configuration actuelle.
                </div>
            <?php endif; ?>

            <h4 style="font-weight: 700; color: #1e293b; margin-top: 0;"><i class="fa-solid fa-database text-primary"></i> Configuration de la base de données</h4>

            <form method="POST">
                <input type="hidden" name="step" value="1">

                <div class="row">
                    <div class="col-sm-8">
                        <div class="form-group">
                            <label for="db_host">Hôte</label>
                            <input type="text" id="db_host" name="db_host" class="form-control" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label for="db_prefix">Préfixe tables</label>
                            <input type="text" id="db_prefix" name="db_prefix" class="form-control" value="<?= htmlspecialchars($_POST['db_prefix'] ?? '') ?>" placeholder="ex: app_">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="db_name">Nom de la base de données *</label>
                    <input type="text" id="db_name" name="db_name" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required>
                </div>

                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="db_user">Utilisateur *</label>
                            <input type="text" id="db_user" name="db_user" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="db_pass">Mot de passe</label>
                            <input type="password" id="db_pass" name="db_pass" class="form-control" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <hr style="border-color: #f1f5f9;">

                <div class="form-group">
                    <label for="app_basepath">Chemin de base de l'application</label>
                    <input type="text" id="app_basepath" name="app_basepath" class="form-control" value="<?= htmlspecialchars($_POST['app_basepath'] ?? '') ?>" placeholder="ex: /planning">
                    <p class="help-text">Laisser vide si l'application est à la racine du domaine.</p>
                </div>

                <div class="form-group">
                    <label for="contact_email">Adresse e-mail de contact (Trello / Support)</label>
                    <input type="email" id="contact_email" name="contact_email" class="form-control" value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>" placeholder="support@boards.trello.com">
                </div>

                <button type="submit" class="btn btn-install btn-block" style="margin-top: 10px;">
                    Tester la connexion et continuer <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

        <!-- ==================== ÉTAPE 2 : Migrations ==================== -->
        <?php elseif ($step === '2'): ?>
            <h4 style="font-weight: 700; color: #1e293b; margin-top: 0;"><i class="fa-solid fa-layer-group text-primary"></i> Création des tables</h4>
            <p style="color: #64748b; font-size: 13px;">Le fichier <code>.env</code> a été créé avec succès. Cliquez sur le bouton ci-dessous pour créer les tables dans la base de données via les migrations Phinx.</p>

            <?php if (!empty($migrationOutput)): ?>
                <div class="migration-log"><?= htmlspecialchars($migrationOutput) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="run_migrate" value="1">
                <button type="submit" class="btn btn-install btn-block" style="margin-top: 15px;">
                    <i class="fa-solid fa-play"></i> Lancer les migrations
                </button>
            </form>

        <!-- ==================== ÉTAPE 3 : Compte admin ==================== -->
        <?php elseif ($step === '3'): ?>
            <?php if (!empty($migrationOutput)): ?>
                <div class="alert alert-success" style="border-radius: 6px; font-size: 13px;">
                    <i class="fa-solid fa-circle-check"></i> Les tables ont été créées avec succès.
                </div>
            <?php endif; ?>

            <h4 style="font-weight: 700; color: #1e293b; margin-top: 0;"><i class="fa-solid fa-user-shield text-primary"></i> Compte administrateur</h4>
            <p style="color: #64748b; font-size: 13px;">Créez le compte administrateur principal qui aura accès à toutes les fonctionnalités.</p>

            <form method="POST">
                <input type="hidden" name="step" value="3">

                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="admin_firstname">Prénom</label>
                            <input type="text" id="admin_firstname" name="admin_firstname" class="form-control" value="<?= htmlspecialchars($_POST['admin_firstname'] ?? 'Admin') ?>">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="admin_name">Nom</label>
                            <input type="text" id="admin_name" name="admin_name" class="form-control" value="<?= htmlspecialchars($_POST['admin_name'] ?? 'Admin') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="admin_username">Nom d'utilisateur *</label>
                    <input type="text" id="admin_username" name="admin_username" class="form-control" value="<?= htmlspecialchars($_POST['admin_username'] ?? 'admin') ?>" required>
                </div>

                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="admin_password">Mot de passe *</label>
                            <input type="password" id="admin_password" name="admin_password" class="form-control" required minlength="6">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="admin_password_confirm">Confirmer *</label>
                            <input type="password" id="admin_password_confirm" name="admin_password_confirm" class="form-control" required minlength="6">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-install btn-block" style="margin-top: 10px;">
                    Créer le compte et terminer <i class="fa-solid fa-check"></i>
                </button>
            </form>

        <!-- ==================== ÉTAPE 4 : Terminé ==================== -->
        <?php elseif ($step === '4'): ?>
            <div class="text-center">
                <div class="success-icon"><i class="fa-solid fa-circle-check"></i></div>
                <h3 style="font-weight: 700; color: #1e293b;">Installation terminée !</h3>
                <p style="color: #64748b; font-size: 14px;">L'application Planning Bénévoles est prête à être utilisée.</p>

                <div class="alert alert-danger" style="border-radius: 6px; font-size: 13px; text-align: left; margin-top: 20px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <strong>Sécurité</strong> : Supprimez immédiatement ce fichier <code>install.php</code> du serveur pour empêcher toute réinstallation non autorisée.
                </div>

                <?php
                    // Lire le basepath depuis le .env
                    require_once __DIR__ . '/vendor/autoload.php';
                    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
                    $dotenv->safeLoad();
                    $basepath = $_ENV['APP_BASEPATH'] ?? '';
                ?>

                <a href="<?= htmlspecialchars($basepath) ?>/" class="btn btn-install btn-block" style="margin-top: 15px;">
                    <i class="fa-solid fa-arrow-right"></i> Accéder à l'application
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
