<?php

namespace App\Http\Requests\Admin\Alerts\Catalog;

use App\Models\TechCatalog;

class UpdateTechRequest extends TechRequest
{
    protected function editing(): ?TechCatalog
    {
        return $this->route('tech');
    }
}
