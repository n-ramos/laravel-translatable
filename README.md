# Laravel Translatable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nramos/translatable.svg?style=flat-square)](https://packagist.org/packages/nramos/translatable)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/nramos/translatable/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/nramos/translatable/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/nramos/translatable/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/nramos/translatable/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/nramos/translatable.svg?style=flat-square)](https://packagist.org/packages/nramos/translatable)

Un package Laravel élégant pour gérer les traductions de vos modèles Eloquent avec une table polymorphique. Ajoutez simplement un trait à vos modèles et vos attributs deviennent automatiquement traduisibles selon la locale courante de l'application.

## Fonctionnalités

✅ **Transparent** : `$model->name` récupère automatiquement la traduction dans la locale courante  
✅ **Fallback intelligent** : Retombe sur la locale par défaut ou la colonne BDD originale  
✅ **Table polymorphique** : Une seule table pour toutes les traductions  
✅ **Compatible Filament** : Fonctionne out-of-the-box avec Filament Admin  
✅ **Performance** : Eager loading et indexes optimisés  
✅ **Flexible** : Fonctionne avec ou sans colonnes BDD existantes

## Installation

Installer via Composer :

```bash
composer require nramos/translatable
```

Publier et exécuter les migrations :

```bash
php artisan vendor:publish --tag="translatable-migrations"
php artisan migrate
```

## Configuration

Optionnel : publier le fichier de configuration

```bash
php artisan vendor:publish --tag="translatable-config"
```

Configuration par défaut :

```php
return [
    'locales' => ['en', 'fr', 'es', 'de', 'it'],
    'fallback_locale' => 'en',
    'translations_table' => 'model_translations',
    'auto_load_translations' => true,
];
```

## Utilisation de base

### 1. Ajouter le trait à votre modèle

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nramos\Translatable\Traits\HasTranslations;

class Product extends Model
{
    use HasTranslations;

    protected $translatableAttributes = [
        '__name',
        '__description',
    ];

    protected $fillable = [
        'price',
        'sku',
        'name',        // Colonne fallback (optionnelle)
        'description', // Colonne fallback (optionnelle)
    ];
}
```

### 2. Utiliser les traductions

```php
// Créer un produit avec traductions
$product = new Product();
$product->sku = 'PROD-001';
$product->price = 99.99;

// Français
app()->setLocale('fr');
$product->name = 'Produit fantastique';
$product->description = 'Une description en français';

// Anglais  
app()->setLocale('en');
$product->name = 'Amazing Product';
$product->description = 'An english description';

$product->save();

// Récupérer selon la locale courante
app()->setLocale('fr');
echo $product->name; // "Produit fantastique"

app()->setLocale('en');
echo $product->name; // "Amazing Product"
```

## Fonctionnalités avancées

### Gestion manuelle des traductions

```php
// Définir une traduction spécifique
$product->setTranslation('__name', 'es', 'Producto increíble');

// Récupérer une traduction spécifique
$spanishName = $product->getTranslation('__name', 'es');

// Récupérer toutes les traductions d'un attribut
$allNames = $product->getTranslations('__name');
// ['fr' => 'Produit fantastique', 'en' => 'Amazing Product', 'es' => 'Producto increíble']
```

### Changer temporairement de locale

```php
$frenchName = Product::withLocale('fr', function() use ($product) {
    return $product->name;
});
```

### Optimisation des requêtes

```php
// Charger les traductions pour éviter les requêtes N+1
$products = Product::withTranslations(['fr', 'en'])->get();

// Ou pour la locale courante seulement
$products = Product::withTranslations()->get();
```

## Avec Filament Admin

Le package fonctionne automatiquement avec Filament sans configuration supplémentaire :

```php
// Dans votre Resource
public static function form(Form $form): Form
{
    return $form->schema([
        Forms\Components\TextInput::make('name')
            ->label('Nom du produit')
            ->required(),
        
        Forms\Components\Textarea::make('description')
            ->label('Description'),
    ]);
}
```

Les champs `name` et `description` seront automatiquement traduits selon la locale de l'application !

## Stratégies de fallback

Le package utilise plusieurs niveaux de fallback :

1. **Traduction dans la locale courante** (priorité maximale)
2. **Traduction dans la locale par défaut** (`app.fallback_locale`)
3. **Colonne BDD originale** (si elle existe)
4. **Null** (si rien n'est trouvé)

## Structure de la base de données

La table `model_translations` stocke toutes les traductions :

```php
Schema::create('model_translations', function (Blueprint $table) {
    $table->id();
    $table->morphs('translatable'); // translatable_type + translatable_id
    $table->string('locale', 10);
    $table->string('attribute_name');
    $table->longText('value');
    $table->timestamps();

    $table->unique(['translatable_type', 'translatable_id', 'locale', 'attribute_name']);
});
```

## Configuration avancée

### Personnaliser les attributs traduisibles

```php
class Product extends Model
{
    use HasTranslations;

