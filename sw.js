const CACHE = 'cw-phone-v2';
const SHELL = [
    './',
    './index.html',
    './assets/app.css',
    './assets/app.js',
    './assets/store.js',
    './assets/logic.js',
    './assets/pdf-parse.js',
    './assets/vendor/pako.min.js',
    './assets/icon.svg',
    './assets/icon-192.png',
    './manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;
    event.respondWith(
        fetch(req).then((res) => {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(req, copy));
            return res;
        }).catch(() => caches.match(req).then((hit) => hit || caches.match('./index.html')))
    );
});
