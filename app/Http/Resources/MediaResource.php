<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module' => $this->module,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'notas' => $this->notas,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'content_sha256' => $this->content_sha256,
            'url' => $this->url,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
