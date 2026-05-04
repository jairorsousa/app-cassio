@props(['name' => null, 'checked' => false])

<input
    type="checkbox"
    @if ($name) name="{{ $name }}" @endif
    @checked($checked)
    {{ $attributes->merge(['class' => 'appearance-none w-[50px] h-[24px] rounded-full bg-cryptex-bg-tertiary relative cursor-pointer transition-colors duration-200 checked:bg-cryptex-brand-400 after:absolute after:top-[2px] after:left-[2px] after:w-5 after:h-5 after:rounded-full after:bg-white after:shadow after:transition-transform after:duration-200 checked:after:translate-x-[26px]']) }}
/>
