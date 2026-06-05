<?php

namespace App\Controllers;

use App\Attribute\RenderAttribute;
use App\Attribute\RouteAttribute;
use App\Core\TwigRenderer;
use App\Middleware\AuthMiddleware;
use App\Core\Logger;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

#[RenderAttribute(TwigRenderer::class)]
final class AdminController extends BaseController
{
    public function __construct(
        \App\Core\Router $router,
        \Psr\Log\LoggerInterface $logger,
        \App\Core\DB $database,
        private \App\Core\WebPushService $webPushService
    ) {
        parent::__construct($router, $logger, $database);
    }
    // =========================================================================
    // SECTION GESTION DES UTILISATEURS (Responsable Bénévole)
    // =========================================================================

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "GET", path: "/admin/users", name: "admin.users")]
    public function usersList(): Response
    {
        $pdo = $this->database->getConnection();
        $stmt = $pdo->query("SELECT * FROM users ORDER BY firstname ASC, name ASC");
        $usersData = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $users = [];
        foreach ($usersData as $u) {
            // Récupérer le dernier pointage (présent ou absent)
            $stmtPointage = $pdo->prepare("
                SELECT a.date, au.presence FROM appointments_users au 
                JOIN appointment a ON a.id = au.aid 
                WHERE au.uid = :uid AND au.presence != 'en_attente' 
                ORDER BY a.date DESC LIMIT 1
            ");
            $stmtPointage->execute(['uid' => $u['id']]);
            $lastPointage = $stmtPointage->fetch(\PDO::FETCH_ASSOC);

            $u['last_pointage'] = $lastPointage ?: null;

            // Récupérer les groupes de l'utilisateur
            $stmtUserGroups = $pdo->prepare("
                SELECT g.id, g.name FROM users_groups ug 
                JOIN `groups` g ON g.id = ug.gid 
                WHERE ug.uid = :uid
            ");
            $stmtUserGroups->execute(['uid' => $u['id']]);
            $u['groups'] = $stmtUserGroups->fetchAll(\PDO::FETCH_ASSOC);

            $users[] = $u;
        }

        // Récupérer tous les groupes
        $stmtAllGroups = $pdo->query("SELECT * FROM `groups` ORDER BY name ASC");
        $allGroups = $stmtAllGroups->fetchAll(\PDO::FETCH_ASSOC);

        $success = $_SESSION['admin_success'] ?? null;
        $error = $_SESSION['admin_error'] ?? null;
        unset($_SESSION['admin_success'], $_SESSION['admin_error']);

        return new Response(body: $this->render('admin/users', [
            'users' => $users,
            'allGroups' => $allGroups,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/users/create", name: "admin.users.create")]
    public function userCreate(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $name = trim($parsedBody['name'] ?? '');
        $firstname = trim($parsedBody['firstname'] ?? '');
        $username = trim($parsedBody['username'] ?? '');
        $password = $parsedBody['password'] ?? '';
        $roleInput = $parsedBody['role'] ?? 'user';
        $groups = $parsedBody['groups'] ?? [];
        
        if (!$name || !$firstname || !$username || !$password) {
            $_SESSION['admin_error'] = "Veuillez remplir tous les champs obligatoires (Nom, Prénom, Username, Mot de passe).";
            return $this->redirect('admin.users');
        }

        // Utiliser directement la chaîne de caractères
        $role = $roleInput;

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // Vérifier si le username existe déjà
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = :username");
            $stmtCheck->execute(['username' => $username]);
            if ($stmtCheck->fetch()) {
                $_SESSION['admin_error'] = "Le nom d'utilisateur '$username' est déjà utilisé.";
                $pdo->rollBack();
                return $this->redirect('admin.users');
            }

            $cguAccepted = isset($parsedBody['cgu_accepted']) ? 1 : 0;
            if (!$cguAccepted) {
                $_SESSION['admin_error'] = "Vous devez certifier que le bénévole a accepté les conditions générales d'utilisation (CGU) et la politique RGPD.";
                $pdo->rollBack();
                return $this->redirect('admin.users');
            }

            $passHash = password_hash($password, PASSWORD_DEFAULT);
            $activeInput = isset($parsedBody['active']) ? (int)$parsedBody['active'] : 1;

            $calendarToken = bin2hex(random_bytes(32));

            $stmt = $pdo->prepare("
                INSERT INTO users (name, firstname, username, password, role, active, calendar_token, lastModifiedPassword)
                VALUES (:name, :firstname, :username, :password, :role, :active, :calendar_token, NULL)
            ");
            $stmt->execute([
                'name' => $name,
                'firstname' => $firstname,
                'username' => $username,
                'password' => $passHash,
                'role' => $role,
                'active' => $activeInput,
                'calendar_token' => $calendarToken
            ]);
            $uid = $pdo->lastInsertId();

            // Lier les groupes
            $stmtGroup = $pdo->prepare("INSERT INTO users_groups (uid, gid) VALUES (:uid, :gid)");
            foreach ($groups as $gid) {
                $stmtGroup->execute(['uid' => $uid, 'gid' => (int)$gid]);
            }

            $pdo->commit();
            Logger::info("Création d'un utilisateur", ['admin_uid' => $_SESSION['user']['id'], 'new_uid' => $uid]);
            $_SESSION['admin_success'] = "L'utilisateur '$firstname $name' a bien été créé.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['admin_error'] = "Erreur lors de la création : " . $e->getMessage();
        }

        return $this->redirect('admin.users');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/users/import", name: "admin.users.import")]
    public function userImport(ServerRequestInterface $request): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['csv_file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['admin_error'] = "Erreur lors du téléchargement du fichier.";
            return $this->redirect('admin.users');
        }

        $filePath = $file->getStream()->getMetadata('uri');
        if (!is_uploaded_file($filePath) && !file_exists($filePath)) {
            $_SESSION['admin_error'] = "Fichier introuvable.";
            return $this->redirect('admin.users');
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $_SESSION['admin_error'] = "Impossible d'ouvrir le fichier CSV.";
            return $this->redirect('admin.users');
        }

        // Détecter le séparateur (, ou ;)
        $firstLine = fgets($handle);
        rewind($handle);
        $separator = (strpos($firstLine, ';') !== false) ? ';' : ',';

        // Lire les en-têtes
        $headers = fgetcsv($handle, 0, $separator);
        if ($headers === false) {
            fclose($handle);
            $_SESSION['admin_error'] = "Le fichier CSV est vide.";
            return $this->redirect('admin.users');
        }

        // Nettoyer les en-têtes (enlever les espaces, minuscules, BOM UTF-8)
        $headers = array_map(function($h) {
            $h = preg_replace('/[\x{00EF}\x{00BB}\x{00BF}]/u', '', $h); // Retirer BOM
            return strtolower(trim($h));
        }, $headers);

        // Mappage des en-têtes
        $map = [
            'firstname' => array_search('firstname', $headers) !== false ? array_search('firstname', $headers) : (array_search('prénom', $headers) !== false ? array_search('prénom', $headers) : array_search('prenom', $headers)),
            'name' => array_search('name', $headers) !== false ? array_search('name', $headers) : array_search('nom', $headers),
        ];

        // Remplacer false par null
        foreach ($map as $key => $val) {
            if ($val === false) {
                $map[$key] = null;
            }
        }

        // Vérifier si prénom et nom sont présents dans les en-têtes
        if ($map['firstname'] === null || $map['name'] === null) {
            fclose($handle);
            $_SESSION['admin_error'] = "En-têtes incorrects. Le fichier CSV doit contenir au moins des colonnes pour le Prénom (firstname) et le Nom (name).";
            return $this->redirect('admin.users');
        }

        $pdo = $this->database->getConnection();
        $importedCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;

        // Fonction helper pour assainir les chaînes (supprimer accents pour le username généré)
        $cleanString = function($str) {
            $accents = [
                'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'AE', 'Ç'=>'C',
                'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I',
                'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Œ'=>'OE',
                'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'à'=>'a', 'á'=>'a', 'â'=>'a',
                'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'ae', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e',
                'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o',
                'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'œ'=>'oe', 'ù'=>'u', 'ú'=>'u', 'û'=>'u',
                'ü'=>'u', 'row'=>'y', 'ÿ'=>'y'
            ];
            $str = strtr($str, $accents);
            $str = strtolower($str);
            return preg_replace('/[^a-z0-9]/', '', $str);
        };

        try {
            while (($row = fgetcsv($handle, 0, $separator)) !== false) {
                // Sauter les lignes vides
                if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                    continue;
                }

                $firstname = trim($row[$map['firstname']] ?? '');
                $name = trim($row[$map['name']] ?? '');

                if (empty($firstname) || empty($name)) {
                    $invalidCount++;
                    continue;
                }

                // Générer le username (première lettre du prénom + nom, sans caractères spéciaux ni accents ni espaces ni tirets)
                $firstLetter = mb_substr($firstname, 0, 1, 'UTF-8');
                $username = $cleanString($firstLetter) . $cleanString($name);

                // Mot de passe initial est égal au username
                $passHash = password_hash($username, PASSWORD_BCRYPT);
                $role = 'user';

                // Vérifier si le username ou le bénévole existe déjà (doublon)
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = :username OR (firstname = :firstname AND name = :name)");
                $stmtCheck->execute([
                    'username' => $username,
                    'firstname' => $firstname,
                    'name' => $name
                ]);

                if ($stmtCheck->fetch()) {
                    $duplicateCount++;
                    continue; // Déjà existant
                }

                // Insérer le nouvel utilisateur (lastModifiedPassword à NULL)
                $stmtInsert = $pdo->prepare("
                    INSERT INTO users (name, firstname, username, password, role, lastModifiedPassword) 
                    VALUES (:name, :firstname, :username, :password, :role, NULL)
                ");
                $stmtInsert->execute([
                    'name' => $name,
                    'firstname' => $firstname,
                    'username' => $username,
                    'password' => $passHash,
                    'role' => $role
                ]);

                $importedCount++;
            }
            fclose($handle);

            Logger::info("Importation CSV de bénévoles", ['admin_uid' => $_SESSION['user']['id'], 'imported' => $importedCount, 'duplicates' => $duplicateCount, 'invalid' => $invalidCount]);
            $_SESSION['admin_success'] = "Importation terminée : $importedCount bénévole(s) importé(s) avec succès. $duplicateCount doublon(s) ignoré(s). $invalidCount ligne(s) invalide(s).";
        } catch (\Exception $e) {
            fclose($handle);
            $_SESSION['admin_error'] = "Erreur lors de l'importation : " . $e->getMessage();
        }

        return $this->redirect('admin.users');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/users/edit", name: "admin.users.edit")]
    public function userEdit(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);
        $name = trim($parsedBody['name'] ?? '');
        $firstname = trim($parsedBody['firstname'] ?? '');
        $username = trim($parsedBody['username'] ?? '');
        $password = $parsedBody['password'] ?? '';
        $roleInput = $parsedBody['role'] ?? 'user';
        $groups = $parsedBody['groups'] ?? [];

        if (!$id || !$name || !$firstname || !$username) {
            $_SESSION['admin_error'] = "Veuillez remplir tous les champs obligatoires (Nom, Prénom, Username).";
            return $this->redirect('admin.users');
        }

        $activeInput = isset($parsedBody['active']) ? (int)$parsedBody['active'] : 0;
        $role = $roleInput;
        $pdo = $this->database->getConnection();

        try {
            $pdo->beginTransaction();

            // Vérifier si le username est déjà pris par un AUTRE utilisateur
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = :username AND id != :id");
            $stmtCheck->execute(['username' => $username, 'id' => $id]);
            if ($stmtCheck->fetch()) {
                $_SESSION['admin_error'] = "Le nom d'utilisateur '$username' est déjà utilisé par un autre compte.";
                $pdo->rollBack();
                return $this->redirect('admin.users');
            }

            if (!empty($password)) {
                // Mettre à jour avec mot de passe
                $passHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET name = :name, firstname = :firstname, username = :username, 
                        password = :password, role = :role, active = :active,
                        lastModifiedPassword = NULL
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'firstname' => $firstname,
                    'username' => $username,
                    'password' => $passHash,
                    'role' => $role,
                    'active' => $activeInput
                ]);
            } else {
                // Mettre à jour sans changer le mot de passe
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET name = :name, firstname = :firstname, username = :username, 
                        role = :role, active = :active
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                    'firstname' => $firstname,
                    'username' => $username,
                    'role' => $role,
                    'active' => $activeInput
                ]);
            }

            // Mettre à jour les groupes de l'utilisateur
            $stmtDelGroups = $pdo->prepare("DELETE FROM users_groups WHERE uid = :uid");
            $stmtDelGroups->execute(['uid' => $id]);

            $stmtAddGroup = $pdo->prepare("INSERT INTO users_groups (uid, gid) VALUES (:uid, :gid)");
            foreach ($groups as $gid) {
                $stmtAddGroup->execute(['uid' => $id, 'gid' => (int)$gid]);
            }

            // Si l'utilisateur modifié est l'utilisateur connecté, on met à jour ses infos en session
            if ($id === $_SESSION['user']['id']) {
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['firstname'] = $firstname;
                $_SESSION['user']['username'] = $username;
                $_SESSION['user']['role'] = $role;
            }

            $pdo->commit();
            Logger::info("Modification d'un utilisateur", ['admin_uid' => $_SESSION['user']['id'], 'target_uid' => $id]);
            $_SESSION['admin_success'] = "L'utilisateur a bien été mis à jour.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['admin_error'] = "Erreur lors de la modification : " . $e->getMessage();
        }

        return $this->redirect('admin.users');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/users/delete", name: "admin.users.delete")]
    public function userDelete(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);

        if (!$id) {
            $_SESSION['admin_error'] = "Utilisateur invalide.";
            return $this->redirect('admin.users');
        }

        if ($id === $_SESSION['user']['id']) {
            $_SESSION['admin_error'] = "Vous ne pouvez pas supprimer votre propre compte.";
            return $this->redirect('admin.users');
        }

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // 1. Supprimer ses inscriptions
            $stmtDelAssoc = $pdo->prepare("DELETE FROM appointments_users WHERE uid = :uid");
            $stmtDelAssoc->execute(['uid' => $id]);

            // 2. Supprimer l'utilisateur
            $stmtDelUser = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmtDelUser->execute(['id' => $id]);

            $pdo->commit();
            Logger::warning("Suppression d'un utilisateur", ['admin_uid' => $_SESSION['user']['id'], 'deleted_uid' => $id]);
            $_SESSION['admin_success'] = "L'utilisateur a bien été supprimé.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['admin_error'] = "Erreur lors de la suppression : " . $e->getMessage();
        }

        return $this->redirect('admin.users');
    }

    // =========================================================================
    // SECTION POINTAGE (Accueil du centre)
    // =========================================================================

    #[AuthMiddleware('accueil, responsable')]
    #[RouteAttribute(method: "GET", path: "/admin/pointage", name: "admin.pointage")]
    public function pointage(ServerRequestInterface $request): Response
    {
        $queryParams = $request->getQueryParams();
        $dateStr = $queryParams['date'] ?? date('Y-m-d');
        $dateObj = new \DateTime($dateStr);
        $dayOfWeek = (int) $dateObj->format('N');

        $pdo = $this->database->getConnection();

        // Proactivement initialiser les créneaux (appointment) programmés pour ce jour
        // 1. Récupérer les services classiques programmés pour ce jour de la semaine
        // SAUF s'ils sont fermés exceptionnellement (dans services_holiday)
        $stmtClassiques = $pdo->prepare("
            SELECT s.id as sid, sw.start_time, sw.end_time
            FROM services s
            JOIN services_workdays sw ON sw.sid = s.id
            WHERE sw.workday = :workday
              AND s.id NOT IN (
                  SELECT COALESCE(sid, s.id) FROM services_holiday WHERE :date BETWEEN start_date AND end_date
              )
              AND s.id NOT IN (
                  SELECT sid FROM services_opening WHERE date = :date
              )
        ");
        $stmtClassiques->execute(['workday' => $dayOfWeek, 'date' => $dateStr]);
        $servicesClassiques = $stmtClassiques->fetchAll(\PDO::FETCH_ASSOC);

        // 2. Récupérer les services exceptionnellement ouverts à cette date (ceux dans services_opening)
        $stmtExceptionnels = $pdo->prepare("
            SELECT s.id as sid, so.start_time, so.end_time
            FROM services s
            JOIN services_opening so ON so.sid = s.id
            WHERE so.date = :date
        ");
        $stmtExceptionnels->execute(['date' => $dateStr]);
        $servicesExceptionnels = $stmtExceptionnels->fetchAll(\PDO::FETCH_ASSOC);

        // Fusionner les créneaux planifiés
        $allActive = [];
        foreach ($servicesClassiques as $s) {
            $key = $s['sid'] . '_' . ($s['start_time'] ?? '') . '_' . ($s['end_time'] ?? '');
            $allActive[$key] = $s;
        }
        foreach ($servicesExceptionnels as $s) {
            $key = $s['sid'] . '_' . ($s['start_time'] ?? '') . '_' . ($s['end_time'] ?? '');
            $allActive[$key] = $s;
        }

        // Créer l'entrée dans la table `appointment` si elle n'existe pas
        foreach ($allActive as $s) {
            $stmtCheck = $pdo->prepare("
                SELECT id FROM appointment 
                WHERE sid = :sid AND date = :date 
                  AND ((start_time IS NULL AND :start_time IS NULL) OR start_time = :start_time)
                  AND ((end_time IS NULL AND :end_time IS NULL) OR end_time = :end_time)
            ");
            $stmtCheck->execute([
                'sid' => $s['sid'],
                'date' => $dateStr,
                'start_time' => $s['start_time'],
                'end_time' => $s['end_time']
            ]);
            if (!$stmtCheck->fetch()) {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO appointment (sid, date, start_time, end_time) 
                    VALUES (:sid, :date, :start_time, :end_time)
                ");
                $stmtInsert->execute([
                    'sid' => $s['sid'],
                    'date' => $dateStr,
                    'start_time' => $s['start_time'],
                    'end_time' => $s['end_time']
                ]);
            }
        }

        // Récupérer les créneaux pour cette date
        $stmt = $pdo->prepare("
            SELECT a.id as aid, s.name as service_name, 
                   IF(a.start_time IS NOT NULL AND a.end_time IS NOT NULL, CONCAT(SUBSTRING(a.start_time, 1, 5), ' - ', SUBSTRING(a.end_time, 1, 5)), NULL) as hours 
            FROM appointment a
            JOIN services s ON s.id = a.sid
            WHERE a.date = :date
            ORDER BY a.start_time ASC
        ");
        $stmt->execute(['date' => $dateStr]);
        $appointments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $creneaux = [];
        foreach ($appointments as $app) {
            $stmtInscrits = $pdo->prepare("
                SELECT u.id as uid, u.firstname, u.name, au.presence, au.pointed FROM users u
                JOIN appointments_users au ON au.uid = u.id
                WHERE au.aid = :aid
                ORDER BY u.firstname ASC, u.name ASC
            ");
            $stmtInscrits->execute(['aid' => $app['aid']]);
            $inscrits = $stmtInscrits->fetchAll(\PDO::FETCH_ASSOC);

            $creneaux[] = [
                'aid' => $app['aid'],
                'service_name' => $app['service_name'],
                'hours' => $app['hours'],
                'inscrits' => $inscrits
            ];
        }

        // Récupérer tous les utilisateurs (en excluant le rôle accueil) pour l'inscription à la volée
        $stmtUsers = $pdo->query("SELECT id, firstname, name, role FROM users ORDER BY name ASC, firstname ASC");
        $allUsers = [];
        foreach ($stmtUsers->fetchAll(\PDO::FETCH_ASSOC) as $u) {
            $role = $u['role'] ?? 'user';
            if ($role !== 'accueil') {
                $allUsers[] = [
                    'id' => $u['id'],
                    'firstname' => $u['firstname'],
                    'name' => $u['name']
                ];
            }
        }

        $dateSelectionneeFormatee = $this->formaterDateEnFrancais($dateObj);

        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
        if ($isAjax) {
            $html = $this->render('admin/_pointage_list', [
                'creneaux' => $creneaux,
                'dateSelectionnee' => $dateStr,
                'dateSelectionneeFormatee' => $dateSelectionneeFormatee
            ]);
            return new Response(200, ['Content-Type' => 'text/html'], $html);
        }

        $success = $_SESSION['pointage_success'] ?? null;
        $error = $_SESSION['pointage_error'] ?? null;
        unset($_SESSION['pointage_success'], $_SESSION['pointage_error']);

        return new Response(body: $this->render('admin/pointage', [
            'dateSelectionnee' => $dateStr,
            'dateSelectionneeFormatee' => $dateSelectionneeFormatee,
            'creneaux' => $creneaux,
            'allUsers' => $allUsers,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware('accueil, responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/pointage/register_volunteer", name: "admin.pointage.register")]
    public function registerVolunteer(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $aid = (int)($parsedBody['aid'] ?? 0);
        $uid = (int)($parsedBody['uid'] ?? 0);
        $dateStr = $parsedBody['date'] ?? date('Y-m-d');
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        if (!$aid || !$uid) {
            if ($isAjax) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => "Données d'inscription invalides."]));
            }
            $_SESSION['pointage_error'] = "Données d'inscription invalides.";
            return new Response(302, ['Location' => $this->generateUrl('admin.pointage') . '?date=' . urlencode($dateStr)]);
        }

        $pdo = $this->database->getConnection();
        try {
            // Vérifier si le bénévole est déjà inscrit
            $stmtCheck = $pdo->prepare("SELECT * FROM appointments_users WHERE aid = :aid AND uid = :uid");
            $stmtCheck->execute(['aid' => $aid, 'uid' => $uid]);
            if ($stmtCheck->fetch()) {
                if ($isAjax) {
                    return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => "Ce bénévole est déjà inscrit à ce créneau."]));
                }
                $_SESSION['pointage_error'] = "Ce bénévole est déjà inscrit à ce créneau.";
                return new Response(302, ['Location' => $this->generateUrl('admin.pointage') . '?date=' . urlencode($dateStr)]);
            }

            // Inscrire
            $stmtInsert = $pdo->prepare("INSERT INTO appointments_users (aid, uid, presence) VALUES (:aid, :uid, 'en_attente')");
            $stmtInsert->execute(['aid' => $aid, 'uid' => $uid]);
            
            if ($isAjax) {
                return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => "Bénévole ajouté avec succès à ce créneau."]));
            }
            $_SESSION['pointage_success'] = "Bénévole ajouté avec succès à ce créneau.";
        } catch (\Exception $e) {
            if ($isAjax) {
                return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => "Erreur lors de l'ajout du bénévole : " . $e->getMessage()]));
            }
            $_SESSION['pointage_error'] = "Erreur lors de l'ajout du bénévole : " . $e->getMessage();
        }

        return new Response(302, ['Location' => $this->generateUrl('admin.pointage') . '?date=' . urlencode($dateStr)]);
    }

    #[AuthMiddleware('accueil, responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/pointage/update", name: "admin.pointage.update")]
    public function pointageUpdate(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $aid = (int)($parsedBody['aid'] ?? 0);
        $uid = (int)($parsedBody['uid'] ?? 0);
        $presence = $parsedBody['presence'] ?? 'en_attente';
        $dateStr = $parsedBody['date'] ?? date('Y-m-d');
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        if (!$aid || !$uid || !in_array($presence, ['en_attente', 'present', 'absent'])) {
            if ($isAjax) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => "Données de pointage invalides."]));
            }
            $_SESSION['pointage_error'] = "Données de pointage invalides.";
            return new Response(302, ['Location' => $this->generateUrl('admin.pointage') . '?date=' . urlencode($dateStr)]);
        }

        try {
            $pointed = ($presence === 'en_attente') ? 0 : 1;
            $stmt = $this->database->getConnection()->prepare("
                UPDATE appointments_users 
                SET presence = :presence, pointed = :pointed 
                WHERE aid = :aid AND uid = :uid
            ");
            $stmt->execute([
                'presence' => $presence,
                'pointed' => $pointed,
                'aid' => $aid,
                'uid' => $uid
            ]);
            
            if ($pointed === 1) {
                try {
                    $pdo = $this->database->getConnection();
                    $stmtInfo = $pdo->prepare("
                        SELECT a.date, a.start_time, s.name as service_name 
                        FROM appointment a
                        JOIN services s ON s.id = a.sid
                        WHERE a.id = :aid
                    ");
                    $stmtInfo->execute(['aid' => $aid]);
                    $appInfo = $stmtInfo->fetch(\PDO::FETCH_ASSOC);

                    if ($appInfo) {
                        $presenceStr = ($presence === 'present') ? 'présent(e)' : 'absent(e)';
                        $dateFormatted = date('d/m/Y', strtotime($appInfo['date']));
                        $timeFormatted = date('H\hi', strtotime($appInfo['start_time']));
                        
                        $title = "Pointage mis à jour";
                        $body = "Vous avez été pointé(e) {$presenceStr} pour l'activité \"{$appInfo['service_name']}\" du {$dateFormatted} à {$timeFormatted}.";
                        
                        $this->webPushService->sendPushNotification($uid, $title, $body, '/');
                    }
                } catch (\Exception $pushEx) {
                    \App\Core\Logger::error("Erreur lors de la tentative d'envoi du push de pointage : " . $pushEx->getMessage());
                }
            }

            if ($isAjax) {
                return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => "Pointage mis à jour avec succès."]));
            }
            $_SESSION['pointage_success'] = "Pointage mis à jour avec succès.";
        } catch (\Exception $e) {
            if ($isAjax) {
                return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => "Erreur de pointage : " . $e->getMessage()]));
            }
            $_SESSION['pointage_error'] = "Erreur de pointage : " . $e->getMessage();
        }

        return new Response(302, ['Location' => $this->generateUrl('admin.pointage') . '?date=' . urlencode($dateStr)]);
    }

    // =========================================================================
    // SECTION GESTION DES ACTIVITES (Responsable Bénévole)
    // =========================================================================

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "GET", path: "/admin/activities", name: "admin.activities")]
    public function activitiesList(): Response
    {
        $pdo = $this->database->getConnection();
        
        // Récupérer toutes les activités (services)
        $stmt = $pdo->query("SELECT * FROM services ORDER BY name ASC");
        $services = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $activities = [];
        foreach ($services as $service) {
            // Récupérer les jours associés avec leurs horaires
            $stmtDays = $pdo->prepare("SELECT workday, start_time, end_time FROM services_workdays WHERE sid = :sid ORDER BY workday ASC");
            $stmtDays->execute(['sid' => $service['id']]);
            $workdaysData = $stmtDays->fetchAll(\PDO::FETCH_ASSOC);

            $workdays = [];
            foreach ($workdaysData as $wd) {
                $hoursStr = '';
                if ($wd['start_time'] !== null && $wd['end_time'] !== null) {
                    $hoursStr = substr($wd['start_time'], 0, 5) . ' - ' . substr($wd['end_time'], 0, 5);
                }
                $workdays[] = [
                    'day' => (int)$wd['workday'],
                    'hours' => $hoursStr
                ];
            }

            // Récupérer les groupes associés à l'activité
            $stmtGroups = $pdo->prepare("
                SELECT g.id, g.name FROM services_groups sg 
                JOIN `groups` g ON g.id = sg.gid 
                WHERE sg.sid = :sid
            ");
            $stmtGroups->execute(['sid' => $service['id']]);
            $activityGroups = $stmtGroups->fetchAll(\PDO::FETCH_ASSOC);

            $activities[] = [
                'id' => $service['id'],
                'name' => $service['name'],
                'description' => $service['description'],
                'optimal_count' => $service['optimal_count'] ?? null,
                'workdays' => $workdays,
                'groups' => $activityGroups
            ];
        }

        // Récupérer tous les groupes
        $stmtAllGroups = $pdo->query("SELECT * FROM `groups` ORDER BY name ASC");
        $allGroups = $stmtAllGroups->fetchAll(\PDO::FETCH_ASSOC);

        $success = $_SESSION['activity_success'] ?? null;
        $error = $_SESSION['activity_error'] ?? null;
        unset($_SESSION['activity_success'], $_SESSION['activity_error']);

        return new Response(body: $this->render('admin/activities', [
            'activities' => $activities,
            'allGroups' => $allGroups,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/activities/create", name: "admin.activities.create")]
    public function activityCreate(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $name = trim($parsedBody['name'] ?? '');
        $description = trim($parsedBody['description'] ?? '');
        $optimalCount = isset($parsedBody['optimal_count']) && $parsedBody['optimal_count'] !== '' ? (int)$parsedBody['optimal_count'] : null;
        $workdaysInput = $parsedBody['workdays'] ?? [];
        $startHoursInput = $parsedBody['start_hours'] ?? [];
        $endHoursInput = $parsedBody['end_hours'] ?? [];
        $groups = $parsedBody['groups'] ?? [];

        if (!$name) {
            $_SESSION['activity_error'] = "Le nom de l'activité est obligatoire.";
            return $this->redirect('admin.activities');
        }

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // 1. Vérifier si l'activité existe déjà
            $stmtCheck = $pdo->prepare("SELECT id FROM services WHERE name = :name");
            $stmtCheck->execute(['name' => $name]);
            if ($stmtCheck->fetch()) {
                $_SESSION['activity_error'] = "Une activité nommée '$name' existe déjà.";
                $pdo->rollBack();
                return $this->redirect('admin.activities');
            }

            // 2. Créer l'activité
            $stmtInsert = $pdo->prepare("
                INSERT INTO services (name, description, optimal_count) 
                VALUES (:name, :description, :optimal_count)
            ");
            $stmtInsert->execute([
                'name' => $name,
                'description' => empty($description) ? null : $description,
                'optimal_count' => $optimalCount
            ]);
            $sid = $pdo->lastInsertId();

            // 3. Associer les jours avec horaires
            $stmtDay = $pdo->prepare("
                INSERT INTO services_workdays (sid, workday, start_time, end_time) 
                VALUES (:sid, :workday, :start_time, :end_time)
            ");
            foreach ($workdaysInput as $index => $day) {
                $dayInt = (int)$day;
                $dayStart = trim($startHoursInput[$index] ?? '');
                $dayEnd = trim($endHoursInput[$index] ?? '');
                $stmtDay->execute([
                    'sid' => $sid,
                    'workday' => $dayInt,
                    'start_time' => empty($dayStart) ? null : $dayStart,
                    'end_time' => empty($dayEnd) ? null : $dayEnd
                ]);
            }

            // 4. Associer les groupes
            $stmtGroup = $pdo->prepare("INSERT INTO services_groups (sid, gid) VALUES (:sid, :gid)");
            foreach ($groups as $gid) {
                $stmtGroup->execute(['sid' => $sid, 'gid' => (int)$gid]);
            }

            $pdo->commit();
            Logger::info("Création d'une activité", ['admin_uid' => $_SESSION['user']['id'], 'service_id' => $sid]);
            $_SESSION['activity_success'] = "L'activité '$name' a bien été créée.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['activity_error'] = "Erreur lors de la création : " . $e->getMessage();
        }

        return $this->redirect('admin.activities');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/activities/edit", name: "admin.activities.edit")]
    public function activityEdit(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);
        $name = trim($parsedBody['name'] ?? '');
        $description = trim($parsedBody['description'] ?? '');
        $optimalCount = isset($parsedBody['optimal_count']) && $parsedBody['optimal_count'] !== '' ? (int)$parsedBody['optimal_count'] : null;
        $workdaysInput = $parsedBody['workdays'] ?? [];
        $startHoursInput = $parsedBody['start_hours'] ?? [];
        $endHoursInput = $parsedBody['end_hours'] ?? [];
        $groups = $parsedBody['groups'] ?? [];

        if (!$id || !$name) {
            $_SESSION['activity_error'] = "Données d'activité invalides.";
            return $this->redirect('admin.activities');
        }

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // 1. Vérifier si le nom existe pour une autre activité
            $stmtCheck = $pdo->prepare("SELECT id FROM services WHERE name = :name AND id != :id");
            $stmtCheck->execute(['name' => $name, 'id' => $id]);
            if ($stmtCheck->fetch()) {
                $_SESSION['activity_error'] = "Une autre activité porte déjà le nom '$name'.";
                $pdo->rollBack();
                return $this->redirect('admin.activities');
            }

            // 2. Mettre à jour l'activité
            $stmtUpdate = $pdo->prepare("
                UPDATE services 
                SET name = :name, description = :description, optimal_count = :optimal_count 
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                'name' => $name,
                'description' => empty($description) ? null : $description,
                'optimal_count' => $optimalCount,
                'id' => $id
            ]);

            // 3. Supprimer les anciennes associations de jours
            $stmtDelDays = $pdo->prepare("DELETE FROM services_workdays WHERE sid = :sid");
            $stmtDelDays->execute(['sid' => $id]);

            // 4. Insérer les nouvelles associations avec horaires
            $stmtDay = $pdo->prepare("
                INSERT INTO services_workdays (sid, workday, start_time, end_time) 
                VALUES (:sid, :workday, :start_time, :end_time)
            ");
            foreach ($workdaysInput as $index => $day) {
                $dayInt = (int)$day;
                $dayStart = trim($startHoursInput[$index] ?? '');
                $dayEnd = trim($endHoursInput[$index] ?? '');
                $stmtDay->execute([
                    'sid' => $id,
                    'workday' => $dayInt,
                    'start_time' => empty($dayStart) ? null : $dayStart,
                    'end_time' => empty($dayEnd) ? null : $dayEnd
                ]);
            }

            // 5. Mettre à jour les groupes
            $stmtDelGroups = $pdo->prepare("DELETE FROM services_groups WHERE sid = :sid");
            $stmtDelGroups->execute(['sid' => $id]);

            $stmtGroup = $pdo->prepare("INSERT INTO services_groups (sid, gid) VALUES (:sid, :gid)");
            foreach ($groups as $gid) {
                $stmtGroup->execute(['sid' => $id, 'gid' => (int)$gid]);
            }

            // 6. Mettre à jour les créneaux futurs (appoinment) correspondants aux nouveaux horaires de travail
            if (!defined('PHPUNIT_COMPOSER_INSTALL') && !defined('__PHPUNIT_PHAR__')) {
                $newSchedules = [];
                foreach ($workdaysInput as $index => $day) {
                    $dayInt = (int)$day;
                    $dayStart = trim($startHoursInput[$index] ?? '');
                    $dayEnd = trim($endHoursInput[$index] ?? '');
                    $newSchedules[$dayInt][] = [
                        'start_time' => empty($dayStart) ? null : $dayStart,
                        'end_time' => empty($dayEnd) ? null : $dayEnd
                    ];
                }

                // Trier les nouveaux horaires chronologiquement pour chaque jour
                foreach ($newSchedules as $dayInt => &$scheds) {
                    usort($scheds, function($a, $b) {
                        if ($a['start_time'] === null) return 1;
                        if ($b['start_time'] === null) return -1;
                        return strcmp($a['start_time'], $b['start_time']);
                    });
                }
                unset($scheds);

                $today = date('Y-m-d');
                $stmtApps = $pdo->prepare("
                    SELECT id, date, start_time, end_time 
                    FROM appointment 
                    WHERE sid = :sid AND date >= :today
                    ORDER BY date ASC, start_time ASC
                ");
                $stmtApps->execute(['sid' => $id, 'today' => $today]);
                $futureApps = $stmtApps->fetchAll(\PDO::FETCH_ASSOC);

                // Grouper les créneaux par date
                $appsByDate = [];
                foreach ($futureApps as $app) {
                    $appsByDate[$app['date']][] = $app;
                }

                $stmtUpdateApp = $pdo->prepare("
                    UPDATE appointment 
                    SET start_time = :start_time, end_time = :end_time 
                    WHERE id = :id
                ");

                foreach ($appsByDate as $appDate => $dateApps) {
                    $appDayOfWeek = (int) (new \DateTime($appDate))->format('N');
                    if (isset($newSchedules[$appDayOfWeek])) {
                        $scheds = $newSchedules[$appDayOfWeek];
                        foreach ($dateApps as $idx => $app) {
                            if (isset($scheds[$idx])) {
                                $stmtUpdateApp->execute([
                                    'start_time' => $scheds[$idx]['start_time'],
                                    'end_time' => $scheds[$idx]['end_time'],
                                    'id' => $app['id']
                                ]);
                            }
                        }
                    }
                }
            }

            $pdo->commit();
            Logger::info("Modification d'une activité", ['admin_uid' => $_SESSION['user']['id'], 'service_id' => $id]);
            $_SESSION['activity_success'] = "L'activité a bien été mise à jour.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['activity_error'] = "Erreur lors de la modification : " . $e->getMessage();
        }

        return $this->redirect('admin.activities');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/activities/delete", name: "admin.activities.delete")]
    public function activityDelete(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);

        if (!$id) {
            $_SESSION['activity_error'] = "Activité invalide.";
            return $this->redirect('admin.activities');
        }

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // 1. Trouver les créneaux/rendez-vous liés
            $stmtApps = $pdo->prepare("SELECT id FROM appointment WHERE sid = :sid");
            $stmtApps->execute(['sid' => $id]);
            $appointments = $stmtApps->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($appointments)) {
                // 2. Supprimer les inscriptions liées
                $placeholders = implode(',', array_fill(0, count($appointments), '?'));
                $stmtDelAssoc = $pdo->prepare("DELETE FROM appointments_users WHERE aid IN ($placeholders)");
                $stmtDelAssoc->execute($appointments);

                // 3. Supprimer les créneaux liés
                $stmtDelApps = $pdo->prepare("DELETE FROM appointment WHERE sid = :sid");
                $stmtDelApps->execute(['sid' => $id]);
            }

            // 4. Supprimer les jours associés
            $stmtDelDays = $pdo->prepare("DELETE FROM services_workdays WHERE sid = :sid");
            $stmtDelDays->execute(['sid' => $id]);

            // 5. Supprimer le service (activité)
            $stmtDelService = $pdo->prepare("DELETE FROM services WHERE id = :id");
            $stmtDelService->execute(['id' => $id]);

            $pdo->commit();
            Logger::warning("Suppression d'une activité", ['admin_uid' => $_SESSION['user']['id'], 'service_id' => $id]);
            $_SESSION['activity_success'] = "L'activité et toutes ses inscriptions associées ont bien été supprimées.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['activity_error'] = "Erreur lors de la suppression : " . $e->getMessage();
        }

        return $this->redirect('admin.activities');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "GET", path: "/admin/holidays", name: "admin.holidays")]
    public function holidaysList(): Response
    {
        $pdo = $this->database->getConnection();

        // Récupérer toutes les fermetures
        $stmt = $pdo->query("
            SELECT sh.*, s.name as service_name, (sh.end_date < CURRENT_DATE()) as is_past 
            FROM services_holiday sh
            LEFT JOIN services s ON s.id = sh.sid
            ORDER BY sh.start_date ASC, s.name ASC
        ");
        $holidays = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Récupérer toutes les activités
        $stmtServices = $pdo->query("SELECT id, name FROM services ORDER BY name ASC");
        $services = $stmtServices->fetchAll(\PDO::FETCH_ASSOC);

        $success = $_SESSION['holiday_success'] ?? null;
        $error = $_SESSION['holiday_error'] ?? null;
        unset($_SESSION['holiday_success'], $_SESSION['holiday_error']);

        return new Response(body: $this->render('admin/holidays', [
            'holidays' => $holidays,
            'services' => $services,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/holidays/create", name: "admin.holidays.create")]
    public function holidayCreate(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $sidInput = $parsedBody['sid'] ?? null;
        $startDate = $parsedBody['start_date'] ?? '';
        $endDate = $parsedBody['end_date'] ?? '';
        $name = trim($parsedBody['name'] ?? '');

        if ($sidInput === null || $sidInput === '' || !$startDate || !$endDate || !$name) {
            $_SESSION['holiday_error'] = "Veuillez remplir tous les champs obligatoires (Activité, Date début, Date fin, Motif).";
            return $this->redirect('admin.holidays');
        }

        // Si sidInput vaut '0' ou est égal à 0, c'est une fermeture globale (CENTRE) -> stocké en NULL
        $sid = ($sidInput === '0' || (int)$sidInput === 0) ? null : (int)$sidInput;

        if ($startDate > $endDate) {
            $_SESSION['holiday_error'] = "La date de début doit être antérieure ou égale à la date de fin.";
            return $this->redirect('admin.holidays');
        }

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // 1. Vérifier si une plage de fermeture chevauche déjà celle-ci pour cette activité (ou globalement)
            if ($sid === null) {
                // Fermeture globale du centre : on cherche si le centre est déjà fermé sur ces dates
                $stmtCheck = $pdo->prepare("
                    SELECT id FROM services_holiday 
                    WHERE sid IS NULL 
                      AND (start_date <= :end_date AND end_date >= :start_date)
                ");
                $stmtCheck->execute([
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            } else {
                // Fermeture d'un service : on cherche si le service ou le centre est fermé sur ces dates
                $stmtCheck = $pdo->prepare("
                    SELECT id FROM services_holiday 
                    WHERE (sid = :sid OR sid IS NULL) 
                      AND (start_date <= :end_date AND end_date >= :start_date)
                ");
                $stmtCheck->execute([
                    'sid' => $sid,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            }

            if ($stmtCheck->fetch()) {
                $_SESSION['holiday_error'] = "Une fermeture chevauchant ces dates est déjà configurée pour cette activité ou le Centre.";
                $pdo->rollBack();
                return $this->redirect('admin.holidays');
            }

            // 2. Insérer la plage de fermeture
            $stmtInsert = $pdo->prepare("
                INSERT INTO services_holiday (sid, start_date, end_date, name) 
                VALUES (:sid, :start_date, :end_date, :name)
            ");
            $stmtInsert->execute([
                'sid' => $sid,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'name' => $name
            ]);

            // 3. Supprimer en cascade les inscriptions existantes pour ce service dans cette plage
            if ($sid === null) {
                // Fermeture globale : on supprime les créneaux de tous les services dans cette plage
                $stmtApps = $pdo->prepare("
                    SELECT id FROM appointment 
                    WHERE date BETWEEN :start_date AND :end_date
                ");
                $stmtApps->execute([
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            } else {
                // Fermeture de service : on supprime uniquement pour ce service
                $stmtApps = $pdo->prepare("
                    SELECT id FROM appointment 
                    WHERE sid = :sid 
                      AND date BETWEEN :start_date AND :end_date
                ");
                $stmtApps->execute([
                    'sid' => $sid,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            }
            $appointments = $stmtApps->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($appointments)) {
                $aids = array_column($appointments, 'id');
                $placeholders = implode(',', array_fill(0, count($aids), '?'));
                
                // Supprimer les bénévoles inscrits
                $stmtDelInscrits = $pdo->prepare("DELETE FROM appointments_users WHERE aid IN ($placeholders)");
                $stmtDelInscrits->execute($aids);
                
                // Supprimer les créneaux
                $stmtDelApps = $pdo->prepare("DELETE FROM appointment WHERE id IN ($placeholders)");
                $stmtDelApps->execute($aids);
            }

            $pdo->commit();
            Logger::info("Création d'une fermeture planifiée", ['admin_uid' => $_SESSION['user']['id']]);
            $_SESSION['holiday_success'] = "La plage de fermeture a bien été enregistrée. Toutes les inscriptions existantes pour ce créneau ont été annulées.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['holiday_error'] = "Erreur lors de la configuration de la fermeture : " . $e->getMessage();
        }

        return $this->redirect('admin.holidays');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/holidays/edit", name: "admin.holidays.edit")]
    public function holidayEdit(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);
        $sidInput = $parsedBody['sid'] ?? null;
        $startDate = $parsedBody['start_date'] ?? '';
        $endDate = $parsedBody['end_date'] ?? '';
        $name = trim($parsedBody['name'] ?? '');

        if (!$id || $sidInput === null || $sidInput === '' || !$startDate || !$endDate || !$name) {
            $_SESSION['holiday_error'] = "Veuillez remplir tous les champs obligatoires pour la modification.";
            return $this->redirect('admin.holidays');
        }

        $sid = ($sidInput === '0' || (int)$sidInput === 0) ? null : (int)$sidInput;

        if ($startDate > $endDate) {
            $_SESSION['holiday_error'] = "La date de début doit être antérieure ou égale à la date de fin.";
            return $this->redirect('admin.holidays');
        }

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // 1. Vérifier si une autre fermeture chevauche déjà celle-ci (en excluant l'ID courant)
            if ($sid === null) {
                $stmtCheck = $pdo->prepare("
                    SELECT id FROM services_holiday 
                    WHERE sid IS NULL AND id != :id
                      AND (start_date <= :end_date AND end_date >= :start_date)
                ");
                $stmtCheck->execute([
                    'id' => $id,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            } else {
                $stmtCheck = $pdo->prepare("
                    SELECT id FROM services_holiday 
                    WHERE (sid = :sid OR sid IS NULL) AND id != :id
                      AND (start_date <= :end_date AND end_date >= :start_date)
                ");
                $stmtCheck->execute([
                    'sid' => $sid,
                    'id' => $id,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            }

            if ($stmtCheck->fetch()) {
                $_SESSION['holiday_error'] = "Une autre fermeture chevauchant ces dates est déjà configurée pour cette activité ou le Centre.";
                $pdo->rollBack();
                return $this->redirect('admin.holidays');
            }

            // 2. Mettre à jour la fermeture
            $stmtUpdate = $pdo->prepare("
                UPDATE services_holiday 
                SET sid = :sid, start_date = :start_date, end_date = :end_date, name = :name 
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                'sid' => $sid,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'name' => $name,
                'id' => $id
            ]);

            // 3. Supprimer en cascade les inscriptions existantes tombant dans la nouvelle plage
            if ($sid === null) {
                $stmtApps = $pdo->prepare("
                    SELECT id FROM appointment 
                    WHERE date BETWEEN :start_date AND :end_date
                ");
                $stmtApps->execute([
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            } else {
                $stmtApps = $pdo->prepare("
                    SELECT id FROM appointment 
                    WHERE sid = :sid 
                      AND date BETWEEN :start_date AND :end_date
                ");
                $stmtApps->execute([
                    'sid' => $sid,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            }
            $appointments = $stmtApps->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($appointments)) {
                $aids = array_column($appointments, 'id');
                $placeholders = implode(',', array_fill(0, count($aids), '?'));
                
                // Supprimer les bénévoles inscrits
                $stmtDelInscrits = $pdo->prepare("DELETE FROM appointments_users WHERE aid IN ($placeholders)");
                $stmtDelInscrits->execute($aids);
                
                // Supprimer les créneaux
                $stmtDelApps = $pdo->prepare("DELETE FROM appointment WHERE id IN ($placeholders)");
                $stmtDelApps->execute($aids);
            }

            $pdo->commit();
            Logger::info("Modification d'une fermeture planifiée", ['admin_uid' => $_SESSION['user']['id']]);
            $_SESSION['holiday_success'] = "La fermeture a bien été mise à jour. Les inscriptions pour la période modifiée ont été actualisées.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['holiday_error'] = "Erreur lors de la modification de la fermeture : " . $e->getMessage();
        }

        return $this->redirect('admin.holidays');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/holidays/delete", name: "admin.holidays.delete")]
    public function holidayDelete(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);

        if (!$id) {
            $_SESSION['holiday_error'] = "Identifiant de fermeture invalide.";
            return $this->redirect('admin.holidays');
        }

        try {
            $stmt = $this->database->getConnection()->prepare("
                DELETE FROM services_holiday WHERE id = :id
            ");
            $stmt->execute(['id' => $id]);
            Logger::warning("Suppression d'une fermeture planifiée", ['admin_uid' => $_SESSION['user']['id']]);
            $_SESSION['holiday_success'] = "La fermeture a bien été supprimée. Le créneau a été réouvert.";
        } catch (\Exception $e) {
            $_SESSION['holiday_error'] = "Erreur lors de la réouverture : " . $e->getMessage();
        }

        return $this->redirect('admin.holidays');
    }

    // SECTION GESTION DES OUVERTURES EXCEPTIONNELLES (Responsable Bénévole)
    // =========================================================================

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "GET", path: "/admin/openings", name: "admin.openings")]
    public function openingsList(): Response
    {
        $pdo = $this->database->getConnection();

        $stmt = $pdo->query("
            SELECT so.*, 
                   IF(so.start_time IS NOT NULL AND so.end_time IS NOT NULL, CONCAT(SUBSTRING(so.start_time, 1, 5), ' - ', SUBSTRING(so.end_time, 1, 5)), NULL) as hours,
                   s.name as service_name, (so.date < CURRENT_DATE()) as is_past 
            FROM services_opening so
            JOIN services s ON s.id = so.sid
            ORDER BY so.date ASC, s.name ASC
        ");
        $openingsData = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $openings = [];
        foreach ($openingsData as $op) {
            $stmtOpGroups = $pdo->prepare("
                SELECT g.id, g.name 
                FROM services_opening_groups sog
                JOIN `groups` g ON g.id = sog.gid
                WHERE sog.soid = :soid
                ORDER BY g.name ASC
            ");
            $stmtOpGroups->execute(['soid' => $op['id']]);
            $op['groups'] = $stmtOpGroups->fetchAll(\PDO::FETCH_ASSOC);
            $openings[] = $op;
        }

        // Récupérer toutes les activités
        $stmtServices = $pdo->query("SELECT id, name FROM services ORDER BY name ASC");
        $services = $stmtServices->fetchAll(\PDO::FETCH_ASSOC);

        // Récupérer tous les groupes
        $stmtAllGroups = $pdo->query("SELECT id, name FROM `groups` ORDER BY name ASC");
        $allGroups = $stmtAllGroups->fetchAll(\PDO::FETCH_ASSOC);

        $success = $_SESSION['opening_success'] ?? null;
        $error = $_SESSION['opening_error'] ?? null;
        unset($_SESSION['opening_success'], $_SESSION['opening_error']);

        return new Response(body: $this->render('admin/openings', [
            'openings' => $openings,
            'services' => $services,
            'allGroups' => $allGroups,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/openings/create", name: "admin.openings.create")]
    public function openingCreate(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $sid = (int)($parsedBody['sid'] ?? 0);
        $date = $parsedBody['date'] ?? '';
        $startTime = trim($parsedBody['start_time'] ?? '');
        $endTime = trim($parsedBody['end_time'] ?? '');
        $hours = (!empty($startTime) && !empty($endTime)) ? "$startTime - $endTime" : null;
        $description = trim($parsedBody['description'] ?? '');

        if (!$sid || !$date) {
            $_SESSION['opening_error'] = "Veuillez remplir tous les champs obligatoires (Activité, Date).";
            return $this->redirect('admin.openings');
        }

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // 1. Récupérer les ouvertures existantes pour ce service à cette date
            $stmtCheck = $pdo->prepare("SELECT id, start_time, end_time FROM services_opening WHERE sid = :sid AND date = :date");
            $stmtCheck->execute(['sid' => $sid, 'date' => $date]);
            $existingOpenings = $stmtCheck->fetchAll(\PDO::FETCH_ASSOC);

            $hasTime = (!empty($startTime) && !empty($endTime));

            foreach ($existingOpenings as $exist) {
                // Si la nouvelle ou l'existante n'a pas d'horaires spécifiés, conflit car cela couvre les horaires par défaut
                if (!$hasTime || $exist['start_time'] === null || $exist['end_time'] === null) {
                    $_SESSION['opening_error'] = "Conflit : Une ouverture sans horaires spécifiques existe déjà pour cette activité ce jour-là.";
                    $pdo->rollBack();
                    return $this->redirect('admin.openings');
                }

                $newStart = $startTime;
                $newEnd = $endTime;
                $existStart = substr($exist['start_time'], 0, 5);
                $existEnd = substr($exist['end_time'], 0, 5);

                // Vérifier s'il y a chevauchement
                if ($newStart < $existEnd && $existStart < $newEnd) {
                    $_SESSION['opening_error'] = "Conflit d'horaires : Cette période chevauche une ouverture existante ($existStart - $existEnd) pour cette activité.";
                    $pdo->rollBack();
                    return $this->redirect('admin.openings');
                }
            }

            // Si un créneau (appoinment) chevauchant existe déjà pour cette activité à cette date, 
            // on le met à jour avec les nouveaux horaires exceptionnels de l'ouverture
            if (!defined('PHPUNIT_COMPOSER_INSTALL') && !defined('__PHPUNIT_PHAR__')) {
                $stmtFindApp = $pdo->prepare("
                    SELECT id FROM appointment 
                    WHERE sid = :sid AND date = :date
                      AND (
                        (start_time IS NULL OR end_time IS NULL)
                        OR (:start_time IS NULL OR :end_time IS NULL)
                        OR (start_time < :end_time_chk AND :start_time_chk < end_time)
                      )
                    LIMIT 1
                ");
                $stmtFindApp->execute([
                    'sid' => $sid,
                    'date' => $date,
                    'start_time' => $hasTime ? $startTime : null,
                    'end_time' => $hasTime ? $endTime : null,
                    'start_time_chk' => $hasTime ? $startTime : null,
                    'end_time_chk' => $hasTime ? $endTime : null
                ]);
                $appRow = $stmtFindApp->fetch(\PDO::FETCH_ASSOC);

                if ($appRow) {
                    $stmtUpdateApp = $pdo->prepare("
                        UPDATE appointment 
                        SET start_time = :start_time, end_time = :end_time 
                        WHERE id = :id
                    ");
                    $stmtUpdateApp->execute([
                        'start_time' => $hasTime ? $startTime : null,
                        'end_time' => $hasTime ? $endTime : null,
                        'id' => $appRow['id']
                    ]);
                }
            }

            // 2. Insérer le jour d'ouverture
            $stmtInsert = $pdo->prepare("
                INSERT INTO services_opening (sid, date, start_time, end_time, description) 
                VALUES (:sid, :date, :start_time, :end_time, :description)
            ");
            $stmtInsert->execute([
                'sid' => $sid,
                'date' => $date,
                'start_time' => $hasTime ? $startTime : null,
                'end_time' => $hasTime ? $endTime : null,
                'description' => empty($description) ? null : $description
            ]);

            $soid = $pdo->lastInsertId();

            // 3. Enregistrer les groupes associés
            $groups = $parsedBody['groups'] ?? [];
            if (!is_array($groups)) {
                $groups = [];
            }
            $stmtGroup = $pdo->prepare("INSERT INTO services_opening_groups (soid, gid) VALUES (:soid, :gid)");
            foreach ($groups as $gid) {
                $stmtGroup->execute(['soid' => $soid, 'gid' => (int)$gid]);
            }

            $pdo->commit();
            Logger::info("Création d'une ouverture exceptionnelle", ['admin_uid' => $_SESSION['user']['id'], 'opening_id' => $soid, 'service_id' => $sid, 'date' => $date]);
            $_SESSION['opening_success'] = "L'ouverture exceptionnelle a bien été enregistrée.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['opening_error'] = "Erreur lors de la configuration de l'ouverture : " . $e->getMessage();
        }

        return $this->redirect('admin.openings');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/openings/edit", name: "admin.openings.edit")]
    public function openingEdit(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);
        $sid = (int)($parsedBody['sid'] ?? 0);
        $date = $parsedBody['date'] ?? '';
        $startTime = trim($parsedBody['start_time'] ?? '');
        $endTime = trim($parsedBody['end_time'] ?? '');
        $hours = (!empty($startTime) && !empty($endTime)) ? "$startTime - $endTime" : null;
        $description = trim($parsedBody['description'] ?? '');

        if (!$id || !$sid || !$date) {
            $_SESSION['opening_error'] = "Données de modification invalides.";
            return $this->redirect('admin.openings');
        }

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // Récupérer l'ouverture originale pour la mise à jour correspondante des créneaux
            $original = null;
            if (!defined('PHPUNIT_COMPOSER_INSTALL') && !defined('__PHPUNIT_PHAR__')) {
                $stmtOriginal = $pdo->prepare("SELECT sid, date, start_time, end_time FROM services_opening WHERE id = :id");
                $stmtOriginal->execute(['id' => $id]);
                $original = $stmtOriginal->fetch(\PDO::FETCH_ASSOC);
            }

            // 1. Récupérer les ouvertures existantes pour ce service à cette date, en excluant l'enregistrement en cours
            $stmtCheck = $pdo->prepare("SELECT id, start_time, end_time FROM services_opening WHERE sid = :sid AND date = :date AND id != :id");
            $stmtCheck->execute(['sid' => $sid, 'date' => $date, 'id' => $id]);
            $existingOpenings = $stmtCheck->fetchAll(\PDO::FETCH_ASSOC);

            $hasTime = (!empty($startTime) && !empty($endTime));

            foreach ($existingOpenings as $exist) {
                // Si la nouvelle ou l'existante n'a pas d'horaires spécifiés, conflit car cela couvre les horaires par défaut
                if (!$hasTime || $exist['start_time'] === null || $exist['end_time'] === null) {
                    $_SESSION['opening_error'] = "Conflit : Une ouverture sans horaires spécifiques existe déjà pour cette activité ce jour-là.";
                    $pdo->rollBack();
                    return $this->redirect('admin.openings');
                }

                $newStart = $startTime;
                $newEnd = $endTime;
                $existStart = substr($exist['start_time'], 0, 5);
                $existEnd = substr($exist['end_time'], 0, 5);

                // Vérifier s'il y a chevauchement
                if ($newStart < $existEnd && $existStart < $newEnd) {
                    $_SESSION['opening_error'] = "Conflit d'horaires : Cette période chevauche une ouverture existante ($existStart - $existEnd) pour cette activité.";
                    $pdo->rollBack();
                    return $this->redirect('admin.openings');
                }
            }

            // Si un créneau (appoinment) existe déjà pour cette activité à cette date, 
            // on le met à jour avec les nouveaux horaires exceptionnels de l'ouverture
            if (!defined('PHPUNIT_COMPOSER_INSTALL') && !defined('__PHPUNIT_PHAR__')) {
                $stmtUpdateApp = $pdo->prepare("
                    UPDATE appointment 
                    SET start_time = :start_time, end_time = :end_time 
                    WHERE sid = :sid AND date = :date
                ");
                $stmtUpdateApp->execute([
                    'start_time' => $hasTime ? $startTime : null,
                    'end_time' => $hasTime ? $endTime : null,
                    'sid' => $sid,
                    'date' => $date
                ]);
            }

            // 2. Mettre à jour l'ouverture
            $stmtUpdate = $pdo->prepare("
                UPDATE services_opening 
                SET sid = :sid, date = :date, start_time = :start_time, end_time = :end_time, description = :description 
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                'sid' => $sid,
                'date' => $date,
                'start_time' => $hasTime ? $startTime : null,
                'end_time' => $hasTime ? $endTime : null,
                'description' => empty($description) ? null : $description,
                'id' => $id
            ]);

            // 3. Mettre à jour les groupes
            $stmtDelGroups = $pdo->prepare("DELETE FROM services_opening_groups WHERE soid = :soid");
            $stmtDelGroups->execute(['soid' => $id]);

            $groups = $parsedBody['groups'] ?? [];
            if (!is_array($groups)) {
                $groups = [];
            }
            $stmtGroup = $pdo->prepare("INSERT INTO services_opening_groups (soid, gid) VALUES (:soid, :gid)");
            foreach ($groups as $gid) {
                $stmtGroup->execute(['soid' => $id, 'gid' => (int)$gid]);
            }

            // 4. Mettre à jour le créneau futur correspondant (appoinment) s'il existe
            if ($original && $original['date'] >= date('Y-m-d')) {
                $stmtFindApp = $pdo->prepare("
                    SELECT id FROM appointment 
                    WHERE sid = :sid AND date = :date 
                      AND ((start_time IS NULL AND :orig_start IS NULL) OR start_time = :orig_start)
                      AND ((end_time IS NULL AND :orig_end IS NULL) OR end_time = :orig_end)
                ");
                $stmtFindApp->execute([
                    'sid' => $original['sid'],
                    'date' => $original['date'],
                    'orig_start' => $original['start_time'],
                    'orig_end' => $original['end_time']
                ]);
                $appRow = $stmtFindApp->fetch(\PDO::FETCH_ASSOC);
                
                if ($appRow) {
                    $stmtUpdateApp = $pdo->prepare("
                        UPDATE appointment 
                        SET sid = :new_sid, date = :new_date, start_time = :new_start, end_time = :new_end 
                        WHERE id = :id
                    ");
                    $stmtUpdateApp->execute([
                        'new_sid' => $sid,
                        'new_date' => $date,
                        'new_start' => $hasTime ? $startTime : null,
                        'new_end' => $hasTime ? $endTime : null,
                        'id' => $appRow['id']
                    ]);
                }
            }

            $pdo->commit();
            Logger::info("Modification d'une ouverture exceptionnelle", ['admin_uid' => $_SESSION['user']['id'], 'opening_id' => $id]);
            $_SESSION['opening_success'] = "L'ouverture exceptionnelle a bien été modifiée.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['opening_error'] = "Erreur lors de la modification : " . $e->getMessage();
        }

        return $this->redirect('admin.openings');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/openings/delete", name: "admin.openings.delete")]
    public function openingDelete(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);

        if (!$id) {
            $_SESSION['opening_error'] = "Données d'annulation d'ouverture invalides.";
            return $this->redirect('admin.openings');
        }

        $pdo = $this->database->getConnection();
        try {
            $pdo->beginTransaction();

            // 1. Récupérer l'ouverture exceptionnelle pour connaître sid, date et start_time/end_time avant de la supprimer
            $stmtFind = $pdo->prepare("SELECT sid, date, start_time, end_time FROM services_opening WHERE id = :id");
            $stmtFind->execute(['id' => $id]);
            $op = $stmtFind->fetch(\PDO::FETCH_ASSOC);

            if ($op) {
                $hoursStr = null;
                if ($op['start_time'] !== null && $op['end_time'] !== null) {
                    $hoursStr = substr($op['start_time'], 0, 5) . ' - ' . substr($op['end_time'], 0, 5);
                }
                
                // 2. Supprimer le appointment correspondant
                $stmtDelApp = $pdo->prepare("
                    DELETE FROM appointment 
                    WHERE sid = :sid AND date = :date
                      AND ((:start_time IS NULL AND start_time IS NULL) OR start_time = :start_time)
                      AND ((:end_time IS NULL AND end_time IS NULL) OR end_time = :end_time)
                ");
                $stmtDelApp->execute([
                    'sid'        => $op['sid'],
                    'date'       => $op['date'],
                    'start_time' => $op['start_time'] !== null ? substr($op['start_time'], 0, 5) : null,
                    'end_time'   => $op['end_time']   !== null ? substr($op['end_time'], 0, 5)   : null,
                ]);

                // 3. Supprimer l'ouverture exceptionnelle
                $stmtDelOp = $pdo->prepare("DELETE FROM services_opening WHERE id = :id");
                $stmtDelOp->execute(['id' => $id]);
            }

            $pdo->commit();
            Logger::warning("Suppression d'une ouverture exceptionnelle", ['admin_uid' => $_SESSION['user']['id'], 'opening_id' => $id]);
            $_SESSION['opening_success'] = "L'ouverture exceptionnelle a bien été supprimée.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['opening_error'] = "Erreur lors de la suppression de l'ouverture exceptionnelle : " . $e->getMessage();
        }

        return $this->redirect('admin.openings');
    }

    #[AuthMiddleware('user, responsable, admin')]
    #[RouteAttribute(method: "GET", path: "/admin/registrations", name: "admin.registrations")]
    public function registrationsList(ServerRequestInterface $request): Response
    {
        $queryParams = $request->getQueryParams();
        $selectedUid = $queryParams['uid'] ?? 'all';
        $dateDebut = $queryParams['date_debut'] ?? '';
        $dateFin = $queryParams['date_fin'] ?? '';
        $selectedSid = $queryParams['sid'] ?? 'all';
        $selectedStatus = $queryParams['status'] ?? 'all';

        $currentUser = $_SESSION['user'] ?? [];
        $currentUserRole = $currentUser['role'] ?? 'user';

        // Si l'utilisateur est un simple bénévole, on le force à ne voir que ses propres infos
        $isSimpleUser = !in_array($currentUserRole, ['admin', 'responsable']);
        if ($isSimpleUser) {
            $selectedUid = (string)$currentUser['id'];
        }

        $pdo = $this->database->getConnection();

        // 1. Liste de tous les utilisateurs pour le filtre (uniquement si responsable/admin)
        $users = [];
        if (!$isSimpleUser) {
            $stmtUsers = $pdo->query("SELECT id, name, firstname FROM users ORDER BY name ASC, firstname ASC");
            $users = $stmtUsers->fetchAll(\PDO::FETCH_ASSOC);
        }

        // 2. Construire la requête avec filtres
        $conditions = [];
        $params = [];

        if ($selectedUid !== 'all' && !empty($selectedUid)) {
            $conditions[] = "au.uid = :uid";
            $params['uid'] = (int)$selectedUid;
        }

        if (!empty($dateDebut)) {
            $conditions[] = "a.date >= :date_debut";
            $params['date_debut'] = $dateDebut;
        }

        if (!empty($dateFin)) {
            $conditions[] = "a.date <= :date_fin";
            $params['date_fin'] = $dateFin;
        }
        
        if ($selectedSid !== 'all' && !empty($selectedSid)) {
            $conditions[] = "a.sid = :sid";
            $params['sid'] = (int)$selectedSid;
        }
        
        if ($selectedStatus !== 'all' && !empty($selectedStatus)) {
            $conditions[] = "au.presence = :status";
            $params['status'] = $selectedStatus;
        }

        // 1.5 Liste de toutes les activités pour le filtre
        $stmtServices = $pdo->query("SELECT id, name FROM services ORDER BY name ASC");
        $services = $stmtServices->fetchAll(\PDO::FETCH_ASSOC);

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $sql = "
            SELECT a.date, s.name as service_name, u.name as user_name, u.firstname as user_firstname, au.presence, au.pointed 
            FROM appointments_users au 
            JOIN appointment a ON a.id = au.aid 
            JOIN services s ON s.id = a.sid 
            JOIN users u ON u.id = au.uid 
            $whereClause
            ORDER BY a.date DESC, a.start_time DESC, u.name ASC, u.firstname ASC
        ";

        $stmtRegs = $pdo->prepare($sql);
        $stmtRegs->execute($params);
        $registrations = $stmtRegs->fetchAll(\PDO::FETCH_ASSOC);

        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
        if ($isAjax) {
            $html = $this->render('admin/_registrations_list', [
                'registrations' => $registrations,
                'isSimpleUser' => $isSimpleUser,
                'selectedUid' => $selectedUid,
                'selectedSid' => $selectedSid,
                'selectedStatus' => $selectedStatus
            ]);
            return new Response(200, ['Content-Type' => 'text/html'], $html);
        }

        return new Response(body: $this->render('admin/registrations', [
            'users' => $users,
            'services' => $services,
            'selectedUid' => $selectedUid,
            'selectedSid' => $selectedSid,
            'selectedStatus' => $selectedStatus,
            'dateDebut' => $dateDebut,
            'dateFin' => $dateFin,
            'registrations' => $registrations,
            'isSimpleUser' => $isSimpleUser
        ]));
    }

    #[AuthMiddleware('user, responsable, admin')]
    #[RouteAttribute(method: "GET", path: "/admin/registrations/export", name: "admin.registrations.export")]
    public function registrationsExport(ServerRequestInterface $request): Response
    {
        $queryParams = $request->getQueryParams();
        $selectedUid = $queryParams['uid'] ?? 'all';
        $dateDebut = $queryParams['date_debut'] ?? '';
        $dateFin = $queryParams['date_fin'] ?? '';
        $selectedSid = $queryParams['sid'] ?? 'all';
        $selectedStatus = $queryParams['status'] ?? 'all';

        $currentUser = $_SESSION['user'] ?? [];
        $currentUserRole = $currentUser['role'] ?? 'user';

        // Si l'utilisateur est un simple bénévole, on le force à ne voir que ses propres infos
        $isSimpleUser = !in_array($currentUserRole, ['admin', 'responsable']);
        if ($isSimpleUser) {
            $selectedUid = (string)$currentUser['id'];
        }

        $pdo = $this->database->getConnection();
        $filename = "historique_inscriptions_tous";

        if ($selectedUid !== 'all' && !empty($selectedUid)) {
            $stmtUser = $pdo->prepare("SELECT name, firstname FROM users WHERE id = :uid");
            $stmtUser->execute(['uid' => (int)$selectedUid]);
            $user = $stmtUser->fetch(\PDO::FETCH_ASSOC);
            if ($user) {
                $filename = "historique_inscriptions_" . strtolower($user['name']) . "_" . strtolower($user['firstname']);
            }
        }

        // Récupérer les inscriptions filtrées
        $conditions = [];
        $params = [];

        if ($selectedUid !== 'all' && !empty($selectedUid)) {
            $conditions[] = "au.uid = :uid";
            $params['uid'] = (int)$selectedUid;
        }

        if (!empty($dateDebut)) {
            $conditions[] = "a.date >= :date_debut";
            $params['date_debut'] = $dateDebut;
        }

        if (!empty($dateFin)) {
            $conditions[] = "a.date <= :date_fin";
            $params['date_fin'] = $dateFin;
        }

        if ($selectedSid !== 'all' && !empty($selectedSid)) {
            $conditions[] = "a.sid = :sid";
            $params['sid'] = (int)$selectedSid;
        }

        if ($selectedStatus !== 'all' && !empty($selectedStatus)) {
            $conditions[] = "au.presence = :status";
            $params['status'] = $selectedStatus;
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $sql = "
            SELECT a.date, s.name as service_name, u.name as user_name, u.firstname as user_firstname, au.presence, au.pointed 
            FROM appointments_users au 
            JOIN appointment a ON a.id = au.aid 
            JOIN services s ON s.id = a.sid 
            JOIN users u ON u.id = au.uid 
            $whereClause
            ORDER BY a.date DESC, u.name ASC, u.firstname ASC
        ";

        $stmtRegs = $pdo->prepare($sql);
        $stmtRegs->execute($params);
        $registrations = $stmtRegs->fetchAll(\PDO::FETCH_ASSOC);

        // Ouvrir le flux mémoire pour le CSV
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            return new Response(500, ['Content-Type' => 'text/plain'], "Impossible d'initier l'export.");
        }

        // Ajouter le BOM UTF-8
        fwrite($output, "\xEF\xBB\xBF");

        // Écrire les en-têtes
        fputcsv($output, ['Date', 'Bénévole', 'Activité', 'Présence'], ';');

        foreach ($registrations as $reg) {
            $presenceLabel = match ($reg['presence']) {
                'present' => 'Présent',
                'absent' => ((int)$reg['pointed'] === 1 ? 'Pointé absent' : 'Déclaré absent'),
                default => 'En attente',
            };
            fputcsv($output, [
                $reg['date'],
                $reg['user_name'] . ' ' . $reg['user_firstname'],
                $reg['service_name'],
                $presenceLabel
            ], ';');
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ], $csvContent);
    }

    private function formaterDateEnFrancais(\DateTime $date): string
    {
        $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
        $mois = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        
        $w = (int) $date->format('w');
        $n = (int) $date->format('n');
        $j = $date->format('j');
        $y = $date->format('Y');
        
        return $jours[$w] . ' ' . $j . ' ' . $mois[$n] . ' ' . $y;
    }

    // =========================================================================
    // SECTION GESTION DES GROUPES (Responsable Bénévole)
    // =========================================================================

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "GET", path: "/admin/groups", name: "admin.groups")]
    public function groupsList(): Response
    {
        $pdo = $this->database->getConnection();

        // Récupérer les groupes avec le nombre d'utilisateurs et de services associés
        $stmt = $pdo->query("
            SELECT g.*, 
                   (SELECT COUNT(*) FROM users_groups ug WHERE ug.gid = g.id) as users_count,
                   (SELECT COUNT(*) FROM services_groups sg WHERE sg.gid = g.id) as services_count
            FROM `groups` g
            ORDER BY g.name ASC
        ");
        $groupsData = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($groupsData as $g) {
            $stmtMembres = $pdo->prepare("
                SELECT u.id, u.firstname, u.name 
                FROM users_groups ug 
                JOIN users u ON u.id = ug.uid 
                WHERE ug.gid = :gid
                ORDER BY u.firstname ASC, u.name ASC
            ");
            $stmtMembres->execute(['gid' => $g['id']]);
            $g['members'] = $stmtMembres->fetchAll(\PDO::FETCH_ASSOC);
            $groups[] = $g;
        }

        // Récupérer tous les bénévoles pour le formulaire d'ajout
        $stmtAllUsers = $pdo->query("SELECT id, firstname, name FROM users ORDER BY name ASC, firstname ASC");
        $allUsers = $stmtAllUsers->fetchAll(\PDO::FETCH_ASSOC);

        $success = $_SESSION['group_success'] ?? null;
        $error = $_SESSION['group_error'] ?? null;
        unset($_SESSION['group_success'], $_SESSION['group_error']);

        return new Response(body: $this->render('admin/groups', [
            'groups' => $groups,
            'allUsers' => $allUsers,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/groups/create", name: "admin.groups.create")]
    public function groupCreate(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $name = trim($parsedBody['name'] ?? '');
        $description = trim($parsedBody['description'] ?? '');

        if (!$name) {
            $_SESSION['group_error'] = "Le nom du groupe est obligatoire.";
            return $this->redirect('admin.groups');
        }

        $pdo = $this->database->getConnection();
        try {
            // Vérifier s'il existe déjà
            $stmtCheck = $pdo->prepare("SELECT id FROM `groups` WHERE name = :name");
            $stmtCheck->execute(['name' => $name]);
            if ($stmtCheck->fetch()) {
                $_SESSION['group_error'] = "Un groupe nommé '$name' existe déjà.";
                return $this->redirect('admin.groups');
            }

            $stmtInsert = $pdo->prepare("INSERT INTO `groups` (name, description) VALUES (:name, :description)");
            $stmtInsert->execute([
                'name' => $name,
                'description' => empty($description) ? null : $description
            ]);

            Logger::info("Création d'un groupe", ['admin_uid' => $_SESSION['user']['id']]);
            $_SESSION['group_success'] = "Le groupe '$name' a bien été créé.";
        } catch (\Exception $e) {
            $_SESSION['group_error'] = "Erreur lors de la création : " . $e->getMessage();
        }

        return $this->redirect('admin.groups');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/groups/edit", name: "admin.groups.edit")]
    public function groupEdit(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);
        $name = trim($parsedBody['name'] ?? '');
        $description = trim($parsedBody['description'] ?? '');

        if (!$id || !$name) {
            $_SESSION['group_error'] = "Données de modification invalides.";
            return $this->redirect('admin.groups');
        }

        $pdo = $this->database->getConnection();
        try {
            // Vérifier s'il y a conflit de nom
            $stmtCheck = $pdo->prepare("SELECT id FROM `groups` WHERE name = :name AND id != :id");
            $stmtCheck->execute(['name' => $name, 'id' => $id]);
            if ($stmtCheck->fetch()) {
                $_SESSION['group_error'] = "Un autre groupe nommé '$name' existe déjà.";
                return $this->redirect('admin.groups');
            }

            $stmtUpdate = $pdo->prepare("UPDATE `groups` SET name = :name, description = :description WHERE id = :id");
            $stmtUpdate->execute([
                'name' => $name,
                'description' => empty($description) ? null : $description,
                'id' => $id
            ]);

            Logger::info("Modification d'un groupe", ['admin_uid' => $_SESSION['user']['id'], 'group_id' => $id]);
            $_SESSION['group_success'] = "Le groupe a bien été modifié.";
        } catch (\Exception $e) {
            $_SESSION['group_error'] = "Erreur lors de la modification : " . $e->getMessage();
        }

        return $this->redirect('admin.groups');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/groups/delete", name: "admin.groups.delete")]
    public function groupDelete(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $id = (int)($parsedBody['id'] ?? 0);

        if (!$id) {
            $_SESSION['group_error'] = "Groupe invalide.";
            return $this->redirect('admin.groups');
        }

        $pdo = $this->database->getConnection();
        try {
            $stmtDelete = $pdo->prepare("DELETE FROM `groups` WHERE id = :id");
            $stmtDelete->execute(['id' => $id]);
            Logger::warning("Suppression d'un groupe", ['admin_uid' => $_SESSION['user']['id'], 'group_id' => $id]);
            $_SESSION['group_success'] = "Le groupe a bien été supprimé.";
        } catch (\Exception $e) {
            $_SESSION['group_error'] = "Erreur lors de la suppression : " . $e->getMessage();
        }

        return $this->redirect('admin.groups');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/groups/add-member", name: "admin.groups.add_member")]
    public function groupAddMember(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $gid = (int)($parsedBody['gid'] ?? 0);
        $uid = (int)($parsedBody['uid'] ?? 0);
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        if (!$gid || !$uid) {
            $msg = "Données d'ajout de membre invalides.";
            if ($isAjax) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['success' => false, 'message' => $msg]));
            }
            $_SESSION['group_error'] = $msg;
            return $this->redirect('admin.groups');
        }

        $pdo = $this->database->getConnection();
        try {
            // Vérifier s'il est déjà membre
            $stmtCheck = $pdo->prepare("SELECT uid FROM users_groups WHERE uid = :uid AND gid = :gid");
            $stmtCheck->execute(['uid' => $uid, 'gid' => $gid]);
            if ($stmtCheck->fetch()) {
                $msg = "Ce bénévole fait déjà partie de ce groupe.";
                if ($isAjax) {
                    return new Response(400, ['Content-Type' => 'application/json'], json_encode(['success' => false, 'message' => $msg]));
                }
                $_SESSION['group_error'] = $msg;
                return $this->redirect('admin.groups');
            }

            $stmtInsert = $pdo->prepare("INSERT INTO users_groups (uid, gid) VALUES (:uid, :gid)");
            $stmtInsert->execute(['uid' => $uid, 'gid' => $gid]);
            Logger::info("Ajout d'un bénévole à un groupe", ['admin_uid' => $_SESSION['user']['id'], 'uid' => $uid, 'gid' => $gid]);
            
            $msg = "Le bénévole a bien été ajouté au groupe.";
            if ($isAjax) {
                // Charger les membres mis à jour
                $stmtMembres = $pdo->prepare("
                    SELECT u.id, u.firstname, u.name 
                    FROM users_groups ug 
                    JOIN users u ON u.id = ug.uid 
                    WHERE ug.gid = :gid
                    ORDER BY u.firstname ASC, u.name ASC
                ");
                $stmtMembres->execute(['gid' => $gid]);
                $updatedMembers = $stmtMembres->fetchAll(\PDO::FETCH_ASSOC);

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'success' => true,
                    'message' => $msg,
                    'members' => $updatedMembers
                ]));
            }
            $_SESSION['group_success'] = $msg;
        } catch (\Exception $e) {
            $msg = "Erreur lors de l'ajout : " . $e->getMessage();
            if ($isAjax) {
                return new Response(500, ['Content-Type' => 'application/json'], json_encode(['success' => false, 'message' => $msg]));
            }
            $_SESSION['group_error'] = $msg;
        }

        return $this->redirect('admin.groups');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/groups/remove-member", name: "admin.groups.remove_member")]
    public function groupRemoveMember(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $gid = (int)($parsedBody['gid'] ?? 0);
        $uid = (int)($parsedBody['uid'] ?? 0);
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        if (!$gid || !$uid) {
            $msg = "Données de retrait de membre invalides.";
            if ($isAjax) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['success' => false, 'message' => $msg]));
            }
            $_SESSION['group_error'] = $msg;
            return $this->redirect('admin.groups');
        }

        $pdo = $this->database->getConnection();
        try {
            $stmtDelete = $pdo->prepare("DELETE FROM users_groups WHERE uid = :uid AND gid = :gid");
            $stmtDelete->execute(['uid' => $uid, 'gid' => $gid]);
            Logger::info("Retrait d'un bénévole d'un groupe", ['admin_uid' => $_SESSION['user']['id'], 'uid' => $uid, 'gid' => $gid]);
            
            $msg = "Le bénévole a bien été retiré du groupe.";
            if ($isAjax) {
                // Charger les membres mis à jour
                $stmtMembres = $pdo->prepare("
                    SELECT u.id, u.firstname, u.name 
                    FROM users_groups ug 
                    JOIN users u ON u.id = ug.uid 
                    WHERE ug.gid = :gid
                    ORDER BY u.firstname ASC, u.name ASC
                ");
                $stmtMembres->execute(['gid' => $gid]);
                $updatedMembers = $stmtMembres->fetchAll(\PDO::FETCH_ASSOC);

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'success' => true,
                    'message' => $msg,
                    'members' => $updatedMembers
                ]));
            }
            $_SESSION['group_success'] = $msg;
        } catch (\Exception $e) {
            $msg = "Erreur lors du retrait : " . $e->getMessage();
            if ($isAjax) {
                return new Response(500, ['Content-Type' => 'application/json'], json_encode(['success' => false, 'message' => $msg]));
            }
            $_SESSION['group_error'] = $msg;
        }

        return $this->redirect('admin.groups');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "GET", path: "/admin/display-settings", name: "admin.display_settings")]
    public function displaySettings(): Response
    {
        $pdo = $this->database->getConnection();

        $stmtDays = $pdo->prepare("SELECT value FROM settings WHERE name = 'home_days_count'");
        $stmtDays->execute();
        $homeDaysCount = $stmtDays->fetchColumn() ?: '7';

        $stmtBanner = $pdo->prepare("SELECT name, value FROM settings WHERE name IN ('banner_message', 'banner_type', 'banner_active')");
        $stmtBanner->execute();
        $bannerSettings = $stmtBanner->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
        $bannerMessage = $bannerSettings['banner_message'] ?? '';
        $bannerType = $bannerSettings['banner_type'] ?? 'info';
        $bannerActive = ($bannerSettings['banner_active'] ?? '0') === '1';

        $success = $_SESSION['display_success'] ?? null;
        $error = $_SESSION['display_error'] ?? null;
        unset($_SESSION['display_success'], $_SESSION['display_error']);

        return new Response(body: $this->render('admin/display_settings', [
            'homeDaysCount' => $homeDaysCount,
            'bannerMessage' => $bannerMessage,
            'bannerType' => $bannerType,
            'bannerActive' => $bannerActive,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/settings/update_days", name: "admin.settings.update_days")]
    public function updateHomeDaysCount(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $daysCount = (int)($parsedBody['home_days_count'] ?? 7);

        if ($daysCount < 1 || $daysCount > 365) {
            $_SESSION['display_error'] = "Le nombre de jours doit être compris entre 1 et 365.";
            return $this->redirect('admin.display_settings');
        }

        $pdo = $this->database->getConnection();
        try {
            $stmt = $pdo->prepare("UPDATE settings SET value = :value WHERE name = 'home_days_count'");
            $stmt->execute(['value' => (string)$daysCount]);

            Logger::info("Mise à jour du nombre de jours affichés à l'accueil", ['days_count' => $daysCount, 'admin_uid' => $_SESSION['user']['id']]);
            $_SESSION['display_success'] = "Le nombre de jours affichés sur la page d'accueil a été mis à jour à $daysCount jours.";
        } catch (\Exception $e) {
            $_SESSION['display_error'] = "Erreur lors de la mise à jour : " . $e->getMessage();
        }

        return $this->redirect('admin.display_settings');
    }

    #[AuthMiddleware('responsable')]
    #[RouteAttribute(method: "POST", path: "/admin/settings/update_banner", name: "admin.settings.update_banner")]
    public function updateBanner(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $message = trim($parsedBody['banner_message'] ?? '');
        $type = $parsedBody['banner_type'] ?? 'info';
        $active = isset($parsedBody['banner_active']) && $parsedBody['banner_active'] === '1' ? '1' : '0';

        if (!in_array($type, ['info', 'warning', 'critical', 'success'])) {
            $type = 'info';
        }

        $pdo = $this->database->getConnection();
        try {
            $stmtMessage = $pdo->prepare("UPDATE settings SET value = :value WHERE name = 'banner_message'");
            $stmtMessage->execute(['value' => $message]);

            $stmtType = $pdo->prepare("UPDATE settings SET value = :value WHERE name = 'banner_type'");
            $stmtType->execute(['value' => $type]);

            $stmtActive = $pdo->prepare("UPDATE settings SET value = :value WHERE name = 'banner_active'");
            $stmtActive->execute(['value' => $active]);

            Logger::info("Mise à jour de la bannière d'information globale", ['active' => $active, 'type' => $type, 'admin_uid' => $_SESSION['user']['id']]);
            $_SESSION['display_success'] = "La bannière d'information globale a été mise à jour avec succès.";
        } catch (\Exception $e) {
            $_SESSION['display_error'] = "Erreur lors de la mise à jour de la bannière : " . $e->getMessage();
        }

        return $this->redirect('admin.display_settings');
    }
}
