<?php
/**
 * Phinx
 *
 * (The MIT license)
 * Copyright (c) 2015 Rob Morgan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated * documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package    Phinx
 * @subpackage Phinx\Db\Adapter
 */
namespace Phinx\Db\Adapter;

use Phinx\Db\Table\ForeignKey;
use Phinx\Db\Table\Index;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Phinx\Db\Table;
use Phinx\Db\Table\Column;
use Phinx\Migration\MigrationInterface;

/**
 * Phinx Mysqli Adapter.
 */
class MysqliAdapter implements AdapterInterface
{
    protected $signedColumnTypes = array('integer' => true, 'biginteger' => true, 'float' => true, 'decimal' => true);

    const TEXT_TINY    = 255;
    const TEXT_SMALL   = 255; /* deprecated, alias of TEXT_TINY */
    const TEXT_REGULAR = 65535;
    const TEXT_MEDIUM  = 16777215;
    const TEXT_LONG    = 4294967295;

    const INT_TINY    = 255;
    const INT_SMALL   = 65535;
    const INT_MEDIUM  = 16777215;
    const INT_REGULAR = 4294967295;
    const INT_BIG     = 18446744073709551615;

    /**
     * @var array
     */
    protected $options = array();

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var string
     */
    protected $schemaTableName = 'phinxlog';

    /**
     * @var \mysqli
     */
    protected $connection;

    /**
     * @var float
     */
    protected $commandStartTime;

