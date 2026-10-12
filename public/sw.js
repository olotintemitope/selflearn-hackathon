const CACHE_NAME = 'selflearn-offline-shell-v11';
const SHELL_FILES = ['/offline-study.html', '/css/offline-study.css', '/js/offline-study.js'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_FILES)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(
        keys.filter((key) => key.startsWith('selflearn-offline-shell-') && key !== CACHE_NAME)
            .map((key) => caches.delete(key)),
    )).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (url.pathname === '/offline-study' || url.pathname === '/offline-study/') {
        event.respondWith(caches.match('/offline-study.html'));
        return;
    }

    if (SHELL_FILES.includes(url.pathname)) {
        event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline-study.html')));
    }
});
