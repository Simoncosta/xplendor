<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Site do consultor: uma fotografia pública de um imóvel ou de um empreendimento, em três
 * larguras WebP (1600, 960, 480) e o original sem metadados (sem EXIF nem localização GPS).
 * Os caminhos são do disco público ("/storage/...").
 */
class ListingImage extends Model
{
    public const OWNER_PROPERTY = 'property';
    public const OWNER_DEVELOPMENT = 'development';

    protected $fillable = ['company_id', 'owner_type', 'owner_id', 'path', 'path_960', 'path_480', 'original_path', 'width', 'height', 'order', 'is_cover', 'alt'];

    protected $casts = ['is_cover' => 'boolean', 'order' => 'integer', 'width' => 'integer', 'height' => 'integer'];
}
