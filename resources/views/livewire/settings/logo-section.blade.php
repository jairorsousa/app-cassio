<x-jr.card id="identidade-visual" data-logo-section>
    <h3 class="font-bold">Identidade visual</h3>
    <p class="mb-3 text-sm text-mono-600">Foto ou logo exibida no topo, no lugar de “CM”. Vale só para a sua conta. Escolha PNG, JPG ou WebP (até 5 MB e 4096 px), ajuste zoom e posição e salve; o arquivo original não é enviado, só o recorte quadrado final (512 px).</p>
    @if (request('logo') === 'salvo')<x-jr.alert variant="success">Logo salva.</x-jr.alert>@endif
    @if (request('logo') === 'removido')<x-jr.alert variant="info">Logo removida; voltou o padrão “CM”.</x-jr.alert>@endif
    @if ($errors->logo->any())<x-jr.alert variant="error">{{ $errors->logo->first() }}</x-jr.alert>@endif

    <div class="flex flex-col gap-4 sm:flex-row" x-data="logoCropper()">
        <div class="shrink-0">
            <p class="mb-1 text-xs text-mono-600">Atual</p>
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="Logo atual" class="h-16 w-16 rounded-lg object-cover">
            @else
                <span class="flex h-16 w-16 items-center justify-center rounded-lg bg-primary-500 text-lg font-bold text-white">CM</span>
            @endif
        </div>
        <div class="min-w-0 flex-1 space-y-3">
            <input type="file" accept="image/png,image/jpeg,image/webp" x-on:change="pick($event)" class="block w-full text-sm" aria-label="Escolher imagem">
            <p class="text-xs text-error" x-show="error" x-text="error"></p>
            <div x-show="img" x-cloak class="space-y-3">
                <canvas x-ref="preview" width="320" height="320" class="h-64 w-64 max-w-full rounded-lg border border-mono-200 bg-mono-50" aria-label="Prévia do recorte"></canvas>
                <label class="block text-sm">Zoom <input type="range" min="1" max="3" step="0.01" x-model.number="zoom" x-on:input="draw()" class="w-full"></label>
                <label class="block text-sm">Posição horizontal <input type="range" min="-1" max="1" step="0.01" x-model.number="px" x-on:input="draw()" class="w-full"></label>
                <label class="block text-sm">Posição vertical <input type="range" min="-1" max="1" step="0.01" x-model.number="py" x-on:input="draw()" class="w-full"></label>
                <form method="POST" action="{{ route('settings.logo.store') }}" enctype="multipart/form-data" x-ref="form" x-on:submit.prevent="submit()">
                    @csrf
                    <input type="file" name="logo" x-ref="out" class="hidden">
                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="rounded-sm bg-primary-500 px-4 py-2 text-sm font-semibold text-white" :disabled="busy">Salvar logo</button>
                        <button type="button" x-on:click="reset()" class="rounded-sm border border-mono-200 px-4 py-2 text-sm">Cancelar</button>
                    </div>
                </form>
            </div>
            @if ($logoUrl)
                <form method="POST" action="{{ route('settings.logo.destroy') }}" onsubmit="return confirm('Remover a logo e voltar ao padrão?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-error">Remover foto</button>
                </form>
            @endif
        </div>
    </div>
</x-jr.card>

<script>
    window.logoCropper = () => ({
        img: null, zoom: 1, px: 0, py: 0, error: '', busy: false,
        pick(e) {
            this.error = ''; this.img = null;
            const f = e.target.files[0]; if (!f) return;
            if (!['image/png', 'image/jpeg', 'image/webp'].includes(f.type)) { this.error = 'Formato não aceito. Use PNG, JPG ou WebP.'; return; }
            if (f.size > 5 * 1024 * 1024) { this.error = 'Arquivo maior que 5 MB.'; return; }
            const url = URL.createObjectURL(f), im = new Image();
            im.onload = () => {
                if (im.naturalWidth > 4096 || im.naturalHeight > 4096) { this.error = 'Imagem maior que 4096 px.'; URL.revokeObjectURL(url); return; }
                this.img = im; this.zoom = 1; this.px = 0; this.py = 0; this.$nextTick(() => this.draw());
            };
            im.onerror = () => { this.error = 'Não foi possível abrir a imagem.'; };
            im.src = url;
        },
        render(ctx, size) {
            const im = this.img, s = Math.min(im.naturalWidth, im.naturalHeight) / this.zoom;
            const maxX = (im.naturalWidth - s) / 2, maxY = (im.naturalHeight - s) / 2;
            const sx = maxX + this.px * maxX, sy = maxY + this.py * maxY;
            ctx.clearRect(0, 0, size, size);
            ctx.drawImage(im, sx, sy, s, s, 0, 0, size, size);
        },
        draw() { if (this.img) this.render(this.$refs.preview.getContext('2d'), 320); },
        reset() { this.img = null; this.error = ''; },
        submit() {
            if (!this.img) return;
            this.busy = true;
            const c = document.createElement('canvas'); c.width = c.height = {{ \App\Support\BrandLogo::SIZE }};
            this.render(c.getContext('2d'), c.width);
            c.toBlob((b) => {
                const dt = new DataTransfer(); dt.items.add(new File([b], 'logo.png', { type: 'image/png' }));
                this.$refs.out.files = dt.files; this.$refs.form.submit();
            }, 'image/png');
        },
    });
</script>
