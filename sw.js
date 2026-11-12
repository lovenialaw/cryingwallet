self.addEventListener('install', (event) => {
    event.waitUntil(caches.open('cw-v1').then((cache) => cache.addAll([
        './index.php',
        './assets/app.css',
        './assets/app.js',
        './assets/icon.svg',
    ])));
    self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    event.respondWith(
        fetch(event.request).catch(() => caches.match(event.request))
    );
});
