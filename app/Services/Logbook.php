<?php

namespace App\Services;

use App\Models\LogEntry;

/** O diário de bordo como a /sobre o mostra: do mais antigo ao mais novo. */
class Logbook
{
    /** @return list<array{0: string, 1: string, 2: string}> [data dd.mm.aaaa, título, texto] */
    public function entries(): array
    {
        return LogEntry::query()
            ->orderBy('logged_on')
            ->orderBy('id')
            ->get()
            ->map(fn (LogEntry $entry) => [$entry->logged_on->format('d.m.Y'), $entry->title, $entry->text])
            ->all();
    }
}
