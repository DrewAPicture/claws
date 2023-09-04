<?php

namespace SharperClaws\Enums;

/**
 * SQL types.
 *
 * @since 2.0.0
 */
enum SqlType : string
{
    case BINARY = 'BINARY';
    case CHAR = 'CHAR';
    case DATE = 'DATE';
    case DATETIME = 'DATETIME';
    case SIGNED = 'SIGNED';
    case UNSIGNED = 'UNSIGNED';
    case TIME = 'TIME';
    case DOUBLE = 'DOUBLE';
    case INTEGER = 'INTEGER';
    case NUMERIC = 'NUMERIC';
    case DECIMAL = 'DECIMAL';

    public function resolve(string $type) : string
    {
        $type = strtoupper($type);

        if (!preg_match(
            '/^(?:BINARY|CHAR|DATE|DATETIME|SIGNED|UNSIGNED|TIME|DOUBLE|INTEGER|NUMERIC(?:\(\d+(?:,\s?\d+)?\))?|DECIMAL(?:\(\d+(?:,\s?\d+)?\))?)$/',
            $type
        )
        ) {
            return SqlType::CHAR->value;
        }

        if ('INTEGER' === $type || 'NUMERIC' === $type) {
            return SqlType::SIGNED->value;
        }

        if ('DOUBLE' === $type) {
            return SqlType::DECIMAL->value;
        }

        $sqlType = SqlType::tryFrom($type) ?? SqlType::CHAR;

        return $sqlType->value;
    }
}
