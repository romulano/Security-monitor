<?php

namespace App\Http\Requests\Admin\Alerts;

use App\Http\Requests\Request;
use App\Models\MonitoredDomain;

class UpdateAlertsConfigRequest extends Request
{
    public function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'realtime_enabled' => ['boolean'],
            'digest_enabled' => ['boolean'],
            'discovery_enabled' => ['boolean'],
            'digest_default_time' => ['required', 'date_format:H:i'],
            'default_timezone' => ['required', 'timezone:all'],
            'max_per_hour' => ['required', 'integer', 'min:1', 'max:100'],
            'min_score' => ['required', 'integer', 'min:0', 'max:100'],
            'ai_review_enabled' => ['boolean'],
            'ai_review_threshold' => ['required', 'integer', 'min:0', 'max:99'],
            'github_ai_threshold' => ['required', 'integer', 'min:0', 'max:99'],
            'discovery_every_days' => ['required', 'integer', 'min:'.MonitoredDomain::MIN_SCAN_INTERVAL_DAYS, 'max:365'],
            'consent_version' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    /** Os campos validados, com os interruptores como booleanos (caixa desmarcada = desligado). */
    public function config(): array
    {
        return [
            'enabled' => $this->boolean('enabled'),
            'realtime_enabled' => $this->boolean('realtime_enabled'),
            'digest_enabled' => $this->boolean('digest_enabled'),
            'discovery_enabled' => $this->boolean('discovery_enabled'),
            'ai_review_enabled' => $this->boolean('ai_review_enabled'),
        ] + $this->validated();
    }
}
