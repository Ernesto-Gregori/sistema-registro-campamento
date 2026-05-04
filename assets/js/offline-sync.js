/* ═══════════════════════════════════════════════════════════════
   OFFLINE SYNC — Campamento Palabra de Vida
   Maneja: IndexedDB · detección de conexión · UI de estado
           · interceptar formularios · sincronización manual
═══════════════════════════════════════════════════════════════ */

const OfflineSync = (() => {

    const DB_NAME    = 'CampamentoPV';
    const DB_VERSION = 3;
    const STORES     = {
        consejerias: 'consejerias_pendientes',
        acampantes:  'acampantes_pendientes'
    };

    let db = null;

    /* ─────────────────────────────────────────────────────────
       INICIALIZACIÓN
    ───────────────────────────────────────────────────────── */
    async function init() {
        try {
            db = await openDB();
            registrarServiceWorker();
            iniciarDeteccionConexion();
            mostrarEstadoConexion();
            await mostrarContadorPendientes();
            interceptarFormularios();
            cachearPaginaActual();      // ← nuevo
            console.log('[OfflineSync] Inicializado correctamente');
        } catch (err) {
            console.error('[OfflineSync] Error al inicializar:', err);
        }
    }
    
    // Páginas cacheables por rol — Admin y Encargado NUNCA se cachean
    const PAGINAS_OFFLINE_POR_ROL = {
        '/consejero/': [
            'dashboard.php',
            'mis_acampantes.php',
            'estadisticas.php',
        ],
        '/apoyo/': [
            'dashboard.php',
            'lista_acampantes.php',
            'registrar_acampante.php',
        ],
        '/encargado_consejeros/': [
            'dashboard.php',
            'acampantes.php',
        ],
    };
    
    function cachearPaginaActual() {
        if (!('caches' in window)) return;
    
        const url  = window.location.href;
        const path = window.location.pathname;
    
        if (url.includes('login') || url.includes('logout')) return;
    
        // Determinar si el rol actual tiene páginas cacheables
        let paginasCacheables = [];
        for (const [ruta, paginas] of Object.entries(PAGINAS_OFFLINE_POR_ROL)) {
            if (path.startsWith(ruta)) {
                paginasCacheables = paginas;
                break;
            }
        }
    
        // Admin y encargado — salir sin cachear
        if (paginasCacheables.length === 0) {
            console.log('[OfflineSync] Página de admin/encargado — no cacheada:', path);
            return;
        }
    
        // Verificar si la página actual está en la lista
        const esCacheable = paginasCacheables.some(p => url.includes(p));
        if (!esCacheable) {
            console.log('[OfflineSync] Página no cacheable para este rol:', url);
            return;
        }
    
        // Cachear página actual
        caches.open('pv-camp-v1.8-dynamic')
            .then(cache => {
                cache.put(url, new Response(document.documentElement.outerHTML, {
                    headers: { 'Content-Type': 'text/html; charset=utf-8' }
                }));
                console.log('[OfflineSync] ✓ Página cacheada:', url);
            })
            .catch(err => console.warn('[OfflineSync] No se pudo cachear:', err));
    
        // Si estamos en mis_acampantes → pre-cachear consejerías
        if (url.includes('mis_acampantes')) {
            preCachearConsejeriasDeAcampantes();
        }
    
        cachearLinksVisibles();
    }
    
    function preCachearConsejeriasDeAcampantes() {
        // Buscar todos los botones/links de +Consejería en la página
        const links = document.querySelectorAll(
            'a[href*="consejeria"], a[href*="acampante_id"], button[data-url*="acampante_id"]'
        );
    
        if (links.length === 0) {
            // Si no hay links con href, buscar por texto del botón
            const botones = Array.from(document.querySelectorAll('a')).filter(a =>
                a.textContent.includes('Consejería') ||
                a.textContent.includes('consejeria') ||
                a.href?.includes('acampante_id')
            );
            botones.forEach(btn => cachearURLEnSW(btn.href));
            return;
        }
    
        links.forEach(link => {
            const href = link.href || link.dataset.url;
            if (href) cachearURLEnSW(href);
        });
    }
    
    function cachearLinksVisibles() {
        // Detectar rol según la URL actual
        const path = window.location.pathname;
    
        const PAGINAS_POR_ROL = {
            '/consejero/': [
                'dashboard.php',
                'mis_acampantes.php',
                'estadisticas.php',
            ],
            '/apoyo/': [
                'dashboard.php',
                'lista_acampantes.php',
                'registrar_acampante.php',
            ],
            '/encargado_consejeros/': [
                'dashboard.php',
                'acampantes.php',
            ],
            // Admin NO se cachea
            '/admin/': [],
        };
    
        // Determinar qué lista aplica
        let paginasCacheables = [];
        for (const [ruta, paginas] of Object.entries(PAGINAS_POR_ROL)) {
            if (path.startsWith(ruta)) {
                paginasCacheables = paginas;
                break;
            }
        }
    
        // Si no hay lista o está vacía, no cachear nada
        if (paginasCacheables.length === 0) {
            console.log('[OfflineSync] Rol sin páginas cacheables:', path);
            return;
        }
    
        const links  = document.querySelectorAll('a[href]');
        const origen = window.location.origin;
    
        links.forEach(link => {
            const href = link.href;
            if (!href.startsWith(origen)) return;
            if (href.includes('logout') || href.includes('login')) return;
    
            const esCacheable = paginasCacheables.some(p => href.includes(p));
            if (!esCacheable) return;
    
            cachearURLEnSW(href);
        });
    }
    
    function cachearURLEnSW(url) {
        if (!url || !url.startsWith('http')) return;
    
        // Enviar mensaje al SW para que cachee la URL con credenciales
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({
                type: 'CACHE_URL',
                url:  url
            });
        }
    }

    /* ─────────────────────────────────────────────────────────
       SERVICE WORKER
    ───────────────────────────────────────────────────────── */
    async function registrarServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            console.warn('[OfflineSync] Service Worker no soportado');
            return;
        }
        try {
            const reg = await navigator.serviceWorker.register('/sw.js?v=21', { scope: '/' });
            console.log('[OfflineSync] SW registrado:', reg.scope);

            // Escuchar mensajes del SW
            navigator.serviceWorker.addEventListener('message', async event => {
                // Sync completado
                if (event.data?.type === 'SYNC_COMPLETE') {
                    mostrarToast('✅ Datos sincronizados con el servidor', 'success');
                    mostrarContadorPendientes();
                    return;
                }
            
                // POST falló porque no hay internet → guardar en IndexedDB
                if (event.data?.type === 'POST_FAILED_OFFLINE') {
                    if (window._offlineFormHandled) {
                        console.log('[OfflineSync] POST ya manejado por interceptor, ignorando SW message');
                        return;
                    }
                
                    const url         = event.data.url        || '';
                    const parsedData  = event.data.parsedData || {};
                    const isMultipart = event.data.isMultipart || false;
                
                    console.log('[OfflineSync] POST capturado por SW (fallback):', url);
                
                    let dataObj = {};
                
                    if (isMultipart || Object.keys(parsedData).length === 0) {
                        // Body era multipart → leer campos directamente del formulario en el DOM
                        const form = document.querySelector('form#formConsejeria') ||
                                     document.querySelector('form');
                
                        if (form) {
                            const formData = new FormData(form);
                            formData.forEach((value, key) => {
                                if (dataObj[key] !== undefined) {
                                    if (!Array.isArray(dataObj[key])) dataObj[key] = [dataObj[key]];
                                    dataObj[key].push(value);
                                } else {
                                    dataObj[key] = value;
                                }
                            });
                            console.log('[OfflineSync] Datos tomados del DOM:', Object.keys(dataObj));
                        } else {
                            console.error('[OfflineSync] No se encontró el formulario en el DOM');
                            return;
                        }
                    } else {
                        dataObj = parsedData;
                    }
            
                    // Agregar metadatos
                    dataObj._url       = url;
                    dataObj._timestamp = new Date().toISOString();
            
                    const esConsejeria = url.includes('consejeria') ||
                                         url.includes('action=add') ||
                                         new URLSearchParams(new URL(url).search).has('acampante_id');
            
                    const esAcampante  = url.includes('acampante') && url.includes('registrar');
            
                    if (!esConsejeria && !esAcampante) return;
            
                    dataObj._tipo = esConsejeria ? 'consejeria' : 'acampante';
            
                    try {
                        const storeName = esConsejeria
                            ? STORES.consejerias
                            : STORES.acampantes;
            
                        const id = await guardarEnDB(storeName, dataObj);
                        await mostrarContadorPendientes();
            
                        // Mostrar modal de confirmación
                        const form      = document.querySelector('form');
                        const submitBtn = form?.querySelector('button[type="submit"]');
                        mostrarModalOffline(esConsejeria, id, submitBtn, form);
            
                    } catch (err) {
                        console.error('[OfflineSync] Error guardando POST offline:', err);
                        mostrarToast('❌ Error al guardar offline', 'error');
                    }
                }
            });
        } catch (err) {
            console.error('[OfflineSync] Error registrando SW:', err);
        }
    }

    /* ─────────────────────────────────────────────────────────
       DETECCIÓN DE CONEXIÓN
    ───────────────────────────────────────────────────── */
    function iniciarDeteccionConexion() {
        window.addEventListener('online',  alRecuperarConexion);
        window.addEventListener('offline', alPerderConexion);
    }

    function alRecuperarConexion() {
        console.log('[OfflineSync] ✅ Conexión recuperada');
        mostrarEstadoConexion();
        mostrarToast('✅ Conexión recuperada — sincronizando datos...', 'success');
        // Esperar 2 segundos para que la red estabilice
        setTimeout(sincronizarTodo, 2000);
    }

    function alPerderConexion() {
        console.log('[OfflineSync] ⚠️ Sin conexión');
        mostrarEstadoConexion();
        mostrarToast('⚠️ Sin conexión — los cambios se guardarán localmente', 'warning');
    }

    /* ─────────────────────────────────────────────────────────
       BARRA DE ESTADO DE CONEXIÓN
    ───────────────────────────────────────────────────── */
    function mostrarEstadoConexion() {
        let bar = document.getElementById('offline-status-bar');
    
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'offline-status-bar';
            bar.style.cssText = `
                position: fixed;
                bottom: 16px;
                left: 16px;
                z-index: 9997;
                padding: 6px 14px;
                font-size: 0.78rem;
                font-weight: 600;
                border-radius: 20px;
                display: flex;
                align-items: center;
                gap: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                transition: all 0.3s ease;
                font-family: 'Helvetica Neue', Arial, sans-serif;
                max-width: 280px;
            `;
            document.body.appendChild(bar);
        }

            if (navigator.onLine) {
                bar.style.cssText += `
                    background-color: #004f68;
                    color: #73d1f5;
                    border: 1px solid rgba(115,209,245,0.3);
                `;
                bar.innerHTML = `
                    <i class="fas fa-wifi" style="font-size:11px;"></i>
                    Conectado
                    <button onclick="OfflineSync.sincronizarTodo()"
                            style="background:rgba(115,209,245,0.15);
                                   border:1px solid rgba(115,209,245,0.3);
                                   color:#73d1f5;border-radius:10px;
                                   padding:1px 8px;font-size:0.72rem;cursor:pointer;
                                   margin-left:2px;">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                `;
                // Ocultar después de 3 segundos si está online
                setTimeout(() => {
                    if (navigator.onLine) {
                        bar.style.opacity = '0';
                        bar.style.transform = 'translateY(10px)';
                        setTimeout(() => { bar.style.display = 'none'; }, 300);
                    }
                }, 3000);
            } else {
                bar.style.display = 'flex';
                bar.style.opacity = '1';
                bar.style.transform = 'translateY(0)';
                bar.style.cssText += `
                    background-color: #e99531;
                    color: #ffffff;
                    border: 1px solid #cc7f22;
                `;
                bar.innerHTML = `
                    <i class="fas fa-wifi-slash" style="font-size:11px;"></i>
                    Sin conexión
                    <span id="pendientes-count"
                          style="background:rgba(255,255,255,0.2);
                                 border-radius:10px;padding:1px 7px;
                                 font-size:0.72rem;">
                    </span>
                `;
                mostrarContadorPendientes();
            }
    }

    /* ─────────────────────────────────────────────────────────
       TOASTS
    ───────────────────────────────────────────────────── */
    function mostrarToast(mensaje, tipo = 'info') {
        const colores = {
            success: { bg: '#004f68', border: '#007ea1', text: '#73d1f5' },
            warning: { bg: '#e99531', border: '#cc7f22', text: '#ffffff' },
            error:   { bg: '#c42a36', border: '#a3222c', text: '#ffffff' },
            info:    { bg: '#007ea1', border: '#004f68', text: '#ffffff' }
        };
        const c = colores[tipo] || colores.info;

        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            background-color: ${c.bg};
            color: ${c.text};
            border-left: 4px solid ${c.border};
            border-radius: 8px;
            padding: 12px 18px;
            font-size: 0.875rem;
            font-weight: 500;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            z-index: 9998;
            max-width: 320px;
            animation: slideInToast 0.3s ease;
            font-family: 'Helvetica Neue', Arial, sans-serif;
        `;
        toast.innerHTML = mensaje;
        document.body.appendChild(toast);

        // Agregar animación
        if (!document.getElementById('toast-style')) {
            const style = document.createElement('style');
            style.id = 'toast-style';
            style.textContent = `
                @keyframes slideInToast {
                    from { transform: translateX(110%); opacity: 0; }
                    to   { transform: translateX(0);    opacity: 1; }
                }
            `;
            document.head.appendChild(style);
        }

        setTimeout(() => toast.remove(), 4000);
    }

    /* ─────────────────────────────────────────────────────────
       INDEXEDDB
    ───────────────────────────────────────────────────── */
    function openDB() {
        return new Promise((resolve, reject) => {
            // Versión 3 — elimina índice 'synced' que causaba DataError
            const req = indexedDB.open(DB_NAME, 3);
    
            req.onupgradeneeded = e => {
                const database   = e.target.result;
                const oldVersion = e.oldVersion;
    
                console.log(`[OfflineSync] Migrando DB v${oldVersion} → v3`);
    
                // Eliminar stores viejos si existen con estructura rota
                Object.values(STORES).forEach(storeName => {
                    if (database.objectStoreNames.contains(storeName)) {
                        database.deleteObjectStore(storeName);
                        console.log(`[OfflineSync] Store recreado: ${storeName}`);
                    }
                });
    
                // Crear stores limpios — SIN índice 'synced'
                Object.values(STORES).forEach(storeName => {
                    const store = database.createObjectStore(storeName, {
                        keyPath: 'id', autoIncrement: true
                    });
                    store.createIndex('timestamp', 'timestamp', { unique: false });
                    console.log(`[OfflineSync] Store creado: ${storeName}`);
                });
            };
    
            req.onsuccess = e => {
                console.log('[OfflineSync] DB abierta correctamente v3');
                resolve(e.target.result);
            };
            req.onerror  = e => reject(e.target.error);
            req.onblocked = () => {
                console.warn('[OfflineSync] DB bloqueada — cierra otras pestañas');
            };
        });
    }

    function guardarEnDB(storeName, data) {
        return new Promise((resolve, reject) => {
            const tx    = db.transaction(storeName, 'readwrite');
            const store = tx.objectStore(storeName);
            const req   = store.add({
                data,
                timestamp : new Date().toISOString(),
                synced    : 0,   // ← número, no boolean
                intentos  : 0
            });
            req.onsuccess = e => resolve(e.target.result);
            req.onerror   = e => reject(e.target.error);
        });
    }

    function obtenerPendientes(storeName) {
        return new Promise((resolve, reject) => {
            const tx    = db.transaction(storeName, 'readonly');
            const store = tx.objectStore(storeName);
            // getAll() sin filtro — filtramos en JS (evita el DataError de IDBIndex)
            const req   = store.getAll();
            req.onsuccess = e => {
                const todos      = e.target.result || [];
                const pendientes = todos.filter(item => item.synced === 0);
                resolve(pendientes);
            };
            req.onerror = e => reject(e.target.error);
        });
    }

    function marcarComoSincronizado(storeName, id) {
        return new Promise((resolve, reject) => {
            const tx    = db.transaction(storeName, 'readwrite');
            const store = tx.objectStore(storeName);
            // Primero leer, luego actualizar synced = 1
            const getReq = store.get(id);
            getReq.onsuccess = e => {
                const item = e.target.result;
                if (!item) return resolve();
                item.synced = 1;
                const putReq    = store.put(item);
                putReq.onsuccess = () => resolve();
                putReq.onerror   = ev => reject(ev.target.error);
            };
            getReq.onerror = e => reject(e.target.error);
        });
    }

    async function contarPendientes() {
        let total = 0;
        for (const storeName of Object.values(STORES)) {
            const items = await obtenerPendientes(storeName);
            total += items.length;
        }
        return total;
    }

    async function mostrarContadorPendientes() {
        const total = await contarPendientes();
        const el    = document.getElementById('pendientes-count');
        if (el) {
            el.textContent = total > 0 ? `${total} pendiente(s)` : '';
        }

        // Badge en el navbar si hay pendientes
        let badge = document.getElementById('sync-badge');
        if (total > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.id = 'sync-badge';
                badge.style.cssText = `
                    background: #e99531;
                    color: white;
                    border-radius: 50%;
                    font-size: 10px;
                    font-weight: 700;
                    padding: 2px 6px;
                    position: fixed;
                    bottom: 20px;
                    left: 20px;
                    z-index: 9997;
                    cursor: pointer;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                `;
                badge.title = 'Datos pendientes de sincronizar';
                badge.onclick = () => sincronizarTodo();
                document.body.appendChild(badge);
            }
            badge.innerHTML = `<i class="fas fa-sync-alt"></i> ${total} pendiente(s)`;
        } else if (badge) {
            badge.remove();
        }
    }

    /* ─────────────────────────────────────────────────────────
       INTERCEPTAR FORMULARIOS
    ───────────────────────────────────────────────────── */
    function interceptarFormularios() {
        // Interceptar ANTES de que el form haga submit
        document.addEventListener('submit', async function (e) {
            
            // Si la página tiene su propio handler, no interferir NUNCA
            if (window._formHasOwnHandler) return;
    
            // Si hay internet, dejar pasar normal
            if (navigator.onLine) return;
            
            // Si la página ya tiene su propio handler (consejerias.php), no interferir
            if (window._formHasOwnHandler) return;
    
            const form   = e.target;
            const action = form.getAttribute('action') || window.location.pathname;
    
            // Detectar tipo por action, id del form, o URL actual
            const urlActual     = window.location.pathname;
            const urlParams     = new URLSearchParams(window.location.search);
            const esConsejeria  = action.includes('consejeria')
                               || action.includes('add')
                               || form.id?.includes('consejeria')
                               || form.id === 'formConsejeria'
                               || urlActual.includes('consejeria')
                               || urlParams.has('acampante_id')
                               || urlParams.get('action') === 'add';
    
            const esAcampante   = action.includes('acampante')
                               || action.includes('registrar')
                               || form.id?.includes('acampante')
                               || urlActual.includes('registrar_acampante');
    
            if (!esConsejeria && !esAcampante) return;
    
            // ── Bloquear el submit normal ──
            e.preventDefault();
            e.stopImmediatePropagation();
            
            // Marcar que ya fue manejado por el interceptor
            window._offlineFormHandled = true;
            setTimeout(() => { window._offlineFormHandled = false; }, 3000);
            
            // Mostrar feedback inmediato al usuario
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Guardando offline...';
            }
    
            // Serializar formulario
            const formData = new FormData(form);
            const dataObj  = {};
            formData.forEach((value, key) => {
                // Manejar campos múltiples (checkboxes con mismo name)
                if (dataObj[key] !== undefined) {
                    if (!Array.isArray(dataObj[key])) {
                        dataObj[key] = [dataObj[key]];
                    }
                    dataObj[key].push(value);
                } else {
                    dataObj[key] = value;
                }
            });
    
            // Metadatos para la sincronización
            dataObj._url        = window.location.href;
            dataObj._action     = action;
            dataObj._timestamp  = new Date().toISOString();
            dataObj._tipo       = esConsejeria ? 'consejeria' : 'acampante';
            dataObj._userAgent  = navigator.userAgent;
    
            try {
                const storeName = esConsejeria
                    ? STORES.consejerias
                    : STORES.acampantes;
    
                const id = await guardarEnDB(storeName, dataObj);
                await mostrarContadorPendientes();
    
                // Registrar background sync si está disponible
                if ('serviceWorker' in navigator && 'SyncManager' in window) {
                    try {
                        const reg = await navigator.serviceWorker.ready;
                        const tag = esConsejeria ? 'sync-consejerias' : 'sync-acampantes';
                        await reg.sync.register(tag);
                        console.log(`[OfflineSync] Background sync registrado: ${tag}`);
                    } catch (syncErr) {
                        console.warn('[OfflineSync] Background sync no disponible:', syncErr);
                    }
                }
    
                // Mostrar confirmación visual
                mostrarModalOffline(esConsejeria, id, submitBtn, form);
    
            } catch (err) {
                console.error('[OfflineSync] Error guardando en DB:', err);
                mostrarToast('❌ Error al guardar offline. Intenta de nuevo.', 'error');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-save"></i> Guardar Sesión';
                }
            }
        }, true); // ← true = capture phase, antes que otros listeners
    }
    
    // Interceptar navegación offline
    document.addEventListener('click', function(e) {
        if (navigator.onLine) return;
    
        const link = e.target.closest('a[href]');
        if (!link) return;
    
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
        if (href.includes('logout')) return;
    
        // Verificar si está en caché antes de navegar
        const urlDestino = new URL(href, window.location.origin).href;
    
        caches.match(urlDestino).then(cached => {
            if (cached) {
                // Está en caché → dejar navegar normal
                console.log('[OfflineSync] Navegación offline permitida (en caché):', href);
            } else {
                // No está en caché → mostrar aviso
                e.preventDefault();
                mostrarToast(
                    `⚠️ Sin conexión — esta página no está disponible offline`,
                    'warning'
                );
            }
        });
    }, true);

    /* ─────────────────────────────────────────────────────────
       SINCRONIZACIÓN MANUAL
    ───────────────────────────────────────────────────── */
    async function sincronizarTodo() {
        if (!navigator.onLine) {
            mostrarToast('⚠️ Sin conexión — no se puede sincronizar aún', 'warning');
            return;
        }

        const total = await contarPendientes();
        if (total === 0) {
            mostrarToast('✅ Todo sincronizado — no hay pendientes', 'success');
            return;
        }

        mostrarToast(`🔄 Sincronizando ${total} registro(s)...`, 'info');

        let exitosos = 0;
        let fallidos = 0;

        // Sincronizar consejerías
        const consejerias = await obtenerPendientes(STORES.consejerias);
        for (const item of consejerias) {
            const ok = await enviarAlServidor('/consejero/api_save_consejeria.php', item);
            if (ok) {
                await marcarComoSincronizado(STORES.consejerias, item.id);
                exitosos++;
            } else {
                fallidos++;
            }
        }

        // Sincronizar acampantes
        const acampantes = await obtenerPendientes(STORES.acampantes);
        for (const item of acampantes) {
            const ok = await enviarAlServidor('/apoyo/api_save_acampante.php', item);
            if (ok) {
                await marcarComoSincronizado(STORES.acampantes, item.id);
                exitosos++;
            } else {
                fallidos++;
            }
        }

        await mostrarContadorPendientes();

        if (fallidos === 0) {
            mostrarToast(`✅ ${exitosos} registro(s) sincronizados correctamente`, 'success');
        } else {
            mostrarToast(`⚠️ ${exitosos} OK · ${fallidos} fallaron — reintentando luego`, 'warning');
        }
    }

    async function enviarAlServidor(endpoint, item) {
        try {
            const response = await fetch(endpoint, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(item.data)
            });
            return response.ok;
        } catch (err) {
            console.warn('[OfflineSync] Error enviando a servidor:', err);
            return false;
        }
    }
    
        /* ─────────────────────────────────────────────────────────
       MODAL DE CONFIRMACIÓN OFFLINE
    ───────────────────────────────────────────────────── */
    function mostrarModalOffline(esConsejeria, id, submitBtn, form) {
        // Quitar modal previo si existe
        document.getElementById('offline-modal')?.remove();

        const tipo     = esConsejeria ? 'consejería' : 'registro de acampante';
        const iconoTipo = esConsejeria ? 'fa-comments' : 'fa-user-plus';
        const urlVolver = esConsejeria
            ? (document.referrer || '../consejero/mis_acampantes.php')
            : '../apoyo/lista_acampantes.php';

        const modal = document.createElement('div');
        modal.id = 'offline-modal';
        modal.style.cssText = `
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            font-family: 'Helvetica Neue', Arial, sans-serif;
        `;

        modal.innerHTML = `
            <div style="
                background: #ffffff;
                border-radius: 14px;
                padding: 2rem 1.5rem;
                max-width: 380px;
                width: 100%;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                animation: popIn 0.3s ease;
            ">
                <!-- Ícono -->
                <div style="
                    width: 64px; height: 64px;
                    background: linear-gradient(135deg, #004f68, #007ea1);
                    border-radius: 50%;
                    display: flex; align-items: center; justify-content: center;
                    margin: 0 auto 1.25rem;
                ">
                    <i class="fas ${iconoTipo}" style="color:#73d1f5; font-size:1.6rem;"></i>
                </div>

                <!-- Título -->
                <h5 style="color:#004f68; font-weight:700; margin-bottom:0.5rem;">
                    ¡Guardado localmente!
                </h5>

                <!-- Descripción -->
                <p style="color:#939598; font-size:0.88rem; line-height:1.6; margin-bottom:1.25rem;">
                    Tu ${tipo} fue guardado en este dispositivo.<br>
                    <strong style="color:#004f68;">Se sincronizará automáticamente</strong><br>
                    cuando vuelva la conexión a internet.
                </p>

                <!-- Info pendiente -->
                <div style="
                    background: rgba(0,79,104,0.06);
                    border: 1px solid rgba(0,79,104,0.15);
                    border-radius: 8px;
                    padding: 0.75rem 1rem;
                    margin-bottom: 1.5rem;
                    font-size: 0.82rem;
                    color: #007ea1;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                ">
                    <i class="fas fa-clock"></i>
                    ID temporal #${id} · pendiente de sincronizar
                </div>

                <!-- Botones -->
                <div style="display:flex; gap:0.75rem; justify-content:center;">
                    <button id="offline-modal-back" style="
                        background: #004f68;
                        color: white;
                        border: none;
                        border-radius: 8px;
                        padding: 0.6rem 1.4rem;
                        font-weight: 700;
                        font-size: 0.9rem;
                        cursor: pointer;
                        flex: 1;
                    ">
                        <i class="fas fa-arrow-left"></i> Volver
                    </button>
                    <button id="offline-modal-new" style="
                        background: transparent;
                        color: #007ea1;
                        border: 2px solid #007ea1;
                        border-radius: 8px;
                        padding: 0.6rem 1.4rem;
                        font-weight: 600;
                        font-size: 0.9rem;
                        cursor: pointer;
                        flex: 1;
                    ">
                        <i class="fas fa-plus"></i> Nueva
                    </button>
                </div>
            </div>
            <style>
                @keyframes popIn {
                    from { transform: scale(0.85); opacity: 0; }
                    to   { transform: scale(1);    opacity: 1; }
                }
            </style>
        `;

        document.body.appendChild(modal);

        // Botón Volver
        document.getElementById('offline-modal-back').addEventListener('click', () => {
            modal.remove();
            window.location.href = urlVolver;
        });

        // Botón Nueva — limpiar form y cerrar modal
        document.getElementById('offline-modal-new').addEventListener('click', () => {
            modal.remove();
            form.reset();
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Guardar Sesión';
            }
            // Scroll al inicio del form
            form.scrollIntoView({ behavior: 'smooth' });
        });
    }

    /* ─────────────────────────────────────────────────────────
       API PÚBLICA
    ───────────────────────────────────────────────────── */
    return { init, sincronizarTodo, mostrarToast };

})();

// Auto-iniciar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => OfflineSync.init());