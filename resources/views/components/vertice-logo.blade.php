@props(['animated' => true])
@php($uid = 'v'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)))
{{-- Marca Vértice CM: vértice (pico) com CM, gradiente da paleta. Animação respeita prefers-reduced-motion. --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="Vértice CM" {{ $attributes->merge(['class' => 'shrink-0']) }} data-vertice-logo>
@if ($animated)
<defs>
<linearGradient id="vxg-{{ $uid }}" x1="58" y1="4" x2="6" y2="62" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FFC233"/><stop offset=".5" stop-color="#FF6F00"/><stop offset="1" stop-color="#E5361F"/></linearGradient>
<linearGradient id="vxs-{{ $uid }}" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity="0"/><stop offset=".5" stop-color="#fff" stop-opacity=".45"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
<clipPath id="vxc-{{ $uid }}"><rect width="64" height="64" rx="15"/></clipPath>
</defs><style>
.vx-peak-{{ $uid }}{stroke-dasharray:90;stroke-dashoffset:0}
.vx-shine-{{ $uid }}{opacity:0}
@media (prefers-reduced-motion:no-preference){
.vx-peak-{{ $uid }}{animation:vxdraw-{{ $uid }} 1.3s cubic-bezier(.2,.8,.2,1) both}
.vx-cm-{{ $uid }}{animation:vxfade-{{ $uid }} .6s .9s ease-out both}
.vx-spark-{{ $uid }}{transform-origin:50px 13px;animation:vxpop-{{ $uid }} .5s 1.2s ease-out both, vxpulse-{{ $uid }} 3s 2s ease-in-out infinite}
.vx-shine-{{ $uid }}{opacity:1;animation:vxshine-{{ $uid }} 6s 1.4s ease-in-out infinite}
}
@keyframes vxdraw-{{ $uid }}{from{stroke-dashoffset:90}to{stroke-dashoffset:0}}
@keyframes vxfade-{{ $uid }}{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
@keyframes vxpop-{{ $uid }}{from{transform:scale(0)}to{transform:scale(1)}}
@keyframes vxpulse-{{ $uid }}{0%,100%{transform:scale(1)}50%{transform:scale(1.35)}}
@keyframes vxshine-{{ $uid }}{0%{transform:translateX(-70px)}35%,100%{transform:translateX(90px)}}
</style>
<rect width="64" height="64" rx="15" fill="url(#vxg-{{ $uid }})"/>
<path d="M0 64 L64 18 L64 64 Z" fill="#fff" opacity=".08"/>
<path class="vx-peak-{{ $uid }}" d="M10 49 L32 13 L54 49" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
<text class="vx-cm-{{ $uid }}" x="32" y="44.5" text-anchor="middle" font-family="Inter, 'Segoe UI', Arial, sans-serif" font-size="11" font-weight="800" letter-spacing="-.3" fill="#fff">CM</text>
<path class="vx-spark-{{ $uid }}" d="M50 7.5 L51.6 11.4 L55.5 13 L51.6 14.6 L50 18.5 L48.4 14.6 L44.5 13 L48.4 11.4 Z" fill="#fff"/>
<g clip-path="url(#vxc-{{ $uid }})"><rect class="vx-shine-{{ $uid }}" x="-10" y="-10" width="22" height="90" fill="url(#vxs-{{ $uid }})" transform="translate(-70 0)"/></g>
@else
<defs>
<linearGradient id="vxg-{{ $uid }}" x1="58" y1="4" x2="6" y2="62" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FFC233"/><stop offset=".5" stop-color="#FF6F00"/><stop offset="1" stop-color="#E5361F"/></linearGradient>
<linearGradient id="vxs-{{ $uid }}" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity="0"/><stop offset=".5" stop-color="#fff" stop-opacity=".45"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
<clipPath id="vxc-{{ $uid }}"><rect width="64" height="64" rx="15"/></clipPath>
</defs>
<rect width="64" height="64" rx="15" fill="url(#vxg-{{ $uid }})"/>
<path d="M0 64 L64 18 L64 64 Z" fill="#fff" opacity=".08"/>
<path class="vx-peak-{{ $uid }}" d="M10 49 L32 13 L54 49" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
<text class="vx-cm-{{ $uid }}" x="32" y="44.5" text-anchor="middle" font-family="Inter, 'Segoe UI', Arial, sans-serif" font-size="11" font-weight="800" letter-spacing="-.3" fill="#fff">CM</text>
<path class="vx-spark-{{ $uid }}" d="M50 7.5 L51.6 11.4 L55.5 13 L51.6 14.6 L50 18.5 L48.4 14.6 L44.5 13 L48.4 11.4 Z" fill="#fff"/>

@endif
</svg>