    /**
     * Class Constructor.
     *
     * @param array $options Options
     * @param OutputInterface $output Output Interface
     */
    public function __construct(array $options, OutputInterface $output = null)
    {
        $this->setOptions($options);
        if (null !== $output) {
            $this->setOutput($output);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setOptions(array $options)
    {
        $this->options = $options;

        if (isset($options['default_migration_table'])) {
            $this->setSchemaTableName($options['default_migration_table']);
        }

        if (isset($options['connection'])) {
            $this->setConnection($options['connection']);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * {@inheritdoc}
     */
    public function hasOption($name)
    {
        return isset($this->options[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function getOption($name)
    {
        if (!$this->hasOption($name)) {
            return null;
        }
        return $this->options[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getOutput()
    {
        if (null == $this->output) {
            $output = new NullOutput();
            $this->setOutput($output);
        }
        return $this->output;
    }

    /**
     * Sets the schema table name.
     *
     * @param string $schemaTableName Schema Table Name
     * @return MysqliAdapter
     */
    public function setSchemaTableName($schemaTableName)
    {
        $this->schemaTableName = $schemaTableName;
        return $this;
    }

    /**
     * Gets the schema table name.
     *
     * @return string
     */
    public function getSchemaTableName()
    {
        return $this->schemaTableName;
    }

    /**
     * Sets the database connection.
     *
     * @param \mysqli $connection Connection
     * @return AdapterInterface
     */
    public function setConnection(\mysqli $connection)
    {
        $this->connection = $connection;

        // Create the schema table if it doesn't already exist
        if (!$this->hasSchemaTable()) {
            $this->createSchemaTable();
        }

        return $this;
    }

    /**
     * Gets the database connection
     *
     * @return \mysqli
     */
    public function getConnection()
    {
        if (null === $this->connection) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Sets the command start time
     *
     * @param int $time
     * @return AdapterInterface
     */
    public function setCommandStartTime($time)
    {
        $this->commandStartTime = $time;
        return $this;
    }

    /**
     * Gets the command start time
     *
     * @return int
     */
    public function getCommandStartTime()
    {
        return $this->commandStartTime;
    }

    /**
     * Start timing a command.
     *
     * @return void
     */
    public function startCommandTimer()
    {
        $this->setCommandStartTime(microtime(true));
    }

    /**
     * Stop timing the current command and write the elapsed time to the
     * output.
     *
     * @return void
     */
    public function endCommandTimer()
    {
        $end = microtime(true);
        if (OutputInterface::VERBOSITY_VERBOSE === $this->getOutput()->getVerbosity()) {
            $this->getOutput()->writeln('    -> ' . sprintf('%.4fs', $end - $this->getCommandStartTime()));
        }
    }

    /**
     * Write a Phinx command to the output.
     *
     * @param string $command Command Name
     * @param array  $args    Command Args
     * @return void
     */
    public function writeCommand($command, $args = array())
    {
        if (OutputInterface::VERBOSITY_VERBOSE === $this->getOutput()->getVerbosity()) {
            if (count($args)) {
                $outArr = array();
                foreach ($args as $arg) {
                    if (is_array($arg)) {
                        $arg = array_map(function ($value) {
                            return '\'' . $value . '\'';
                        }, $arg);
                        $outArr[] = '[' . implode(', ', $arg)  . ']';
                        continue;
                    }

                    $outArr[] = '\'' . $arg . '\'';
                }
                $this->getOutput()->writeln(' -- ' . $command . '(' . implode(', ', $outArr) . ')');
                return;
            }
            $this->getOutput()->writeln(' -- ' . $command);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function connect()
    {
        if (null === $this->connection) {
            if (!class_exists('mysqli')) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('You need to enable the mysqli extension for Phinx to run properly.');
                // @codeCoverageIgnoreEnd
            }

            /**
             * @var \mysqli
             */
            $db = null;
            $options = $this->getOptions();

            if (!empty($options['unix_socket'])) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('Unix socket connection not supported.');
                // @codeCoverageIgnoreEnd
            }

            $db = new \mysqli($options['host'], $options['user'], $options['pass'], $options['name']);

            // charset support
            if (!empty($options['charset'])) {
                $db->query("SET NAMES " . $options['charset']);
            }

            if ($db->connect_errno) {
                throw new \InvalidArgumentException(sprintf(
                    'There was a problem connecting to the database: %s',
                    $db->connect_error
                ));
            }

            $this->setConnection($db);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        @$this->connection->close();
        $this->connection = NULL;
    }

    /**
     * {@inheritdoc}
     */
    public function execute($sql)
    {
        $this->query($sql);
        return $this->connection->affected_rows;
    }

    /**
     * {@inheritdoc}
     */
    public function query($sql)
    {
        return $this->getConnection()->query($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchRow($sql)
    {
        return $this->query($sql)->fetch_row();
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll($sql)
    {
        $rows = array();
        $result = $this->query($sql);
        while($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function getVersions()
    {
        $rows = $this->fetchAll(sprintf('SELECT * FROM %s ORDER BY version ASC', $this->getSchemaTableName()));
        return array_map(
            function ($v) {
                return $v['version'];
            },
            $rows
        );
    }

    /**
     * {@inheritdoc}
     */
    public function migrated(MigrationInterface $migration, $direction, $startTime, $endTime)
    {
        if (strcasecmp($direction, MigrationInterface::UP) === 0) {
            // up
            $sql = sprintf(
                'INSERT INTO %s ('
                . 'version, start_time, end_time'
                . ') VALUES ('
                . '\'%s\','
                . '\'%s\','
                . '\'%s\''
                . ');',
                $this->getSchemaTableName(),
                $migration->getVersion(),
                $startTime,
                $endTime
            );

            $this->query($sql);
        } else {
            // down
            $sql = sprintf(
                "DELETE FROM %s WHERE version = '%s'",
                $this->getSchemaTableName(),
                $migration->getVersion()
            );

            $this->query($sql);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function hasSchemaTable()
    {
        return $this->hasTable($this->getSchemaTableName());
    }

    /**
     * {@inheritdoc}
     */
    public function createSchemaTable()
    {
        try {
            $options = array(
                'id' => false
            );

            $table = new Table($this->getSchemaTableName(), $options, $this);
            $table->addColumn('version', 'biginteger')
                  ->addColumn('start_time', 'timestamp')
                  ->addColumn('end_time', 'timestamp')
                  ->save();
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('There was a problem creating the schema table: ' . $exception->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getAdapterType()
    {
        return $this->getOption('adapter');
    }

    /**
     * {@inheritdoc}
     */
    public function getColumnTypes()
    {
        return array(
            'string',
            'char',
            'text',
            'integer',
            'biginteger',
            'float',
            'decimal',
            'datetime',
            'timestamp',
            'time',
            'date',
            'binary',
            'boolean',
            'uuid',
            // Geospatial data types
            'geometry',
            'point',
            'linestring',
            'polygon',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isValidColumnType(Column $column) {
        return in_array($column->getType(), $this->getColumnTypes());
    }

    /**
     * Does the adapter support transactions?
     *
     * @return boolean
     */
    public function hasTransactions() {
        return true;
    }

    /**
     * Begin a transaction.
     *
     * @return void
     */
    public function beginTransaction() {
        $this->execute('START TRANSACTION');
    }

    /**
     * Commit a transaction.
     *
     * @return void
     */
    public function commitTransaction() {
        $this->execute('COMMIT');
    }

    /**
     * Rollback a transaction.
     *
     * @return void
     */
    public function rollbackTransaction() {
        $this->execute('ROLLBACK');
    }

    /**
     * Quotes a table name for use in a query.
     *
     * @param string $tableName Table Name
     *
     * @return string
     */
    public function quoteTableName($tableName) {
        return str_replace('.', '`.`', $this->quoteColumnName($tableName));
    }

    /**
     * Quotes a column name for use in a query.
     *
     * @param string $columnName Table Name
     *
     * @return string
     */
    public function quoteColumnName($columnName) {
        return '`' . str_replace('`', '``', $columnName) . '`';
    }

    /**
     * Checks to see if a table exists.
     *
     * @param string $tableName Table Name
     *
     * @return boolean
     */
    public function hasTable($tableName) {
        $options = $this->getOptions();

        $exists = $this->fetchRow(sprintf(
            "SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME = '%s'",
            $options['name'], $tableName
        ));

        return !empty($exists);
    }

    /**
     * Creates the specified database table.
     *
     * @param Table $table Table
     *
     * @return void
     */
    public function createTable(Table $table) {
        $this->startCommandTimer();

        // This method is based on the MySQL docs here: http://dev.mysql.com/doc/refman/5.1/en/create-index.html
        $defaultOptions = array(
            'engine' => 'InnoDB',
            'collation' => 'utf8_general_ci'
        );
        $options = array_merge($defaultOptions, $table->getOptions());

        // Add the default primary key
        $columns = $table->getPendingColumns();
        if (!isset($options['id']) || (isset($options['id']) && $options['id'] === true)) {
            $column = new Column();
            $column->setName('id')
                ->setType('integer')
                ->setIdentity(true);

            array_unshift($columns, $column);
            $options['primary_key'] = 'id';

        } elseif (isset($options['id']) && is_string($options['id'])) {
            // Handle id => "field_name" to support AUTO_INCREMENT
            $column = new Column();
            $column->setName($options['id'])
                ->setType('integer')
                ->setIdentity(true);

            array_unshift($columns, $column);
            $options['primary_key'] = $options['id'];
        }

        // TODO - process table options like collation etc

        // process table engine (default to InnoDB)
        $optionsStr = 'ENGINE = InnoDB';
        if (isset($options['engine'])) {
            $optionsStr = sprintf('ENGINE = %s', $options['engine']);
        }

        // process table collation
        if (isset($options['collation'])) {
            $charset = explode('_', $options['collation']);
            $optionsStr .= sprintf(' CHARACTER SET %s', $charset[0]);
            $optionsStr .= sprintf(' COLLATE %s', $options['collation']);
        }

        // set the table comment
        if (isset($options['comment'])) {
            $optionsStr .= sprintf(" COMMENT=%s ", $this->getConnection()->quote($options['comment']));
        }

        $sql = 'CREATE TABLE ';
        $sql .= $this->quoteTableName($table->getName()) . ' (';
        foreach ($columns as $column) {
            $sql .= $this->quoteColumnName($column->getName()) . ' ' . $this->getColumnSqlDefinition($column) . ', ';
        }

        // set the primary key(s)
        if (isset($options['primary_key'])) {
            $sql = rtrim($sql);
            $sql .= ' PRIMARY KEY (';
            if (is_string($options['primary_key'])) {       // handle primary_key => 'id'
                $sql .= $this->quoteColumnName($options['primary_key']);
            } elseif (is_array($options['primary_key'])) { // handle primary_key => array('tag_id', 'resource_id')
                // PHP 5.4 will allow access of $this, so we can call quoteColumnName() directly in the
                // anonymous function, but for now just hard-code the adapter quotes
                $sql .= implode(
                    ',',
                    array_map(
                        function ($v) {
                            return '`' . $v . '`';
                        },
                        $options['primary_key']
                    )
                );
            }
            $sql .= ')';
        } else {
            $sql = substr(rtrim($sql), 0, -1);              // no primary keys
        }

        // set the indexes
        $indexes = $table->getIndexes();
        if (!empty($indexes)) {
            foreach ($indexes as $index) {
                $sql .= ', ' . $this->getIndexSqlDefinition($index);
            }
        }

        // set the foreign keys
        $foreignKeys = $table->getForeignKeys();
        if (!empty($foreignKeys)) {
            foreach ($foreignKeys as $foreignKey) {
                $sql .= ', ' . $this->getForeignKeySqlDefinition($foreignKey);
            }
        }

        $sql .= ') ' . $optionsStr;
        $sql = rtrim($sql) . ';';

        // execute the sql
        $this->writeCommand('createTable', array($table->getName()));
        $this->execute($sql);
        $this->endCommandTimer();
    }

    /**
     * Renames the specified database table.
     *
     * @param string $tableName Table Name
     * @param string $newName New Name
     *
     * @return void
     */
    public function renameTable($tableName, $newName) {
        $this->startCommandTimer();
        $this->writeCommand('renameTable', array($tableName, $newName));
        $this->execute(sprintf('RENAME TABLE %s TO %s', $this->quoteTableName($tableName), $this->quoteTableName($newName)));
        $this->endCommandTimer();
    }

    /**
     * Drops the specified database table.
     *
     * @param string $tableName Table Name
     *
     * @return void
     */
    public function dropTable($tableName) {
        $this->startCommandTimer();
        $this->writeCommand('dropTable', array($tableName));
        $this->execute(sprintf('DROP TABLE %s', $this->quoteTableName($tableName)));
        $this->endCommandTimer();
    }

    /**
     * Returns table columns
     *
     * @param string $tableName Table Name
     *
     * @return Column[]
     */
    public function getColumns($tableName) {
        $columns = array();
        $rows = $this->fetchAll(sprintf('SHOW COLUMNS FROM %s', $this->quoteTableName($tableName)));
        foreach ($rows as $columnInfo) {

            $phinxType = $this->getPhinxType($columnInfo['Type']);

            $column = new Column();
            $column->setName($columnInfo['Field'])
                ->setNull($columnInfo['Null'] != 'NO')
                ->setDefault($columnInfo['Default'])
                ->setType($phinxType['name'])
                ->setLimit($phinxType['limit']);

            if ($columnInfo['Extra'] == 'auto_increment') {
                $column->setIdentity(true);
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * Checks to see if a column exists.
     *
     * @param string $tableName Table Name
     * @param string $columnName Column Name
     *
     * @return boolean
     */
    public function hasColumn($tableName, $columnName) {
        $rows = $this->fetchAll(sprintf('SHOW COLUMNS FROM %s', $this->quoteTableName($tableName)));
        foreach ($rows as $column) {
            if (strcasecmp($column['Field'], $columnName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds the specified column to a database table.
     *
     * @param Table $table Table
     * @param Column $column Column
     *
     * @return void
     */
    public function addColumn(Table $table, Column $column) {
        $this->startCommandTimer();
        $sql = sprintf(
            'ALTER TABLE %s ADD %s %s',
            $this->quoteTableName($table->getName()),
            $this->quoteColumnName($column->getName()),
            $this->getColumnSqlDefinition($column)
        );

        if ($column->getAfter()) {
            $sql .= ' AFTER ' . $this->quoteColumnName($column->getAfter());
        }

        $this->writeCommand('addColumn', array($table->getName(), $column->getName(), $column->getType()));
        $this->execute($sql);
        $this->endCommandTimer();
    }

    /**
     * Renames the specified column.
     *
     * @param string $tableName Table Name
     * @param string $columnName Column Name
     * @param string $newColumnName New Column Name
     *
     * @return void
     */
    public function renameColumn($tableName, $columnName, $newColumnName) {
        $this->startCommandTimer();
        $rows = $this->fetchAll(sprintf('DESCRIBE %s', $this->quoteTableName($tableName)));
        foreach ($rows as $row) {
            if (strcasecmp($row['Field'], $columnName) === 0) {
                $null = ($row['Null'] == 'NO') ? 'NOT NULL' : 'NULL';
                $extra = ' ' . strtoupper($row['Extra']);
                if (!is_null($row['Default'])) {
                    $extra .= $this->getDefaultValueDefinition($row['Default']);
                }
                $definition = $row['Type'] . ' ' . $null . $extra;

                $this->writeCommand('renameColumn', array($tableName, $columnName, $newColumnName));
                $this->execute(
                    sprintf(
                        'ALTER TABLE %s CHANGE COLUMN %s %s %s',
                        $this->quoteTableName($tableName),
                        $this->quoteColumnName($columnName),
                        $this->quoteColumnName($newColumnName),
                        $definition
                    )
                );
                $this->endCommandTimer();
                return;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'The specified column doesn\'t exist: '
            . $columnName
        ));
    }

    /**
     * Change a table column type.
     *
     * @param string $tableName Table Name
     * @param string $columnName Column Name
     * @param Column $newColumn New Column
     *
     * @return Table
     */
    public function changeColumn($tableName, $columnName, Column $newColumn) {
        $this->startCommandTimer();
        $this->writeCommand('changeColumn', array($tableName, $columnName, $newColumn->getType()));
        $this->execute(
            sprintf(
                'ALTER TABLE %s CHANGE %s %s %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName),
                $this->quoteColumnName($newColumn->getName()),
                $this->getColumnSqlDefinition($newColumn)
            )
        );
        $this->endCommandTimer();
    }

    /**
     * Drops the specified column.
     *
     * @param string $tableName Table Name
     * @param string $columnName Column Name
     *
     * @return void
     */
    public function dropColumn($tableName, $columnName) {
        $this->startCommandTimer();
        $this->writeCommand('dropColumn', array($tableName, $columnName));
        $this->execute(
            sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName)
            )
        );
        $this->endCommandTimer();
    }

    /**
     * Checks to see if an index exists.
     *
     * @param string $tableName Table Name
     * @param mixed $columns Column(s)
     *
     * @return boolean
     */
    public function hasIndex($tableName, $columns) {
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }

        $columns = array_map('strtolower', $columns);
        $indexes = $this->getIndexes($tableName);

        foreach ($indexes as $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds the specified index to a database table.
     *
     * @param Table $table Table
     * @param Index $index Index
     *
     * @return void
     */
    public function addIndex(Table $table, Index $index) {
        $this->startCommandTimer();
        $this->writeCommand('addIndex', array($table->getName(), $index->getColumns()));
        $this->execute(
            sprintf(
                'ALTER TABLE %s ADD %s',
                $this->quoteTableName($table->getName()),
                $this->getIndexSqlDefinition($index)
            )
        );
        $this->endCommandTimer();
    }

    /**
     * Drops the specified index from a database table.
     *
     * @param string $tableName
     * @param mixed $columns Column(s)
     *
     * @return void
     */
    public function dropIndex($tableName, $columns) {
        $this->startCommandTimer();
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }

        $this->writeCommand('dropIndex', array($tableName, $columns));
        $indexes = $this->getIndexes($tableName);
        $columns = array_map('strtolower', $columns);

        foreach ($indexes as $indexName => $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                $this->execute(
                    sprintf(
                        'ALTER TABLE %s DROP INDEX %s',
                        $this->quoteTableName($tableName),
                        $this->quoteColumnName($indexName)
                    )
                );
                $this->endCommandTimer();
                return;
            }
        }
    }

    /**
     * Drops the index specified by name from a database table.
     *
     * @param string $tableName
     * @param string $indexName
     *
     * @return void
     */
    public function dropIndexByName($tableName, $indexName) {
        $this->startCommandTimer();

        $this->writeCommand('dropIndexByName', array($tableName, $indexName));
        $indexes = $this->getIndexes($tableName);

        foreach ($indexes as $name => $index) {
            //$a = array_diff($columns, $index['columns']);
            if ($name === $indexName) {
                $this->execute(
                    sprintf(
                        'ALTER TABLE %s DROP INDEX %s',
                        $this->quoteTableName($tableName),
                        $this->quoteColumnName($indexName)
                    )
                );
                $this->endCommandTimer();
                return;
            }
        }
    }

    /**
     * Checks to see if a foreign key exists.
     *
     * @param string $tableName
     * @param string[] $columns Column(s)
     * @param string $constraint Constraint name
     *
     * @return boolean
     */
    public function hasForeignKey($tableName, $columns, $constraint = null) {
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        $foreignKeys = $this->getForeignKeys($tableName);
        if ($constraint) {
            if (isset($foreignKeys[$constraint])) {
                return !empty($foreignKeys[$constraint]);
            }
            return false;
        } else {
            foreach ($foreignKeys as $key) {
                $a = array_diff($columns, $key['columns']);
                if (empty($a)) {
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * Adds the specified foreign key to a database table.
     *
     * @param Table $table
     * @param ForeignKey $foreignKey
     *
     * @return void
     */
    public function addForeignKey(Table $table, ForeignKey $foreignKey) {
        $this->startCommandTimer();
        $this->writeCommand('addForeignKey', array($table->getName(), $foreignKey->getColumns()));
        $this->execute(
            sprintf(
                'ALTER TABLE %s ADD %s',
                $this->quoteTableName($table->getName()),
                $this->getForeignKeySqlDefinition($foreignKey)
            )
        );
        $this->endCommandTimer();
    }

    /**
     * Drops the specified foreign key from a database table.
     *
     * @param string $tableName
     * @param string[] $columns Column(s)
     * @param string $constraint Constraint name
     *
     * @return void
     */
    public function dropForeignKey($tableName, $columns, $constraint = null) {
        $this->startCommandTimer();
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }

        $this->writeCommand('dropForeignKey', array($tableName, $columns));

        if ($constraint) {
            $this->execute(
                sprintf(
                    'ALTER TABLE %s DROP FOREIGN KEY %s',
                    $this->quoteTableName($tableName),
                    $constraint
                )
            );
            $this->endCommandTimer();
            return;
        } else {
            foreach ($columns as $column) {
                $rows = $this->fetchAll(sprintf(
                    "SELECT
                        CONSTRAINT_NAME
                      FROM information_schema.KEY_COLUMN_USAGE
                      WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                        AND TABLE_NAME = '%s'
                        AND COLUMN_NAME = '%s'
                      ORDER BY POSITION_IN_UNIQUE_CONSTRAINT",
                    $tableName,
                    $column
                ));
                foreach ($rows as $row) {
                    $this->dropForeignKey($tableName, $columns, $row['CONSTRAINT_NAME']);
                }
            }
        }
        $this->endCommandTimer();
    }

    /**
     * Converts the Phinx logical type to the adapter's SQL type.
     *
     * @param string $type
     * @param integer $limit
     *
     * @return string
     */
    public function getSqlType($type, $limit = null) {
        switch ($type) {
            case static::PHINX_TYPE_STRING:
                return array('name' => 'varchar', 'limit' => $limit ? $limit : 255);
                break;
            case static::PHINX_TYPE_CHAR:
                return array('name' => 'char', 'limit' => $limit ? $limit : 255);
                break;
            case static::PHINX_TYPE_TEXT:
                if ($limit) {
                    $sizes = array(
                        // Order matters! Size must always be tested from longest to shortest!
                        'longtext'   => static::TEXT_LONG,
                        'mediumtext' => static::TEXT_MEDIUM,
                        'text'       => static::TEXT_REGULAR,
                        'tinytext'   => static::TEXT_SMALL,
                    );
                    foreach ($sizes as $name => $length) {
                        if ($limit >= $length) {
                            return array('name' => $name);
                        }
                    }
                }
                return array('name' => 'text');
                break;
            case static::PHINX_TYPE_INTEGER:
                if ($limit && $limit >= static::INT_TINY) {
                    $sizes = array(
                        // Order matters! Size must always be tested from longest to shortest!
                        'bigint'    => static::INT_BIG,
                        'int'       => static::INT_REGULAR,
                        'mediumint' => static::INT_MEDIUM,
                        'smallint'  => static::INT_SMALL,
                        'tinyint'   => static::INT_TINY,
                    );
                    $limits = array(
                        'int'    => 11,
                        'bigint' => 20,
                    );
                    foreach ($sizes as $name => $length) {
                        if ($limit >= $length) {
                            $def = array('name' => $name);
                            if (isset($limits[$name])) {
                                $def['limit'] = $limits[$name];
                            }
                            return $def;
                        }
                    }
                }
                return array('name' => 'int', 'limit' => 11);
                break;
            case static::PHINX_TYPE_BIG_INTEGER:
                return array('name' => 'bigint', 'limit' => 20);
                break;
            case static::PHINX_TYPE_FLOAT:
                return array('name' => 'float');
                break;
            case static::PHINX_TYPE_DECIMAL:
                return array('name' => 'decimal');
                break;
            case static::PHINX_TYPE_DATETIME:
                return array('name' => 'datetime');
                break;
            case static::PHINX_TYPE_TIMESTAMP:
                return array('name' => 'timestamp');
                break;
            case static::PHINX_TYPE_TIME:
                return array('name' => 'time');
                break;
            case static::PHINX_TYPE_DATE:
                return array('name' => 'date');
                break;
            case static::PHINX_TYPE_BINARY:
                return array('name' => 'blob');
                break;
            case static::PHINX_TYPE_BOOLEAN:
                return array('name' => 'tinyint', 'limit' => 1);
                break;
            case static::PHINX_TYPE_UUID:
                return array('name' => 'char', 'limit' => 36);
            // Geospatial database types
            case static::PHINX_TYPE_GEOMETRY:
            case static::PHINX_TYPE_POINT:
            case static::PHINX_TYPE_LINESTRING:
            case static::PHINX_TYPE_POLYGON:
                return array('name' => $type);
            case static::PHINX_TYPE_ENUM:
                return array('name' => 'enum');
                break;
            case static::PHINX_TYPE_SET:
                return array('name' => 'set');
                break;
            default:
                throw new \RuntimeException('The type: "' . $type . '" is not supported.');
        }
    }

    /**
     * Creates a new database.
     *
     * @param string $name Database Name
     * @param array $options Options
     *
     * @return void
     */
    public function createDatabase($name, $options = array()) {
        $this->startCommandTimer();
        $this->writeCommand('createDatabase', array($name));
        $charset = isset($options['charset']) ? $options['charset'] : 'utf8';

        if (isset($options['collation'])) {
            $this->execute(sprintf('CREATE DATABASE `%s` DEFAULT CHARACTER SET `%s` COLLATE `%s`', $name, $charset, $options['collation']));
        } else {
            $this->execute(sprintf('CREATE DATABASE `%s` DEFAULT CHARACTER SET `%s`', $name, $charset));
        }
        $this->endCommandTimer();
    }

    /**
     * Checks to see if a database exists.
     *
     * @param string $name Database Name
     *
     * @return boolean
     */
    public function hasDatabase($name)    {
        $rows = $this->fetchAll(
            sprintf(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = \'%s\'',
                $name
            )
        );

        foreach ($rows as $row) {
            if (!empty($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drops the specified database.
     *
     * @param string $name Database Name
     *
     * @return void
     */
    public function dropDatabase($name) {
        $this->startCommandTimer();
        $this->writeCommand('dropDatabase', array($name));
        $this->execute(sprintf('DROP DATABASE IF EXISTS `%s`', $name));
        $this->endCommandTimer();
    }

    /**
     * Gets the MySQL Index Definition for an Index object.
     *
     * @param Index $index Index
     * @return string
     */
    protected function getIndexSqlDefinition(Index $index)
    {
        $def = '';

        if ($index->getType() == Index::UNIQUE) {
            $def .= ' UNIQUE';
        }

        $def .= ' KEY';

        if (is_string($index->getName())) {
            $def .= ' `' . $index->getName() . '`';
        }

        $def .= ' (`' . implode('`,`', $index->getColumns()) . '`)';

        return $def;
    }

    /**
     * Gets the MySQL Foreign Key Definition for an ForeignKey object.
     *
     * @param ForeignKey $foreignKey
     * @return string
     */
    protected function getForeignKeySqlDefinition(ForeignKey $foreignKey)
    {
        $def = '';
        if ($foreignKey->getConstraint()) {
            $def .= ' CONSTRAINT ' . $this->quoteColumnName($foreignKey->getConstraint());
        }
        $columnNames = array();
        foreach ($foreignKey->getColumns() as $column) {
            $columnNames[] = $this->quoteColumnName($column);
        }
        $def .= ' FOREIGN KEY (' . implode(',', $columnNames) . ')';
        $refColumnNames = array();
        foreach ($foreignKey->getReferencedColumns() as $column) {
            $refColumnNames[] = $this->quoteColumnName($column);
        }
        $def .= ' REFERENCES ' . $this->quoteTableName($foreignKey->getReferencedTable()->getName()) . ' (' . implode(',', $refColumnNames) . ')';
        if ($foreignKey->getOnDelete()) {
            $def .= ' ON DELETE ' . $foreignKey->getOnDelete();
        }
        if ($foreignKey->getOnUpdate()) {
            $def .= ' ON UPDATE ' . $foreignKey->getOnUpdate();
        }
        return $def;
    }

    /**
     * Get an array of foreign keys from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
    protected function getForeignKeys($tableName)
    {
        $foreignKeys = array();
        $rows = $this->fetchAll(sprintf(
            "SELECT
              CONSTRAINT_NAME,
              TABLE_NAME,
              COLUMN_NAME,
              REFERENCED_TABLE_NAME,
              REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME IS NOT NULL
              AND TABLE_NAME = '%s'
            ORDER BY POSITION_IN_UNIQUE_CONSTRAINT",
            $tableName
        ));
        foreach ($rows as $row) {
            $foreignKeys[$row['CONSTRAINT_NAME']]['table'] = $row['TABLE_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['columns'][] = $row['COLUMN_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['referenced_table'] = $row['REFERENCED_TABLE_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        return $foreignKeys;
    }

    /**
     * Get an array of indexes from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
    protected function getIndexes($tableName)
    {
        $indexes = array();
        $rows = $this->fetchAll(sprintf('SHOW INDEXES FROM %s', $this->quoteTableName($tableName)));
        foreach ($rows as $row) {
            if (!isset($indexes[$row['Key_name']])) {
                $indexes[$row['Key_name']] = array('columns' => array());
            }
            $indexes[$row['Key_name']]['columns'][] = strtolower($row['Column_name']);
        }
        return $indexes;
    }

    /**
     * Gets the MySQL Column Definition for a Column object.
     *
     * @param Column $column Column
     * @return string
     */
    protected function getColumnSqlDefinition(Column $column)
    {
        $sqlType = $this->getSqlType($column->getType(), $column->getLimit());

        $def = '';
        $def .= strtoupper($sqlType['name']);
        if ($column->getPrecision() && $column->getScale()) {
            $def .= '(' . $column->getPrecision() . ',' . $column->getScale() . ')';
        } elseif (isset($sqlType['limit'])) {
            $def .= '(' . $sqlType['limit'] . ')';
        }
        if (($values = $column->getValues()) && is_array($values)) {
            $def .= "('" . implode("', '", $values) . "')";
        }
        $def .= (!$column->isSigned() && isset($this->signedColumnTypes[$column->getType()])) ? ' unsigned' : '' ;
        $def .= ($column->isNull() == false) ? ' NOT NULL' : ' NULL';
        $def .= ($column->isIdentity()) ? ' AUTO_INCREMENT' : '';
        $def .= $this->getDefaultValueDefinition($column->getDefault());

        if ($column->getComment()) {
            $def .= ' COMMENT ' . $this->getConnection()->quote($column->getComment());
        }

        if ($column->getUpdate()) {
            $def .= ' ON UPDATE ' . $column->getUpdate();
        }

        return $def;
    }

    /**
     * Get the defintion for a `DEFAULT` statement.
     *
     * @param  mixed $default
     * @return string
     */
    protected function getDefaultValueDefinition($default)
    {
        if (is_string($default) && 'CURRENT_TIMESTAMP' !== $default) {
            $default = $this->getConnection()->real_escape_string($default);
        } elseif (is_bool($default)) {
            $default = (int) $default;
        }
        return isset($default) ? ' DEFAULT ' . $default : '';
    }

    /**
     * Returns Phinx type by SQL type
     *
     * @param $sqlTypeDef
     * @throws \RuntimeException
     * @internal param string $sqlType SQL type
     * @returns string Phinx type
     */
    public function getPhinxType($sqlTypeDef)
    {
        if (!preg_match('/^([\w]+)(\(([\d]+)*(,([\d]+))*\))*(.+)*$/', $sqlTypeDef, $matches)) {
            throw new \RuntimeException('Column type ' . $sqlTypeDef . ' is not supported');
        } else {
            $limit = null;
            $precision = null;
            $type = $matches[1];
            if (count($matches) > 2) {
                $limit = $matches[3] ? (int) $matches[3] : null;
            }
            if (count($matches) > 4) {
                $precision = (int) $matches[5];
            }
            if ($type === 'tinyint' && $limit === 1) {
                $type = static::PHINX_TYPE_BOOLEAN;
                $limit = null;
            }
            switch ($type) {
                case 'varchar':
                    $type = static::PHINX_TYPE_STRING;
                    if ($limit === 255) {
                        $limit = null;
                    }
                    break;
                case 'char':
                    $type = static::PHINX_TYPE_CHAR;
                    if ($limit === 255) {
                        $limit = null;
                    }
                    if ($limit === 36) {
                        $type = static::PHINX_TYPE_UUID;
                    }
                    break;
                case 'tinyint':
                    $type  = static::PHINX_TYPE_INTEGER;
                    $limit = static::INT_TINY;
                    break;
                case 'smallint':
                    $type  = static::PHINX_TYPE_INTEGER;
                    $limit = static::INT_SMALL;
                    break;
                case 'mediumint':
                    $type  = static::PHINX_TYPE_INTEGER;
                    $limit = static::INT_MEDIUM;
                    break;
                case 'int':
                    $type = static::PHINX_TYPE_INTEGER;
                    if ($limit === 11) {
                        $limit = null;
                    }
                    break;
                case 'bigint':
                    if ($limit === 20) {
                        $limit = null;
                    }
                    $type = static::PHINX_TYPE_BIG_INTEGER;
                    break;
                case 'blob':
                    $type = static::PHINX_TYPE_BINARY;
                    break;
                case 'tinytext':
                    $type  = static::PHINX_TYPE_TEXT;
                    $limit = static::TEXT_TINY;
                    break;
                case 'mediumtext':
                    $type  = static::PHINX_TYPE_TEXT;
                    $limit = static::TEXT_MEDIUM;
                    break;
                case 'longtext':
                    $type  = static::PHINX_TYPE_TEXT;
                    $limit = static::TEXT_LONG;
                    break;
            }

            $this->getSqlType($type, $limit);

            return array(
                'name' => $type,
                'limit' => $limit,
                'precision' => $precision
            );
        }
    }
}
