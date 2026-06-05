const BASE_PREFIX = new URL(self.registration.scope).pathname.replace(/\/$/, '');
const CACHE_NAME = 'planning-benevoles-v1';

// Fichiers statiques à mettre en cache immédiatement (Pre-caching)
const ASSETS_TO_CACHE = [
    BASE_PREFIX + '/js/app.js',
    BASE_PREFIX + '/dist/app.webmanifest',
    'https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
    'https://cdn.jsdelivr.net/npm/flatpickr',
    'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js'
];

// Fonction utilitaire pour identifier si une URL correspond à un asset statique
function isAsset(url) {
    return /\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|json|webmanifest)$/i.test(url) || url.includes('flatpickr');
}

// Installation du Service Worker et mise en cache des ressources essentielles
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[Service Worker] Pré-mise en cache des ressources');
            return cache.addAll(ASSETS_TO_CACHE);
        }).then(() => self.skipWaiting())
    );
});

// Activation du Service Worker et nettoyage des anciens caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        console.log('[Service Worker] Suppression de l\'ancien cache', cache);
                        return caches.delete(cache);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Interception des requêtes réseau (Stratégie réseau en premier pour les assets, pas de cache pour le PHP)
self.addEventListener('fetch', (event) => {
    // Ne pas intercepter les requêtes non-GET (POST d'inscription, modifications, etc.)
    if (event.request.method !== 'GET') {
        return;
    }

    const url = event.request.url;

    // Si ce n'est pas un asset statique (ex: route PHP dynamique comme /, /profile, /admin/pointage)
    if (!isAsset(url)) {
        // Stratégie : Réseau uniquement (Network Only), pas d'interférence avec le cache
        event.respondWith(fetch(event.request));
        return;
    }

    // Stratégie pour les assets statiques : Réseau en premier, avec repli sur le cache
    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response.status === 200) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        // Mettre en cache uniquement les requêtes de l'application (dans son scope) ou les CDN statiques
                        if (event.request.url.startsWith(self.registration.scope) || event.request.url.includes('cdn')) {
                            cache.put(event.request, responseClone);
                        }
                    });
                }
                return response;
            })
            .catch(() => {
                return caches.match(event.request);
            })
    );
});

// Gestion des notifications push
self.addEventListener('push', (event) => {
    let data = { title: 'Planning Bénévoles', body: 'Vous avez reçu une notification.' };
    
    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data = { title: 'Planning Bénévoles', body: event.data.text() };
        }
    }

    const options = {
        body: data.body,
        icon: BASE_PREFIX + '/icon-192.png',
        badge: BASE_PREFIX + '/icon-192.png',
        data: data.url || (BASE_PREFIX + '/'),
        vibrate: [100, 50, 100],
        actions: [
            { action: 'open', title: 'Ouvrir' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// Clic sur une notification
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = event.notification.data;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Si un onglet est déjà ouvert avec l'URL, on le focus
            for (const client of clientList) {
                if (client.url === targetUrl && 'focus' in client) {
                    return client.focus();
                }
            }
            // Sinon on ouvre une nouvelle fenêtre
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});
