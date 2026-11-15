<?php

namespace App\Services\Admin;

use App\Models\LogEntry;
use Illuminate\Database\Eloquent\Collection;

/** Admin → Diário de bordo: os registros que a /sobre mostra. */
class LogbookManager
{
    /** @return Collection<int, LogEntry> do mais novo ao mais antigo */
    public function list(): Collection
    {
        return LogEntry::query()->orderByDesc('logged_on')->orderByDesc('id')->get();
    }

    /** @param  array{logged_on: string, title: string, text: string}  $data */
    public function create(array $data): LogEntry
    {
        return LogEntry::create($this->attributes($data));
    }

    /** @param  array{logged_on: string, title: string, text: string}  $data */
    public function update(LogEntry $entry, array $data): void
    {
        $entry->update($this->attributes($data));
    }

    public function delete(LogEntry $entry): void
    {
        $entry->delete();
    }

    /** @return array{logged_on: string, title: string, text: string} */
    private function attributes(array $data): array
    {
        return [
            'logged_on' => $data['logged_on'],
            'title' => trim($data['title']),
            'text' => trim($data['text']),
        ];
    }
}
