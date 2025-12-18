@props(['logo', 'w' => 99])

<img 
    src="{{ $logo }}"
    class="rounded-xl object-cover"
    style="width: {{ $w }}px; height: {{ $w }}px;"
    width="{{ $w }}" 
    height="{{ $w }}"
>
