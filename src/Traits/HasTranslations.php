<?php

namespace Nramos\Translatable\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nramos\Translatable\Models\Translation;

trait HasTranslations
{
    protected static $currentLocale = null;

    private $_pendingTranslations = [];

    public static function bootHasTranslations(): void
    {
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
     * Obtenir l'attribut traduit correspondant (ex: name -> __name)
     */
    protected function getTranslatableAttributeName(string $attribute): string
    {
        // Si l'attribut commence déjà par __, le retourner tel quel
        if (strpos($attribute, '__') === 0) {
            return $attribute;
        }

        // Sinon, ajouter le préfixe __
        return '__'.$attribute;
    }

    /**
     * Obtenir l'attribut original depuis l'attribut traduit (ex: __name -> name)
     */
    protected function getOriginalAttributeName(string $translatableAttribute): string
    {
        if (strpos($translatableAttribute, '__') === 0) {
            return substr($translatableAttribute, 2); // Enlever les 2 premiers caractères "__"
        }

        return $translatableAttribute;
    }

    /**
     * Magic getter pour les attributs traduisibles
     */
    public function getAttribute($key)
    {
        $translatableKey = $this->getTranslatableAttributeName($key);

        // Si l'attribut traduit existe dans translatableAttributes
        if ($this->isTranslatableAttribute($translatableKey)) {
            $translation = $this->getTranslation($translatableKey);

            if ($translation !== null) {
                return $translation;
            }

            // Fallback sur la locale par défaut
            if ($this->getCurrentLocale() !== config('app.fallback_locale')) {
                $fallback = $this->getTranslation($translatableKey, config('app.fallback_locale'));
                if ($fallback !== null) {
                    return $fallback;
                }
            }

            // Fallback final sur la colonne BDD originale si elle existe
            $originalValue = parent::getAttribute($key);
            if ($originalValue !== null) {
                return $originalValue;
            }
        }

        // Si c'est directement un attribut traduisible (ex: __name)
        if ($this->isTranslatableAttribute($key)) {
            $translation = $this->getTranslation($key);

            if ($translation !== null) {
                return $translation;
            }

            // Fallback sur la locale par défaut
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
        $translatableKey = $this->getTranslatableAttributeName($key);

        // Si l'attribut traduit existe dans translatableAttributes (ex: name -> __name)
        if ($this->isTranslatableAttribute($translatableKey)) {
            // Sauvegarder dans la colonne BDD originale si elle existe
            if ($this->hasColumn($key)) {
                $this->attributes[$key] = $value;
            }

            // ET sauvegarder comme traduction
            $this->_pendingTranslations[$translatableKey] = [
                'locale' => $this->getCurrentLocale(),
                'value' => $value,
            ];

            return $this;
        }

        // Si c'est directement un attribut traduisible (ex: __name)
        if ($this->isTranslatableAttribute($key)) {
            $this->_pendingTranslations[$key] = [
                'locale' => $this->getCurrentLocale(),
                'value' => $value,
            ];

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Vérifier si une colonne existe dans la table
     */
    protected function hasColumn(string $column): bool
    {
        try {
            return \Schema::hasColumn($this->getTable(), $column);
        } catch (\Exception $e) {
            // En cas d'erreur, on assume que la colonne n'existe pas
            return false;
        }
    }

    /**
     * Sauvegarder les traductions en attente
     */
    protected function saveTranslations(): void
    {
        if (empty($this->_pendingTranslations)) {
            return;
        }

        foreach ($this->_pendingTranslations as $attribute => $data) {
            $this->setTranslation($attribute, $data['locale'], $data['value']);
        }

        $this->_pendingTranslations = [];
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
