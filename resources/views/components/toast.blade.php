{{-- Toast — porté de ui.jsx <Toast>. Affichage piloté par le parent (Livewire/Alpine). --}}
<div {{ $attributes->merge(['class' => 'toast']) }}><x-icon name="check" />{{ $slot }}</div>
