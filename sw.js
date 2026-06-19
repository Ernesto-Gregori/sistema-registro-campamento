/* ═══════════════════════════════════════════════════════════════
   SERVICE WORKER — Campamento Palabra de Vida v1.4
   FIXES: 
   - Eliminado doble fetch listener
   - Removido navigator.onLine (no existe en SW)
   - Estrategia: siempre red primero, caché como fallback
═══════════════════════════════════════════════════════════════ */

const SW_VERSION    = 'pv-camp-v2.1';
const CACHE_STATIC  = `${SW_VERSION}-static`;
const CACHE_DYNAMIC = `${SW_VERSION}-dynamic`;

const STATIC_ASSETS = [
    '/offline.php',
    '/assets/css/style.css',
    '/assets/js/script.js',
    '/assets/js/offline-sync.js',
    '/assets/img/icon-192.png',
    '/assets/img/icon-512.png',
    '/assets/vendor/fontawesome/css/all.min.css',
    '/assets/vendor/fontawesome/webfonts/fa-solid-900.woff2',
    '/assets/vendor/fontawesome/webfonts/fa-solid-900.ttf',
    '/assets/vendor/fontawesome/webfonts/fa-regular-400.woff2',
    '/assets/vendor/fontawesome/webfonts/fa-regular-400.ttf',
    '/assets/vendor/fontawesome/webfonts/fa-brands-400.woff2',
    '/assets/vendor/fontawesome/webfonts/fa-brands-400.ttf',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js'
];

const NEVER_CACHE = [
    '/logout.php',
    '/login.php',
    // Acciones destructivas — NUNCA cachear
    'action=delete',
    'action=edit',
    'action=update',
    'action=toggle',
    'action=activar',
    'action=desactivar',
    'action=add',
    'action=save',
    'action=crear',
    'action=editar',
    'action=eliminar',
    'accion=',          // ← parámetro usado en gestionar_usuarios.php
    'post_accion=',
    // APIs
    'api_save_',
    // Respuestas post-acción
    'message=',
    'error=',
    'success=',
    // Parámetros de edición individual
    '?id=',
    '&id=',
];

const BYPASS_ROUTES = [
    '/admin/',
    '/reportes/',
    '/backups/',
    '/encargado_consejeros/',
    '/admisiones/',
];
/* ── INSTALL ── */
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_STATIC)
            .then(cache =>
                Promise.allSettled(
                    STATIC_ASSETS.map(url =>
                        cache.add(url).catch(err =>
                            console.warn(`[SW] No se pudo cachear: ${url}`, err)
                        )
                    )
                )
            )
            .then(() => self.skipWaiting())
    );
});

/* ── ACTIVATE ── */
self.addEventListener('activate', event => {

    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(k => k !== CACHE_STATIC && k !== CACHE_DYNAMIC)
                    .map(k => {
                        return caches.delete(k);
                    })
            ))
            .then(() => self.clients.claim())
    );
});

/* ── FETCH — UN SOLO LISTENER ── */
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // ── BYPASS: Admin y Encargado — NUNCA interceptar ──
    const esBypass = BYPASS_ROUTES.some(ruta => url.pathname.startsWith(ruta));
    if (esBypass) return;

    // ── POST ──
    if (request.method === 'POST') {
        if (NEVER_CACHE.some(nc => request.url.includes(nc))) return;
        event.respondWith(manejarPostOffline(request));
        return;
    }

    if (request.method !== 'GET') return;
    if (!url.protocol.startsWith('http')) return;
    if (NEVER_CACHE.some(nc => request.url.includes(nc))) return;

    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    if (url.pathname.endsWith('.php')) {
        event.respondWith(networkFirst(request));
        return;
    }
});

/* ── SYNC ── */
self.addEventListener('sync', event => {
    if (event.tag === 'sync-consejerias') {
        event.waitUntil(
            syncPendingData('consejerias_pendientes', '/consejero/api_save_consejeria.php')
        );
    }
    if (event.tag === 'sync-acampantes') {
        event.waitUntil(
            syncPendingData('acampantes_pendientes', '/apoyo/api_save_acampante.php')
        );
    }
});

/* ── PUSH ── */
self.addEventListener('push', event => {
    const data = event.data?.json() ?? {};
    self.registration.showNotification(data.title || 'Campamento PV', {
        body: data.body || 'Tienes una actualización',
        icon: '/assets/img/icon-192.png',
        badge: '/assets/img/icon-72.png',
        tag: 'pv-notif'
    });
});

