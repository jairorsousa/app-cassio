<?php

namespace App\Support;

/** Grava números de processo de 20 dígitos já no formato CNJ (ver ProcessNumber). */
trait NormalizesProcessNumbers
{
    public static function bootNormalizesProcessNumbers(): void
    {
        static::saving(function ($model) {
            foreach (static::PROCESS_NUMBER_FIELDS as $field) {
                $value = $model->getAttribute($field);
                if (is_string($value)) {
                    $formatted = ProcessNumber::format($value);
                    if ($formatted !== $value) {
                        $model->setAttribute($field, $formatted === '' ? null : $formatted);
                    }
                }
            }
        });
    }
}
