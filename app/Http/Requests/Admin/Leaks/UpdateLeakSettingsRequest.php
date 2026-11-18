<?php

namespace App\Http\Requests\Admin\Leaks;

use App\Http\Requests\Request;

/**
 * O admin escolhe a subpasta dentro da pasta de vazamentos e se o .txt é apagado depois de carregar.
 *
 * A raiz não entra aqui de propósito: ela é fixa (o disco `leaks`, em storage/app/private/vazamentos).
 * O que varia é a subpasta dentro dela.
 */
class UpdateLeakSettingsRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            // Vazio = a raiz da pasta de vazamentos. Barra no começo e no fim não muda nada, então cai fora.
            'prefix' => trim(str_replace('\\', '/', (string) $this->input('prefix')), ' /'),
            'delete_after_import' => $this->boolean('delete_after_import'),
        ]);
    }

    public function rules(): array
    {
        return [
            'prefix' => [
                'present', 'string', 'max:200',
                'regex:/^$|^[A-Za-z0-9._\-]+(\/[A-Za-z0-9._\-]+)*$/',
                // O ponto é permitido nos nomes, então `..` formaria um segmento válido e
                // `../fora` passaria. Aqui a pasta é disco local: `..` seria travessia de verdade
                // para fora de storage/app/private/vazamentos, por isso é barrado.
                'not_regex:/(^|\/)\.\.?(\/|$)/',
            ],
            'delete_after_import' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['prefix' => 'pasta', 'delete_after_import' => 'apagar depois de carregar'];
    }

    public function messages(): array
    {
        return [
            'prefix.not_regex' => 'A pasta não aceita "." nem ".." como parte do caminho.',
            'prefix.regex' => 'A pasta aceita letras, números, ponto, hífen, sublinhado e barra: por exemplo vazamentos ou dumps/2026. Deixe vazio para a raiz da pasta de vazamentos.',
        ];
    }
}
