<?php

namespace App\Http\Requests\Admin\Alerts\Deliveries;

use App\Http\Requests\Request;

class ListDeliveriesRequest extends Request
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:sent,failed,queued,skipped'],
            'tipo' => ['nullable', 'in:realtime,grouped,digest,test,code,tests'],
            'periodo' => ['nullable', 'in:24h,7d,30d'],
            'q' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** Situação (`status`), tipo de envio (`tipo`), período (`periodo`) e busca pelo cliente (`q`). */
    public function filters(): array
    {
        return $this->validated();
    }

    /** `?payload=ID`: o envio cujo payload abre na página. */
    public function payloadId(): ?int
    {
        return $this->integer('payload') ?: null;
    }
}
