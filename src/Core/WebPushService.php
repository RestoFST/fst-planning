<?php

namespace App\Core;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class WebPushService
{
    /**
     * Génère une paire de clés VAPID (courbe elliptique prime256v1) compatibles avec les navigateurs modernes.
     */
    public static function generateVapidKeys(): array
    {
        $config = [
            "curve_name" => "prime256v1",
            "private_key_type" => OPENSSL_KEYTYPE_EC,
        ];
        
        $keyResource = openssl_pkey_new($config);
        if (!$keyResource) {
            throw new \Exception("Impossible de générer la clé EC avec OpenSSL. Vérifiez que l'extension OpenSSL est active.");
        }

        openssl_pkey_export($keyResource, $pem);
        $details = openssl_pkey_get_details($keyResource);
        
        if (!isset($details['ec'])) {
            throw new \Exception("La clé générée n'est pas une clé de type Courbe Elliptique.");
        }

        $ecDetails = $details['ec'];
        $x = str_pad($ecDetails['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($ecDetails['y'], 32, "\x00", STR_PAD_LEFT);
        $d = str_pad($ecDetails['d'], 32, "\x00", STR_PAD_LEFT);

        // VAPID public key must be the uncompressed point (0x04) followed by X and Y coordinates
        $publicKeyBytes = "\x04" . $x . $y;

        // Base64url encoding (sans padding, '-' au lieu de '+', '_' au lieu de '/')
        $base64urlEncode = function(string $data): string {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        return [
            'publicKey' => $base64urlEncode($publicKeyBytes),
            'privateKey' => $base64urlEncode($d)
        ];
    }

    /**
     * Met à jour les clés VAPID dans le fichier .env de l'application.
     */
    public static function updateEnvKeys(string $publicKey, string $privateKey): void
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($envFile)) {
            file_put_contents($envFile, "");
        }

        $content = file_get_contents($envFile);

        $updateOrAdd = function(string $content, string $key, string $value): string {
            $pattern = "/^" . preg_quote($key) . "=(.*)$/m";
            $newLine = "{$key}={$value}";
            if (preg_match($pattern, $content)) {
                return preg_replace($pattern, $newLine, $content);
            } else {
                return $content . (empty($content) ? "" : "\n") . $newLine;
            }
        };

        $content = $updateOrAdd($content, 'VAPID_PUBLIC_KEY', $publicKey);
        $content = $updateOrAdd($content, 'VAPID_PRIVATE_KEY', $privateKey);

        file_put_contents($envFile, $content);
        
        // Mettre à jour les variables d'environnement PHP
        $_ENV['VAPID_PUBLIC_KEY'] = $publicKey;
        $_ENV['VAPID_PRIVATE_KEY'] = $privateKey;
    }

    public function __construct(
        private DB $database
    ) {}

    /**
     * Envoie une notification push à un utilisateur spécifique.
     */
    public function sendPushNotification(int $uid, string $title, string $body, string $url = null): void
    {
        $publicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? null;
        $privateKey = $_ENV['VAPID_PRIVATE_KEY'] ?? null;

        if (!$publicKey || !$privateKey) {
            Logger::warning("Impossible d'envoyer la notification push : clés VAPID manquantes.");
            return;
        }
        
        try {
            $pdo = $this->database->getConnection();

            $stmt = $pdo->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE uid = :uid");
            $stmt->execute(['uid' => $uid]);
            $subscriptions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($subscriptions)) {
                return; // Pas de souscription pour cet utilisateur
            }

            $authConfig = [
                'VAPID' => [
                    'subject' => 'mailto:contact@resto-fst.fr',
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ];

            $webPush = new WebPush($authConfig);
            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'url' => $url
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subscriptions as $sub) {
                $subscription = Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'publicKey' => $sub['p256dh'],
                    'authToken' => $sub['auth'],
                ]);

                $webPush->sendNotification($subscription, $payload);
            }

            $results = $webPush->flush();

            // Nettoyage des abonnements expirés ou invalides
            $stmtDelete = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = :endpoint");
            foreach ($results as $report) {
                if (!$report->isSuccess()) {
                    $stmtDelete->execute(['endpoint' => $report->getEndpoint()]);
                    Logger::info("Souscription push expirée ou invalide supprimée", ['endpoint' => $report->getEndpoint()]);
                }
            }
        } catch (\Exception $e) {
            Logger::error("Erreur d'envoi push : " . $e->getMessage());
        }
    }
}
