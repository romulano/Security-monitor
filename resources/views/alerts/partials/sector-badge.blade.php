{{-- O setor de um monitoramento de empresa (R4): quem vê sabe de que setor é, ou que é da empresa toda. Nada na conta pessoal. --}}
@if ($item->company_id)
    <span class="inline-flex items-center rounded border border-ink-600 bg-ink-850 px-1.5 py-px text-xs text-ink-200" data-sector-badge>
        {{ $item->sector ? 'Setor '.$item->sector->name : 'Empresa toda' }}
    </span>
@endif
