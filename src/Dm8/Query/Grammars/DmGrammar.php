<?php

namespace Duorenwei\LaravelDm8\Dm8\Query\Grammars;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Str;
use Duorenwei\LaravelDm8\Dm8\Dm8ReservedWords;

class DmGrammar extends Grammar
{
    use Dm8ReservedWords;

    /**
     * The keyword identifier wrapper format.
     *
     * @var string
     */
    protected $wrapper = '%s';

    /**
     * @var bool
     */
    protected $lowercaseNames = false;

    /**
     * @var string
     */
    protected $schema_prefix = '';

    protected function compileDeleteWithJoins(Builder $query, $table, $where)
    {
        $alias = last(explode(' as ', $table));
        $joins = $this->compileJoins($query, $query->joins);

        return "delete (select * from {$alias} {$joins} {$where})";
    }

    public function compileExists(Builder $query)
    {
        $q = clone $query;
        $q->columns = [];
        $q->select('1 as exists')->whereRaw('rownum = 1');

        $sql = $this->compileSelect($q);

        return preg_replace('/1 as exists/', '1 as "exists"', $sql);
    }

    public function compileSelect(Builder $query)
    {
        $original = parent::compileSelect($query);

        if (
            preg_match('/select \* from \(select \* from/i', $original) &&
            preg_match('/where rownum = 1\)$/i', $original)
        ) {
            if (preg_match('/select \* from \((.*?)\) where rownum = 1$/is', $original, $matches)) {
                return 'select * from (' . $matches[1] . ') where rownum = 1';
            }
        }

        return $original;
    }

    protected function isPaginationable(Builder $query, array $components)
    {
        return ($query->limit > 0 || $query->offset > 0) && ! array_key_exists('lock', $components);
    }

    protected function compileAnsiOffset(Builder $query, array $components)
    {
        if ($query->getConnection()->getConfig('server_version') == '12c') {
            $components['columns'] = str_replace('select', "select /*+ FIRST_ROWS({$query->limit}) */", $components['columns']);
            $offset = $query->offset ?: 0;
            $limit = $query->limit;
            $components['limit'] = "offset $offset rows fetch next $limit rows only";

            return $this->concatenate($components);
        }

        $constraint = $this->compileRowConstraint($query);
        $sql = $this->concatenate($components);

        return $this->compileTableExpression($sql, $constraint, $query);
    }

    protected function compileRowConstraint(Builder $query)
    {
        $start = $query->offset + 1;
        $finish = $query->offset + $query->limit;

        if ($query->limit == 1 && is_null($query->offset)) {
            return '= 1';
        }

        if ($query->offset && is_null($query->limit)) {
            return ">= {$start}";
        }

        return "between {$start} and {$finish}";
    }

    protected function compileTableExpression($sql, $constraint, Builder $query)
    {
        if ($query->limit == 1 && is_null($query->offset)) {
            return "select * from ({$sql}) where rownum {$constraint}";
        }

        if (! is_null($query->limit) && ! is_null($query->offset)) {
            $start = $query->offset + 1;
            $finish = $query->offset + $query->limit;

            return "select t2.* from ( select rownum AS \"rn\", t1.* from ({$sql}) t1 where rownum <= {$finish}) t2 where t2.\"rn\" >= {$start}";
        }

        return "select t2.* from ( select rownum AS \"rn\", t1.* from ({$sql}) t1 ) t2 where t2.\"rn\" {$constraint}";
    }

    public function compileTruncate(Builder $query)
    {
        return ['truncate table '.$this->wrapTable($query->from) => []];
    }

    public function wrap($value, $prefixAlias = false)
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $isReservedWord = false;

        if (is_string($value) && str_contains($value, ' as ')) {
            list($column, $alias) = explode(' as ', $value);
            if (in_array(strtoupper(trim($alias)), $this->reserves)) {
                $isReservedWord = true;
            }
        } elseif (is_string($value)) {
            $parts = explode('.', $value);
            $lastPart = end($parts);
            if (in_array(strtoupper($lastPart), $this->reserves)) {
                $isReservedWord = true;
            }
        }

