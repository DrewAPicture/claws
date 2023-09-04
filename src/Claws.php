<?php
/**
 * (Sharper) Claws Library
 *
 * This version of Claws is fork of work previously done by Sandhills Development.
 *
 * @copyright Copyright (c) 2023, Drew A Picture, LLC
 * @copyright Copyright (c) 2018, Sandhills Development, LLC
 * @license   http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @author    Drew Jaynes
 * @version   2.0.0-alpha
 */

namespace SharperClaws;

use SharperClaws\Enums\Operator;
use SharperClaws\Enums\SqlType;

/**
 * Implements a library to provide a variety of database sanitization helpers when
 * interacting with WordPress' wp-db class for custom queries.
 *
 * @since 1.0.0
 *
 * @method or (null|string $clause)
 * @method and (null|string $clause)
 */
class Claws
{

    /**
     * Represents the current clause being worked with.
     *
     * Resets at the end of escape_input().
     *
     * @since 1.0.0
     * @var   string
     */
    private $currentClause;

    /**
     * Represents the current field(s) being worked with.
     *
     * @since 1.0.0
     * @var   string
     */
    private $currentField;

    /**
     * Used for carrying the operator between methods when doing complex operations.
     *
     * @since 1.0.0
     * @var   Operator
     */
    private $currentOperator;

    /**
     * Stores clauses in progress for retrieval.
     *
     * @since 1.0.0
     * @var   array<string, array<string>>
     */
    private $clausesInProgress = [];

    /**
     * Whether the current operation is amending the previous phrase.
     *
     * Used when chaining multiple comparisons of different fields together
     * in the same phrase.
     *
     * @since 1.0.0
     * @var   bool
     */
    private $amendingPrevious = false;

    /**
     * Holds the value of the previously-stored phrase when set.
     *
     * Used in complex phrase-building.
     *
     * @since 1.0.0
     * @var   string
     */
    private $previousPhrase;

    /**
     * Whitelist of clauses Claws is built to handle.
     *
     * @since 1.0.0
     * @var   string[]
     */
    private $allowedClauses = ['where'];

    /**
     * Handles calling pseudo-methods.
     *
     * @since 1.0.0
     *
     * @param string       $name Method name.
     * @param array<mixed> $args Method arguments.
     *
     * @return static Claws instance.
     */
    public function __call(string $name, array $args) : static
    {
        /*
         * Prior to PHP 7, reserved keywords could not be used in method names,
         * so having or()/and() methods wouldn't be allowed. Using __call() allows
         * us to circumvent that problem.
         */
        switch ($name) {
            case 'or':
                $clause = $args[0] ?: null;

                // Shared logic.
                $this->__setCurrentOperator(Operator::OR, $clause);

                return $this;

            case 'and':
                $clause = $args[0] ?: null;

                // Shared logic.
                $this->__setCurrentOperator(Operator::AND, $clause);

                return $this;
        }

        return $this;
    }

    /**
     * Builds a section of the WHERE clause.
     *
     * @since 1.0.0
     *
     * @param string      $field            Field to build conditions from.
     * @param mixed|null  $values           Single value of varying types, or array of values.
     * @param string|null $callback_or_type Sanitization callback to pass values through, or shorthand
     *                                      types to use preset callbacks. Default 'esc_sql'.
     * @param string|null $compare_type     MySQL operator used for comparing the $value. Accepts '=', '!=',
     *                                      '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN',
     *                                      'NOT BETWEEN', 'EXISTS' or 'NOT EXISTS'.
     *                                      Default is 'IN' when `$value` is an array, '=' otherwise.
     *
     * @return static Current Claws instance.
     */
    public function where(
        string $field,
        string|null $compare_type = null,
        $values = null,
        ?string $callback_or_type = 'esc_sql'
    ) : static {
        $this->setCurrentClause('where');
        $this->setCurrentField($field);

        // Handle shorthand comparison phrases.
        if (isset($compare_type) && isset($values)) {
            $callback = $this->getCallback($callback_or_type);

            $this->compare($compare_type, $values, $callback);
        }

        return $this;
    }

