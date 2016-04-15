<?php
/**
 * Simple command line tool for creating phinx migration code from an existing MySQL database.
 *
 * Commandline usage:
 * ```
 * $ php -f mysql2phinx [database] [user] [password] > migration.php
 * $ php -f mysql2phinx [database] [user] [password] [host] > migration.php
 * $ php -f mysql2phinx [database] [user] [password] [host] [port] > migration.php
 * ```
 */

// <editor-fold desc="Config and Useage">

const FILE_PATH    = 0;
const DB_NAME      = 1;
const DB_USER_NAME = 2;
const DB_USER_PWD  = 3;
const DB_HOST      = 4;
const DB_PORT      = 5;
const TAB          = '    ';

if ($argc < 4) {
    echo '===============================' . PHP_EOL;
    echo 'Phinx MySQL migration generator' . PHP_EOL;
    echo '===============================' . PHP_EOL;
    echo 'Usage:' . PHP_EOL;
    echo 'php -f ' . $argv[FILE_PATH] . ' [database] [user] [password] > migration.php' . PHP_EOL;
    echo 'php -f ' . $argv[FILE_PATH] . ' [database] [user] [password] [host] > migration.php' . PHP_EOL;
    echo 'php -f ' . $argv[FILE_PATH] . ' [database] [user] [password] [host] [port] > migration.php' . PHP_EOL;
    echo '[host] and [port] default to localhost and 3306 respectively';
    echo PHP_EOL;
    exit;
}

$config = array(
    'name'    => $argv[DB_NAME],
    'user'    => $argv[DB_USER_NAME],
    'pass'    => $argv[DB_USER_PWD],
    'host'    => $argc === 5 ? $argv[DB_HOST] : 'localhost',
    'port'    => $argc === 6 ? $argv[DB_PORT] : '3306'
);
// </editor-fold>

// <editor-fold desc="Migration Writers">

/**
 * Actually writes the migration code
 * @param \mysqli $mysqli
 * @param integer $indent
 * @return string
 */
function createMigration($mysqli, $indent = 2)
{
    $output = array();
    foreach (getTables($mysqli) as $table) {
        $output[] = getTableMigration($table, $mysqli, $indent);
    }
    return implode(PHP_EOL, $output) . PHP_EOL ;
}

/**
 * Get the PHP code for a table migration
 * @param string $table
 * @param \mysqli $mysqli
 * @param integer $indent
 * @return string PHP code to migrate table
 */
function getTableMigration($table, $mysqli, $indent)
{
    $ind = getIndentation($indent);

    $output = array();
    $output[] = $ind . '// Migration for table ' . $table;
    $output[] = $ind . '$table = $this->table(\'' . $table . '\');';
    $output[] = $ind . '$table';

    $columns = getColumns($table, $mysqli);
    foreach ($columns as $column) {
        if ($column['Field'] !== 'id') {
            $output[] = getColumnMigration($column['Field'], $column, $indent + 1);
        }
    }

    $foreign_keys = getForeignKeys($table, $mysqli);
    $foreign_key_migrations = getForeignKeysMigrations($foreign_keys, $indent + 1);
    if ($foreign_key_migrations)  {
        $output[] = $foreign_key_migrations;
    }

    $indexes = getIndexes($table, $mysqli);
    $index_migrations = getIndexMigrations($indexes, $indent + 1);
    if ($index_migrations) {
        $output[] = $index_migrations;
    }

    $output[] = $ind . '    ->create();';
    $output[] = PHP_EOL;

    return implode(PHP_EOL, $output);
}

/**
 * Get the PHP code to migrate foreign key constraints
 * @param type $foreign_keys
 * @param type $indent
 * @return type
 */
function getForeignKeysMigrations($foreign_keys, $indent)
{
    $ind = getIndentation($indent);
    $output = [];
    foreach ($foreign_keys as $foreign_key) {
        $output[] = $ind . "->addForeignKey('" . $foreign_key['COLUMN_NAME'] . "', '" . $foreign_key['REFERENCED_TABLE_NAME'] . "', '" . $foreign_key['REFERENCED_COLUMN_NAME'] . "', array("
            . "'delete' => '" . str_replace(' ', '_', $foreign_key['DELETE_RULE']) . "',"
            . "'update' => '" . str_replace(' ', '_', $foreign_key['UPDATE_RULE']) . "'"
        . "))";
    }
    return implode(PHP_EOL, $output);
}

/**
 * Get PHP code for index migration
 * @param type $indexes
 * @param integer $indent
 * @return string
 */
