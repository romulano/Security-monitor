<?php

namespace App\Http\Requests\Alerts\Keys;

use App\Models\ExposedKey;
use Illuminate\Validation\Rule;

/** A decisão sobre uma chave do domínio: `allowed`, `leak` ou vazio (desfazer). A chave de outro domínio é 404. */
class ClassifyExposedKeyRequest extends KeyDomainRequest
{
    protected bool $changes = true;

    private ?ExposedKey $key = null;

    public function authorize(): bool
    {
        return $this->key() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['verdict' => ['present', 'nullable', 'string', Rule::in(ExposedKey::VERDICTS)]];
    }

    public function key(): ?ExposedKey
    {
        $id = $this->route('chave');

        return $this->key ??= $this->owned() !== null && ctype_digit((string) $id)
            ? ExposedKey::query()->where('owned_domain_id', $this->owned()->id)->whereKey((int) $id)->first()
            : null;
    }

    public function verdict(): ?string
    {
        $verdict = $this->validated('verdict');

        return is_string($verdict) && $verdict !== '' ? $verdict : null;
    }
}
