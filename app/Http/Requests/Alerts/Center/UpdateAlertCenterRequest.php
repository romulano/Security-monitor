<?php

namespace App\Http\Requests\Alerts\Center;

use App\Http\Requests\Request;
use App\Models\NotificationChannel;

/**
 * Os tipos ligados de cada app: `tipos[ID do canal][news|radar|github|tests]` = 0 ou 1.
 * Canal de outro cliente é ignorado pelo service, que só percorre os do logado; o aviso
 * dos testes, sem a liberação, também.
 */
class UpdateAlertCenterRequest extends Request
{
    public function rules(): array
    {
        return [
            'tipos' => ['required', 'array'],
            'tipos.*' => ['array:'.implode(',', [...array_keys(NotificationChannel::TYPES), NotificationChannel::TESTS])],
            'tipos.*.*' => ['boolean'],
        ];
    }

    /** @return array<int|string, array<string, bool>> */
    public function choices(): array
    {
        return collect($this->validated('tipos'))
            ->map(fn (array $types) => array_map(fn ($on) => filter_var($on, FILTER_VALIDATE_BOOLEAN), $types))
            ->all();
    }
}
