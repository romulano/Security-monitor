<?php

namespace App\Http\Requests\Alerts;

use App\Http\Requests\Request;
use App\Models\NotificationChannel;

/**
 * Tempo real, horário silencioso e resumo diário: o formulário é o mesmo no
 * Slack, no Discord e no Telegram. Sem canal do usuário naquele app, 404.
 */
abstract class ChannelPreferencesRequest extends Request
{
    /** O canal do usuário no app desta tela. */
    abstract public function channel(): ?NotificationChannel;

    public function authorize(): bool
    {
        return $this->channel() !== null;
    }

    public function rules(): array
    {
        return [
            'realtime' => ['boolean'],
            'quiet' => ['boolean'],
            'quiet_start' => ['nullable', 'required_if:quiet,1', 'date_format:H:i'],
            'quiet_end' => ['nullable', 'required_if:quiet,1', 'date_format:H:i', 'different:quiet_start'],
            'digest' => ['boolean'],
            'digest_time' => ['required', 'date_format:H:i'],
            'digest_scope' => ['required', 'in:all,profile'],
            'digest_days' => ['array'],
            'digest_days.*' => ['integer', 'between:1,7'],
            'timezone' => ['required', 'in:'.implode(',', array_keys(NotificationChannel::TIMEZONES))],
        ];
    }

    public function messages(): array
    {
        return [
            'quiet_end.different' => 'O horário silencioso precisa terminar em outro horário.',
            'digest_time.date_format' => 'Use o formato HH:MM.',
        ];
    }

    /** As preferências validadas, com os interruptores (desmarcado não vem no formulário) como booleanos. */
    public function preferences(): array
    {
        $data = $this->validated();

        return [
            'realtime' => $this->boolean('realtime'),
            'quiet' => $this->boolean('quiet'),
            'quiet_start' => $data['quiet_start'] ?? null,
            'quiet_end' => $data['quiet_end'] ?? null,
            'digest' => $this->boolean('digest'),
            'digest_time' => $data['digest_time'],
            'digest_scope' => $data['digest_scope'],
            'digest_days' => $data['digest_days'] ?? [],
            'timezone' => $data['timezone'],
        ];
    }
}
