<?php

namespace App\Services\Crawler;

use Illuminate\Support\Carbon;

class FeedItem
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $guid = null,
        public ?string $author = null,
        public ?Carbon $publishedAt = null,
        public ?string $description = null,
        public array $categories = [],
    ) {}

    public function host(): string
    {
        return (string) preg_replace('/^www\./', '', (string) parse_url($this->url, PHP_URL_HOST));
    }
}