function getIndexMigrations($indexes, $indent)
{
    $ind = getIndentation($indent);

    $keyed_indexes = array();
    foreach($indexes as $index) {
        // TODO see if this thing can handle pk's not named id
        // If it can then this check should be on Key_name for PRIMARY and not a specific field name
        if ($index['Column_name'] === 'id') {
            continue;
        }

        $key = $index['Key_name'];
        if (!isset($keyed_indexes[$key])) {
            $keyed_indexes[$key]             = array();
            $keyed_indexes[$key]['columns']  = array();
            $keyed_indexes[$key]['unique']   = $index['Non_unique'] !== '1';
            $keyed_indexes[$key]['fulltext'] = $index['Index_type'] === 'FULLTEXT';
        }

        $keyed_indexes[$key]['columns'][] = $index['Column_name'];
    }

    $output = [];

    foreach ($keyed_indexes as $key_name => $index) {
        $columns = "array('" . implode("', '", $index['columns']) . "')";

        $needs_comma = false;
        $options = [];
        $options[] = 'array(';

        // Support for unique indexes
        if ($index['unique']) {
            $options[] =  "'unique' => true";
            $needs_comma = true;
        }

        // Support for full text indexes
        if ($index['fulltext']) {
            if ($needs_comma === true) {
                $options[] = ', ';
            }
            $options[] = "'type' => 'fulltext'";
            $needs_comma = true;
        }

        // Support for named indexes
        if ($needs_comma === true) {
            $options[] = ', ';
        }
        $options[] = "'name' => '" . $key_name . "')";

        $output[] = $ind . '->addIndex(' . $columns . ', ' . implode('', $options) . ')';
    }

    return implode(PHP_EOL, $output);
}

/**
 * Get the PHP code for a colum migration
 * @param string $column column name
 * @param mixed[] $column_data type information about column (type, null, key, etc)
 * @param integer $indent
 * @return string
 */
function getColumnMigration($column, $column_data, $indent)
{
    $ind = getIndentation($indent);

    $phinx_type = getPhinxColumnType($column_data);
    $column_attributes = getPhinxColumnAttibutes($phinx_type, $column_data);
    $output = $ind . '->addColumn(\'' . $column . '\', \'' . $phinx_type . '\', ' . $column_attributes . ')';
    return $output;
}

// </editor-fold>

// <editor-fold desc="MySQL Databse Info Gathering">

/**
 * Establish a connection to the database
 * @param mixed[] $config
 * @return \mysqli
 */
function getMysqliConnection($config)
{
    $mysqli = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
    if ($mysqli) {
        return $mysqli;
    } else {
        die('Unable to connect to database ' . $config['name'] . ' on ' . $config['host'] . ':' . $config['port']);
    }
}

/**
 * Get a list of tables in the database
 * @param \mysqli $mysqli
 * @return string[] list of table names
 */
function getTables($mysqli)
{
    $res = $mysqli->query('SHOW TABLES');
    $tables = $res->fetch_all();
    mysqli_free_result($res);
    return array_map(function($a) { return $a[0]; }, $tables);
}

/**
 * Get the columns from a table in the database
 * @param string $table
 * @param \mysqli $mysqli
 * @return string[] list of column names
 */
function getColumns($table, $mysqli)
{
    $res = $mysqli->query('SHOW COLUMNS FROM ' . $table);
    $columns = $res->fetch_all(MYSQLI_ASSOC);
    mysqli_free_result($res);
    return $columns;
}

/**
 * Get index information from a table
 * @param string $table
 * @param \mysqli $mysqli
 * @return mixed[][]
 */
function getIndexes($table, $mysqli)
{
    $res = $mysqli->query('SHOW INDEXES FROM ' . $table);
    $indexes = $res->fetch_all(MYSQLI_ASSOC);
    mysqli_free_result($res);
    return $indexes;
}

/**
 * Get foreign key information from a table
 * @param type $table
 * @param type $mysqli
 * @return type
 */
