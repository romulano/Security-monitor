<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;
use App\Services\Company\CompanyEntry;

/** O link só abre enquanto vale (CompanyEntry::pending); senão, 404. */
class ShowCompanyEntryRequest extends Request
{
    public function authorize(CompanyEntry $entry): bool
    {
        return $entry->pending($this->route('user'), (string) $this->query('stamp')) !== null;
    }
}
