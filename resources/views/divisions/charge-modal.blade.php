{{-- Pré-visualização da cobrança: o texto pode ser editado; o link abre o WhatsApp numa nova aba. Nada é enviado pelo sistema. --}}
<div class="fixed inset-0 z-modal flex items-center justify-center px-4 py-6" data-charge-modal
     x-data="{ text: @js($chargeText), phone: @js(ltrim((string) $phone, '+')) }">
    <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="$set('chargeText', '')" x-on:click="$wire.set('{{ isset($chargeKey) ? 'chargeKey' : 'chargeMemberId' }}', null)" aria-label="Fechar"></button>
    <div class="relative w-full max-w-lg rounded-2xl border border-mono-100 bg-mono-white p-6 shadow-elevated">
        <h3 class="text-lg font-bold">Cobrar {{ $name }}</h3>
        <p class="mt-1 text-xs text-mono-600">R$ {{ $service->money($cents) }} · WhatsApp {{ \App\Support\BrazilianDocuments::formatPhone(substr((string) $phone, 3)) }}. Revise a mensagem; ela abre no WhatsApp para você enviar.</p>
        <textarea x-model="text" rows="7" class="mt-3 w-full rounded-xl border-mono-200 text-sm" data-charge-text></textarea>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" class="fx-btn fx-btn--standard" x-on:click="$wire.set('{{ isset($chargeKey) ? 'chargeKey' : 'chargeMemberId' }}', null)">Cancelar</button>
            <a target="_blank" rel="noopener" class="fx-btn fx-btn--primary" data-charge-open
               :href="'https://wa.me/' + phone + '?text=' + encodeURIComponent(text)"
               href="https://wa.me/{{ ltrim((string) $phone, '+') }}?text={{ rawurlencode($chargeText) }}"
               x-on:click="$wire.logCharge()">Abrir WhatsApp</a>
        </div>
    </div>
</div>
