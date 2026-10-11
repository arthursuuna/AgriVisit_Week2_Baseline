{{-- One header nav for every page, so the three parts read as one application. --}}
@php
    $sections = [
        'checklist.index' => ['Visit Prep Console', 'checklist.*'],
        'tools.index'     => ['Tool Console', 'tools.index'],
        'tools.audit'     => ['Approval audit', 'tools.audit'],
        'agent.index'     => ['Agent runs', 'agent.*'],
    ];
@endphp
<nav class="nav">
    @foreach ($sections as $route => [$label, $pattern])
        @if (request()->routeIs($pattern))
            <strong aria-current="page">{{ $label }}</strong>
        @else
            <a href="{{ route($route) }}">{{ $label }}</a>
        @endif
        @unless ($loop->last) · @endunless
    @endforeach
</nav>