/* ═══════════════════════════════════════════════════════════════
   ESTRATEGIAS DE CACHÉ
═══════════════════════════════════════════════════════════════ */

function isStaticAsset(url) {
    const staticExts = ['.css', '.js', '.png', '.jpg', '.jpeg', '.svg', '.ico', '.woff', '.woff2'];
    return staticExts.some(ext => url.pathname.endsWith(ext))
        || url.hostname.includes('jsdelivr.net')
        || url.hostname.includes('cloudflare.com')
        || url.hostname.includes('fonts.googleapis.com')
        || url.hostname.includes('fonts.gstatic.com');
}

/* Cache First — para assets que no cambian */
async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_STATIC);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        console.warn('[SW] cacheFirst falló:', request.url);
        return new Response('Recurso no disponible', { status: 503 });
    }
}

/* Network First — para páginas PHP
   1. Intenta la red con timeout de 6s
   2. Si falla → busca en caché (exacto, luego por pathname)
   3. Si no hay caché → offline.php                          */
async function networkFirst(request) {
    try {
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 6000);

        const response = await fetch(request, {
            signal:   controller.signal,
            redirect: 'follow'
        });
        clearTimeout(timeoutId);

        // NO cachear:
        // - redirects (status 301/302)
        // - respuestas opacas
        // - páginas con ?message= (son respuestas post-acción)
        const url = new URL(request.url);
        const esRespuestaAccion = url.searchParams.has('message')   ||
                                  url.searchParams.has('error')     ||
                                  url.searchParams.has('success')   ||
                                  url.searchParams.has('id')        ||
                                  url.searchParams.has('accion')    ||
                                  url.searchParams.has('action')    ||
                                  url.searchParams.has('acampante_id') ||
                                  url.searchParams.has('semana_id') ||
                                  url.searchParams.has('pago_id');

        if (response.ok &&
            response.type !== 'opaqueredirect' &&
            !response.redirected &&
            !esRespuestaAccion) {
            const cache = await caches.open(CACHE_DYNAMIC);
            cache.put(request, response.clone());
        } else if (esRespuestaAccion) {
            console.log('[SW] ↩ Respuesta de acción — no cacheada:', url.pathname);
        }

        return response;

    } catch (err) {
        console.warn('[SW] Red falló, buscando caché:', request.url);
        return buscarEnCache(request);
    }
}

// Parámetros que indican que es una acción — nunca servir desde caché
const ACTION_PARAMS = ['id', 'action', 'accion', 'pago_id', 'acampante_id',
                       'message', 'error', 'success', 'semana_id'];

async function buscarEnCache(request) {
    const url = new URL(request.url);

    // Si la URL tiene parámetros de acción → NO buscar en caché
    // Ir directo a offline.php para no servir página incorrecta
    const tieneParamAccion = ACTION_PARAMS.some(p => url.searchParams.has(p));
    if (tieneParamAccion) {
        console.warn('[SW] URL con parámetros de acción sin red → offline.php:', url.pathname);
        const offlinePage = await caches.match('/offline.php');
        return offlinePage || new Response(
            '<!DOCTYPE html><html><body><h1>Sin conexión</h1><p>Esta página requiere conexión.</p></body></html>',
            { headers: { 'Content-Type': 'text/html' } }
        );
    }

    // 1. URL exacta (sin parámetros de acción)
    let cached = await caches.match(request);
    if (cached && cached.ok && cached.type !== 'opaqueredirect') {
        return cached;
    }

    // 2. Solo pathname (sin query params)
    cached = await caches.match(url.pathname);
    if (cached && cached.ok && cached.type !== 'opaqueredirect') {
        return cached;
    }

    // 3. Buscar en todos los caches por pathname
    const cacheKeys = await caches.keys();
    for (const cacheName of cacheKeys) {
        const store   = await caches.open(cacheName);
        const allKeys = await store.keys();
        const match   = allKeys.find(r => {
            const cachedUrl = new URL(r.url);
            // Solo usar caché si la URL cacheada tampoco tenía parámetros de acción
            const cachedTieneAccion = ACTION_PARAMS.some(p => cachedUrl.searchParams.has(p));
            return cachedUrl.pathname === url.pathname && !cachedTieneAccion;
        });
        if (match) {
            const found = await store.match(match);
            if (found) {
                return found;
            }
        }
    }

    // 4. Sin caché → offline.php
    console.warn('[SW] ✗ Sin caché → offline.php para:', url.pathname);
    const offlinePage = await caches.match('/offline.php');
    return offlinePage || new Response(
        '<!DOCTYPE html><html><body><h1>Sin conexión</h1></body></html>',
        { headers: { 'Content-Type': 'text/html' } }
    );
}

