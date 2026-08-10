<?php

namespace App\Services\Catalog;

use App\Models\CatalogObjectType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Valida gli attributi di un asset contro i campi personalizzati definiti
 * nel Catalog Manager per il suo tipo oggetto. Chiavi non previste vengono
 * rifiutate: la scheda contiene solo campi definiti dall'amministratore.
 */
class AttributeValidator
{
    public function validate(CatalogObjectType $type, array $attributes): array
    {
        $fields = $type->customFields()->orderBy('sort_order')->get();

        $unknown = array_diff(array_keys($attributes), $fields->pluck('key')->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'attributes' => 'Attributi non previsti per il tipo '.$type->code.': '.implode(', ', $unknown),
            ]);
        }

        $rules = [];
        $names = [];

        foreach ($fields as $field) {
            $constraints = $field->validation ?? [];
            $rule = [$field->required ? 'required' : 'nullable'];

            switch ($field->field_type) {
                case 'integer':
                    $rule[] = 'integer';
                    break;
                case 'number':
                    $rule[] = 'numeric';
                    break;
                case 'boolean':
                    $rule[] = 'boolean';
                    break;
                case 'date':
                    $rule[] = 'date';
                    break;
                case 'select':
                    $rule[] = 'string';
                    $rule[] = Rule::in($field->options ?? []);
                    break;
                case 'multiselect':
                    $rule[] = 'array';
                    $rules["{$field->key}.*"] = [Rule::in($field->options ?? [])];
                    break;
                case 'url':
                    $rule[] = 'string';
                    $rule[] = 'url';
                    break;
                default: // text, textarea, photo
                    $rule[] = 'string';
                    $rule[] = 'max:'.($constraints['max_length'] ?? 2000);
            }

            if (isset($constraints['min']) && in_array($field->field_type, ['integer', 'number'], true)) {
                $rule[] = 'min:'.$constraints['min'];
            }
            if (isset($constraints['max']) && in_array($field->field_type, ['integer', 'number'], true)) {
                $rule[] = 'max:'.$constraints['max'];
            }
            if (isset($constraints['regex']) && in_array($field->field_type, ['text', 'textarea'], true)) {
                $rule[] = 'regex:'.$constraints['regex'];
            }

            $rules[$field->key] = $rule;
            $names[$field->key] = $field->label;
        }

        $validator = Validator::make($attributes, $rules, [], $names);

        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->messages())
                    ->mapWithKeys(fn ($messages, $key) => ["attributes.{$key}" => $messages])
                    ->all()
            );
        }

        return array_intersect_key($attributes, $rules);
    }
}
