<?php

namespace App\Http\Requests\Admin\Alerts\Catalog;

use App\Models\TechCatalog;

class StoreTechRequest extends TechRequest
{
    protected function editing(): ?TechCatalog
    {
        return null;
    }
}
