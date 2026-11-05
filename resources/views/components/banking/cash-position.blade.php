@props(['accountsBalance', 'thirdPartyBalance', 'ownBalance'])

<div class="grid grid-cols-1 md:grid-cols-3 gap-space-5">
    <x-fx.card class="min-w-0">
        <div class="flex items-center gap-space-2 text-fs-12 text-cryptex-text-tertiary font-medium">
            <span class="uppercase tracking-[0.05em]">Saldo nas contas</span>
            <span class="material-icons-outlined text-fs-16 text-cryptex-brand-400" tabindex="0" title="Soma das contas ativas, exceto as de repasse. É o dinheiro que está no banco, inclusive a parte que ainda pertence a terceiros." aria-label="Soma das contas ativas, exceto as de repasse. É o dinheiro que está no banco, inclusive a parte que ainda pertence a terceiros.">info</span>
        </div>
        <div class="mt-space-2 text-fs-24 font-bold text-cryptex-text-primary font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($accountsBalance, 2, ',', '.') }}</div>
    </x-fx.card>
    <x-fx.card class="min-w-0">
        <div class="flex items-center gap-space-2 text-fs-12 text-cryptex-text-tertiary font-medium">
            <span class="uppercase tracking-[0.05em]">Dinheiro de terceiros</span>
            <span class="material-icons-outlined text-fs-16 text-cryptex-brand-400" tabindex="0" title="Quanto as contas de repasse ainda devem. Saldo negativo nessas contas é valor a repassar. Saldo positivo não entra aqui." aria-label="Quanto as contas de repasse ainda devem. Saldo negativo nessas contas é valor a repassar. Saldo positivo não entra aqui.">info</span>
        </div>
        <div class="mt-space-2 text-fs-24 font-bold text-cryptex-text-primary font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($thirdPartyBalance, 2, ',', '.') }}</div>
    </x-fx.card>
    <x-fx.card class="min-w-0">
        <div class="flex items-center gap-space-2 text-fs-12 text-cryptex-text-tertiary font-medium">
            <span class="uppercase tracking-[0.05em]">Saldo próprio</span>
            <span class="material-icons-outlined text-fs-16 text-cryptex-brand-400" tabindex="0" title="Saldo nas contas menos o dinheiro de terceiros. É a parte que permanece depois de reservar o que ainda será repassado." aria-label="Saldo nas contas menos o dinheiro de terceiros. É a parte que permanece depois de reservar o que ainda será repassado.">info</span>
        </div>
        <div class="mt-space-2 text-fs-24 font-bold font-mono [font-variant-numeric:tabular-nums] {{ $ownBalance >= 0 ? 'text-cryptex-text-primary' : 'text-cryptex-red-500' }}">R$ {{ number_format($ownBalance, 2, ',', '.') }}</div>
    </x-fx.card>
</div>
