<?php

namespace Duorenwei\LaravelDm8\Dm8\Query\Processors;

use DateTime;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use PDO;

class DmProcessor extends Processor
{
    /**
     * Linux pdo_dm rejects the vendor driver's default output binding
     * `PDO::PARAM_INT, -1` with "Invalid buffer length[0]".
     * Bind the returning id as an explicit output parameter with a
     * positive buffer length so insertGetId works consistently.
     *
     * @param  Builder  $query
     * @param  string  $sql
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $connection = $query->getConnection();

        $connection->recordsHaveBeenModified();
        $start = microtime(true);

        $id = 0;
        $parameter = 1;
        $statement = $this->prepareStatement($query, $sql);
        $values = $this->incrementBySequence($values, $sequence);
        $parameter = $this->bindValues($values, $statement, $parameter);
        $this->bindReturningId($statement, $parameter, $id);
        $statement->execute();

        $values[] = '?';
        $connection->logQuery($sql, $values, $start);

        return (int) $id;
    }

    /**
     * Save query with blob returning primary key value.
     *
     * @param  Builder  $query
     * @param  string  $sql
     * @param  array  $values
     * @param  array  $binaries
     * @return int|false
     */
    public function saveLob(Builder $query, $sql, array $values, array $binaries)
    {
        $connection = $query->getConnection();

        $connection->recordsHaveBeenModified();
        $start = microtime(true);

        $id = 0;
        $parameter = 1;
        $statement = $this->prepareStatement($query, $sql);

        $parameter = $this->bindValues($values, $statement, $parameter);

        $countBinary = count($binaries);
        for ($i = 0; $i < $countBinary; $i++) {
            $statement->bindParam($parameter, $binaries[$i], PDO::PARAM_LOB, -1);
            $parameter++;
        }

        $this->bindReturningId($statement, $parameter, $id);

        if (! $statement->execute()) {
            return false;
        }

        $connection->logQuery($sql, $values, $start);

        return (int) $id;
    }

    /**
     * Process the results of a column listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processColumnListing($results)
    {
        $mapping = function ($r) {
            $r = (object) $r;

            return strtolower($r->column_name);
        };

        return array_map($mapping, $results);
    }

    /**
     * Get prepared statement.
     *
     * @param  Builder  $query
     * @param  string  $sql
     * @return \PDOStatement
     */
    private function prepareStatement(Builder $query, $sql)
    {
        $connection = $query->getConnection();
        $pdo = $connection->getPdo();

        return $pdo->prepare($sql);
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  array  $values
     * @param  string|null  $sequence
     * @return array
     */
    protected function incrementBySequence(array $values, $sequence)
    {
        $builder = null;
        $builderArgs = [];

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 10) as $frame) {
            if (($frame['object'] ?? null) instanceof EloquentBuilder) {
                $builder = $frame['object'];
                break;
            }
        }

        if ($builder instanceof EloquentBuilder) {
            foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 10) as $frame) {
                $args = $frame['args'] ?? [];
                if (isset($args[1][0]) && is_array($args[1][0])) {
                    $builderArgs = $args;
                    break;
                }
            }
        }

        if ($builder instanceof EloquentBuilder && ! isset($builderArgs[1][0][$sequence])) {
            $model = $builder->getModel();
            $connection = $model->getConnection();
            if ($model->sequence && $model->incrementing) {
                $values[] = (int) $connection->getSequence()->nextValue($model->sequence);
            }
        }

        return $values;
    }

    /**
     * Bind values to parameter.
     *
     * @param  array  $values
     * @param  \PDOStatement  $statement
     * @param  int  $parameter
     * @return int
     */
    private function bindValues(&$values, $statement, $parameter)
    {
        $count = count($values);
        for ($i = 0; $i < $count; $i++) {
            if (is_object($values[$i])) {
                if ($values[$i] instanceof DateTime) {
                    $values[$i] = $values[$i]->format('Y-m-d H:i:s');
                } else {
                    $values[$i] = (string) $values[$i];
                }
            }

            $type = $this->getPdoType($values[$i]);
            $statement->bindParam($parameter, $values[$i], $type);
            $parameter++;
        }

        return $parameter;
    }

    /**
     * Bind the returning id using a positive output buffer length.
     *
     * @param  \PDOStatement  $statement
     * @param  int  $parameter
     * @param  int  $id
     * @return void
     */
    private function bindReturningId($statement, $parameter, &$id)
    {
        $type = PDO::PARAM_INT;

        if (defined('PDO::PARAM_INPUT_OUTPUT')) {
            $type |= PDO::PARAM_INPUT_OUTPUT;
        }

        $statement->bindParam($parameter, $id, $type, 32);
    }

    /**
     * Get PDO type depending on value.
     *
     * @param  mixed  $value
     * @return int
     */
    private function getPdoType($value)
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }

        if (is_null($value)) {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }
}
