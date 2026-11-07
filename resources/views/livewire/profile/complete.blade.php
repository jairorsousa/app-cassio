<?php

use App\Support\BrazilianDocuments;
use App\Support\ProfileCompletionService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $document = '';

    public ?string $birth_date = null;

    public string $zip_code = '';

    public string $street = '';

    public string $number = '';

    public string $complement = '';

    public string $neighborhood = '';

    public string $city = '';

    public string $state = '';

    public string $pix_key_type = '';

    public string $pix_key = '';

    public function mount(ProfileCompletionService $service): void
    {
        $user = auth()->user();
        if ($user->isAdmin() || $user->profile_completed_at) {
            $this->redirect(\App\Support\UserPreferences::homeUrl($user));

            return;
        }
        foreach ($service->prefill($user) as $k => $v) {
            $this->{$k} = $v;
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:200',
            'document' => ['required', 'string', 'max:20', function ($a, $v, $fail) {
                if (! BrazilianDocuments::isValidCpf($v)) {
                    $fail('Informe um CPF válido.');
                }
            }],
            'phone' => ['required', 'string', 'max:30', function ($a, $v, $fail) {
                if (! preg_match('/^\d{10,11}$/', BrazilianDocuments::digits($v))) {
                    $fail('Informe um telefone válido com DDD (10 ou 11 dígitos).');
                }
            }],
            'birth_date' => 'required|date|before:today|after:1900-01-01',
            'zip_code' => ['required', 'string', 'max:20', fn ($a, $v, $fail) => strlen(BrazilianDocuments::digits($v)) === 8 ? null : $fail('CEP inválido.')],
            'street' => 'required|string|max:255',
            'number' => 'required|string|max:30',
            'complement' => 'nullable|string|max:255',
            'neighborhood' => 'required|string|max:120',
            'city' => 'required|string|max:120',
            'state' => 'required|string|size:2',
            'pix_key_type' => 'nullable|in:email,cpf,telefone,aleatoria',
            'pix_key' => 'nullable|string|max:200',
        ];
    }

    protected function messages(): array
    {
        return ['required' => 'Campo obrigatório.', 'birth_date.before' => 'Data inválida.', 'state.size' => 'Use a sigla (ex.: CE).'];
    }

    public function lookupZipCode(): void
    {
        $r = app(\App\Domains\Contacts\Services\CepLookupService::class)->lookup($this->zip_code);
        if (! $r) {
            $this->addError('zip_code', 'CEP não encontrado.');

            return;
        }
        $this->zip_code = $r['zip_code'];
        $this->street = $r['street'];
        $this->neighborhood = $r['neighborhood'];
        $this->city = $r['city'];
        $this->state = $r['state'];
        $this->resetErrorBag('zip_code');
    }

    public function updatedZipCode(): void
    {
        if (strlen(BrazilianDocuments::digits($this->zip_code)) === 8) {
            $this->lookupZipCode();
        }
    }

    public function save(ProfileCompletionService $service)
    {
        $data = $this->validate();
        $service->complete(auth()->user(), $data);
        session()->flash('status', 'Cadastro completo. Obrigado!');

        return $this->redirect(\App\Support\UserPreferences::homeUrl(auth()->user()->fresh()), navigate: false);
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6" data-profile-complete>
    <x-fx.card>
        <h1 class="text-fs-24 font-bold">Complete seu cadastro</h1>
        <p class="mt-1 text-sm text-mono-600">Antes de continuar, confirme seus dados. Eles ficam guardados com segurança e são usados só dentro do sistema.</p>
        <form wire:submit="save" class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2"><x-jr.input label="Nome completo *" name="name" wire:model="name" /></div>
            <x-jr.input label="E-mail" name="email" type="email" wire:model="email" readonly helper="O e-mail do seu acesso não muda aqui." />
            <x-jr.input label="Celular *" name="phone" type="tel" x-phone placeholder="(99) 99999-9999" maxlength="15" wire:model="phone" />
            <x-jr.input label="CPF *" name="document" x-cpf-cnpj placeholder="000.000.000-00" wire:model="document" />
            <x-jr.input label="Data de nascimento *" name="birth_date" type="date" wire:model="birth_date" />
            <x-jr.input label="CEP *" name="zip_code" placeholder="00000-000" wire:model.live.debounce.500ms="zip_code" />
            <x-jr.input label="Rua *" name="street" wire:model="street" />
            <x-jr.input label="Número *" name="number" wire:model="number" />
            <x-jr.input label="Complemento" name="complement" wire:model="complement" />
            <x-jr.input label="Bairro *" name="neighborhood" wire:model="neighborhood" />
            <x-jr.input label="Cidade *" name="city" wire:model="city" />
            <x-jr.input label="UF *" name="state" maxlength="2" wire:model="state" />
            <div><label class="mb-2 block text-sm font-medium text-mono-600">Tipo de chave Pix</label>
                <select wire:model="pix_key_type" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm"><option value="">Nenhuma</option><option value="cpf">CPF</option><option value="email">E-mail</option><option value="telefone">Telefone</option><option value="aleatoria">Aleatória</option></select></div>
            <x-jr.input label="Chave Pix" name="pix_key" wire:model="pix_key" />
            <div class="flex items-center justify-between gap-3 md:col-span-2">
                <button type="button" class="text-sm text-mono-600" onclick="document.getElementById('profile-logout').submit()">Sair</button>
                <button type="submit" class="fx-btn fx-btn--primary" data-profile-save>Salvar e continuar</button>
            </div>
        </form>
        <form id="profile-logout" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
    </x-fx.card>
</div>
