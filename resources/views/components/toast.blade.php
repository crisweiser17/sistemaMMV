{{-- Toast global. Disparado por window.mmvToast(msg, type), pela flash session
     ou pelos erros de validacao (formularios que redirecionam com withErrors).
     Sem o bloco de $errors os formularios multipart falhavam em silencio. --}}
<div
    x-data="{
        shown: false, message: '', detail: '', type: 'success',
        show(message, type = 'success', detail = '') {
            this.message = message; this.detail = detail; this.type = type; this.shown = true;
            // Com linha de detalhe o toast fica mais tempo: e informacao para
            // anotar (nome do banco, caminho do backup), nao um simples 'ok'.
            clearTimeout(this._t);
            this._t = setTimeout(() => this.shown = false, detail ? 15000 : 4000);
        }
    }"
    x-init="
        @if (session('success')) show(@js(session('success')), 'success', @js(session('toast_detalhe', ''))); @endif
        @if (session('error')) show(@js(session('error')), 'error', @js(session('toast_detalhe', ''))); @endif
        @if ($errors->any()) show(@js($errors->first()), 'error'); @endif
        window.addEventListener('mmv-toast', e => show(e.detail.message, e.detail.type));
    "
    x-show="shown" x-transition
    style="display:none"
    class="fixed bottom-6 right-6 z-50 max-w-sm"
>
    <div class="rounded-lg shadow-lg px-4 py-3 text-sm text-white flex items-start gap-3"
         :class="type === 'error' ? 'bg-red-600' : (type === 'info' ? 'bg-mmv-600' : 'bg-emerald-600')">
        <div class="min-w-0">
            <span x-text="message"></span>
            {{-- Segunda linha: banco em uso e caminho do backup. Quebra em qualquer
                 ponto porque e nome de arquivo longo, sem espacos onde quebrar. --}}
            <span x-show="detail" x-text="detail" class="block mt-1 text-xs text-white/85 break-all"></span>
        </div>
        <button @click="shown = false" class="ml-auto text-white/80 hover:text-white">&times;</button>
    </div>
</div>
