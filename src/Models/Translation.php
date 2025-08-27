<?php

namespace Nramos\Translatable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Translation extends Model
{
    protected $table = 'model_translations';

    protected $fillable = [
        'translatable_type',
        'translatable_id',
        'locale',
        'attribute_name',
        'value',
    ];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