        $wrapped = parent::wrap($value, $prefixAlias);

        if ($isReservedWord) {
            return $wrapped;
        }

        return str_replace('"', '', $wrapped);
    }

    public function wrapTable($table)
    {
        if ($this->isExpression($table)) {
            return $this->getValue($table);
        }

        if (strpos(strtolower((string) $table), ' as ') !== false) {
            list($tableName, $alias) = explode(' as ', strtolower((string) $table));

            return $this->wrap($this->tablePrefix.$tableName).' '.$alias;
        }

        return $this->getSchemaPrefix().$this->tablePrefix.$table;
    }

    public function getSchemaPrefix()
    {
        return ! empty($this->schema_prefix) ? $this->wrapValue($this->schema_prefix).'.' : '';
    }

    public function setSchemaPrefix($prefix)
    {
        $this->schema_prefix = $prefix;
    }

    protected function wrapValue($value)
    {
        if ($value === '*' || is_numeric($value) || $value === null) {
            return $value;
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return $value;
        }

        return '"' . str_replace('"', '""', Str::upper($value)) . '"';
    }

    public function whereBasic($query, $where)
    {
        $value = $this->parameter($where['value']);
        $operator = str_replace('?', '??', $where['operator']);

        return $this->wrap($where['column']).' '.$operator.' '.$value;
    }

    public function parameter($value)
    {
        return $this->isExpression($value) ? $this->getValue($value) : '?';
    }

    public function compileInsertGetId(Builder $query, $values, $sequence = 'id')
    {
        if (empty($sequence)) {
            $sequence = 'id';
        }

        $backtrace = $this->findEloquentBuilderInBacktrace();

        if ($backtrace instanceof EloquentBuilder) {
            $model = $backtrace->getModel();
            if ($model->sequence && ! isset($values[$model->getKeyName()]) && $model->incrementing) {
                $values[$sequence] = null;
            }
        }

        return $this->compileInsert($query, $values).' returning '.$this->wrap($sequence).' into ?';
    }

    protected function findEloquentBuilderInBacktrace()
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 10) as $frame) {
            if (($frame['object'] ?? null) instanceof EloquentBuilder) {
                return $frame['object'];
            }
        }

        return null;
    }

    public function compileInsert(Builder $query, $values)
    {
        $table = $this->wrapTable($query->from);

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        $columns = $this->columnize(array_keys(reset($values)));

        if (count($values) === 1) {
            $parameters = $this->parameterize(reset($values));

            return "insert into $table ($columns) values ($parameters)";
        }

        $parameterGroups = [];
        foreach ($values as $record) {
            $parameterGroups[] = '('.$this->parameterize($record).')';
        }

        $parameters = implode(', ', $parameterGroups);

        return "insert into $table ($columns) values $parameters";
    }

    public function compileInsertLob(Builder $query, $values, $binaries, $sequence = 'id')
    {
        if (empty($sequence)) {
            $sequence = 'id';
        }

        $table = $this->wrapTable($query->from);

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        if (! is_array(reset($binaries))) {
            $binaries = [$binaries];
        }

        $columns = $this->columnize(array_keys(reset($values)));
        $binaryColumns = $this->columnize(array_keys(reset($binaries)));
        $columns .= (empty($columns) ? '' : ', ').$binaryColumns;

        $parameters = $this->parameterize(reset($values));
        $binaryParameters = $this->parameterize(reset($binaries));

        $value = array_fill(0, count($values), "$parameters");
        $binaryValue = array_fill(0, count($binaries), str_replace('?', 'EMPTY_BLOB()', $binaryParameters));

        $value = array_merge($value, $binaryValue);
        $parameters = implode(', ', array_filter($value));

        return "insert into $table ($columns) values ($parameters) returning ".$binaryColumns.', '.$this->wrap($sequence).' into '.$binaryParameters.', ?';
    }

    public function compileUpdateLob(Builder $query, $values, $binaries, $sequence = 'id')
    {
        $table = $this->wrapTable($query->from);

        $columns = [];
        foreach ($values as $key => $value) {
            $columns[] = $this->wrap($key).' = '.$this->parameter($value);
        }

        $columns = implode(', ', $columns);

        if (! is_array(reset($binaries))) {
            $binaries = [$binaries];
        }
        $binaryColumns = $this->columnize(array_keys(reset($binaries)));
        $binaryParameters = $this->parameterize(reset($binaries));

        $binarySql = [];
        foreach ((array) $binaryColumns as $binary) {
            $binarySql[] = "$binary = EMPTY_BLOB()";
        }

        if (count($binarySql)) {
            $binarySql = (empty($columns) ? '' : ', ').implode(',', $binarySql);
        }

        $joins = '';
        if (isset($query->joins)) {
            $joins = ' '.$this->compileJoins($query, $query->joins);
        }

        $where = $this->compileWheres($query);

        return "update {$table}{$joins} set $columns$binarySql $where returning ".$binaryColumns.', '.$this->wrap($sequence).' into '.$binaryParameters.', ?';
    }

    protected function compileLock(Builder $query, $value)
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value) {
            return 'for update';
        }

        return '';
    }

    public function compileLimit(Builder $query, $limit)
    {
        if ($limit && ! $query->offset) {
            return 'fetch first ' . (int) $limit . ' rows only';
        }

        return '';
    }

    public function compileOffset(Builder $query, $offset)
    {
        if ($offset) {
            return 'offset ' . (int) $offset . ' rows';
        }

        return '';
    }

    protected function whereDate(Builder $query, $where)
    {
        $value = $this->parameter($where['value']);

        return "trunc({$this->wrap($where['column'])}) {$where['operator']} $value";
    }

    protected function dateBasedWhere($type, Builder $query, $where)
    {
        $value = $this->parameter($where['value']);

        return "extract ($type from {$this->wrap($where['column'])}) {$where['operator']} $value";
    }

    protected function whereNotInRaw($query, $where)
    {
        if (! empty($where['values'])) {
            if (is_array($where['values']) && count($where['values']) > 1000) {
                return $this->resolveClause($where['column'], $where['values'], 'not in');
            }

            return $this->wrap($where['column']).' not in ('.implode(', ', $where['values']).')';
        }

        return '1 = 1';
    }

    protected function whereInRaw($query, $where)
    {
        if (! empty($where['values'])) {
            if (is_array($where['values']) && count($where['values']) > 1000) {
                return $this->resolveClause($where['column'], $where['values'], 'in');
            }

            return $this->wrap($where['column']).' in ('.implode(', ', $where['values']).')';
        }

        return '0 = 1';
    }

    private function resolveClause($column, $values, $type)
    {
        $chunks = array_chunk($values, 1000);
        $whereClause = '';
        $i = 0;
        $baseType = $this->wrap($column).' '.$type.' ';

        foreach ($chunks as $ch) {
            $chunkType = $baseType;
            if ($i === 1) {
                $chunkType = ' or '.$baseType.' ';
            }

            $whereClause .= $chunkType.'('.implode(', ', $ch).')';
            $i++;
        }

        return '('.$whereClause.')';
    }

    protected function compileUnionAggregate(Builder $query)
    {
        $sql = $this->compileAggregate($query, $query->aggregate);
        $query->aggregate = null;

        return $sql.' from ('.$this->compileSelect($query).') '.$this->wrapTable('temp_table');
    }

    public function compilePaginate(Builder $query, $perPage, $columns, $pageName, $page)
    {
        $offset = ($page - 1) * $perPage;
        $sql = $this->compileSelect($query);
        $sql = preg_replace('/^\s*select\s+\*\s+from\s*\(\s*(select\s+.+?)\)\s*$/is', '$1', $sql);

        return $sql . ' offset ' . $offset . ' rows fetch next ' . $perPage . ' rows only';
    }
}
