<?php

namespace BeyondCode\LaravelMaskedDumper;

use Faker\Factory;
use Doctrine\DBAL\Schema\Table;
use BeyondCode\LaravelMaskedDumper\TableDefinitions\TableDefinition;
use Doctrine\DBAL\Types\Types;
use Illuminate\Support\Facades\Schema;

class DumpSchema
{
    protected $connectionName;
    protected $availableTables = [];
    protected $dumpTables = [];

    protected $loadAllTables = false;
    protected $customizedTables = [];

    public function __construct($connectionName = null)
    {
        $this->connectionName = $connectionName;
    }

    public static function define($connectionName = null)
    {
        return new static($connectionName);
    }

    public function schemaOnly(string $tableName)
    {
        return $this->table($tableName, function (TableDefinition $table) {
            $table->schemaOnly();
        });
    }

    public function table(string $tableName, callable $tableDefinition)
    {
        $this->customizedTables[$tableName] = $tableDefinition;

        return $this;
    }

    public function allTables()
    {
        $this->loadAllTables = true;

        return $this;
    }

    /**
     * @return \Illuminate\Database\Schema\Builder
     */
    public function getBuilder()
    {
        return Schema::connection($this->connectionName);
    }

    /**
     * @return \Illuminate\Database\Connection
     */
    public function getConnection()
    {
        return Schema::connection($this->connectionName)->getConnection();
    }

    protected function getTable(string $tableName)
    {
        $table = collect($this->availableTables)->first(function (Table $table) use ($tableName) {
            return $table->getName() === $tableName;
        });

        if (is_null($table)) {
            throw new \Exception("Invalid table name {$tableName}");
        }

        return $table;
    }

    /**
     * @return TableDefinition[]
     */
    public function getDumpTables()
    {
        return $this->dumpTables;
    }

    protected function loadAvailableTables()
    {
        if ($this->availableTables !== []) {
            return;
        }

        $this->availableTables = $this->createDoctrineTables($this->getBuilder()->getTables());
    }

    protected function createDoctrineTables(array $tables): array
    {
        $doctrineTables = [];

        foreach ($tables as $table) {
            $columns = $this->getBuilder()->getColumns($table['name']);

            $table = new Table($table['name']);

            foreach ($columns as $column) {
                $type = $this->mapType($column['type_name']);
                $table->addColumn(
                    $column['name'],
                    $type
                );
            }

            $doctrineTables[] = $table;
        }

        return $doctrineTables;
    }

    protected function mapType(string $typeName): string
    {
        switch ($typeName) {
            case 'char':
            case 'varchar':
                return Types::STRING;
            case 'int':
            case 'integer':
                return Types::INTEGER;
            case 'text':
            case 'longtext':
            case 'mediumtext':
                return Types::TEXT;
            case 'date':
                return Types::DATE_MUTABLE;
            case 'datetime':
            case 'timestamp':
                return Types::DATETIME_MUTABLE;
            case 'bigint':
            case 'mediumint':
                return Types::BIGINT;
            case 'tinyint':
            case 'smallint':
                return Types::SMALLINT;
            case 'binary':
                return Types::BINARY;
            case 'json':
                return Types::JSON;
            case 'decimal':
                return Types::DECIMAL;
            default:
                return Types::TEXT;
        }
    }


    public function load()
    {
        $this->loadAvailableTables();

        if ($this->loadAllTables) {
            $this->dumpTables = collect($this->availableTables)->mapWithKeys(function (Table $table) {
                return [$table->getName() => new TableDefinition($table)];
            })->toArray();
        }

        foreach ($this->customizedTables as $tableName => $tableDefinition) {
            $table = new TableDefinition($this->getTable($tableName));
            call_user_func_array($tableDefinition, [$table, Factory::create()]);

            $this->dumpTables[$tableName] = $table;
        }
    }
}
