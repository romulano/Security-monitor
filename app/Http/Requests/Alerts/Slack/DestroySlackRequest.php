<?php

namespace App\Http\Requests\Alerts\Slack;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;

class DestroySlackRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'slack';
    }

    public function authorize(): bool
    {
        return $this->managesTargetedChannel();
    }
}