    /**
     * Handles delegating short-hand value comparison phrases.
     *
     * @since 1.0.0
     *
     * @param string                  $type     Type of comparison. Accepts '=', '!=', '<', '>', '>=', or '<='.
     * @param string|int|array<mixed> $values   Single value(s) of varying type, or an array of values.
     * @param callable                $callback Callback to pass to the comparison method.
     *
     * @return static Current Claws instance.
     */
    public function compare(
        string $type,
        string|int|array $values,
        callable $callback
    ) : static {
        switch ($type) {
            case '!=':
                $this->doesntEqual($values, $callback);
                break;

            case '<':
                $this->lt($values, $callback);
                break;

            case '>':
                $this->gt($values, $callback);
                break;

            case '<=':
                $this->lte($values, $callback);
                break;

            case '>=':
                $this->gte($values, $callback);
                break;

            case '=':
            default:
                $this->equals($values, $callback);
                break;
        }

        return $this;
    }

    /**
     * Handles '=' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed|array<mixed> $values           Value of varying types, or array of values.
     * @param string|callable    $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                             types to use preset callbacks. Default 'esc_sql'.
     * @param Operator           $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                             building the expression. Default 'OR'.
     *
     * @return static Current Claws instance.
     */
    public function equals(
        $values,
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        $sql = $this->getComparisonSql(
            $values,
            $callback_or_type,
            '=',
            $operator
        );

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles '!=' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default 'OR'.
     *
     * @return static Current Claws instance.
     */
    public function doesntEqual(
        $values,
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        $sql = $this->getComparisonSql(
            $values,
            $callback_or_type,
            '!=',
            $operator
        );

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles '>' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function gt(
        $values,
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        $sql = $this->getComparisonSql(
            $values,
            $callback_or_type,
            '>',
            $operator
        );

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles '<' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function lt(
        $values,
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        $sql = $this->getComparisonSql(
            $values,
            $callback_or_type,
            '<',
            $operator
        );

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles '>=' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function gte(
        $values,
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        $sql = $this->getComparisonSql(
            $values,
            $callback_or_type,
            '>=',
            $operator
        );

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles '<=' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function lte(
        $values,
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        $sql = $this->getComparisonSql(
            $values,
            $callback_or_type,
            '<=',
            $operator
        );

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles 'LIKE' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default `Claws->esc_like()`.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function like(
        $values,
        string|callable $callback_or_type = 'esc_like',
        Operator $operator = Operator::OR
    ) : static {
        $sql = $this->getLikeSql($values, $callback_or_type, 'LIKE', $operator);

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles 'NOT LIKE' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default is `Claws->esc_like()`.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function notLike(
        $values,
        string|callable $callback_or_type = 'esc_like',
        Operator $operator = Operator::OR
    ) : static {
        $sql = $this->getLikeSql(
            $values,
            $callback_or_type,
            'NOT LIKE',
            $operator
        );

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles 'IN' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function in(
        $values,
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        if (!is_array($values)) {
            $this->equals($values, $callback_or_type, $operator);
        } else {
            $sql = $this->getInSql($values, $callback_or_type, 'IN');

            $this->addClauseSql($sql);
        }

        return $this;
    }

    /**
     * Handles 'NOT IN' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function notIn(
        $values,
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        if (!is_array($values)) {
            $this->doesntEqual($values, $callback_or_type, $operator);
        } else {
            $sql = $this->getInSql($values, $callback_or_type, 'NOT IN');

            $this->addClauseSql($sql);
        }

        return $this;
    }

    /**
     * Handles 'BETWEEN' value comparison.
     *
     * Note: If doing a between comparison for dates, care should be taken to ensure
     * the beginning and ending dates represent the beginning and/or end of the day
     * including hours, minutes, and seconds, depending on the expected range.
     *
     * @since 1.0.0
     *
     * @param array<mixed>    $values           Array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     *
     * @return static Current Claws instance.
     */
    public function between(
        $values,
        string|callable $callback_or_type = 'esc_sql'
    ) : static {
        $sql = $this->getBetweenSql($values, $callback_or_type, 'BETWEEN');

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles 'NOT BETWEEN' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     *
     * @return static Current Claws instance.
     */
    public function notBetween(
        $values,
        string|callable $callback_or_type = 'esc_sql'
    ) : static {
        $sql = $this->getBetweenSql($values, $callback_or_type, 'NOT BETWEEN');

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Handles 'EXISTS' value comparison.
     *
     * @since 1.0.0
     *
     * @param mixed           $values           Value of varying types, or array of values.
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function exists(
        $values,
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        return $this->equals($values, $callback_or_type, $operator);
    }

    /**
     * Handles 'NOT EXISTS' value comparison.
     *
     * @since 1.0.0
     *
     * @param string|callable $callback_or_type Optional. Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks. Default 'esc_sql'.
     * @param Operator        $operator         Optional. If `$value` is an array, whether to use 'OR' or 'AND' when
     *                                          building the expression. Default Operator::OR.
     *
     * @return static Current Claws instance.
     */
    public function notExists(
        string|callable $callback_or_type = 'esc_sql',
        Operator $operator = Operator::OR
    ) : static {
        $sql = $this->buildComparisonSql([''], 'IS NULL', $operator);

        $this->addClauseSql($sql);

        return $this;
    }

    /**
     * Helper used by direct comparison methods to build SQL.
     *
     * @since 1.0.0
     *
     * @param array<mixed>    $values            Array of values to compare.
     * @param string|callable $callback_or_type  Sanitization callback to pass values through, or shorthand
     *                                           types to use preset callbacks.
     * @param string          $compare_type      Comparison type to make. Accepts '=', '!=', '<', '>', '<=', or '>='.
     *                                           Default '='.
     * @param Operator        $operator          Optional. Operator to use between multiple sets of value comparisons.
     *                                           Default Operator::OR.
     *
     * @return string Raw, sanitized SQL.
     */
    protected function getComparisonSql(
        $values,
        string|callable $callback_or_type,
        string $compare_type,
        Operator $operator = Operator::OR
    ) : string {
        if (!in_array($compare_type, ['=', '!=', '<', '>', '<=', '>='])) {
            $compare_type = '=';
        }

        $callback = $this->getCallback($callback_or_type);
        $values = $this->prepareValues($values);

        // Sanitize the values and built the SQL.
        $values = array_map($callback, $values);

        return $this->buildComparisonSql($values, $compare_type, $operator);
    }

    /**
     * Builds and retrieves the actual comparison SQL.
     *
     * @since 1.0.0
     *
     * @param array<mixed> $values       Array of values.
     * @param string       $compare_type Comparison type to make. Accepts '=', '!=', '<', '>', '<=', or '>='.
     *                                   Default '='.
     * @param Operator     $operator     Optional. Operator to use between value comparisons. Default Operator::OR.
     *
     * @return string Comparison SQL.
     */
    protected function buildComparisonSql(
        $values,
        string $compare_type,
        Operator $operator = Operator::OR
    ) : string {
        $sql = '';

        $count = count($values);
        $current = 0;
        $field = $this->getCurrentField();

        // Loop through the values and bring in $operator if needed.
        foreach ($values as $value) {
            $type = $this->getCastForType(gettype($value));

            $value = $this->prepare('%s', $value);

            if (SqlType::CHAR->value !== $type) {
                $value = "CAST( {$value} AS {$type} )";
            }

            $sql .= "`{$field}` {$compare_type} {$value}";

            if (++$current !== $count) {
                $sql .= " {$operator->value} ";
            }
        }

        // Finish the phrase.
        if ($count > 1) {
            $sql = '( '.$sql.' )';
        }

        return $sql;
    }

    /**
     * Helper used by 'in' comparison methods to build SQL.
     *
     * @since 1.0.0
     *
     * @param array<mixed>    $values           Array of values to compare.
     * @param string|callable $callback_or_type Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks.
     * @param string          $compare_type     Comparison to make. Accepts 'IN' or 'NOT IN'.
     *
     * @return string Raw, sanitized SQL.
     */
    protected function getInSql(
        $values,
        string|callable $callback_or_type,
        string $compare_type
    ) : string {
        $field = $this->getCurrentField();
        $callback = $this->getCallback($callback_or_type);
        $compare_type = strtoupper($compare_type);

        if (!in_array($compare_type, ['IN', 'NOT IN'])) {
            $compare_type = 'IN';
        }

        // Escape values.
        $values = array_map(function ($value) use ($callback) {
            $value = call_user_func($callback, $value);

            if ('string' === gettype($value)) {
                $value = "'{$value}'";
            }

            return $value;
        }, $values);

        $values = implode(', ', $values);

        $sql = "{$field} {$compare_type}( {$values} )";

        return $sql;
    }

    /**
     * Helper used by 'LIKE' comparison methods to build SQL.
     *
     * @since 1.0.0
     *
     * @param array<mixed>    $values           Array of values to compare.
     * @param string|callable $callback_or_type Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks.
     * @param string          $compare_type     Comparison to make. Accepts 'LIKE' or 'NOT LIKE'.
     * @param Operator        $operator         Operator.
     *
     * @return string Raw, sanitized SQL.
     */
    protected function getLikeSql(
        $values,
        string|callable $callback_or_type,
        string $compare_type,
        Operator $operator
    ) : string {
        $sql = '';

        $callback = $this->getCallback($callback_or_type);
        $field = $this->getCurrentField();
        $values = $this->prepareValues($values);
        $compare_type = strtoupper($compare_type);

        if (!in_array($compare_type, ['LIKE', 'NOT LIKE'])) {
            $compare_type = 'LIKE';
        }

        $values = array_map($callback, $values);
        $value_count = count($values);

        $current = 0;

        // Escape values and build the SQL.
        foreach ($values as $value) {
            $value = $this->prepare('%s', $value);

            $sql .= "`{$field}` {$compare_type} '%%{$value}%%'";

            if ($value_count > 1 && ++$current !== $value_count) {
                $sql .= " {$operator->value} ";
            }
        }

        return $sql;
    }

    /**
     * Helper used by 'BETWEEN' comparison methods to build SQL.
     *
     * @since 1.0.0
     *
     * @param array<mixed>    $values           Array of values to compare.
     * @param string|callable $callback_or_type Sanitization callback to pass values through, or shorthand
     *                                          types to use preset callbacks.
     * @param string          $compare_type     Comparison to make. Accepts 'BETWEEN' or 'NOT BETWEEN'.
     *
     * @return string Raw, sanitized SQL.
     */
    protected function getBetweenSql(
        $values,
        string|callable $callback_or_type,
        string $compare_type
    ) : string {
        $sql = '';

        // Bail if `$values` isn't an array or there aren't at least two values.
        if (!is_array($values) || count($values) < 2) {
            return $sql;
        }

        $compare_type = strtoupper($compare_type);

        if (!in_array($compare_type, ['BETWEEN', 'NOT BETWEEN'])) {
            $compare_type = 'BETWEEN';
        }

        $field = $this->getCurrentField();
        $callback = $this->getCallback($callback_or_type);

        // Grab the first two values in the array.
        $values = array_slice($values, 0, 2);

        // Sanitize the values according to the callback and cast dates.
        $values = array_map(function ($value) use ($callback) {
            $value = call_user_func($callback, $value);

            if (false !== strpos($value, ':')) {
                $value = $this->prepare('%s', $value);
                $value = "CAST( {$value} AS DATE)";
            }

            return $value;
        }, $values);

        $sql .= "( `{$field}` {$compare_type} %s AND %s )";

        return $this->prepare($sql, $values);
    }

    /**
     * Retrieves the callback to use for the given type.
     *
     * @since 1.0.0
     *
     * @param string|callable $callback_or_type Standard type to retrieve a callback for, or an callback.
     *
     * @return callable Callback.
     */
    public function getCallback(string|callable $callback_or_type) : callable
    {
        return is_callable($callback_or_type) ? $callback_or_type
            : $this->getCallbackForType($callback_or_type);
    }

    /**
     * Determines the right callback for a given type of value.
     *
     * @since 1.0.0
     *
     * @param string $type Type of value to retrieve a callback for.
     *
     * @return callable Callback string.
     */
    public function getCallbackForType(string $type) : callable
    {
        switch ($type) {
            case 'int':
            case 'integer':
                $callback = 'intval';
                break;

            case 'float':
            case 'double':
                $callback = 'floatval';
                break;

            case 'string':
                $callback = 'sanitize_text_field';
                break;

            case 'key':
                $callback = 'sanitize_key';
                break;

            case 'esc_like':
                $callback = [$this, 'escLike'];
                break;

            default:
                $callback = 'esc_sql';
                break;
        }

        return $callback;
    }

    /**
     * Retrieves the CAST value for a given value type.
     *
     * @since 1.0.0
     *
     * @param string $type Value type (as derived from gettype()).
     *
     * @return string MySQL-ready CAST type.
     */
    public function getCastForType(string $type) : string
    {
        return SqlType::CHAR->resolve($type);
    }

    /**
     * Escapes a value used in a 'LIKE' comparison.
     *
     * @since 1.0.0
     *
     * @param mixed $like LIKE comparison value.
     *
     * @return string Escaped value.
     */
    protected function escLike($like) : string
    {
        return addcslashes($like, '_%\\');
    }

    /**
     * Ensures values are in array form.
     *
     * Seems silly, but anywhere blatant duplication can be reduced is a win.
     *
     * @since 1.0.0
     *
     * @param mixed|array $values Single values of varying type or an array of values.
     *
     * @return array<mixed> Array of values.
     */
    protected function prepareValues($values) : array
    {
        return (array)$values;
    }

    /**
     * Replaces the previous phrase with the given prepared SQL.
     *
     * @since 1.0.0
     *
     * @param string      $sql    Prepared SQL to replace the phrase with.
     * @param null|string $clause Optional. Clause to replace the last phrase for. Default is the current clause.
     *
     * @return void
     */
    protected function replacePreviousPhrase(
        string $sql,
        ?string $clause = null
    ) : void {
        $clause = $this->getClause($clause);

        // Pop off the last phrase.
        array_pop($this->clausesInProgress[$clause]);

        // Replace it with the new one.
        $this->clausesInProgress[$clause][] = $sql;
    }

    /**
     * Adds prepared SQL to the current clause.
     *
     * @since 1.0.0
     *
     * @param string      $sql    Prepared SQL to add to the clause.
     * @param null|string $clause Optional. Clause to add the SQL to. Default is the current clause.
     *
     * @return void
     */
    public function addClauseSql(string $sql, ?string $clause = null) : void
    {
        $clause = $this->getClause($clause);

        if (true === $this->amendingPrevious) {
            $operator = $this->getCurrentOperator();

            $sql = $this->getPreviousPhrase()." {$operator->value} {$sql}";

            $this->replacePreviousPhrase($sql, $clause);

            // Reset the amendment flag.
            $this->amendingPrevious = false;

            $this->previousPhrase = $sql;
        } else {
            $this->previousPhrase = $sql;
            $this->clausesInProgress[$clause][] = $this->previousPhrase;
        }
    }

    /**
     * Retrieves raw, sanitized SQL for the current clause.
     *
     * @since 1.0.0
     *
     * @param null|string $clause     Optional. Clause to build SQL for. Default is the current clause.
     * @param null|bool   $reset_vars Optional. Whether to reset the clause, field, and operator vars
     *                                after retrieving the clause's SQL. Default true.
     *
     * @return string Raw, sanitized SQL.
     */
    public function getSql(
        ?string $clause = null,
        ?bool $reset_vars = true
    ) : string {
        $sql = '';

        $clause = $this->getClause($clause);

        if (isset($this->clausesInProgress[$clause])) {
            $sql .= strtoupper($clause);

            $current = 0;

            foreach ($this->clausesInProgress[$clause] as $chunk) {
                if (++$current === 1) {
                    $sql .= " {$chunk}";
                } elseif ($current >= 2) {
                    $sql .= " AND {$chunk}";
                }
            }

            if (true === $reset_vars) {
                $this->resetVars();
            }
        }

        return $sql;
    }

    /**
     * Sets the current clause.
     *
     * @since 1.0.0
     *
     * @param string $clause Clause to set as current.
     *
     * @return static Current claws instance.
     */
    public function setCurrentClause(string $clause) : static
    {
        $clause = strtolower($clause);

        if (in_array($clause, $this->allowedClauses, true)) {
            $this->currentClause = $clause;
        }

        return $this;
    }

    /**
     * Retrieves the current clause.
     *
     * @since 1.0.0
     *
     * @param null|string $clause Optional. Clause to retrieve. Default is the current clause.
     *
     * @return string Current clause name.
     */
    public function getClause(?string $clause = null) : string
    {
        if (!isset($clause)
            || !in_array(
                $clause,
                $this->allowedClauses,
                true
            )
        ) {
            $clause = $this->currentClause;
        }

        return $clause;
    }

    /**
     * Sets the current field.
     *
     * @since 1.0.0
     *
     * @param string $field Field to set as current.
     *
     * @return static Current claws instance.
     */
    public function setCurrentField(string $field) : static
    {
        if ($field !== $this->getCurrentField()) {
            $this->currentField = preg_replace(
                '/[^a-z0-9_\-]/',
                '',
                strtolower($field)
            );
        }

        return $this;
    }

    /**
     * Retrieves the current field name.
     *
     * @since 1.0.0
     *
     * @return string Current field name.
     */
    public function getCurrentField() : string
    {
        return $this->currentField;
    }

    /**
     * Sets the current operator for use in complex phrase building.
     *
     * @since 1.0.0
     *
     * @param Operator $operator Operator to persist between method calls. Accepts 'OR' or 'AND'.
     *
     * @return static Current claws instance.
     */
    public function setCurrentOperator(Operator $operator) : static
    {
        $this->currentOperator = $operator;

        return $this;
    }

    /**
     * Flags the previously-stored phrase to be amended and appended with the given operator.
     *
     * @since 1.0.0
     *
     * @param Operator    $operator Operator to persist.
     * @param null|string $clause   Optional. Clause to amend the previous chunk for.
     *                              Default is the current clause.
     *
     * @return static Current Claws instance.
     */
    private function __setCurrentOperator(
        Operator $operator,
        ?string $clause
    ) : static {
        $this->setCurrentOperator($operator);
        $this->amendingPrevious = true;

        $clause = $this->getClause($clause);
        $chunks = $this->clausesInProgress[$clause];

        if (!empty($chunks)) {
            $this->previousPhrase = end($chunks);
        }

        return $this;
    }

    /**
     * Retrieves the current operator (for use in complex phrase building).
     *
     * @since 1.0.0
     *
     * @return Operator Current operator.
     */
    public function getCurrentOperator() : Operator
    {
        return $this->currentOperator;
    }

    /**
     * Retrieves the previous phrase for the given clause.
     *
     * @since 1.0.0
     *
     * @return string Previous phrase SQL.
     */
    public function getPreviousPhrase() : string
    {
        return $this->previousPhrase;
    }

    /**
     * Resets the current clause, field, and operator.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function resetVars() : void
    {
        $this->currentClause = '';
        $this->currentField = '';
        $this->currentOperator = Operator::OR;
    }

    /**
     * Prepare an escaped SQL statement with given values.
     *
     * Forked from WordPress wpdb::prepare() method.
     *
     * @param string       $sql
     * @param array<mixed> ...$values
     *
     * @return string
     */
    private function prepare(string $sql, ...$values) : string
    {
        // @TODO fix this discrepancy
        $query = $sql;

        /*
         * Specify the formatting allowed in a placeholder. The following are allowed:
         *
         * - Sign specifier, e.g. $+d
         * - Numbered placeholders, e.g. %1$s
         * - Padding specifier, including custom padding characters, e.g. %05s, %'#5s
         * - Alignment specifier, e.g. %05-s
         * - Precision specifier, e.g. %.2f
         */
        $allowed_format
            = '(?:[1-9][0-9]*[$])?[-+0-9]*(?: |0|\'.)?[-+0-9]*(?:\.[0-9]+)?';

        /*
         * If a %s placeholder already has quotes around it, removing the existing quotes
         * and re-inserting them ensures the quotes are consistent.
         *
         * For backward compatibility, this is only applied to %s, and not to placeholders like %1$s,
         * which are frequently used in the middle of longer strings, or as table name placeholders.
         */
        $query = str_replace(
            "'%s'",
            '%s',
            $query
        ); // Strip any existing single quotes.
        $query = str_replace(
            '"%s"',
            '%s',
            $query
        ); // Strip any existing double quotes.

        // Escape any unescaped percents (i.e. anything unrecognised).
        $query = preg_replace(
            "/%(?:%|$|(?!($allowed_format)?[sdfFi]))/",
            '%%\\1',
            $query
        );

        // Extract placeholders from the query.
        $split_query = preg_split(
            "/(^|[^%]|(?:%%)+)(%(?:$allowed_format)?[sdfFi])/",
            $query,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        $split_query_count = count($split_query);

        /*
         * Split always returns with 1 value before the first placeholder (even with $query = "%s"),
         * then 3 additional values per placeholder.
         */
        $placeholder_count = (($split_query_count - 1) / 3);

        // If args were passed as an array, as in vsprintf(), move them up.
        $passed_as_array = (isset($values[0]) && is_array($values[0])
            && 1 === count($values)); // @phpstan-ignore-line
        if ($passed_as_array) {
            $values = $values[0];
        }

        $new_query = '';
        $key
            = 2; // Keys 0 and 1 in $split_query contain values before the first placeholder.
        $arg_id = 0;
        $arg_identifiers = [];
        $arg_strings = [];

        while ($key < $split_query_count) {
            $placeholder = $split_query[$key];

            $format = substr($placeholder, 1, -1);
            $type = substr($placeholder, -1);

            if ('f' === $type
                && '%' === substr(
                    $split_query[$key - 1],
                    -1,
                    1
                )
            ) {
                $s = $split_query[$key - 2].$split_query[$key - 1];
                $k = 1;
                $l = strlen($s);
                while ($k <= $l && '%' === $s[$l - $k]) {
                    $k++;
                }

                $placeholder = '%'.($k % 2 ? '%' : '').$format.$type;

                --$placeholder_count;
            } else {
                // Force floats to be locale-unaware.
                if ('f' === $type) {
                    $type = 'F';
                    $placeholder = '%'.$format.$type;
                }

                if ('i' === $type) {
                    $placeholder = '`%'.$format.'s`';
                    // Using a simple strpos() due to previous checking (e.g. $allowed_format).
                    $argnum_pos = strpos($format, '$');

                    if (false !== $argnum_pos) {
                        // sprintf() argnum starts at 1, $arg_id from 0.
                        $arg_identifiers[] = (((int)substr(
                                $format,
                                0,
                                $argnum_pos
                            )) - 1);
                    } else {
                        $arg_identifiers[] = $arg_id;
                    }
                } elseif ('d' !== $type && 'F' !== $type) {
                    /*
                     * i.e. ( 's' === $type ), where 'd' and 'F' keeps $placeholder unchanged,
                     * and we ensure string escaping is used as a safe default (e.g. even if 'x').
                     */
                    $argnum_pos = strpos($format, '$');

                    if (false !== $argnum_pos) {
                        $arg_strings[] = (((int)substr($format, 0, $argnum_pos))
                            - 1);
                    } else {
                        $arg_strings[] = $arg_id;
                    }

                    /*
                     * Unquoted strings for backward compatibility (dangerous).
                     * First, "numbered or formatted string placeholders (eg, %1$s, %5s)".
                     * Second, if "%s" has a "%" before it, even if it's unrelated (e.g. "LIKE '%%%s%%'").
                     */
                    if ('' === $format
                        && '%' !== substr(
                            $split_query[$key - 1],
                            -1,
                            1
                        )
                    ) {
                        $placeholder = "'%".$format."s'";
                    }
                }
            }

            // Glue (-2), any leading characters (-1), then the new $placeholder.
            $new_query .= $split_query[$key - 2].$split_query[$key - 1]
                .$placeholder;

            $key += 3;
            $arg_id++;
        }

        // Replace $query; and add remaining $query characters, or index 0 if there were no placeholders.
        $query = $new_query.$split_query[$key - 2];

        $dual_use = array_intersect($arg_identifiers, $arg_strings);

        if (count($dual_use) > 0) {
            return '';
        }

        $args_count = count($values);

        if ($args_count !== $placeholder_count) {
            if (1 === $placeholder_count && $passed_as_array) {
                return '';
            } else {
                /*
                 * If we don't have enough arguments to match the placeholders,
                 * return an empty string to avoid a fatal error on PHP 8.
                 */
                if ($args_count < $placeholder_count) {
                    $max_numbered_placeholder = 0;

                    for ($i = 2, $l = $split_query_count; $i < $l; $i += 3) {
                        // Assume a leading number is for a numbered placeholder, e.g. '%3$s'.
                        $argnum = (int)substr($split_query[$i], 1);

                        if ($max_numbered_placeholder < $argnum) {
                            $max_numbered_placeholder = $argnum;
                        }
                    }

                    if (!$max_numbered_placeholder
                        || $args_count < $max_numbered_placeholder
                    ) {
                        return '';
                    }
                }
            }
        }

        $args_escaped = [];

        foreach ($values as $i => $value) {
            if (in_array($i, $arg_identifiers, true)) {
                $args_escaped[] = str_replace('`', '``', $value);
            } elseif (is_int($value) || is_float($value)) {
                $args_escaped[] = $value;
            } else {
                if (!is_scalar($value) && !is_null($value)) {
                    // Preserving old behavior, where values are escaped as strings.
                    $value = '';
                }

                $args_escaped[] = $this->_real_escape($value);
            }
        }

        $sql = vsprintf($query, $args_escaped);

        return $this->add_placeholder_escape($sql);
    }

    /**
     * Real escape, using mysqli_real_escape_string() or mysql_real_escape_string().
     *
     * Forked from WordPress' wpdb::_real_escape() method.
     *
     * @param string $data
     *
     * @return string
     */
    private function _real_escape(string $data) : string
    {
        $escaped = addslashes($data);

        return $this->add_placeholder_escape($escaped);
    }

    /**
     * Adds a placeholder escape string, to escape anything that resembles a printf() placeholder.
     *
     * Forked from WordPress' wpdb::add_placeholder_escape() method.
     *
     * @param string $sql SQL.
     *
     * @return string
     */
    private function add_placeholder_escape(string $sql) : string
    {
        return str_replace('%', $this->placeholder_escape(), $sql);
    }

    /**
     * Replace % placeholders with a hash.
     *
     * Forked from WordPress' wpdb::placeholder_escape() method.
     *
     * @return string
     */
    private function placeholder_escape() : string
    {
        static $placeholder;

        if (!$placeholder) {
            // If ext/hash is not present, compat.php's hash_hmac() does not support sha256.
            $algo = function_exists('hash') ? 'sha256' : 'sha1';
            $salt = (string)rand();

            $placeholder = '{'.hash_hmac($algo, uniqid($salt, true), $salt).'}';
        }

        return $placeholder;
    }
}

/**
 * Shorthand helper for retrieving a Claws instance.
 *
 * @since 1.0.0
 *
 * @return Claws Claws instance.
 */
function claws()
{
    return new Claws;
}
