<?php

namespace App\Controllers;

use App\Attribute\RenderAttribute;
use App\Attribute\RouteAttribute;
use App\Core\TwigRenderer;
use App\Middleware\AuthMiddleware;
use App\Core\Logger;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

#[AuthMiddleware]
#[RenderAttribute(TwigRenderer::class)]
final class HomeController extends BaseController
{
    #[RouteAttribute(method: "GET", path: "/", name: "index")]
    public function index(): Response
    {
        $userRole = $_SESSION['user']['role'] ?? 'user';
        if ($userRole === 'accueil') {
            return $this->redirect('admin.pointage');
        }

        $pdo = $this->database->getConnection();

        // Récupérer toutes les restrictions d'activités
        $stmtAllRestrictions = $pdo->query("SELECT sid, gid FROM services_groups");
        $restrictions = [];
        foreach ($stmtAllRestrictions->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $restrictions[(int)$row['sid']][] = (int)$row['gid'];
        }

        // Récupérer toutes les restrictions d'ouvertures exceptionnelles
        $stmtOpRestrictions = $pdo->query("SELECT soid, gid FROM services_opening_groups");
        $opRestrictions = [];
        foreach ($stmtOpRestrictions->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $opRestrictions[(int)$row['soid']][] = (int)$row['gid'];
        }

        // Récupérer les groupes du membre connecté
        $uid = $_SESSION['user']['id'];
        $stmtMyGroups = $pdo->prepare("SELECT gid FROM users_groups WHERE uid = :uid");
        $stmtMyGroups->execute(['uid' => $uid]);
        $myGroupIds = $stmtMyGroups->fetchAll(\PDO::FETCH_COLUMN);
        $myGroupIds = array_map('intval', $myGroupIds);

        // Vérifier si l'utilisateur est restreint
        $isRestrictedUser = !in_array($userRole, ['admin', 'responsable', 'accueil']);

        $stmtDaysCount = $pdo->prepare("SELECT value FROM settings WHERE name = 'home_days_count'");
        $stmtDaysCount->execute();
        $daysCountSetting = $stmtDaysCount->fetchColumn();
        $daysCount = $daysCountSetting !== false ? (int)$daysCountSetting : 7;

        $creneauxParJour = [];
        $today = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        
        for ($i = 0; $i < $daysCount; $i++) {
            $date = clone $today;
            $date->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            $dayOfWeek = (int) $date->format('N'); // 1 (lundi) à 7 (dimanche)

            // 1. Récupérer les services classiques (ceux dans services_workdays pour ce jour-là)
            // SAUF s'ils sont fermés exceptionnellement (dans services_holiday)
            $stmtClassiques = $pdo->prepare("
                SELECT s.*, sw.start_time, sw.end_time,
                       IF(sw.start_time IS NOT NULL AND sw.end_time IS NOT NULL, CONCAT(SUBSTRING(sw.start_time, 1, 5), ' - ', SUBSTRING(sw.end_time, 1, 5)), NULL) as hours 
                FROM services s
                JOIN services_workdays sw ON sw.sid = s.id
                WHERE sw.workday = :workday
                  AND s.id NOT IN (
                      SELECT COALESCE(sid, s.id) FROM services_holiday WHERE :date BETWEEN start_date AND end_date
                  )
                  AND s.id NOT IN (
                      SELECT sid FROM services_opening WHERE date = :date
                  )
                ORDER BY s.name ASC
            ");
            $stmtClassiques->execute(['workday' => $dayOfWeek, 'date' => $dateStr]);
            $servicesClassiques = $stmtClassiques->fetchAll(\PDO::FETCH_ASSOC);

            // 2. Récupérer les services exceptionnellement ouverts à cette date (ceux dans services_opening)
            $stmtExceptionnels = $pdo->prepare("
                SELECT s.*, so.start_time, so.end_time,
                       IF(so.start_time IS NOT NULL AND so.end_time IS NOT NULL, CONCAT(SUBSTRING(so.start_time, 1, 5), ' - ', SUBSTRING(so.end_time, 1, 5)), NULL) as hours, 
                       so.id as opening_id 
                FROM services s
                JOIN services_opening so ON so.sid = s.id
                WHERE so.date = :date
                ORDER BY s.name ASC
            ");
            $stmtExceptionnels->execute(['date' => $dateStr]);
            $servicesExceptionnels = $stmtExceptionnels->fetchAll(\PDO::FETCH_ASSOC);

            // 3. Fusionner les deux listes
            $servicesMap = [];
            foreach ($servicesClassiques as $s) {
                if ($isRestrictedUser && isset($restrictions[(int)$s['id']])) {
                    if (empty(array_intersect($myGroupIds, $restrictions[(int)$s['id']]))) {
                        continue;
                    }
                }
                $key = $s['id'] . '_' . ($s['hours'] ?? '');
                $servicesMap[$key] = $s;
            }
            foreach ($servicesExceptionnels as $s) {
                if ($isRestrictedUser) {
                    $openingId = (int)$s['opening_id'];
                    if (isset($opRestrictions[$openingId])) {
                        // Des restrictions spécifiques sont définies sur cette ouverture
                        if (empty(array_intersect($myGroupIds, $opRestrictions[$openingId]))) {
                            continue; // Pas membre de l'un des groupes autorisés pour cette date
                        }
                    }
                    // Si aucune restriction n'est définie sur l'ouverture, elle est PUBLIQUE (on ne filtre pas sur l'activité d'origine)
                }
                $key = $s['id'] . '_' . ($s['hours'] ?? '');
                $servicesMap[$key] = $s;
            }
            
            $services = array_values($servicesMap);
            usort($services, function($a, $b) {
                $hA = $a['hours'] ?? '';
                $hB = $b['hours'] ?? '';
                if ($hA !== $hB) {
                    if ($hA === '') return 1;
                    if ($hB === '') return -1;
                    return strcmp($hA, $hB);
                }
                return strcmp($a['name'], $b['name']);
            });

            $servicesAvecInscriptions = [];
            foreach ($services as $service) {
                // Masquer le créneau si l'heure de fin est dépassée pour aujourd'hui
                if ($dateStr === $today->format('Y-m-d') && !empty($service['end_time'])) {
                    $currentTime = (new \DateTime('now', new \DateTimeZone('Europe/Paris')))->format('H:i:s');
                    if ($currentTime > $service['end_time']) {
                        continue;
                    }
                }

                $startTime = $service['start_time'] ? substr($service['start_time'], 0, 5) : null;
                $endTime   = $service['end_time']   ? substr($service['end_time'], 0, 5)   : null;
                $cardData = $this->getServiceCardData((int)$service['id'], $dateStr, $startTime, $endTime);
                if ($cardData) {
                    $servicesAvecInscriptions[] = $cardData;
                }
            }

            if (!empty($servicesAvecInscriptions)) {
                $creneauxParJour[] = [
                    'date' => $dateStr,
                    'date_formatee' => $this->formaterDateEnFrancais($date),
                    'services' => $servicesAvecInscriptions
                ];
            }
        }

        $success = $_SESSION['success_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['success_message'], $_SESSION['error_message']);

        return new Response(body: $this->render('home/index', [
            'creneauxParJour' => $creneauxParJour,
            'success' => $success,
            'error' => $error
        ]));
    }

    #[RouteAttribute(method: "POST", path: "/appointments/register", name: "appointments.register")]
    public function register(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $dateStr = $parsedBody['date'] ?? '';
        $sid = (int)($parsedBody['sid'] ?? 0);
        $hoursRaw = isset($parsedBody['hours']) && $parsedBody['hours'] !== '' ? trim($parsedBody['hours']) : null;
        $uid = $_SESSION['user']['id'];
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        // Décomposer "HH:MM - HH:MM" -> start_time / end_time
        $startTime = null;
        $endTime   = null;
        if ($hoursRaw !== null && str_contains($hoursRaw, ' - ')) {
            [$startTime, $endTime] = array_map('trim', explode(' - ', $hoursRaw, 2));
        }

        if (!$dateStr || !$sid) {
            if ($isAjax) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => "Données d'inscription invalides."]));
            }
            $_SESSION['error_message'] = "Données d'inscription invalides.";
            return $this->redirect('index');
        }

