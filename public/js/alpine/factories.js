/*
 * MMV — Fabricas Alpine reutilizaveis (sem build step).
 * Funcoes globais consumidas via x-data="nomeDaFabrica(...)".
 */

/**
 * liveResource — estado reativo ligado a um endpoint JSON (motor) e, opcionalmente,
 * a um canal Echo/Reverb. Quando um evento chega, refaz o fetch => a tela reflete
 * a mudanca de outros usuarios SEM reload. Reuso central da reatividade async.
 *
 * Uso: x-data="liveResource({ url:'/demandas/data', channel:'demandas', events:['.demanda.atualizada'] })"
 */
window.liveResource = function (config) {
    return {
        items: config.initial || [],
        meta: {},
        loading: false,
        error: null,

        _lastParams: {},

        async load(params = {}) {
            this._lastParams = params;
            this.loading = true;
            this.error = null;
            try {
                const qs = new URLSearchParams(params).toString();
                const url = config.url + (qs ? (config.url.includes('?') ? '&' : '?') + qs : '');
                const data = await window.mmvFetch(url);
                this.items = data.data ?? data;
                this.meta = data.meta ?? {};
            } catch (e) {
                this.error = e;
                window.mmvToast('Falha ao carregar dados.', 'error');
            } finally {
                this.loading = false;
            }
        },

        init() {
            this.load(config.params || {});
            if (window.Echo && config.channel) {
                const channel = window.Echo.channel(config.channel);
                (config.events || ['.updated']).forEach((ev) => {
                    channel.listen(ev, () => this.load(this._lastParams || config.params || {}));
                });
                this._channelName = config.channel;
            }
        },

        destroy() {
            if (window.Echo && this._channelName) {
                window.Echo.leaveChannel(this._channelName);
            }
        },
    };
};

/**
 * itemsRepeater — lista dinamica de linhas de item (Cotacao / Liberacao).
 * Uso: x-data="itemsRepeater(@js($itens), @js($itemTemplate))"
 */
window.itemsRepeater = function (initial = [], template = {}) {
    return {
        itens: initial.length ? initial.map((i, idx) => ({ _key: idx, ...i })) : [{ _key: 0, ...template }],
        _seq: initial.length || 1,

        add() {
            this.itens.push({ _key: this._seq++, numero_item: this.itens.length + 1, ...structuredClone(template) });
        },
        remove(key) {
            this.itens = this.itens.filter((i) => i._key !== key);
            this.renumerar();
        },
        renumerar() {
            this.itens.forEach((i, idx) => (i.numero_item = idx + 1));
        },
        validar() {
            // Validacao client-side basica: descricao e quantidade obrigatorias.
            return this.itens.every((i) => (i.descricao || '').trim() !== '' && Number(i.quantidade) > 0);
        },
    };
};

/**
 * dependentSelects — dropdowns encadeados componente -> categoria -> tipo -> material.
 * Consome os endpoints JSON de /admin (os "motores" de apoio).
 * Uso: x-data="dependentSelects(@js($linha))"
 */
window.dependentSelects = function (initial = {}) {
    return {
        tipo_componente: initial.tipo_componente || '',
        categoria_componente_id: initial.categoria_componente_id || '',
        tipo_componente_id: initial.tipo_componente_id || '',
        material_id: initial.material_id || '',

        categorias: [],
        tipos: [],
        materiais: [],

        async init() {
            if (this.tipo_componente) await this.carregarCategorias(false);
            if (this.categoria_componente_id) await this.carregarTipos(false);
            if (this.tipo_componente_id) await this.carregarMateriais(false);
        },

        /**
         * Busca uma lista de apoio. Falha nao pode ser silenciosa: sem isso o select
         * dependente fica travado (:disabled) para sempre e o usuario nao sabe por que.
         */
        async buscarLista(url, rotulo) {
            try {
                return await window.mmvFetch(url);
            } catch (e) {
                window.mmvToast(`Falha ao carregar ${rotulo}. Verifique os Cadastros e tente novamente.`, 'error');

                return [];
            }
        },

        async onComponenteChange() {
            this.categoria_componente_id = this.tipo_componente_id = this.material_id = '';
            this.tipos = this.materiais = [];
            await this.carregarCategorias();
        },
        async carregarCategorias(reset = true) {
            if (!this.tipo_componente) return (this.categorias = []);
            this.categorias = await this.buscarLista(
                `/admin/categorias?tipo=${encodeURIComponent(this.tipo_componente)}`,
                'as categorias'
            );
            if (reset) this.categoria_componente_id = '';
        },
        async onCategoriaChange() {
            this.tipo_componente_id = this.material_id = '';
            this.materiais = [];
            await this.carregarTipos();
        },
        async carregarTipos(reset = true) {
            if (!this.categoria_componente_id) return (this.tipos = []);
            this.tipos = await this.buscarLista(
                `/admin/tipos?categoria_id=${encodeURIComponent(this.categoria_componente_id)}`,
                'os tipos'
            );
            if (reset) this.tipo_componente_id = '';
        },
        async onTipoChange() {
            this.material_id = '';
            await this.carregarMateriais();
        },
        async carregarMateriais(reset = true) {
            if (!this.tipo_componente_id) return (this.materiais = []);
            this.materiais = await this.buscarLista(
                `/admin/lookup/materiais?tipo_id=${encodeURIComponent(this.tipo_componente_id)}`,
                'os materiais'
            );
            if (reset) this.material_id = '';
        },
    };
};

/**
 * unidadesDoCliente — lista de unidades (plantas) do cliente selecionado.
 * Consome o lookup JSON /admin/lookup/unidades, no mesmo padrao de dependentSelects.
 * Uso: x-data="Object.assign(unidadesDoCliente(), { ... })" e
 *      @change="carregarUnidades($event.target.value)" no select de cliente.
 */
window.unidadesDoCliente = function () {
    return {
        unidades: [],

        /**
         * Recarrega a lista. Falha nao pode ser silenciosa: sem isso o select de
         * unidade fica vazio e o usuario nao sabe por que.
         */
        async carregarUnidades(clienteId) {
            if (!clienteId) {
                this.unidades = [];

                return;
            }
            try {
                this.unidades = await window.mmvFetch(
                    `/admin/lookup/unidades?cliente_id=${encodeURIComponent(clienteId)}`
                );
            } catch (e) {
                this.unidades = [];
                window.mmvToast('Falha ao carregar as unidades do cliente.', 'error');
            }
        },
    };
};

/**
 * niLookup — busca historico de cotacoes/liberacoes por NI e abre modal.
 * Uso: x-data="niLookup()" e @blur="buscar(item.ni)" no campo NI.
 */
window.niLookup = function () {
    return {
        aberto: false,
        carregando: false,
        ni: '',
        historico: [],

        async buscar(ni) {
            if (!ni) return;
            this.ni = ni;
            this.carregando = true;
            try {
                this.historico = await window.mmvFetch(`/cotacao/busca-ni?ni=${encodeURIComponent(ni)}`);
                if (this.historico.length) this.aberto = true;
            } catch (e) {
                window.mmvToast('Falha na busca por NI.', 'error');
            } finally {
                this.carregando = false;
            }
        },
        fechar() {
            this.aberto = false;
        },
    };
};
