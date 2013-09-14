<?php

namespace Ana;

use \Cabinet\DBAL\Db;

class MigrationManager
{
    const UP = 1;
    const DOWN = 2;
    const RESET = 4;

    public static $template;
    protected $directoryPath = '';
    protected $migrationInheritanceClass = '\Ana\Migration';

    protected $connectionConfig = array();
    private $connectionToDatabase = false;

    protected $tableName = 'app_migrations';

    /**
     * Connection object.
     * @var \Cabiner\DBAL\Connection
     */
    protected $connection;

    public function __construct($config)
    {
        if ( ! static::$template) {
            static::$template = __DIR__.'/../../migration.class.template';
        }

        $config['asObject'] = false;
        $this->connectionConfig = $config;
    }

    /**
     * Sets path to directory with migrations.
     * 
     * @param string $path Path to directory which contains migrations.
     * @return $this
     * @throws \InvalidArgumentException If Directory not exists.
     */
    public function setDirectory($path)
    {
        if ( ! is_dir($path)) {
            throw new \InvalidArgumentException(sprintf(
                'Specified directory %s does not exists.', $path));
        }

        $this->directoryPath = rtrim($path, '/').'/';

        return $this;
    }

    public function getDirectory()
    {
        return $this->directoryPath;
    }

    public function setMigrationsInheritance($class)
    {
        $this->migrationInheritanceClass = $class;

        return $this;
    }

    public function create($name, $time = null)
    {
        if ( ! preg_match('/^[-_a-z0-9]+$/', $name)) {
            throw new \InvalidArgumentException(
                'Migration name contains disallowed chars. Use only -_a-z0-9');
        }

        if ($time === null) {
            $time = time();
        }

        if ( ! preg_match('/^[0-9]+$/', $time)) {
            throw new \InvalidArgumentException(
                'Migration time contains disallowed chars. use only 0-9.');
        }

        $name = $time.'_'.$name;
        $fullPath = $this->directoryPath.$name.'.php';

        if (is_file($fullPath)) {
            throw new \InvalidArgumentException(
                'Migration with this name and current timestamp already exists.');
        }

        $tmpl = strtr(file_get_contents(static::$template), array(
                ':createdAt'    => date('r ', $time),
                ':extends'      => $this->migrationInheritanceClass,
                ':className'    => $name
            ));

        file_put_contents($fullPath, $tmpl);

        return array($name, $fullPath);
    }

    public function remove($name)
    {
        $path = $this->directoryPath.$name.'.php';

        if ( ! is_file($path)) {
            throw new \InvalidArgumentException(sprintf(
                'Specified migration "%s" does not exists.', $path));
        }

        $this->connection()
            ->delete()
            ->from($this->tableName)
            ->where('name', $name)
            ->execute();

        return unlink($path);
    }

    public function rename($name, $newName)
    {
        if ( ! $this->exists($name)) {
            throw new \InvalidArgumentException(
                'Specified migration does not exists.');
        }

        if ( ! $this->isValidName($newName)) {
            throw new \InvalidArgumentException(
                'Specified name is not valid migration name.');
        }

        if ($this->exists($newName)) {
            throw new \InvalidArgumentException(
                'Migration with specified name is already exists.');
        }

        if ($name === $newName) {
            return true;
        }

        $path = $this->directoryPath.$name.'.php';

        $content = file_get_contents($path);
        $content = preg_replace('/class\s+Migration_('.$name.')\s{1}/si', 
                    'class Migration_'.$newName.' ', $content);
        file_put_contents($this->directoryPath.$newName.'.php', $content);
        $this->remove($name);

        return true;
    }

    public function get($name)
    {
        if ( ! $this->exists($name)) {
            throw new \InvalidArgumentException(
                'Specified migration does not exists.');
        }

        $path = $this->directoryPath.$name.'.php';

        $info = pathinfo($path);
        $parts = explode('_', $name);
        $time = array_shift($parts);

        return array(
            'timestamp' => $time,
            'name'      => $name,
            'path'      => $path
        );
    }

    /**
     * Return true if specified migration exists.
     *
     *     if ($migrationManager->exists('342888834_createUserTable')) {
     *         echo 'Migration exists';
     *     }
     * 
     * @param  string $name Migration name.
     * @return boolean
     */
    public function exists($name)
    {
        if ( ! $this->isValidName($name)) {
            throw new \InvalidArgumentException(sprintf(
                'Migration name "%s" contains disallowed chars or is in the wrong format.', $name));
        }

        $path = $this->directoryPath.$name.'.php';

        return (bool) is_file($path);
    }

    /**
     * Return true if string is valid migration name.
     * 
     * @param  string $name
     * @return boolean
     */
    public function isValidName($name)
    {
        return preg_match('/^[0-9]+_[-_a-z0-9]+$/', $name);
    }

