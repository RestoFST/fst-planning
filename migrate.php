<?php
/**
 * Script de migration de base de données (Phinx) pour hébergement mutualisé.
 * Lance automatiquement les migrations en attente.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Charger les variables d'environnement
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

$migrationOutput = '';
$error = null;
$success = false;

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
        $success = true;
    } else {
        $error = "Les migrations ont échoué avec le code de sortie $exitCode.";
    }
} catch (\Exception $e) {
    $error = "Erreur lors de l'exécution des migrations : " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrations de base de données - Planning Bénévoles</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8fafc; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 30px 15px; font-family: sans-serif; }
        .migration-card { background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); max-width: 700px; width: 100%; overflow: hidden; border: 1px solid #e2e8f0; }
        .migration-header { background: #1e293b; color: #f8fafc; padding: 20px 25px; display: flex; align-items: center; justify-content: space-between; }
        .migration-header h1 { margin: 0; font-size: 18px; font-weight: 700; }
        .migration-body { padding: 25px; }
        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .status-success { background: #dcfce7; color: #166534; }
        .status-danger { background: #fee2e2; color: #991b1b; }
        .log-container { background: #0f172a; color: #22c55e; font-family: monospace; font-size: 13px; padding: 20px; border-radius: 8px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; margin-top: 15px; }
        .btn-home { background: #6366f1; border: none; color: #fff; font-weight: 600; border-radius: 6px; padding: 10px 20px; text-decoration: none; display: inline-block; transition: background 0.2s; }
        .btn-home:hover { background: #4f46e5; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
<div class="migration-card">
    <div class="migration-header">
        <h1><i class="fa-solid fa-database"></i> Phinx Migrations</h1>
        <?php if ($success): ?>
            <span class="status-badge status-success"><i class="fa-solid fa-circle-check"></i> Réussi</span>
        <?php else: ?>
            <span class="status-badge status-danger"><i class="fa-solid fa-circle-xmark"></i> Échec</span>
        <?php endif; ?>
    </div>

    <div class="migration-body">
        <?php if ($error): ?>
            <div class="alert alert-danger" style="border-radius: 6px;">
                <strong><i class="fa-solid fa-circle-exclamation"></i> Erreur :</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php else: ?>
            <div class="alert alert-success" style="border-radius: 6px;">
                <strong><i class="fa-solid fa-circle-check"></i> Succès :</strong> Les migrations ont été exécutées.
            </div>
        <?php endif; ?>

        <h5 style="font-weight: 700; color: #334155; margin-bottom: 5px;">Log d'exécution :</h5>
        <div class="log-container"><?= htmlspecialchars(trim($migrationOutput) ?: "Aucune migration en attente.") ?></div>

        <div style="margin-top: 20px; text-align: right;">
            <?php
                $basepath = $_ENV['APP_BASEPATH'] ?? '';
            ?>
            <a href="<?= htmlspecialchars($basepath) ?: '/' ?>" class="btn-home"><i class="fa-solid fa-house"></i> Retour à l'accueil</a>
        </div>
    </div>
</div>
</body>
</html>
