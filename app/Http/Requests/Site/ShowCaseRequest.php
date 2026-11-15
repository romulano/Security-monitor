<?php

namespace App\Http\Requests\Site;

use App\Http\Requests\Request;
use App\Support\ReportLanguage;

class ShowCaseRequest extends Request
{
    public function language(): string
    {
        return ReportLanguage::fromRequest($this);
    }

    /** O admin abre também a matéria escondida do feed público, para conferir e devolver. */
    public function seesHidden(): bool
    {
        return (bool) $this->user()?->is_admin;
    }
}
