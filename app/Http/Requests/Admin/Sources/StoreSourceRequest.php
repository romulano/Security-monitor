<?php

namespace App\Http\Requests\Admin\Sources;

use App\Http\Requests\Request;
use App\Rules\ProxyUrl;

class StoreSourceRequest extends Request
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'feed_url' => ['required', 'url', 'max:500', 'unique:sources,feed_url'],
            'host' => ['nullable', 'string', 'max:160'],
            'interval_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'max_items' => ['nullable', 'integer', 'min:1', 'max:100'],
            'proxy_url' => ['nullable', 'string', 'max:500', new ProxyUrl],
        ];
    }
}
