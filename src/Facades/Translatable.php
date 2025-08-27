<?php

namespace Nramos\Translatable\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Nramos\Translatable\Translatable
 */
class Translatable extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nramos\Translatable\Translatable::class;
    }
}