        $pdo = $this->database->getConnection();
        
        // Contrôle des restrictions de groupes
        $userRole = $_SESSION['user']['role'] ?? 'user';
        $isRestrictedUser = !in_array($userRole, ['admin', 'responsable', 'accueil']);

        if ($isRestrictedUser) {
            // Récupérer les groupes de l'utilisateur
            $stmtMyGroups = $pdo->prepare("SELECT gid FROM users_groups WHERE uid = :uid");
            $stmtMyGroups->execute(['uid' => $uid]);
            $myGroupIds = array_map('intval', $stmtMyGroups->fetchAll(\PDO::FETCH_COLUMN));

            // Vérifier s'il s'agit d'une ouverture exceptionnelle
            $stmtOpening = $pdo->prepare("SELECT id FROM services_opening WHERE sid = :sid AND date = :date");
            $stmtOpening->execute(['sid' => $sid, 'date' => $dateStr]);
            $opening = $stmtOpening->fetch(\PDO::FETCH_ASSOC);

            $hasAccess = true;
            if ($opening) {
                // C'est une ouverture exceptionnelle. On vérifie ses groupes
                $stmtOpGroups = $pdo->prepare("SELECT gid FROM services_opening_groups WHERE soid = :soid");
                $stmtOpGroups->execute(['soid' => $opening['id']]);
                $allowedGroupIds = array_map('intval', $stmtOpGroups->fetchAll(\PDO::FETCH_COLUMN));

                if (!empty($allowedGroupIds)) {
                    // Restriction de groupe spécifique à cette ouverture exceptionnelle
                    $hasAccess = !empty(array_intersect($myGroupIds, $allowedGroupIds));
                }
                // Si empty($allowedGroupIds), alors l'ouverture est publique !
            } else {
                // Fonctionnement régulier de la semaine. On vérifie les groupes de l'activité
                $stmtActGroups = $pdo->prepare("SELECT gid FROM services_groups WHERE sid = :sid");
                $stmtActGroups->execute(['sid' => $sid]);
                $allowedGroupIds = array_map('intval', $stmtActGroups->fetchAll(\PDO::FETCH_COLUMN));

                if (!empty($allowedGroupIds)) {
                    $hasAccess = !empty(array_intersect($myGroupIds, $allowedGroupIds));
                }
            }

            if (!$hasAccess) {
                $errorMsg = "Vous n'avez pas l'autorisation de vous inscrire à cette activité ce jour-là.";
                if ($isAjax) {
                    return new Response(403, ['Content-Type' => 'application/json'], json_encode(['error' => $errorMsg]));
                }
                $_SESSION['error_message'] = $errorMsg;
                return $this->redirect('index');
            }
        }