    public function getMigrationsFromDirectory()
    {
        $arr = array();

        foreach (scandir($this->directoryPath) as $file) {

            $path = $this->directoryPath.$file;
            $info = pathinfo($path);

            if ($file == '.' or $file == '..' or ! is_file($path) or $info['extension'] != 'php') {
                continue;
            }

            $parts = explode('_', $info['filename']);
            $time = array_shift($parts);

            $arr[$time] = array(
                'timestamp' => $time,
                'name'      => $info['filename'],
                'path'      => $path
            );
        }

        ksort($arr);

        $list = array();

        foreach ($arr as $timestamp => $file) {
            $list[$file['name']] = $file;
        }

        reset($list);

        return $list;
    }

    public function getMigrationsFromDatabase()
    {
        $result = 
            $this->connection()
                ->select()
                ->from($this->tableName)
                ->orderBy('timestamp', 'desc')
                ->execute()
                ->asArray('name');

        return $result;
    }

    public function getList()
    {
        $local = $this->getMigrationsFromDirectory();
        $database = $this->getMigrationsFromDatabase();

        foreach ($local as $key => $item) {
            $local[$key]['applied'] = isset($database[$item['name']]);
        }

        return $local;
    }

    public function getDatabaseLevel()
    {
        $result = 
            $this->connection()
                ->select()
                ->from($this->tableName)
                ->orderBy('timestamp', 'desc')
                ->limit(1)
                ->execute();

        return $result->count() 
            ? array(
                'name'  => $result->get('name'), 
                'timestamp' => $result->get('timestamp'))
            : false;
    }

    public function getDirectoryLevel()
    {
        $items = $this->getMigrationsFromDirectory();

        if ($items) {
            $current = $items[count($items)-1];

            return array(
                'name'  => $current['name'],
                'timestamp' => $current['timestamp']
            );
        }

        return false;
    }

    public function up($dryRun = true)
    {
        return $this->migrate(static::UP, $dryRun);
    }

    public function down($dryRun = true)
    {
        return $this->migrate(static::DOWN, $dryRun);
    }

    public function sync($dryRun = true)
    {
        return $this->migrate(static::UP, $dryRun);
    }

    public function reset($dryRun = true)
    {
        if ( ! $dryRun) {
            $this->createSchema();
        }

        return $this->migrate(static::RESET, $dryRun);
    }

    /**
     * 
     * 
     * @param  int      $mode      Migration mode (MigrationManager::UP, MigrationManager::DOWN, MigrationManager::RESET).
     * @param  boolean  $dryRun    Dry run (do not execute migrations).
     * @param  string   $target    Target migration name (if not specified the newest/oldest migration is taken).
     * @return array Logs.
     * @throws \InvalidArgumentException If $target migration is not exists.
     * @throws \InvalidArgumentException If $target migration is lower than current database level (and mode is UP).
     * @throws \InvalidArgumentException If $target migration is higher than current database level (and mode is DOWN).
     * @throws \InvalidArgumentException If mode is DOWN and there is no database schema or level is alredy zero.
     * @throws \InvalidArgumentException If mode is UP and migrations are up to date.
     * @throws \Exception If no migrations exists in directory.
     */
    public function migrate($mode = MigrationManager::UP, $dryRun = true, $target = null)
    {
        $log = array();

        // Database not migration table exist, this is first run
        if ( ! $this->databaseExists() or ! $this->tableExists($this->tableName)) {
            $log[] = 'Database or migration table not exists; Creating migrations schema.';

            if ( ! $dryRun) {
                $this->createSchema();
            }
        }
        
        $conn = $this->connection();

        // Existing migrations
        $list = $this->getList();
        
        // Migrations list is empty
        if ( ! $list) {
            throw new \Exception('There is no migrations to run.');
        } 

        // Target migration are not defined, 
        // migrate to the newest one.
        if ($target === null) {
            if ($mode === MigrationManager::UP) {
                end($list);
            }

            $target = current($list);

            reset($list);
        } else {
            // Otherwise check if specified migration exists.
            // (exception will be thrown if not exists)
            $target = $this->get($target);
        }

        $targetTimestamp = $target['timestamp'];
        $dbTimestamp = 0;

        if ($dryRun and $mode === static::RESET) {
            $current = false;
        } else {
            $current = $this->getDatabaseLevel();
            $dbTimestamp = $current['timestamp'];
        }

        $log[] = 'Current version is: '.($current ? $current['name'] : '-- none --');
        

        // Check if target is newer than current level
        if ($mode === MigrationManager::UP and $targetTimestamp <= $dbTimestamp) {
            throw new \InvalidArgumentException(sprintf(
                'Migration %s is lower than current database level.', $target['name']));
            
        }

        if ($mode === MigrationManager::DOWN and $targetTimestamp >= $dbTimestamp) {
            throw new \InvalidArgumentException(sprintf(
                'Migration %s is higher than current database level.', $target['name']));
        }

        // Can't go down we not even init yet
        if ($current === false and $mode === static::DOWN) {
            throw new \InvalidArgumentException('Can\'t go down because database schema is not created yet or no lower migrations exists.');
        } 

        // Can't go up
        if ($mode === static::UP) {
            end($list);
            $tmp = current($list);

            if ($current !== false and $tmp['name'] == $current['name']) {
                throw new \InvalidArgumentException('Can\'t go up, migrations are up to date.');
            }

            reset($list);
        }

        // We are on the first position, can't go down,
        // so just recreate schema and... that's it.
        if ($mode === static::DOWN and key($list) == $current['name']) {
            $this->createSchema();
            $log[] = 'Migration done.';

            if ( ! $dryRun) {
                return $log;
            }
        }

        if ( ! $dryRun) {
            // Disable foreign key checks
            $conn->execute('SET foreign_key_checks=0', Db::PLAIN);
        }
        
        $found = false;

        if ($mode === static::DOWN) {
            $list = array_reverse($list);
        }

        // And now magic happens
        foreach ($list as $item) {
            // We are sync to 
            if ($mode === static::UP) {
                if ( ! $found and $item['name'] === $current['name']) {
                    $found = true;
                    continue;
                }

                if ($found or $current === false) {
                    $log[] = sprintf('Run migration %s (up)', $item['name']);

                    if ( ! $dryRun) {
                        $this->_migrate($item, 'up');
                    }
                }
            } else if ($mode === static::DOWN) {
                if ($item['name'] == $target['name']) {
                    break;
                }

                if ( ! $found and $item['name'] === $current['name']) {
                    $found = true;
                }

                if ($found or $current === false) {
                    $log[] = sprintf('Run migration %s (down)', $item['name']);

                    if ( ! $dryRun) {
                        $this->_migrate($item, 'down');
                    }
                }
            }

            if ($item['name'] == $target['name']) {
                break;
            }
        }

        if ( ! $dryRun) {
            // Enable foreigk key checks
            $conn->execute('SET foreign_key_checks=1', Db::PLAIN);
        }

        return $log;
    }

