<?php

namespace Nramos\Translatable\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Nramos\Translatable\Models\Translation;

trait HasTranslations
{
    protected static $currentLocale = null;

    protected static $columnCache = [];

    private $_pendingTranslations = [];

    private $_loadedTranslations = null;

    public static function bootHasTranslations(): void
    {
        static::saved(function ($model) {
            $model->saveTranslations();
        });

        // Amélioration : Suppression automatique des traductions
        static::deleting(function ($model) {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return; // Soft delete : conserver les traductions
            }
            $model->translations()->delete();
        });

        // Amélioration : Nettoyer le cache lors de la suppression
        static::deleted(function ($model) {
            $model->clearTranslationCache();
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
     * Amélioration : Obtenir la traduction avec cache
     */
    public function getTranslation(string $attribute, ?string $locale = null): ?string
    {
        $locale = $locale ?? $this->getCurrentLocale();

        // Utiliser le cache des traductions chargées
        if ($this->_loadedTranslations !== null) {
            return $this->_loadedTranslations
                ->where('locale', $locale)
                ->where('attribute_name', $attribute)
                ->first()?->value;
        }

        $translation = $this->translations
            ->where('locale', $locale)
            ->where('attribute_name', $attribute)
            ->first();

        return $translation?->value;
    }

    /**
     * Amélioration : Batch update pour les traductions
     */
    public function setTranslation(string $attribute, string $locale, $value): self
    {
        // Validation de la locale
        if (! $this->isValidLocale($locale)) {
            throw new \InvalidArgumentException("Invalid locale: {$locale}");
        }

        $this->translations()->updateOrCreate([
            'locale' => $locale,
            'attribute_name' => $attribute,
        ], [
            'value' => $value,
        ]);

        // Nettoyer le cache
        $this->clearTranslationCache();

        return $this;
    }

    /**
     * Amélioration : Définir plusieurs traductions en une fois
     */
    public function setTranslations(string $attribute, array $translations): self
    {
        foreach ($translations as $locale => $value) {
            $this->_pendingTranslations[$attribute][$locale] = $value;
        }

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
     * Amélioration : Cache des attributs traduisibles
     */
    public function getTranslatableAttributes(): array
    {
        $cacheKey = static::class.'_translatable_attributes';

        return Cache::remember($cacheKey, 3600, function () {
            return property_exists($this, 'translatableAttributes')
                ? $this->translatableAttributes
                : [];
        });
    }

    /**
     * Vérifier si un attribut est traduisible
     */
    public function isTranslatableAttribute(string $attribute): bool
    {
        return in_array($attribute, $this->getTranslatableAttributes());
    }

    /**
     * Obtenir l'attribut traduit correspondant (ex: name -> __name)
     */
    protected function getTranslatableAttributeName(string $attribute): string
    {
        if (str_starts_with($attribute, '__')) {
            return $attribute;
        }

        return '__'.$attribute;
    }

    /**
     * Obtenir l'attribut original depuis l'attribut traduit (ex: __name -> name)
     */
    protected function getOriginalAttributeName(string $translatableAttribute): string
    {
        if (str_starts_with($translatableAttribute, '__')) {
            return substr($translatableAttribute, 2);
        }

        return $translatableAttribute;
    }

    /**
     * Amélioration : Magic getter optimisé
     */
    public function getAttribute($key)
    {
        if ($key === null) {
            return null;
        }

        $translatableKey = $this->getTranslatableAttributeName($key);

        // Gestion des attributs traduisibles
        if ($this->isTranslatableAttribute($translatableKey)) {
            return $this->getTranslatedAttribute($translatableKey, $key);
        }

        // Gestion directe des attributs traduisibles (ex: __name)
        if ($this->isTranslatableAttribute($key)) {
            return $this->getTranslatedAttribute($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * Amélioration : Logique de récupération des traductions centralisée
     */
    protected function getTranslatedAttribute(string $translatableKey, ?string $originalKey = null): mixed
    {
        $currentLocale = $this->getCurrentLocale();
        $fallbackLocale = config('app.fallback_locale');

        // Traduction dans la locale courante
        $translation = $this->getTranslation($translatableKey, $currentLocale);
        if ($translation !== null) {
            return $translation;
        }

        // Fallback sur la locale par défaut
        if ($currentLocale !== $fallbackLocale) {
            $fallback = $this->getTranslation($translatableKey, $fallbackLocale);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        // Fallback sur la colonne BDD originale
        if ($originalKey && $this->hasColumn($originalKey)) {
            $originalValue = parent::getAttribute($originalKey);
            if ($originalValue !== null) {
                return $originalValue;
            }
        }

        return null;
    }

    /**
     * Magic setter pour les attributs traduisibles
     */
    public function setAttribute($key, $value)
    {
        $translatableKey = $this->getTranslatableAttributeName($key);

        if ($this->isTranslatableAttribute($translatableKey)) {
            // Sauvegarder dans la colonne BDD originale si elle existe
            if ($this->hasColumn($key)) {
                $this->attributes[$key] = $value;
            }

            // Préparer la traduction
            $this->_pendingTranslations[$translatableKey] = [
                'locale' => $this->getCurrentLocale(),
                'value' => $value,
            ];

            return $this;
        }

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
     * Amélioration : Cache des colonnes pour éviter les requêtes répétées
     */
    protected function hasColumn(string $column): bool
    {
        $table = $this->getTable();
        $cacheKey = "{$table}.{$column}";

        if (! isset(static::$columnCache[$cacheKey])) {
            try {
                static::$columnCache[$cacheKey] = Schema::hasColumn($table, $column);
            } catch (\Exception $e) {
                static::$columnCache[$cacheKey] = false;
            }
        }

        return static::$columnCache[$cacheKey];
    }

    /**
     * Amélioration : Sauvegarde optimisée des traductions
     */
    protected function saveTranslations(): void
    {
        if (empty($this->_pendingTranslations)) {
            return;
        }

        foreach ($this->_pendingTranslations as $attribute => $data) {
            if (is_array($data) && isset($data['locale'], $data['value'])) {
                // Format simple
                $this->setTranslation($attribute, $data['locale'], $data['value']);
            } elseif (is_array($data)) {
                // Format multiple (setTranslations)
                foreach ($data as $locale => $value) {
                    $this->setTranslation($attribute, $locale, $value);
                }
            }
        }

        $this->_pendingTranslations = [];
        $this->clearTranslationCache();
    }

    /**
     * Amélioration : Validation des locales
     */
    protected function isValidLocale(string $locale): bool
    {
        $availableLocales = config('app.available_locales', ['en', 'fr']);

        return in_array($locale, $availableLocales);
    }

    /**
     * Amélioration : Nettoyer le cache des traductions
     */
    public function clearTranslationCache(): void
    {
        $this->_loadedTranslations = null;

        // Nettoyer le cache Laravel si utilisé
        $cacheKey = static::class.'_translatable_attributes';
        Cache::forget($cacheKey);
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
     * Amélioration : Scope optimisé avec eager loading
     */
    public function scopeWithTranslations($query, ?array $locales = null)
    {
        $locales = $locales ?? [app()->getLocale(), config('app.fallback_locale')];
        $locales = array_unique($locales);

        return $query->with(['translations' => function ($query) use ($locales) {
            $query->whereIn('locale', $locales)
                ->select(['translatable_type', 'translatable_id', 'locale', 'attribute_name', 'value']);
        }]);
    }

    /**
     * Amélioration : Précharger les traductions
     */
    public function loadTranslations(?array $locales = null): self
    {
        $locales = $locales ?? [app()->getLocale(), config('app.fallback_locale')];

        $this->_loadedTranslations = $this->translations()
            ->whereIn('locale', $locales)
            ->get();

        return $this;
    }

    /**
     * Amélioration : Dupliquer un modèle avec ses traductions
     */
    public function replicateWithTranslations(?array $except = null): self
    {
        $replica = $this->replicate($except);
        $replica->save();

        // Copier les traductions
        foreach ($this->translations as $translation) {
            $replica->setTranslation(
                $translation->attribute_name,
                $translation->locale,
                $translation->value
            );
        }

        return $replica;
    }

    /**
     * Amélioration : Obtenir le pourcentage de traduction
     */
    public function getTranslationCompleteness(): array
    {
        $locales = config('app.available_locales', ['en', 'fr']);
        $translatableAttributes = $this->getTranslatableAttributes();
        $totalRequired = count($locales) * count($translatableAttributes);

        if ($totalRequired === 0) {
            return ['percentage' => 100, 'missing' => []];
        }

        $existing = $this->translations->count();
        $percentage = round(($existing / $totalRequired) * 100, 2);

        $missing = [];
        foreach ($locales as $locale) {
            foreach ($translatableAttributes as $attribute) {
                if (! $this->getTranslation($attribute, $locale)) {
                    $missing[] = "{$locale}.{$attribute}";
                }
            }
        }

        return [
            'percentage' => $percentage,
            'missing' => $missing,
            'completed' => $existing,
            'total' => $totalRequired,
        ];
    }

    /**
     * Amélioration : Surcharge du pluck pour supporter les traductions
     */
    public function newCollection(array $models = [])
    {
        $collection = parent::newCollection($models);

        // Ajouter la méthode pluckTranslated à la collection
        $collection->macro('pluckTranslated', function ($value, $key = null, $locale = null) {
            $locale = $locale ?? app()->getLocale();

            return $this->map(function ($model) use ($value) {
                if (method_exists($model, 'isTranslatableAttribute')) {
                    $translatableKey = $model->getTranslatableAttributeName($value);
                    if ($model->isTranslatableAttribute($translatableKey)) {
                        return $model->getTranslatedAttribute($translatableKey, $value);
                    }
                    if ($model->isTranslatableAttribute($value)) {
                        return $model->getTranslatedAttribute($value);
                    }
                }

                return $model->getAttribute($value);
            })->pluck($value, $key);
        });

        return $collection;
    }

    /**
     * Amélioration : Scope pour pluck traduit
     */
    /**
     * Amélioration : Scope pour pluck traduit
     */
    public function scopePluckTranslated($query, string $column, ?string $key = null, ?string $locale = null)
    {
        $locale = $locale ?? app()->getLocale();
        $translatableKey = $this->getTranslatableAttributeName($column);

        // Si c'est un attribut traduisible, on fait une jointure avec les traductions
        if ($this->isTranslatableAttribute($translatableKey) || $this->isTranslatableAttribute($column)) {
            $attributeName = $this->isTranslatableAttribute($column) ? $column : $translatableKey;

            // Utiliser le nom de table du modèle Translation
            $translationTable = (new Translation)->getTable(); // 'model_translations'

            $modelClass = get_class($query->getModel());
            $tableName = $query->getModel()->getTable();

            return $query->leftJoin($translationTable, function ($join) use ($attributeName, $locale, $modelClass, $tableName, $translationTable) {
                $join->on($translationTable.'.translatable_id', '=', $tableName.'.id')
                    ->where($translationTable.'.translatable_type', '=', $modelClass)
                    ->where($translationTable.'.attribute_name', '=', $attributeName)
                    ->where($translationTable.'.locale', '=', $locale);
            })
                ->pluck($translationTable.'.value', $key ?: $query->getModel()->getKeyName());
        }

        // Sinon, utiliser le pluck normal
        return $query->pluck($column, $key);
    }

    /**
     * Amélioration : Méthode helper pour pluck avec fallback
     */
    public function scopePluckTranslatedWithFallback($query, string $column, ?string $key = null, ?string $locale = null)
    {
        $locale = $locale ?? app()->getLocale();
        $fallbackLocale = config('app.fallback_locale');
        $translatableKey = $this->getTranslatableAttributeName($column);

        if (! $this->isTranslatableAttribute($translatableKey) && ! $this->isTranslatableAttribute($column)) {
            return $query->pluck($column, $key);
        }

        $attributeName = $this->isTranslatableAttribute($column) ? $column : $translatableKey;

        return $query->with(['translations' => function ($q) use ($attributeName, $locale, $fallbackLocale) {
            $q->where('attribute_name', $attributeName)
                ->whereIn('locale', array_unique([$locale, $fallbackLocale]));
        }])
            ->get()
            ->mapWithKeys(function ($model) use ($column, $key, $locale, $fallbackLocale, $attributeName) {
                $keyValue = $key ? $model->getAttribute($key) : $model->getKey();

                // Essayer la locale demandée
                $translation = $model->translations
                    ->where('attribute_name', $attributeName)
                    ->where('locale', $locale)
                    ->first();

                if ($translation) {
                    return [$keyValue => $translation->value];
                }

                // Fallback sur la locale par défaut
                if ($locale !== $fallbackLocale) {
                    $fallbackTranslation = $model->translations
                        ->where('attribute_name', $attributeName)
                        ->where('locale', $fallbackLocale)
                        ->first();

                    if ($fallbackTranslation) {
                        return [$keyValue => $fallbackTranslation->value];
                    }
                }

                // Dernier recours : colonne originale
                $originalColumn = str_starts_with($column, '__')
                    ? substr($column, 2)
                    : $column;

                return [$keyValue => $model->getAttribute($originalColumn)];
            });
    }
}
