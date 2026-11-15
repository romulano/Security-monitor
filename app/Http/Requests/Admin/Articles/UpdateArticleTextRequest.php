<?php

namespace App\Http\Requests\Admin\Articles;

use App\Http\Requests\Request;
use App\Services\Crawler\ManualTextIntake;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UpdateArticleTextRequest extends Request
{
    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:3000000'],
            'format' => ['nullable', 'in:auto,text,html'],
            'prints' => ['nullable', 'array', 'max:'.ManualTextIntake::MAX_PRINTS],
            'prints.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.ManualTextIntake::MAX_PRINT_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'content.max' => 'O conteúdo colado passou de 3 MB.',
            'prints.max' => 'Envie no máximo '.ManualTextIntake::MAX_PRINTS.' prints.',
            'prints.*.mimes' => 'Os prints precisam ser PNG, JPG, WEBP ou GIF.',
            'prints.*.max' => 'Cada print pode ter até 5 MB.',
        ];
    }

    protected function passedValidation(): void
    {
        if (trim($this->pasted()) === '' && $this->prints() === []) {
            throw ValidationException::withMessages(['content' => 'Cole o texto ou o HTML da matéria, ou envie os prints.']);
        }
    }

    /** O texto ou o HTML colado. */
    public function pasted(): string
    {
        return (string) ($this->validated('content') ?? '');
    }

    /** Como ler o que foi colado: `auto` decide pelas tags. @return 'auto'|'text'|'html' */
    public function pastedFormat(): string
    {
        return $this->validated('format') ?? 'auto';
    }

    /** @return list<UploadedFile> */
    public function prints(): array
    {
        return $this->file('prints', []);
    }
}