/* ═══════════════════════════════════════════════════════════════
   INDEXEDDB — Para sincronización background
═══════════════════════════════════════════════════════════════ */

async function syncPendingData(storeName, endpoint) {
    const db    = await openDB();
    const items = await getAllFromStore(db, storeName);

    for (const item of items) {
        try {
            const response = await fetch(endpoint, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(item.data)
            });
            if (response.ok) {
                await deleteFromStore(db, storeName, item.id);
            } else {
                console.warn(`[SW] ✗ Error servidor ID ${item.id}:`, response.status);
            }
        } catch (err) {
            console.warn(`[SW] ✗ Sin red, abortando sync:`, err.message);
            break;
        }
    }
}

function openDB() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open('CampamentoPV', 3);
        req.onupgradeneeded = e => {
            const database = e.target.result;
            ['consejerias_pendientes', 'acampantes_pendientes'].forEach(storeName => {
                if (database.objectStoreNames.contains(storeName)) {
                    database.deleteObjectStore(storeName);
                }
                database.createObjectStore(storeName, { keyPath: 'id', autoIncrement: true });
            });
        };
        req.onsuccess = e => resolve(e.target.result);
        req.onerror   = e => reject(e.target.error);
    });
}

function getAllFromStore(db, storeName) {
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(storeName, 'readonly');
        const req = tx.objectStore(storeName).getAll();
        req.onsuccess = e => resolve(e.target.result || []);
        req.onerror   = e => reject(e.target.error);
    });
}

function deleteFromStore(db, storeName, id) {
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(storeName, 'readwrite');
        const req = tx.objectStore(storeName).delete(id);
        req.onsuccess = () => resolve();
        req.onerror   = e  => reject(e.target.error);
    });
}

async function manejarPostOffline(request) {
    // Clonar ANTES de consumir el body
    const requestClone = request.clone();
    const bodyClone    = request.clone();

    try {
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 5000);
        const response   = await fetch(requestClone, { signal: controller.signal });
        clearTimeout(timeoutId);
        return response;
    } catch (err) {
        console.warn('[SW] POST falló offline, notificando al cliente:', request.url);

        // Leer el body como texto
        const body = await bodyClone.text().catch(() => '');
        
        // Intentar parsear como URLEncoded primero, si falla enviar raw
        let parsedData = {};
        let parseOk = false;
        
        try {
            const params = new URLSearchParams(body);
            // Verificar que realmente tiene campos parseables
            if ([...params.keys()].length > 0 && !body.includes('WebKitFormBoundary')) {
                params.forEach((value, key) => { parsedData[key] = value; });
                parseOk = true;
            }
        } catch (e) {}
        
        // Si era multipart (FormData), no podemos parsearlo en el SW
        // Solo enviamos la URL para que offline-sync.js tome los datos del DOM
        const clients = await self.clients.matchAll({ type: 'window' });
        clients.forEach(client => {
            client.postMessage({
                type      : 'POST_FAILED_OFFLINE',
                url       : request.url,
                body      : parseOk ? body : '',
                parsedData: parseOk ? parsedData : {},
                isMultipart: body.includes('WebKitFormBoundary')
            });
        });

        // Devolver respuesta vacía para que el navegador no muestre error
        return new Response(
            JSON.stringify({ offline: true, saved: false }),
            {
                status  : 200,
                headers : { 'Content-Type': 'application/json' }
            }
        );
    }
}

// Escuchar mensajes del cliente para cachear URLs específicas
self.addEventListener('message', event => {
    // Forzar activación cuando hay SW en espera
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
        return;
    }

    if (event.data?.type === 'CACHE_URL') {
        const url = event.data.url;
        caches.open(CACHE_DYNAMIC)
            .then(cache =>
                fetch(url, { credentials: 'include' })
                    .then(res => {
                        if (res.ok) {
                            cache.put(url, res.clone());
                        }
                    })
                    .catch(() => console.warn('[SW] No se pudo cachear:', url))
            );
    }
});