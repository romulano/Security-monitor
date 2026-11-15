<?php

namespace App\Http\Requests\Admin\Monitoring;

use App\Http\Requests\Request;
use App\Services\Admin\AccessReport;
use App\Support\RobotCatalog;

/** O recorte da tela de acessos: período, robôs (e o tipo e o dono deles), os nossos, e um IP, país ou página. */
class ShowAccessesRequest extends Request
{
    public function rules(): array
    {
        // "Ver mais" de uma seção da Visão geral: fora dos filtros, não passa para as outras abas.
        return self::filterRules() + ['mais' => ['nullable', 'in:paginas,ips,paises']];
    }

    /** Os filtros da tela e a página da lista. */
    public static function filterRules(): array
    {
        return [
            'periodo' => ['nullable', 'in:'.implode(',', array_keys(AccessReport::PERIODS))],
            'robos' => ['nullable', 'in:com,sem,so'],
            'tipo' => ['nullable', 'in:'.implode(',', array_keys(RobotCatalog::KINDS))],
            'dono' => ['nullable', 'string', 'max:60'],
            'nossos' => ['nullable', 'in:esconder,mostrar,so'],
            'ip' => ['nullable', 'ip'],
            'pais' => ['nullable', 'string', 'size:2'],
            'pagina' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['ip.ip' => 'IP inválido.'];
    }
}
