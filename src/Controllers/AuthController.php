<?php

namespace App\Controllers;

use App\Attribute\RouteAttribute;
use App\Attribute\RenderAttribute;
use App\Core\TwigRenderer;
use App\Core\Logger;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

#[RenderAttribute(TwigRenderer::class)]
final class AuthController extends BaseController
{
    #[RouteAttribute(method: "GET", path: "/login", name: "auth.login_form")]
    public function loginForm(): Response
    {
        if (!empty($_SESSION['user'])) {
            return $this->redirect('index');
        }
        
        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);

        // Rendu du template Twig
        // BaseController a une méthode render, qui cherche RenderAttribute.
        // Mais nous pouvons aussi utiliser le TwigRenderer directement si nous l'injectons,
        // ou simplement appeler le renderer de BaseController si nous mettons l'attribut.
        return new Response(body: $this->render('auth/login', ['error' => $error]));
    }

    #[RouteAttribute(method: "POST", path: "/login", name: "auth.login_submit")]
    public function loginSubmit(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $username = $parsedBody['username'] ?? '';
        $password = $parsedBody['password'] ?? '';

        $stmt = $this->database->getConnection()->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            if ((int)($user['active'] ?? 1) === 0) {
                Logger::warning("Tentative de connexion sur un compte désactivé", ['username' => $username]);
                $_SESSION['login_error'] = 'Votre compte a été désactivé. Veuillez contacter un administrateur.';
                return $this->redirect('auth.login_form');
            }
            $role = $user['role'] ?? 'user';
            $rssToken = null;
            if ($role === 'admin') {
                $stmtToken = $this->database->getConnection()->prepare("SELECT value FROM settings WHERE name = 'rss_token'");
                $stmtToken->execute();
                $rssToken = $stmtToken->fetch(\PDO::FETCH_COLUMN) ?: 'init_token_abc123';
            }

            // Mettre à jour la date de dernière connexion
            $nowStr = (new \DateTime('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
            $stmtUpdateCnx = $this->database->getConnection()->prepare("UPDATE users SET last_login = :last_login WHERE id = :id");
            $stmtUpdateCnx->execute(['last_login' => $nowStr, 'id' => $user['id']]);

            $_SESSION['user'] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'firstname' => $user['firstname'],
                'username' => $user['username'],
                'role' => $role,
                'rss_token' => $rssToken,
                'last_password_modified' => $user['lastModifiedPassword']
            ];

            // Gestion du "Se souvenir de moi"
            $rememberMe = !empty($parsedBody['remember_me']);
            if ($rememberMe) {
                $publicToken = bin2hex(random_bytes(16));
                $privateToken = bin2hex(random_bytes(32));
                $privateHash = password_hash($privateToken, PASSWORD_DEFAULT);
                $expirationDate = (new \DateTime('+30 days'))->format('Y-m-d H:i:s');

                $stmtRemember = $this->database->getConnection()->prepare("
                    INSERT INTO remember_tokens (uid, public_token, private_hash, expiration_date) 
                    VALUES (:uid, :public_token, :private_hash, :expiration_date)
                ");
                $stmtRemember->execute([
                    'uid' => $user['id'],
                    'public_token' => $publicToken,
                    'private_hash' => $privateHash,
                    'expiration_date' => $expirationDate
                ]);

                setcookie(
                    'remember_me',
                    $publicToken . ':' . $privateToken,
                    [
                        'expires' => time() + (30 * 24 * 60 * 60), // 30 jours
                        'path' => '/',
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]
                );
            }

            Logger::info("Connexion réussie de l'utilisateur", ['uid' => $user['id']]);
            return $this->redirect('index');
        }

        Logger::warning("Tentative de connexion échouée", ['username' => $username]);
        $_SESSION['login_error'] = 'Identifiants incorrects.';
        return $this->redirect('auth.login_form');
    }

    #[RouteAttribute(method: "GET", path: "/logout", name: "auth.logout")]
    public function logout(): Response
    {
        if (isset($_SESSION['user'])) {
            Logger::info("Déconnexion de l'utilisateur", ['uid' => $_SESSION['user']['id']]);
        }

        // Supprimer le token remember_me de la DB et effacer le cookie
        if (!empty($_COOKIE['remember_me'])) {
            $cookieValue = $_COOKIE['remember_me'];
            if (strpos($cookieValue, ':') !== false) {
                list($publicToken, $privateToken) = explode(':', $cookieValue, 2);
                $stmtDel = $this->database->getConnection()->prepare("
                    DELETE FROM remember_tokens WHERE public_token = :public_token
                ");
                $stmtDel->execute(['public_token' => $publicToken]);
            }
            
            setcookie('remember_me', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }

        unset($_SESSION['user']);
        session_destroy();
        return $this->redirect('auth.login_form');
    }

    public function autoLoginWithCookie(): void
    {
        $cookieValue = $_COOKIE['remember_me'] ?? '';
        if (!$cookieValue || strpos($cookieValue, ':') === false) {
            return;
        }

        list($publicToken, $privateToken) = explode(':', $cookieValue, 2);

        $pdo = $this->database->getConnection();
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM remember_tokens 
                WHERE public_token = :public_token AND expiration_date > NOW()
            ");
            $stmt->execute(['public_token' => $publicToken]);
            $tokenRecord = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($tokenRecord && password_verify($privateToken, $tokenRecord['private_hash'])) {
                $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = :id");
                $stmtUser->execute(['id' => $tokenRecord['uid']]);
                $user = $stmtUser->fetch(\PDO::FETCH_ASSOC);

                if ($user) {
                    if ((int)($user['active'] ?? 1) === 0) {
                        // Compte désactivé, supprimer le token et le cookie
                        $stmtDel = $pdo->prepare("DELETE FROM remember_tokens WHERE public_token = :public_token");
                        $stmtDel->execute(['public_token' => $publicToken]);
                        setcookie('remember_me', '', [
                            'expires' => time() - 3600,
                            'path' => '/',
                            'httponly' => true,
                            'samesite' => 'Lax'
                        ]);
                        return;
                    }
                    $role = $user['role'] ?? 'user';
                    $rssToken = null;
                    if ($role === 'admin') {
                        $stmtToken = $pdo->prepare("SELECT value FROM settings WHERE name = 'rss_token'");
                        $stmtToken->execute();
                        $rssToken = $stmtToken->fetch(\PDO::FETCH_COLUMN) ?: 'init_token_abc123';
                    }

                    // Mettre à jour la date de dernière connexion
                    $nowStr = (new \DateTime('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
                    $stmtUpdateCnx = $pdo->prepare("UPDATE users SET last_login = :last_login WHERE id = :id");
                    $stmtUpdateCnx->execute(['last_login' => $nowStr, 'id' => $user['id']]);

                    $_SESSION['user'] = [
                        'id' => $user['id'],
                        'name' => $user['name'],
                        'firstname' => $user['firstname'],
                        'username' => $user['username'],
                        'role' => $role,
                        'rss_token' => $rssToken,
                        'last_password_modified' => $user['lastModifiedPassword']
                    ];
                    
                    // Régénérer le token privé (rotation de sécurité)
                    $newPrivateToken = bin2hex(random_bytes(32));
                    $newPrivateHash = password_hash($newPrivateToken, PASSWORD_DEFAULT);
                    
                    $stmtUpdate = $pdo->prepare("
                        UPDATE remember_tokens 
                        SET private_hash = :private_hash 
                        WHERE id = :id
                    ");
                    $stmtUpdate->execute([
                        'private_hash' => $newPrivateHash,
                        'id' => $tokenRecord['id']
                    ]);
                    
                    setcookie(
                        'remember_me',
                        $publicToken . ':' . $newPrivateToken,
                        [
                            'expires' => strtotime($tokenRecord['expiration_date']),
                            'path' => '/',
                            'httponly' => true,
                            'samesite' => 'Lax'
                        ]
                    );
                    
                    Logger::info("Connexion automatique réussie via remember_me cookie", ['uid' => $user['id']]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Erreur de connexion automatique remember me", ['error' => $e->getMessage()]);
        }
    }

    #[AuthMiddleware('user, responsable, admin, accueil')]
    #[RouteAttribute(method: "GET", path: "/profile", name: "profile")]
    public function profile(): Response
    {
        $success = $_SESSION['profile_success'] ?? null;
        $error = $_SESSION['profile_error'] ?? null;
        unset($_SESSION['profile_success'], $_SESSION['profile_error']);

        $pdo = $this->database->getConnection();
        $stmt = $pdo->prepare("SELECT calendar_token FROM users WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['user']['id']]);
        $calendarToken = $stmt->fetchColumn() ?: null;

        $vapidPublicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? '';

        return new Response(body: $this->render('profile', [
            'success' => $success,
            'error' => $error,
            'calendar_token' => $calendarToken,
            'vapid_public_key' => $vapidPublicKey
        ]));
    }

    #[AuthMiddleware('user, responsable, admin, accueil')]
    #[RouteAttribute(method: "POST", path: "/profile", name: "profile.submit")]
    public function profileSubmit(ServerRequestInterface $request): Response
    {
        $parsedBody = $request->getParsedBody();
        $username = trim($parsedBody['username'] ?? '');
        $password = $parsedBody['password'] ?? '';
        $passwordConfirm = $parsedBody['password_confirm'] ?? '';

        if (!$username) {
            $_SESSION['profile_error'] = "Le nom d'utilisateur ne peut pas être vide.";
            return $this->redirect('profile');
        }

        $pdo = $this->database->getConnection();
        $uid = $_SESSION['user']['id'];

        try {
            // 1. Vérifier si le nouveau nom d'utilisateur est déjà pris
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = :username AND id != :id");
            $stmtCheck->execute(['username' => $username, 'id' => $uid]);
            if ($stmtCheck->fetch()) {
                $_SESSION['profile_error'] = "Le nom d'utilisateur '$username' est déjà utilisé par un autre compte.";
                return $this->redirect('profile');
            }

            // 2. Mettre à jour l'username
            $stmtUpdate = $pdo->prepare("UPDATE users SET username = :username WHERE id = :id");
            $stmtUpdate->execute(['username' => $username, 'id' => $uid]);
            $_SESSION['user']['username'] = $username;

            // 3. Mettre à jour le mot de passe s'il est renseigné
            if (!empty($password)) {
                if ($password !== $passwordConfirm) {
                    $_SESSION['profile_error'] = "Le mot de passe et sa confirmation ne correspondent pas.";
                    return $this->redirect('profile');
                }
                
                $nowStr = date('Y-m-d H:i:s');
                $passHash = password_hash($password, PASSWORD_DEFAULT);
                $stmtPass = $pdo->prepare("
                    UPDATE users 
                    SET password = :password, lastModifiedPassword = :last_mod 
                    WHERE id = :id
                ");
                $stmtPass->execute([
                    'password' => $passHash,
                    'last_mod' => $nowStr,
                    'id' => $uid
                ]);

                $_SESSION['user']['last_password_modified'] = $nowStr;

                // Déconnecter tous les autres appareils (remember_tokens)
                $stmtDelTokens = $pdo->prepare("DELETE FROM remember_tokens WHERE uid = :uid");
                $stmtDelTokens->execute(['uid' => $uid]);
            }

            $_SESSION['profile_success'] = "Votre profil a bien été mis à jour.";
        } catch (\Exception $e) {
            $_SESSION['profile_error'] = "Erreur lors de la mise à jour : " . $e->getMessage();
        }

        return $this->redirect('profile');
    }

    #[AuthMiddleware('user, responsable, admin, accueil')]
    #[RouteAttribute(method: "POST", path: "/profile/logout-devices", name: "profile.logout_devices")]
    public function logoutDevices(ServerRequestInterface $request): Response
    {
        $pdo = $this->database->getConnection();
        $uid = $_SESSION['user']['id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE uid = :uid");
            $stmt->execute(['uid' => $uid]);
            $_SESSION['profile_success'] = "Vous avez bien été déconnecté de tous vos autres appareils.";
        } catch (\Exception $e) {
            $_SESSION['profile_error'] = "Erreur lors de la déconnexion globale : " . $e->getMessage();
        }

        return $this->redirect('profile');
    }

    #[AuthMiddleware]
    #[RouteAttribute(method: "GET", path: "/force-change-password", name: "auth.force_change_password")]
    public function forceChangePassword(): Response
    {
        if ($_SESSION['user']['last_password_modified'] !== null) {
            return $this->redirect('index');
        }

        $error = $_SESSION['force_password_error'] ?? null;
        unset($_SESSION['force_password_error']);

        return new Response(body: $this->render('auth/force_change_password', [
            'error' => $error,
            'app_user' => null
        ]));
    }

    #[AuthMiddleware]
    #[RouteAttribute(method: "POST", path: "/force-change-password", name: "auth.force_change_password_submit")]
    public function forceChangePasswordSubmit(ServerRequestInterface $request): Response
    {
        if ($_SESSION['user']['last_password_modified'] !== null) {
            return $this->redirect('index');
        }

        $parsedBody = $request->getParsedBody();
        $password = $parsedBody['password'] ?? '';
        $passwordConfirm = $parsedBody['password_confirm'] ?? '';

        if (empty($password)) {
            $_SESSION['force_password_error'] = "Le mot de passe ne peut pas être vide.";
            return $this->redirect('auth.force_change_password');
        }

        if ($password !== $passwordConfirm) {
            $_SESSION['force_password_error'] = "Le nouveau mot de passe et sa confirmation ne correspondent pas.";
            return $this->redirect('auth.force_change_password');
        }

        $pdo = $this->database->getConnection();
        $uid = $_SESSION['user']['id'];

        try {
            $nowStr = date('Y-m-d H:i:s');
            $passHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE users 
                SET password = :password, lastModifiedPassword = :last_mod 
                WHERE id = :id
            ");
            $stmt->execute([
                'password' => $passHash,
                'last_mod' => $nowStr,
                'id' => $uid
            ]);

            $_SESSION['user']['last_password_modified'] = $nowStr;
            $_SESSION['success_message'] = "Votre mot de passe a bien été initialisé. Bienvenue sur votre espace !";

            return $this->redirect('index');
        } catch (\Exception $e) {
            $_SESSION['force_password_error'] = "Erreur lors du changement de mot de passe : " . $e->getMessage();
            return $this->redirect('auth.force_change_password');
        }
    }

    #[AuthMiddleware('user, responsable, admin, accueil')]
    #[RouteAttribute(method: "POST", path: "/profile/regenerate-calendar", name: "profile.regenerate_calendar")]
    public function regenerateCalendarToken(): Response
    {
        $pdo = $this->database->getConnection();
        $uid = $_SESSION['user']['id'];
        $newToken = bin2hex(random_bytes(32));

        try {
            $stmt = $pdo->prepare("UPDATE users SET calendar_token = :token WHERE id = :id");
            $stmt->execute(['token' => $newToken, 'id' => $uid]);
            $_SESSION['profile_success'] = "Votre lien de calendrier a bien été régénéré.";
        } catch (\Exception $e) {
            $_SESSION['profile_error'] = "Erreur lors de la régénération du jeton : " . $e->getMessage();
        }

        return $this->redirect('profile');
    }

    #[AuthMiddleware('user, responsable, admin, accueil')]
    #[RouteAttribute(method: "POST", path: "/profile/push-subscription", name: "profile.push_subscription")]
    public function savePushSubscription(ServerRequestInterface $request): Response
    {
        $parsedBody = json_decode((string)$request->getBody(), true);
        $endpoint = $parsedBody['endpoint'] ?? null;
        $p256dh = $parsedBody['keys']['p256dh'] ?? null;
        $auth = $parsedBody['keys']['auth'] ?? null;

        if (!$endpoint || !$p256dh || !$auth) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'Données de souscription invalides.']));
        }

        $pdo = $this->database->getConnection();
        $uid = $_SESSION['user']['id'];

        try {
            // Vérifier si l'endpoint existe déjà
            $stmtCheck = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint = :endpoint");
            $stmtCheck->execute(['endpoint' => $endpoint]);
            $existing = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE push_subscriptions 
                    SET uid = :uid, p256dh = :p256dh, auth = :auth 
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    'uid' => $uid,
                    'p256dh' => $p256dh,
                    'auth' => $auth,
                    'id' => $existing['id']
                ]);
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO push_subscriptions (uid, endpoint, p256dh, auth) 
                    VALUES (:uid, :endpoint, :p256dh, :auth)
                ");
                $stmtInsert->execute([
                    'uid' => $uid,
                    'endpoint' => $endpoint,
                    'p256dh' => $p256dh,
                    'auth' => $auth
                ]);
            }

            return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => true]));
        } catch (\Exception $e) {
            return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => $e->getMessage()]));
        }
    }
}