        try {
            $pdo->beginTransaction();

            // Vérifier s'il y a un jour de fermeture (vacances/férié) configuré pour ce service ce jour-là
            $stmtHoly = $pdo->prepare("SELECT name FROM services_holiday WHERE (sid = :sid OR sid IS NULL) AND :date BETWEEN start_date AND end_date");
            $stmtHoly->execute(['sid' => $sid, 'date' => $dateStr]);
            if ($stmtHoly->fetch()) {
                $errorMsg = "Ce créneau est fermé pour cause de jour férié ou de vacances.";
                if ($isAjax) {
                    $pdo->rollBack();
                    return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => $errorMsg]));
                }
                $_SESSION['error_message'] = $errorMsg;
                $pdo->rollBack();
                return $this->redirect('index');
            }

            // 1. Chercher ou créer l'appointment
            $stmt = $pdo->prepare("
                SELECT id FROM appointment 
                WHERE sid = :sid AND date = :date
                  AND ((:start_time IS NULL AND start_time IS NULL) OR start_time = :start_time)
                  AND ((:end_time IS NULL AND end_time IS NULL) OR end_time = :end_time)
            ");
            $stmt->execute(['sid' => $sid, 'date' => $dateStr, 'start_time' => $startTime, 'end_time' => $endTime]);
            $appointment = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($appointment) {
                $aid = $appointment['id'];
            } else {
                $stmtInsert = $pdo->prepare("INSERT INTO appointment (sid, date, start_time, end_time) VALUES (:sid, :date, :start_time, :end_time)");
                $stmtInsert->execute(['sid' => $sid, 'date' => $dateStr, 'start_time' => $startTime, 'end_time' => $endTime]);
                $aid = $pdo->lastInsertId();
            }

            // 2. Vérifier si l'utilisateur est déjà inscrit
            $stmtCheck = $pdo->prepare("SELECT * FROM appointments_users WHERE aid = :aid AND uid = :uid");
            $stmtCheck->execute(['aid' => $aid, 'uid' => $uid]);
            $existing = $stmtCheck->fetch(\PDO::FETCH_ASSOC);
            
            if ($existing) {
                if ($existing['presence'] === 'absent') {
                    // Remettre en attente (présent)
                    $stmtUpdate = $pdo->prepare("UPDATE appointments_users SET presence = 'en_attente', pointed = 0 WHERE aid = :aid AND uid = :uid");
                    $stmtUpdate->execute(['aid' => $aid, 'uid' => $uid]);
                } else {
                    $errorMsg = "Vous êtes déjà inscrit à ce créneau.";
                    if ($isAjax) {
                        $pdo->rollBack();
                        return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => $errorMsg]));
                    }
                    $_SESSION['error_message'] = $errorMsg;
                    $pdo->rollBack();
                    return $this->redirect('index');
                }
            } else {
                // 3. Inscrire l'utilisateur
                $stmtSub = $pdo->prepare("INSERT INTO appointments_users (aid, uid, presence, pointed) VALUES (:aid, :uid, 'en_attente', 0)");
                $stmtSub->execute(['aid' => $aid, 'uid' => $uid]);
            }

            $pdo->commit();
            Logger::info("Inscription d'un bénévole à un créneau", ['uid' => $uid, 'appointment_id' => $aid]);
            $_SESSION['success_message'] = "Inscription réussie !";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($isAjax) {
                return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => "Erreur lors de l'inscription : " . $e->getMessage()]));
            }
            $_SESSION['error_message'] = "Erreur lors de l'inscription : " . $e->getMessage();
            return $this->redirect('index');
        }

        if ($isAjax) {
            $serviceData = $this->getServiceCardData($sid, $dateStr, $startTime, $endTime);
            $html = $this->render('home/_service_card', [
                'service' => $serviceData,
                'jour'    => ['date' => $dateStr]
            ]);
            return new Response(200, ['Content-Type' => 'text/html'], $html);
        }

        return $this->redirect('index');
    }

    #[RouteAttribute(method: "POST", path: "/appointments/set-absent", name: "appointments.set_absent")]
    public function setAbsent(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $dateStr = $parsedBody['date'] ?? '';
        $sid = (int)($parsedBody['sid'] ?? 0);
        $hoursRaw = isset($parsedBody['hours']) && $parsedBody['hours'] !== '' ? trim($parsedBody['hours']) : null;
        $uid = $_SESSION['user']['id'];
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        $startTime = null;
        $endTime   = null;
        if ($hoursRaw !== null && str_contains($hoursRaw, ' - ')) {
            [$startTime, $endTime] = array_map('trim', explode(' - ', $hoursRaw, 2));
        }

        if (!$dateStr || !$sid) {
            if ($isAjax) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => "Données d'absence invalides."]));
            }
            $_SESSION['error_message'] = "Données d'absence invalides.";
            return $this->redirect('index');
        }

        $pdo = $this->database->getConnection();
        
        try {
            $pdo->beginTransaction();

            // 1. Chercher ou créer l'appointment
            $stmt = $pdo->prepare("
                SELECT id FROM appointment 
                WHERE sid = :sid AND date = :date
                  AND ((:start_time IS NULL AND start_time IS NULL) OR start_time = :start_time)
                  AND ((:end_time IS NULL AND end_time IS NULL) OR end_time = :end_time)
            ");
            $stmt->execute(['sid' => $sid, 'date' => $dateStr, 'start_time' => $startTime, 'end_time' => $endTime]);
            $appointment = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($appointment) {
                $aid = $appointment['id'];
            } else {
                $stmtInsert = $pdo->prepare("INSERT INTO appointment (sid, date, start_time, end_time) VALUES (:sid, :date, :start_time, :end_time)");
                $stmtInsert->execute(['sid' => $sid, 'date' => $dateStr, 'start_time' => $startTime, 'end_time' => $endTime]);
                $aid = $pdo->lastInsertId();
            }

            // 2. Vérifier si l'utilisateur est déjà inscrit
            $stmtCheck = $pdo->prepare("SELECT * FROM appointments_users WHERE aid = :aid AND uid = :uid");
            $stmtCheck->execute(['aid' => $aid, 'uid' => $uid]);
            $existing = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                if ($existing['presence'] !== 'en_attente' && $existing['presence'] !== 'absent') {
                    throw new \Exception("Modification impossible : vous avez déjà été pointé sur ce créneau.");
                }
                // Mettre à jour en absent
                $stmtUpdate = $pdo->prepare("
                    UPDATE appointments_users SET presence = 'absent', pointed = 0 WHERE aid = :aid AND uid = :uid
                ");
                $stmtUpdate->execute(['aid' => $aid, 'uid' => $uid]);
            } else {
                // Inscrire en absent
                $stmtSub = $pdo->prepare("
                    INSERT INTO appointments_users (aid, uid, presence, pointed) VALUES (:aid, :uid, 'absent', 0)
                ");
                $stmtSub->execute(['aid' => $aid, 'uid' => $uid]);
            }

            $pdo->commit();
            Logger::info("Déclaration d'absence d'un bénévole à un créneau", ['uid' => $uid, 'appointment_id' => $aid]);
            $_SESSION['success_message'] = "Déclaration d'absence enregistrée.";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($isAjax) {
                return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => "Erreur lors de la déclaration d'absence : " . $e->getMessage()]));
            }
            $_SESSION['error_message'] = "Erreur lors de la déclaration d'absence : " . $e->getMessage();
            return $this->redirect('index');
        }

        if ($isAjax) {
            $serviceData = $this->getServiceCardData($sid, $dateStr, $startTime, $endTime);
            $html = $this->render('home/_service_card', [
                'service' => $serviceData,
                'jour'    => ['date' => $dateStr]
            ]);
            return new Response(200, ['Content-Type' => 'text/html'], $html);
        }

        return $this->redirect('index');
    }

    #[RouteAttribute(method: "POST", path: "/appointments/unregister", name: "appointments.unregister")]
    public function unregister(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $aid = (int)($parsedBody['aid'] ?? 0);
        $uid = $_SESSION['user']['id'];
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        if (!$aid) {
            if ($isAjax) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => "Créneau invalide."]));
            }
            $_SESSION['error_message'] = "Créneau invalide.";
            return $this->redirect('index');
        }

        $pdo = $this->database->getConnection();
        
        try {
            // Récupérer le sid, la date et les horaires pour la reconstruction de la card en AJAX
            $stmtAppInfo = $pdo->prepare("SELECT sid, date, start_time, end_time FROM appointment WHERE id = :aid");
            $stmtAppInfo->execute(['aid' => $aid]);
            $appInfo = $stmtAppInfo->fetch(\PDO::FETCH_ASSOC);
            
            if (!$appInfo) {
                throw new \Exception("Créneau introuvable.");
            }
            
            $sid       = (int)$appInfo['sid'];
            $dateStr   = $appInfo['date'];
            $startTime = $appInfo['start_time'] ? substr($appInfo['start_time'], 0, 5) : null;
            $endTime   = $appInfo['end_time']   ? substr($appInfo['end_time'], 0, 5)   : null;

            // Vérifier si le pointage a déjà eu lieu
            $stmtCheck = $pdo->prepare("
                SELECT presence, pointed FROM appointments_users WHERE aid = :aid AND uid = :uid
            ");
            $stmtCheck->execute(['aid' => $aid, 'uid' => $uid]);
            $assoc = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

            // Le pointage officiel a eu lieu si la présence est 'present', ou s'il a été pointé absent (pointed = 1)
            $aEtePointe = $assoc && ($assoc['presence'] === 'present' || ($assoc['presence'] === 'absent' && (int)$assoc['pointed'] === 1));

            if ($aEtePointe) {
                $errorMsg = "Désinscription impossible : vous avez déjà été pointé sur ce créneau.";
                if ($isAjax) {
                    return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => $errorMsg]));
                }
                $_SESSION['error_message'] = $errorMsg;
                return $this->redirect('index');
            }

            $stmt = $pdo->prepare("
                DELETE FROM appointments_users WHERE aid = :aid AND uid = :uid
            ");
            $stmt->execute(['aid' => $aid, 'uid' => $uid]);
            Logger::info("Désinscription d'un bénévole d'un créneau", ['uid' => $uid, 'appointment_id' => $aid]);
            $_SESSION['success_message'] = "Désinscription réussie.";
        } catch (\Exception $e) {
            if ($isAjax) {
                return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => "Erreur lors de la désinscription : " . $e->getMessage()]));
            }
            $_SESSION['error_message'] = "Erreur lors de la désinscription : " . $e->getMessage();
            return $this->redirect('index');
        }

        if ($isAjax) {
            $serviceData = $this->getServiceCardData($sid, $dateStr, $startTime, $endTime);
            $html = $this->render('home/_service_card', [
                'service' => $serviceData,
                'jour'    => ['date' => $dateStr]
            ]);
            return new Response(200, ['Content-Type' => 'text/html'], $html);
        }

        return $this->redirect('index');
    }

    #[RouteAttribute(method: "POST", path: "/appointments/register-day", name: "appointments.register_day")]
    public function registerDay(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $dateStr = $parsedBody['date'] ?? '';
        $uid = $_SESSION['user']['id'];
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        if (!$dateStr) {
            if ($isAjax) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => "Date invalide."]));
            }
            $_SESSION['error_message'] = "Date invalide.";
            return $this->redirect('index');
        }

        $pdo = $this->database->getConnection();
        $creneauxMap = [];

        try {
            $dateObj = new \DateTime($dateStr);
            $dayOfWeek = (int)$dateObj->format('N');

            $userRole = $_SESSION['user']['role'] ?? 'user';
            $isRestrictedUser = !in_array($userRole, ['admin', 'responsable', 'accueil']);

            $myGroupIds = [];
            if ($isRestrictedUser) {
                $stmtMyGroups = $pdo->prepare("SELECT gid FROM users_groups WHERE uid = :uid");
                $stmtMyGroups->execute(['uid' => $uid]);
                $myGroupIds = array_map('intval', $stmtMyGroups->fetchAll(\PDO::FETCH_COLUMN));
            }

            // --- 1. Services classiques (hors fermetures) ---
            $stmtClassiques = $pdo->prepare("
                SELECT s.id as sid, sw.start_time, sw.end_time
                FROM services s
                JOIN services_workdays sw ON sw.sid = s.id
                WHERE sw.workday = :workday
                  AND s.id NOT IN (
                      SELECT COALESCE(sid, s.id) FROM services_holiday WHERE :date BETWEEN start_date AND end_date
                  )
            ");
            $stmtClassiques->execute(['workday' => $dayOfWeek, 'date' => $dateStr]);

            foreach ($stmtClassiques->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                if ($isRestrictedUser) {
                    $stmtGrp = $pdo->prepare("SELECT gid FROM services_groups WHERE sid = :sid");
                    $stmtGrp->execute(['sid' => $c['sid']]);
                    $allowed = array_map('intval', $stmtGrp->fetchAll(\PDO::FETCH_COLUMN));
                    if (!empty($allowed) && empty(array_intersect($myGroupIds, $allowed))) {
                        continue;
                    }
                }
                $key = $c['sid'] . '_' . ($c['start_time'] ?? '') . '_' . ($c['end_time'] ?? '');
                $creneauxMap[$key] = ['sid' => (int)$c['sid'], 'start_time' => $c['start_time'], 'end_time' => $c['end_time']];
            }

            // --- 2. Ouvertures exceptionnelles ---
            $stmtExcept = $pdo->prepare("
                SELECT so.id as opening_id, so.sid, so.start_time, so.end_time
                FROM services_opening so
                WHERE so.date = :date
            ");
            $stmtExcept->execute(['date' => $dateStr]);

            foreach ($stmtExcept->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                if ($isRestrictedUser) {
                    $stmtGrp = $pdo->prepare("SELECT gid FROM services_opening_groups WHERE soid = :soid");
                    $stmtGrp->execute(['soid' => $c['opening_id']]);
                    $allowed = array_map('intval', $stmtGrp->fetchAll(\PDO::FETCH_COLUMN));
                    if (!empty($allowed) && empty(array_intersect($myGroupIds, $allowed))) {
                        continue;
                    }
                }
                $key = $c['sid'] . '_' . ($c['start_time'] ?? '') . '_' . ($c['end_time'] ?? '');
                $creneauxMap[$key] = ['sid' => (int)$c['sid'], 'start_time' => $c['start_time'], 'end_time' => $c['end_time']];
            }

            // --- 3. Inscrire à chaque créneau ---
            $inscriptionsReussies = 0;

            foreach ($creneauxMap as $creneau) {
                $csid      = $creneau['sid'];
                $startTime = $creneau['start_time'] ?? null;
                $endTime   = $creneau['end_time'] ?? null;

                $pdo->beginTransaction();

                // Trouver ou créer l'appointment
                $stmtApp = $pdo->prepare("
                    SELECT id FROM appointment
                    WHERE sid = :sid AND date = :date
                      AND ((:start_time IS NULL AND start_time IS NULL) OR start_time = :start_time)
                      AND ((:end_time IS NULL AND end_time IS NULL) OR end_time = :end_time)
                ");
                $stmtApp->execute(['sid' => $csid, 'date' => $dateStr, 'start_time' => $startTime, 'end_time' => $endTime]);
                $appointment = $stmtApp->fetch(\PDO::FETCH_ASSOC);

                if ($appointment) {
                    $aid = $appointment['id'];
                } else {
                    $stmtIns = $pdo->prepare("INSERT INTO appointment (sid, date, start_time, end_time) VALUES (:sid, :date, :start_time, :end_time)");
                    $stmtIns->execute(['sid' => $csid, 'date' => $dateStr, 'start_time' => $creneau['start_time'] ?? null, 'end_time' => $creneau['end_time'] ?? null]);
                    $aid = $pdo->lastInsertId();
                }

                // Vérifier si déjà inscrit
                $stmtChk = $pdo->prepare("SELECT 1 FROM appointments_users WHERE aid = :aid AND uid = :uid");
                $stmtChk->execute(['aid' => $aid, 'uid' => $uid]);
                if ($stmtChk->fetch()) {
                    $pdo->rollBack();
                    continue;
                }

                $stmtSub = $pdo->prepare("INSERT INTO appointments_users (aid, uid, presence) VALUES (:aid, :uid, 'en_attente')");
                $stmtSub->execute(['aid' => $aid, 'uid' => $uid]);
                $pdo->commit();
                $inscriptionsReussies++;
            }

            if ($inscriptionsReussies > 0) {
                Logger::info("Inscription globale d'un bénévole à une journée", ['uid' => $uid, 'date' => $dateStr, 'count' => $inscriptionsReussies]);
            }

        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($isAjax) {
                return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => "Erreur lors de l'inscription groupée : " . $e->getMessage()]));
            }
            $_SESSION['error_message'] = "Erreur lors de l'inscription groupée : " . $e->getMessage();
            return $this->redirect('index');
        }

        if ($isAjax) {
            $cards = [];
            foreach ($creneauxMap as $creneau) {
                $serviceData = $this->getServiceCardData($creneau['sid'], $dateStr, $creneau['start_time'] ?? null, $creneau['end_time'] ?? null);
                if ($serviceData) {
                    $html = $this->render('home/_service_card', [
                        'service' => $serviceData,
                        'jour'    => ['date' => $dateStr]
                    ]);
                    $cards[] = [
                        'id'   => "service-card-{$creneau['sid']}-{$dateStr}",
                        'html' => $html
                    ];
                }
            }

            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'cards'   => $cards
            ]));
        }

        return $this->redirect('index');
    }

    private function getServiceCardData(int $sid, string $dateStr, ?string $startTime = null, ?string $endTime = null): ?array
    {
        $pdo = $this->database->getConnection();
        
        $dateObj = new \DateTime($dateStr);
        $dayOfWeek = (int)$dateObj->format('N');
        
        // 1. Chercher si c'est une ouverture exceptionnelle
        $stmtOpening = $pdo->prepare("
            SELECT id, start_time, end_time,
                   IF(start_time IS NOT NULL AND end_time IS NOT NULL, CONCAT(SUBSTRING(start_time, 1, 5), ' - ', SUBSTRING(end_time, 1, 5)), NULL) as hours,
                   description 
            FROM services_opening 
            WHERE sid = :sid AND date = :date 
              AND ((:start_time IS NULL AND start_time IS NULL) OR start_time = :start_time)
              AND ((:end_time IS NULL AND end_time IS NULL) OR end_time = :end_time)
        ");
        $stmtOpening->execute(['sid' => $sid, 'date' => $dateStr, 'start_time' => $startTime, 'end_time' => $endTime]);
        $opening = $stmtOpening->fetch(\PDO::FETCH_ASSOC);

        if ($opening !== false) {
            // C'est une ouverture exceptionnelle !
            $stmtService = $pdo->prepare("SELECT * FROM services WHERE id = :sid");
            $stmtService->execute(['sid' => $sid]);
            $service = $stmtService->fetch(\PDO::FETCH_ASSOC);
            if ($service) {
                $service['hours'] = $opening['hours'];
                if (!empty($opening['description'])) {
                    $service['description'] = $opening['description'];
                }
            }
        } else {
            // Sinon, fonctionnement normal de la semaine
            $stmt = $pdo->prepare("
                SELECT s.*, 
                       IF(sw.start_time IS NOT NULL AND sw.end_time IS NOT NULL, CONCAT(SUBSTRING(sw.start_time, 1, 5), ' - ', SUBSTRING(sw.end_time, 1, 5)), NULL) as hours 
                FROM services s
                JOIN services_workdays sw ON sw.sid = s.id
                WHERE s.id = :sid AND sw.workday = :workday 
                  AND ((:start_time IS NULL AND sw.start_time IS NULL) OR sw.start_time = :start_time)
                  AND ((:end_time IS NULL AND sw.end_time IS NULL) OR sw.end_time = :end_time)
            ");
            $stmt->execute(['sid' => $sid, 'workday' => $dayOfWeek, 'start_time' => $startTime, 'end_time' => $endTime]);
            $service = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$service) {
            return null;
        }
        
        $stmtHoly = $pdo->prepare("
            SELECT name FROM services_holiday WHERE (sid = :sid OR sid IS NULL) AND :date BETWEEN start_date AND end_date
        ");
        $stmtHoly->execute(['sid' => $sid, 'date' => $dateStr]);
        $holiday = $stmtHoly->fetch(\PDO::FETCH_ASSOC);

        $ferme = false;
        $raisonFermeture = null;
        if ($holiday) {
            $ferme = true;
            $raisonFermeture = $holiday['name'];
        }

        $stmtApp = $pdo->prepare("
            SELECT * FROM appointment 
            WHERE sid = :sid AND date = :date
              AND ((:start_time IS NULL AND start_time IS NULL) OR start_time = :start_time)
              AND ((:end_time IS NULL AND end_time IS NULL) OR end_time = :end_time)
        ");
        $stmtApp->execute([
            'sid'        => $sid,
            'date'       => $dateStr,
            'start_time' => $startTime,
            'end_time'   => $endTime,
        ]);
        $appointment = $stmtApp->fetch(\PDO::FETCH_ASSOC);

        $inscrits = [];
        $dejaInscrit = false;
        $presenceUtilisateurConnecte = null;
        $aid = null;

        if ($appointment) {
            $aid = $appointment['id'];
            $stmtInscrits = $pdo->prepare("
                SELECT u.id, u.firstname, u.name, au.presence, au.pointed FROM users u
                JOIN appointments_users au ON au.uid = u.id
                WHERE au.aid = :aid
                ORDER BY (au.presence = 'absent' AND au.pointed = 0) ASC, u.firstname ASC, u.name ASC
            ");
            $stmtInscrits->execute(['aid' => $aid]);
            $inscrits = $stmtInscrits->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($inscrits as $inscrit) {
                if ($inscrit['id'] == $_SESSION['user']['id']) {
                    $dejaInscrit = true;
                    $presenceUtilisateurConnecte = $inscrit['presence'];
                    break;
                }
            }
        }

        // Récupérer les groupes associés (de l'ouverture exceptionnelle ou de l'activité)
        $groups = [];
        if ($opening !== false) {
            $stmtGroups = $pdo->prepare("
                SELECT g.name FROM services_opening_groups sog
                JOIN `groups` g ON g.id = sog.gid
                WHERE sog.soid = :soid
                ORDER BY g.name ASC
            ");
            $stmtGroups->execute(['soid' => $opening['id']]);
            $groups = $stmtGroups->fetchAll(\PDO::FETCH_COLUMN);
        } else {
            $stmtGroups = $pdo->prepare("
                SELECT g.name FROM services_groups sg
                JOIN `groups` g ON g.id = sg.gid
                WHERE sg.sid = :sid
                ORDER BY g.name ASC
            ");
            $stmtGroups->execute(['sid' => $sid]);
            $groups = $stmtGroups->fetchAll(\PDO::FETCH_COLUMN);
        }

        return [
            'id' => $service['id'],
            'name' => $service['name'],
            'hours' => $service['hours'] ?? null,
            'description' => $service['description'] ?? null,
            'optimal_count' => $service['optimal_count'] ?? null,
            'aid' => $aid,
            'inscrits' => $inscrits,
            'dejaInscrit' => $dejaInscrit,
            'presenceUtilisateurConnecte' => $presenceUtilisateurConnecte,
            'ferme' => $ferme,
            'raisonFermeture' => $raisonFermeture,
            'groups' => $groups
        ];
    }

    private function formaterDateEnFrancais(\DateTime $date): string
    {
        $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
        $mois = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        
        $w = (int) $date->format('w');
        $n = (int) $date->format('n');
        $j = $date->format('j');
        
        return $jours[$w] . ' ' . $j . ' ' . $mois[$n];
    }

    #[AuthMiddleware]
    #[RouteAttribute(method: "GET", path: "/contact", name: "contact")]
    public function contactForm(): Response
    {
        $success = $_SESSION['contact_success'] ?? null;
        $error = $_SESSION['contact_error'] ?? null;
        unset($_SESSION['contact_success'], $_SESSION['contact_error']);

        return new Response(body: $this->render('contact', [
            'success' => $success,
            'error' => $error
        ]));
    }

    #[AuthMiddleware]
    #[RouteAttribute(method: "POST", path: "/contact", name: "contact.submit")]
    public function contactSubmit(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $subject = trim($parsedBody['subject'] ?? '');
        $message = trim($parsedBody['message'] ?? '');

        if (empty($subject) || empty($message)) {
            $_SESSION['contact_error'] = "Veuillez remplir tous les champs obligatoires.";
            return $this->redirect('contact');
        }

        $contactMail = $this->container->get('contact.mail');
        if (empty($contactMail)) {
            $_SESSION['contact_error'] = "Le service de contact n'est pas configuré. Veuillez contacter l'administrateur.";
            return $this->redirect('contact');
        }

        $user = $_SESSION['user'];
        $userFullName = $user['firstname'] . ' ' . $user['name'];

        $type = trim($parsedBody['type'] ?? 'idee');
        $typeLabel = ($type === 'bug') ? '[BUG]' : '[IDÉE]';

        // Construire l'e-mail
        $to = $contactMail;
        $emailSubject = $typeLabel . " " . $subject;
        $emailBody = "Nouveau retour utilisateur reçu depuis le site :\n\n";
        $emailBody .= "Type : " . (($type === 'bug') ? 'Bug / Problème technique' : 'Idée / Amélioration') . "\n";
        $emailBody .= "Bénévole : " . $userFullName . " (Nom d'utilisateur: " . $user['username'] . ")\n";
        $emailBody .= "Adresse e-mail : " . $userEmail . "\n";
        $emailBody .= "Sujet : " . $subject . "\n\n";
        $emailBody .= "Message :\n" . $message . "\n";

        // Déterminer dynamiquement le nom de domaine
        $host = $request->getUri()->getHost();
        if (empty($host) || in_array($host, ['localhost', '127.0.0.1', '::1'])) {
            $host = 'planning-benevoles.fr';
        }

        // En-têtes pour l'e-mail
        $headers = [
            'From' => 'no-reply@' . $host,
            'X-Mailer' => 'PHP/' . phpversion(),
            'Content-Type' => 'text/plain; charset=UTF-8'
        ];

        // Envoyer le mail
        $sent = false;
        try {
            // mail() en PHP accepte les en-têtes sous forme de tableau (depuis PHP 7.2)
            $sent = mail($to, $emailSubject, $emailBody, $headers);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de l'envoi de mail de contact", ['error' => $e->getMessage()]);
        }

        if ($sent) {
            Logger::info("E-mail de contact envoyé", ['from_uid' => $user['id'], 'to' => $to]);
            $_SESSION['contact_success'] = "Votre message a été envoyé avec succès.";
        } else {
            $_SESSION['contact_error'] = "Une erreur est survenue lors de l'envoi de votre message. Veuillez réessayer.";
        }

        return $this->redirect('contact');
    }

    #[RouteAttribute(method: "GET", path: "/changelog", name: "changelog")]
    public function changelog(): Response
    {
        return new Response(body: $this->render('home/changelog'));
    }

    #[RouteAttribute(method: "GET", path: "/cgu", name: "cgu")]
    public function cgu(): Response
    {
        return new Response(body: $this->render('home/cgu'));
    }
}
