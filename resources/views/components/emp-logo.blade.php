@props(['logo', 'w' => 99])

@php
    // We removed 'file_exists' because scanning the disk is slow.
    $logoPath = !empty($logo) 
        ? asset('storage/'.$logo) 
        : asset('images/default-company-logo.png'); // Replace with your actual default image
@endphp

<img 
    src="{{ $logoPath }}"
    class="rounded-xl object-cover"
    style="width: {{ $w }}px; height: {{ $w }}px;"
    width="{{ $w }}" 
    height="{{ $w }}"
>
