{{-- Bandeau de contexte enfant (§4.2) — porté de shell.jsx <SubjectBanner>.
     Rappelle « tu agis pour X » (jamais EN TANT QUE X). Attend : $subjectUser. --}}
@php($inline = $inline ?? false)
@if ($subjectUser->id !== auth()->id())
    @php($p1 = \App\Support\SubjectContext::phase($subjectUser) === 'P1')
    @php($cat = $subjectUser->primaryCategory()?->label)
    <div class="subj-banner {{ $p1 ? 'is-p1' : 'is-p2' }} {{ $inline ? 'is-inline' : '' }}">
        <x-icon :name="$p1 ? 'user-plus' : 'users'" />
        <div class="f1">
            @if ($p1)
                <b>Tu agis pour {{ $subjectUser->first_name }}.</b> {{ $cat ? $cat.' · ' : '' }}pas encore de compte — tu gères ses inscriptions.
            @else
                <b>Tu es co-pilote de {{ $subjectUser->first_name }}.</b> {{ $cat ? $cat.' · ' : '' }}{{ $subjectUser->first_name }} gère aussi son compte et reçoit ses notifications.
            @endif
        </div>
    </div>
@endif
