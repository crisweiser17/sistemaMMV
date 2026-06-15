/*
 * MMV — bootstrap global (sem build step).
 * Inicializa o Laravel Echo (Reverb) e expoe helpers de fetch reutilizaveis.
 */
(function () {
    const cfg = window.MMV_CONFIG || {};

    // ---- Echo / Reverb ----
    // O build IIFE expoe a CLASSE em window.Echo; capturamos antes de sobrescrever
    // com a INSTANCIA usada pela aplicacao.
    try {
        const EchoClass = window.Echo;
        if (EchoClass && window.Pusher && cfg.reverb && cfg.reverb.key) {
            window.Echo = new EchoClass({
                broadcaster: 'reverb',
                key: cfg.reverb.key,
                wsHost: cfg.reverb.host,
                wsPort: cfg.reverb.port,
                wssPort: cfg.reverb.port,
                forceTLS: cfg.reverb.scheme === 'https',
                enabledTransports: ['ws', 'wss'],
            });
        } else {
            window.Echo = null;
        }
    } catch (e) {
        console.warn('[MMV] Echo nao inicializado:', e);
        window.Echo = null;
    }

    // ---- Helper de requisicoes JSON (CSRF + credenciais de sessao) ----
    // Centraliza o consumo dos "motores" (endpoints JSON) pelo Alpine.
    window.mmvFetch = async function (url, options = {}) {
        const opts = Object.assign(
            {
                headers: {},
                credentials: 'same-origin',
            },
            options
        );
        opts.headers = Object.assign(
            {
                'X-CSRF-TOKEN': cfg.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            opts.headers
        );

        // Serializa body objeto como JSON (a menos que seja FormData)
        if (opts.body && !(opts.body instanceof FormData) && typeof opts.body === 'object') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }

        const res = await fetch(url, opts);
        const isJson = (res.headers.get('content-type') || '').includes('application/json');
        const data = isJson ? await res.json() : await res.text();

        if (!res.ok) {
            const err = new Error('Erro de requisicao');
            err.status = res.status;
            err.payload = data;
            throw err;
        }
        return data;
    };

    // ---- Toast global via evento de janela ----
    window.mmvToast = function (message, type = 'success') {
        window.dispatchEvent(new CustomEvent('mmv-toast', { detail: { message, type } }));
    };
})();
