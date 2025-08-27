<?php

namespace Nramos\Translatable\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nramos\Translatable\Models\Translation;

class HasTranslations
{
    protected static $currentLocale = null;

    public static function bootTranslatable()
    {
        // Intercepter les événements pour sauvegarder les traductions
        static::saved(function ($model) {
            $model->saveTranslations();
        });
    }

    /**
     * Relation avec les traductions
     */
    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    /**
     * Obtenir la traduction pour un attribut et une locale
     */
    public function getTranslation(string $attribute, ?string $locale = null): ?string
    {
        $locale = $locale ?? $this->getCurrentLocale();

        $translation = $this->translations
            ->where('locale', $locale)
            ->where('attribute_name', $attribute)
            ->first();

        return $translation ? $translation->value : null;
    }

    /**
     * Définir une traduction
     */
    public function setTranslation(string $attribute, string $locale, $value): self
    {
        $this->translations()->updateOrCreate([
            'locale' => $locale,
            'attribute_name' => $attribute,
        ], [
            'value' => $value,
        ]);

        return $this;
    }

    /**
     * Obtenir toutes les traductions pour un attribut
     */
    public function getTranslations(string $attribute): array
    {
        return $this->translations
            ->where('attribute_name', $attribute)
            ->pluck('value', 'locale')
            ->toArray();
    }

    /**
     * Vérifier si un attribut est traduisible
     */
    public function isTranslatableAttribute(string $attribute): bool
    {
        return in_array($attribute, $this->getTranslatableAttributes());
    }

    /**
     * Obtenir les attributs traduisibles
     */
    public function getTranslatableAttributes(): array
    {
        return property_exists($this, 'translatableAttributes')
            ? $this->translatableAttributes
            : [];
    }

    /**
     * Magic getter pour les attributs traduisibles
     */
    public function getAttribute($key)
    {
        if ($this->isTranslatableAttribute($key)) {
            $translation = $this->getTranslation($key);

            if ($translation !== null) {
                return $translation;
            }

            // Fallback sur la locale par défaut si pas de traduction trouvée
            if ($this->getCurrentLocale() !== config('app.fallback_locale')) {
                $fallback = $this->getTranslation($key, config('app.fallback_locale'));
                if ($fallback !== null) {
                    return $fallback;
                }
            }
        }

        return parent::getAttribute($key);
    }

    /**
     * Magic setter pour les attributs traduisibles
     */
    public function setAttribute($key, $value)
    {
        if ($this->isTranslatableAttribute($key)) {
            $this->pendingTranslations[$key] = [
                'locale' => $this->getCurrentLocale(),
                'value' => $value,
            ];

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Sauvegarder les traductions en attente
     */
    protected function saveTranslations()
    {
        if (! isset($this->pendingTranslations)) {
            return;
        }

        foreach ($this->pendingTranslations as $attribute => $data) {
            $this->setTranslation($attribute, $data['locale'], $data['value']);
        }

        unset($this->pendingTranslations);
    }

    /**
     * Obtenir la locale courante
     */
    protected function getCurrentLocale(): string
    {
        return static::$currentLocale ?? app()->getLocale();
    }

    /**
     * Définir temporairement la locale
     */
    public static function withLocale(string $locale, callable $callback)
    {
        $previous = static::$currentLocale;
        static::$currentLocale = $locale;

        try {
            return $callback();
        } finally {
            static::$currentLocale = $previous;
        }
    }

    /**
     * Scope pour charger les traductions
     */
    public function scopeWithTranslations($query, ?array $locales = null)
    {
        $locales = $locales ?? [app()->getLocale()];

        return $query->with(['translations' => function ($query) use ($locales) {
            $query->whereIn('locale', $locales);
        }]);
    }
}