    protected $translatableAttributes = [
        '__name',
        '__description',
        '__short_description',
        '__seo_title',
        '__meta_description',
    ];
}
```

### Migration pour modèle existant

Si vous avez déjà des données dans une colonne `name`, vous pouvez les migrer vers le système de traductions :

```php
// Migration de migration des données existantes
$products = Product::all();

foreach ($products as $product) {
    if ($product->getOriginal('name')) {
        $product->setTranslation('__name', 'fr', $product->getOriginal('name'));
        $product->save();
    }
}
```

## Exemples d'utilisation

### E-commerce multilingue

```php
class Product extends Model
{
    use HasTranslations;

    protected $translatableAttributes = ['__name', '__description', '__short_description'];

    public function getLocalizedSlugAttribute()
    {
        return Str::slug($this->name);
    }
}

// Usage
app()->setLocale('fr');
$product->name = 'Chaussures de sport';
echo $product->localized_slug; // "chaussures-de-sport"

app()->setLocale('en');
$product->name = 'Sports shoes';
echo $product->localized_slug; // "sports-shoes"
```

### Blog multilingue

```php
class Article extends Model
{
    use HasTranslations;

    protected $translatableAttributes = [
        '__title',
        '__content', 
        '__excerpt',
        '__seo_title',
        '__meta_description'
    ];
}
```

### Menu de restaurant

```php
class MenuItem extends Model
{
    use HasTranslations;

    protected $translatableAttributes = [
        '__name',
        '__description',
        '__ingredients'
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
```

## Commandes Artisan

```bash
# Trouver les traductions manquantes
php artisan translatable:missing

# Nettoyer les traductions orphelines
php artisan translatable:cleanup

# Exporter les traductions
php artisan translatable:export --locale=fr
```

## Performance et bonnes pratiques

### 1. Utiliser le eager loading

```php
// ❌ Cause des requêtes N+1
$products = Product::all();
foreach ($products as $product) {
    echo $product->name;
}

// ✅ Une seule requête supplémentaire
$products = Product::withTranslations()->get();
foreach ($products as $product) {
    echo $product->name;
}
```

### 2. Limiter les locales chargées

```php
// Charger seulement les locales nécessaires
$products = Product::withTranslations(['fr', 'en'])->get();
```

### 3. Cache des traductions

Le package supporte automatiquement le cache Laravel. Pour l'activer :

```php
// Dans config/translatable.php
'cache_translations' => true,
'cache_duration' => 3600, // 1 heure
```

## Migration depuis d'autres packages

### Depuis spatie/laravel-translatable

```php
// Ancien code spatie
$product->setTranslation('name', 'fr', 'Mon produit');

// Nouveau code avec ce package  
$product->setTranslation('__name', 'fr', 'Mon produit');
// ou plus simple :
app()->setLocale('fr');
$product->name = 'Mon produit';
```

## Tests

```bash
composer test
```

## Débogage

Activer les logs pour diagnostiquer :

```php
// Dans un modèle pour debug
public function debugTranslations()
{
    return [
        'translatable_attributes' => $this->getTranslatableAttributes(),
        'pending_translations' => $this->debugPendingTranslations(),
        'current_locale' => $this->getCurrentLocale(),
        'existing_translations' => $this->translations->toArray(),
    ];
}
```

## Contribuer

Les contributions sont les bienvenues ! Merci de lire [CONTRIBUTING.md](CONTRIBUTING.md) pour les détails.

## Sécurité

Si vous découvrez une faille de sécurité, merci d'envoyer un email à nicolas@example.com plutôt que d'utiliser le tracker d'issues.

## Changelog

Consultez [CHANGELOG.md](CHANGELOG.md) pour voir les changements récents.

## Crédits

- [Nicolas RAMOS](https://github.com/n-ramos)
- [Tous les contributeurs](../../contributors)

## Licence

MIT License. Voir [LICENSE.md](LICENSE.md) pour plus d'informations.