    private function _migrate($item, $mode)
    {
        $conn = $this->connection();

        require_once $item['path'];

        $className = 'Migration_'.$item['name'];
        $class = new $className($conn);

        if (method_exists($class, $mode)) {
            $class->$mode();

            if ($mode == 'up') {
                $conn
                    ->insert($this->tableName)
                    ->values(array(
                        'name'      => $item['name'],
                        'timestamp' => $item['timestamp']
                    ))
                    ->execute();
            } else if ($mode == 'down') {
                $conn
                    ->delete()
                    ->from($this->tableName)
                    ->where('name', $item['name'])
                    ->execute();
            }
        }
    }

    public function resetTable()
    {
        $this->connection()->delete()->from($this->tableName)->execute();
    }

    public function createSchema()
    {
        // Be sure we use connection that not use any database
        $conn = $this->connection(false);
        $database = $this->connectionConfig['database'];

        // Be sure database not exists
        $conn
            ->schema()
            ->database($database)
            ->drop()
            ->ifExists()
            ->execute();

        // Create database if not exists yet
        $conn
            ->schema()
            ->database($database)
            ->create()
            ->ifNotExists()
            ->charset('utf8_general_ci', true)
            ->execute();

        $conn->execute('USE '.$conn->quoteIdentifier($database), Db::PLAIN);

        // Create migration table
        $conn
            ->schema()
            ->table($this->tableName)
            ->create()
            ->ifNotExists()
            ->engine('InnoDB')
            ->charset('utf8')
            ->indexes(array(function($index){
                $index->on('id')->type('primary key');
            }))
            ->fields(array(
                'id' => function($field){
                    $field->type('int')
                        ->constraint(11)
                        ->incremental();
                },
                'name' => function($field){
                    $field->type('varchar')
                        ->constraint(255);
                },
                'timestamp' => function($field) {
                    $field->type('int')->constraint(11);
                }
            ))
            ->execute();

        // Switch back connection to use database
        $this->connection(true);

        return $this;
    }

    /**
     * Connection manager.
     * 
     * @param  boolean $database Choose database?
     * @return \Cabinet\DBAL\Connection
     */
    public function connection($database = true)
    {
        // Return current connetion
        if ($this->connection and $database === $this->connectionToDatabase) {
            return $this->connection;
        }

        // Disconnect we will create new connection
        if ($this->connection and $database !== $this->connectionToDatabase) {
            $this->connection->disconnect();
        }

        if ($database) {
            $config = $this->connectionConfig;
            $this->connectionToDatabase = true;
        } else {
            $config = array(
                'host'      => $this->connectionConfig['host'],
                'username'  => $this->connectionConfig['username'],
                'password'  => $this->connectionConfig['password'],
                'driver'    => $this->connectionConfig['driver'],
                'asObject'  => false
            );
            $this->connectionToDatabase = false;
        }

        return $this->connection = Db::connection($config);
    }

    public function databaseExists()
    {
        try {
            $this->connection();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function tableExists($table = null)
    {
        if ( ! $table) {
            $table = $this->tableName;
        }

        try {
            $this->connection()->select()->from($table)->limit(1)->execute();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getTableName()
    {
        return $this->tableName;
    }
}