<?php

namespace KraenkVisuell\NovaAstrotranslatable;

use Exception;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class TranslatableFieldMixin
{
    public function translatable()
    {
        return function ($overrideLocales = []) {
            $locales = FieldServiceProvider::getLocales($overrideLocales);
            $component = $this->component;

            $originalResolveCallback = $this->resolveCallback;
            $this->resolveUsing(function ($value, $resource, $attribute) use ($locales, $component, $originalResolveCallback) {
                $this->resolveCallback = $originalResolveCallback;
                $attribute = FieldServiceProvider::normalizeAttribute($attribute);

                // Load value from either the model or from the given $value
                if (isset($resource) && (is_object($resource) || is_string($resource)) && method_exists($resource, 'getTranslationsArray')) {
                    // In case a model has the HasTranslations trait, but some fields are wrapped
                    // we must be prepared to get an Exception here

                    try {
                        $allTranslations = $resource->getTranslationsArray();
                        $value = [];
                        foreach ($locales as $localeKey => $localeName) {
                            $value[$localeKey] = $allTranslations[$localeKey][$attribute] ?? null;
                        }
                    } catch (Exception $e) {
                        $value = [];
                    }
                }

                if (empty($value)) {
                    $value = data_get($resource, str_replace('->', '.', $attribute)) ?? [];
                }

                try {
                    if (! is_array($value)) {
                        if (is_object($value)) {
                            $value = (array) $value;
                        } else {
                            $testValue = json_decode($value, true);
                            if (is_array($testValue)) {
                                $value = $testValue;
                            }
                        }
                    }
                } catch (Exception $e) {
                }

                if (! empty($value)) {
                    $value = array_map(function ($val) {
                        return ! is_numeric($val) ? $val : (float) $val;
                    }, (array) $value);
                }

                $request = app(NovaRequest::class);
                $this->withMeta([
                    'translatable' => [
                        'original_attribute' => $this->attribute,
                        'original_component' => $component,
                        'locales' => $locales,
                        'value' => $value ?: ($this->resolveDefaultValue($request) ?? ''),
                    ],
                ]);

                $this->component = 'translatable-field';

                // If it's a CREATE or UPDATE request, we need to trick the validator a bit
                $hasValidationTrick = property_exists($this, '__validationTrick') && $this->__validationTrick;
                if (in_array(request()->method(), ['PUT', 'POST']) && ! $hasValidationTrick) {
                    $this->attribute = "{$this->attribute}.*";
                    $this->__validationTrick = true;
                }

                return $this->resolve($resource, $attribute);
            });

            $originalDisplayCallback = $this->displayCallback;
            $this->displayUsing(function ($value, $resource, $attribute) use ($originalDisplayCallback) {
                $this->displayCallback = $originalDisplayCallback;

                /*
                 * Avoid calling resolveForDisplay on the main Textarea instance as it contains a call to e()
                 * and it only accepts string, passing an array will cause a crash
                 */
                if ($this instanceof Textarea) {
                    $this->displayCallback = null;
                    parent::resolveForDisplay($resource, $attribute);

                    if (is_string($value)) {
                        return $value;
                    }

                    return collect(array_values((array) ($value ?? [])))->filter()->first() ?? '';
                }

                $this->resolveForDisplay($resource, $attribute);

                return $value;
            });

            $this->fillUsing(function ($request, $model, $attribute, $requestAttribute) use ($locales) {
                if (class_exists('\Astrotomic\Translatable\TranslatableServiceProvider') && method_exists($model, 'translateOrNew')) {
                    $realAttribute = FieldServiceProvider::normalizeAttribute($this->meta['translatable']['original_attribute'] ?? $attribute);
                    $translations = $request->{$realAttribute};

                    foreach ($locales as $localeKey => $localeName) {
                        $translationEntry = $model->translateOrNew($localeKey);

                        $translationEntry->{$realAttribute} = $translations[$localeKey] ?? '';
                    }
                }else{
                    $realAttribute = FieldServiceProvider::normalizeAttribute($this->meta['translatable']['original_attribute'] ?? $attribute);
                    $translations = $request->{$realAttribute};
                    if (gettype($translations) === "string"){
                        $translations = json_decode($translations, true);
                    }
                    $request->request->add($translations);
                    parent::fillAttributeFromRequest($request, $requestAttribute, $model, $attribute);
                }
            });

            return $this;
        };
    }

    public function rulesFor()
    {
        return function ($locales, $rules) {
            $setRule = function ($locale, $rules) {
                if (! in_array($locale, array_keys(FieldServiceProvider::getLocales()))) {
                    throw new Exception("Invalid locale specified ({$locale})");
                }

                if (! is_array($rules)) {
                    $rules = [$rules];
                }

                $this->rules['translatable'][$locale] = $rules;

                return $this;
            };

            if (is_callable($locales)) {
                $locales = call_user_func($locales);
            }

            // Array of locales or callable rules
            if (is_array($locales) || is_callable($rules)) {
                // Single locale with callable rules
                if (! is_array($locales)) {
                    return $setRule($locales, call_user_func($rules, $locales));
                }
                foreach ($locales as $locale) {
                    $_rules = $rules;
                    if (is_callable($_rules)) {
                        $_rules = call_user_func($rules, $locale);
                    }
                    $setRule($locale, $_rules);
                }

                return $this;
            }

            $setRule($locales, $rules);

            return $this;
        };
    }
}
