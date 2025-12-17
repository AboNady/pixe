@props(['logo', 'w' => 99])

@php
  $logoPath = $logo && file_exists(public_path('storage/'.$logo))
              ? asset('storage/'.$logo)
              : 'https://picsum.photos/seed/' . rand(0,100000) . '/100/100';
@endphp

<img 
    src="{{ $logoPath }}"
    class="rounded-xl object-cover"
    style="width: {{ $w }}px; height: {{ $w }}px;"
    width="{{ $w }}" 
    height="{{ $w }}"
>