function getForeignKeys($table, $mysqli)
{
    $res = $mysqli->query("SELECT
        cols.TABLE_NAME,
        cols.COLUMN_NAME,
        refs.REFERENCED_TABLE_NAME,
        refs.REFERENCED_COLUMN_NAME,
        cRefs.UPDATE_RULE,
        cRefs.DELETE_RULE
    FROM INFORMATION_SCHEMA.COLUMNS as cols
    LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS refs
        ON refs.TABLE_SCHEMA=cols.TABLE_SCHEMA
        AND refs.REFERENCED_TABLE_SCHEMA=cols.TABLE_SCHEMA
        AND refs.TABLE_NAME=cols.TABLE_NAME
        AND refs.COLUMN_NAME=cols.COLUMN_NAME
    LEFT JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS cons
        ON cons.TABLE_SCHEMA=cols.TABLE_SCHEMA
        AND cons.TABLE_NAME=cols.TABLE_NAME
        AND cons.CONSTRAINT_NAME=refs.CONSTRAINT_NAME
    LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS AS cRefs
        ON cRefs.CONSTRAINT_SCHEMA=cols.TABLE_SCHEMA
        AND cRefs.CONSTRAINT_NAME=refs.CONSTRAINT_NAME
    WHERE
        cols.TABLE_NAME = '" . $table . "'
        AND cols.TABLE_SCHEMA = DATABASE()
        AND refs.REFERENCED_TABLE_NAME IS NOT NULL
        AND cons.CONSTRAINT_TYPE = 'FOREIGN KEY'
    ;");

    $foreign_keys = $res->fetch_all(MYSQLI_ASSOC);
    mysqli_free_result($res);

    return $foreign_keys;
}

// </editor-fold>

// <editor-fold desc="MySQL -> Phinx Mapping">

/**
 * Pulls the base type from a column. eg, "int(10)" is returned as "int"
 * @param mixed[] $column_data
 * @return type
 */
function getMySQLColumnType($column_data)
{
    $type = $column_data['Type'];
    $pattern = '/^[a-z]+/';
    preg_match($pattern, $type, $match);
    return $match[0];
}

/**
 * Maps a MySQL column type to a Phinx colum type
 * @param mixed[] $column_data
 * @return string
 */
function getPhinxColumnType($column_data)
{
    $type = getMySQLColumnType($column_data);

    switch($type) {
        case 'tinyint':
        case 'smallint':
        case 'int':
        case 'mediumint':
            return 'integer';

        // TODO Decide if theres a reason this used to be returned as [decimal]
        case 'decimal':
            return 'decimal';

        case 'timestamp':
            return 'timestamp';

        case 'date':
            return 'date';

        case 'datetime':
            return 'datetime';

        case 'enum':
            return 'enum';

        case 'char':
            return 'char';

        case 'text':
        case 'tinytext':
            return 'text';

        case 'varchar':
            return 'string';

        default:
            return '[' . $type . ']';
    }
}

/**
 * Maps MySQL column type information into Phinx equiv
 * @param string $phinx_type
 * @param mixed[] $column_data
 * @return string
 */
function getPhinxColumnAttibutes($phinx_type, $column_data)
{
    // TODO apparently this doesn't support any type with precision, hence the missing types like DECIMAL(10,2)
    $attributes = array();

    // has NULL
    if ($column_data['Null'] === 'YES') {
        $attributes[] = '\'null\' => true';
    }

    // default value
    if ($column_data['Default'] !== null) {
        $default = is_int($column_data['Default']) ? $column_data['Default'] : '\'' . $column_data['Default'] . '\'';
        $attributes[] = '\'default\' => ' . $default;
    }

    // on update CURRENT_TIMESTAMP
    if ($column_data['Extra'] === 'on update CURRENT_TIMESTAMP') {
        $attributes[] = '\'update\' => \'CURRENT_TIMESTAMP\'';
    }

    // limit / length
    $limit = 0;
    switch (getMySQLColumnType($column_data)) {
        case 'tinyint':
            $limit = 'MysqlAdapter::INT_TINY';
            break;

        case 'smallint':
            $limit = 'MysqlAdapter::INT_SMALL';
            break;

        case 'mediumint':
            $limit = 'MysqlAdapter::INT_MEDIUM';
            break;

        case 'bigint':
            $limit = 'MysqlAdapter::INT_BIG';
            break;

        case 'tinytext':
            $limit = 'MysqlAdapter::TEXT_TINY';
            break;

        case 'mediumtext':
            $limit = 'MysqlAdapter::TEXT_MEDIUM';
            break;

        case 'longtext':
            $limit = 'MysqlAdapter::TEXT_LONG';
            break;

        default:
            // TODO precision probably goes here
            $pattern = '/\((\d+)\)$/';
            if (1 === preg_match($pattern, $column_data['Type'], $match)) {
                $limit = $match[1];
            }
    }
    if ($limit) {
        $attributes[] = '\'limit\' => ' . $limit;
    }

    // unsigned
    $pattern = '/\(\d+\) unsigned$/';
    if (1 === preg_match($pattern, $column_data['Type'], $match)) {
        $attributes[] = '\'signed\' => false';
    }

    // enum values
    if ($phinx_type === 'enum') {
        $attributes[] = '\'values\' => ' . str_replace('enum', 'array', $column_data['Type']);
    }

    return 'array(' . implode(', ', $attributes) . ')';
}

// </editor-fold>

// <editor-fold desc="Helper Functions">

/**
 * Provided $level * count(TAB) spaces for indentation
 * @param integer $level
 * @return string
 */
function getIndentation($level)
{
    return str_repeat(TAB, $level);
}

// </editor-fold>

// <editor-fold desc="Actual Script">

$mysqli = getMysqliConnection($config);

echo '<?php' . PHP_EOL;
echo 'use Phinx\Migration\AbstractMigration;' . PHP_EOL;
echo 'use Phinx\Db\Adapter\MysqlAdapter;' . PHP_EOL . PHP_EOL;

echo 'class InitialMigration extends AbstractMigration' . PHP_EOL;
echo '{' . PHP_EOL;
echo '    public function up()' . PHP_EOL;
echo '    {' . PHP_EOL;
echo '        // Automatically created phinx migration commands for tables from database ' . $config['name'] . PHP_EOL . PHP_EOL;
echo          createMigration($mysqli);
echo '    }' . PHP_EOL;
echo '}' . PHP_EOL;

mysqli_close($mysqli);


// </editor-fold>
