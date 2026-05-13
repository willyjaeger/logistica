const CACHE = 'logax-v3';

const STATIC_ASSETS = [
    '/ops/assets/css/app.css',
    '/ops/assets/img/logo.svg',
    '/ops/assets/img/icon-192.png',
    '/ops/assets/img/icon-512.png',
    '/ops/favicon.ico',
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', e => {
    const req = e.request;
    const url = new URL(req.url);

    // CDN externos: siempre red
    if (!url.hostname.includes('localhost') && url.hostname !== location.hostname) return;

    // Archivos estáticos: cache first
    if (/\.(css|js|png|jpg|jpeg|svg|ico|woff2?)(\?|$)/.test(url.pathname)) {
        e.respondWith(
            caches.match(req).then(cached => {
                if (cached) return cached;
                return fetch(req).then(res => {
                    caches.open(CACHE).then(c => c.put(req, res.clone()));
                    return res;
                });
            })
        );
        return;
    }

    // Páginas PHP: solo red, nunca cachear
    if (/\.php(\?|$)/.test(url.pathname) || url.pathname.endsWith('/')) return;
});
