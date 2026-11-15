{{-- Select de modelo montado pelo ModelCatalog: grupo com rótulo vira <optgroup>. --}}
<label class="block">
    <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">{{ $label }}</span>
    <select name="{{ $name }}" class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 font-mono text-sm">
        @foreach ($catalog['groups'] as $group => $options)
            @if ($group === '')
                @foreach ($options as $option => $text)
                    <option value="{{ $option }}" @selected($value === (string) $option)>{{ $text }}</option>
                @endforeach
            @else
                <optgroup label="{{ $group }}">
                    @foreach ($options as $option => $text)
                        <option value="{{ $option }}" @selected($value === (string) $option)>{{ $text }}</option>
                    @endforeach
                </optgroup>
            @endif
        @endforeach
    </select>
    <span class="mt-1 block text-[11px] text-ink-700">{{ $catalog['note'] }}</span>
</label>
