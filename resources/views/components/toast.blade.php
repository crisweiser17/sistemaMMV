{{-- Toast global. Disparado por window.mmvToast(msg, type) ou pela flash session. --}}
<div
    x-data="{
        shown: false, message: '', type: 'success',
        show(message, type = 'success') {
            this.message = message; this.type = type; this.shown = true;
            clearTimeout(this._t); this._t = setTimeout(() => this.shown = false, 4000);
        }
    }"
    x-init="
        @if (session('success')) show(@js(session('success')), 'success'); @endif
        @if (session('error')) show(@js(session('error')), 'error'); @endif
        window.addEventListener('mmv-toast', e => show(e.detail.message, e.detail.type));
    "
    x-show="shown" x-transition
    style="display:none"
    class="fixed bottom-6 right-6 z-50 max-w-sm"
>
    <div class="rounded-lg shadow-lg px-4 py-3 text-sm text-white flex items-center gap-3"
         :class="type === 'error' ? 'bg-red-600' : (type === 'info' ? 'bg-mmv-600' : 'bg-emerald-600')">
        <span x-text="message"></span>
        <button @click="shown = false" class="ml-auto text-white/80 hover:text-white">&times;</button>
    </div>
</div>
