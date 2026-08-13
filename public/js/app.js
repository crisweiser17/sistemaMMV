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

    // ---- Traducao de erro de requisicao para mensagem util ----
    // mmvFetch anexa err.status e err.payload; sem isto o usuario so via
    // mensagens genericas ("Falha ao anexar arquivo.") sem saber o motivo.
    window.mmvErroMsg = function (err, fallback = 'Erro ao processar a requisição.') {
        if (!err) return fallback;

        // Erros que nunca chegam ao validator do Laravel: precisam de texto proprio.
        if (err.status === 401) return 'Sessão encerrada. Faça login novamente.';
        if (err.status === 419) return 'Sessão expirada. Recarregue a página e tente novamente.';
        if (err.status === 413) return 'Arquivo maior que o limite aceito pelo servidor (post_max_size do PHP).';
        if (err.status === 403) return 'Você não tem permissão para esta ação.';
        if (err.status === 404) return 'Registro não encontrado (a página pode estar desatualizada).';

        const p = err.payload;
        if (typeof p === 'string' && p.trim() !== '' && !p.trim().startsWith('<')) return p;

        if (p && typeof p === 'object') {
            // 422: { message, errors: { campo: [msg, ...] } } — a primeira msg e a acionavel.
            if (p.errors && typeof p.errors === 'object') {
                const primeiro = Object.values(p.errors).flat().filter(Boolean)[0];
                if (primeiro) return primeiro;
            }
            if (p.message) return p.message;
        }

        return fallback;
    };

    // ---- Toast global via evento de janela ----
    window.mmvToast = function (message, type = 'success') {
        window.dispatchEvent(new CustomEvent('mmv-toast', { detail: { message, type } }));
    };

    // Atalho: mostra o erro real de uma requisicao que falhou.
    window.mmvToastErro = function (err, fallback) {
        window.mmvToast(window.mmvErroMsg(err, fallback), 'error');
    };

    // ---- Aviso global: processo ja liberado que mudou ----
    // Canal publico: nao existe perfil de Producao nem de Compras no cadastro, entao
    // todo usuario logado recebe o aviso "Houve alteracao no processo NNNN".
    if (window.Echo) {
        try {
            window.Echo.channel('processos').listen('.processo.alterado', (e) => {
                window.mmvToast(e.mensagem || 'Houve alteração em um processo já liberado.', 'info');
            });
        } catch (e) {
            console.warn('[MMV] Canal de processos indisponivel:', e);
        }
    }
})();
