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
