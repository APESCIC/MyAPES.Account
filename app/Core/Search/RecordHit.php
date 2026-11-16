<?php

namespace App\Core\Search;

/**
 * One record hit for Ctrl+K / list search (#527).
 */
final readonly class RecordHit
{
    public function __construct(
        public string $type,
        public string $id,
        public string $label,
        public string $url,
        public int $rank = 100,
        public ?string $subtitle = null,
        public ?string $status = null,
        public ?string $reference = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     url: string,
     *     subtitle: ?string,
     *     status: ?string,
     *     reference: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'url' => $this->url,
            'subtitle' => $this->subtitle,
            'status' => $this->status,
            'reference' => $this->reference,
        ];
    }
}
