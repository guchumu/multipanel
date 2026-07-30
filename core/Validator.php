<?php

declare(strict_types=1);

namespace Core;

use Core\Exceptions\ValidationException;

/**
 * Input validation engine.
 */
final class Validator
{
    /** @param array<string, mixed> $data */
    /** @param array<string, string> $rules */
    public static function make(array $data, array $rules): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $error = self::validateRule($field, $value, $rule, $params, $data);
                if ($error !== null) {
                    $errors[$field][] = $error;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    /** @param array<string, mixed> $data */
    private static function validateRule(string $field, mixed $value, string $rule, array $params, array $data): ?string
    {
        return match ($rule) {
            'required' => ($value === null || $value === '') ? "El campo {$field} es obligatorio." : null,
            'email' => ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL))
                ? "El campo {$field} debe ser un email válido." : null,
            'min' => (is_string($value) && strlen($value) < (int) ($params[0] ?? 0))
                ? "El campo {$field} debe tener al menos {$params[0]} caracteres." : null,
            'max' => (is_string($value) && strlen($value) > (int) ($params[0] ?? PHP_INT_MAX))
                ? "El campo {$field} no puede superar {$params[0]} caracteres." : null,
            'numeric' => ($value !== null && $value !== '' && !is_numeric($value))
                ? "El campo {$field} debe ser numérico." : null,
            'integer' => ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false)
                ? "El campo {$field} debe ser un entero." : null,
            'in' => ($value !== null && !in_array($value, $params, true))
                ? "El campo {$field} tiene un valor no permitido." : null,
            'confirmed' => ($value !== ($data[$field . '_confirmation'] ?? null))
                ? "La confirmación de {$field} no coincide." : null,
            'url' => ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL))
                ? "El campo {$field} debe ser una URL válida." : null,
            'nullable' => null,
            default => null,
        };
    }
}
