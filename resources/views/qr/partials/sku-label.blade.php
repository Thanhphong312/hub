{{--
    One SKU-label tile. Labels arrive as PDF about as often as PNG, so the tile
    branches: an image is shown, a PDF gets an icon and opens in a new tab.
    Neither case prints the URL — these filenames run past 200 characters and
    filled the tile with unreadable text.

    Expects: $url, $isPdf
--}}
<a href="{{ $url }}" target="_blank" rel="noreferrer" class="block group" title="Mở nhãn SKU">
    <div class="aspect-square bg-neutral-50 border-2 border-blue-300 rounded overflow-hidden group-hover:ring-2 group-hover:ring-orange-400 transition flex items-center justify-center">
        @if ($isPdf)
            <div class="text-center px-2">
                <svg class="w-8 h-8 mx-auto text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <div class="mt-1 text-[10px] font-semibold text-blue-600">PDF</div>
                <div class="text-[9px] text-neutral-400">bấm để mở</div>
            </div>
        @else
            {{-- object-contain, not cover: the label carries a barcode and
                 cropping it makes the label useless. --}}
            <img src="{{ $url }}" alt="sku label" class="w-full h-full object-contain" loading="lazy"
                 onerror="this.parentElement.innerHTML='<div class=&quot;text-[10px] text-neutral-400 p-2&quot;>không mở được ảnh</div>'" />
        @endif
    </div>
    <div class="mt-1 text-[11px] text-blue-700 font-semibold">SKU label</div>
</a>
