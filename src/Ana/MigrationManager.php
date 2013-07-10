<?php

namespace Ana;

use \Cabinet\DBAL\Db;

class MigrationManager
{
    const UP = 1;
    const DOWN = 2;
    const SYNC = 3;
    const RESET = 4;

    public static $template = 'migration.class.template';
    protected $directoryPath = '';
    protected $migrationInheritanceClass = '\Ana\Migration';

    protected $connectionConfig = array();
    private $connectionToDatabase = false;

    protected $tableName = 'ana_migrations';

    /**
     * Connection object.
     * @var \Cabiner\DBAL\Connection
     */
    protected $connection;

    public function __construct($config)
    {
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

        $name = $time.'_'.$name;
        $fullPath = $this->directoryPath.$name.'.php';

        if (is_file($fullPath)) {
            throw new \InvalidArgumentException(
                'Unit with this name and current timestamp already exists.');
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

        return unlink($path);
    }

    public function getList()
    {
        $arr = array();

        foreach (scandir($this->directoryPath) as $file) {

            $path = $this->directoryPath.$file;
            $info = pathinfo($path);

            if ($file == '.' or $file == '..' or ! is_file($path) or $info['extension'] != 'php') {
                continue;
            }

            $parts = explode('_', $file);

            $arr[$parts[0]] = array(
                'timestamp' => $parts[0],
                'filename'  => $info['basename'],
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
        return $this->migrate(static::SYNC, $dryRun);
    }

    public function reset($dryRun = true)
    {
        if ( ! $dryRun) {
            $this->createSchema();
        }

        return $this->migrate(static::RESET, $dryRun);
    }

    public function migrate($to = MigrationManager::SYNC, $dryRun = true)
    {
        $log = array();

        // Database not migration table exist, this is first run
        if ( ! $this->databaseExists() or ! $this->tableExists($this->tableName)) {
            if ($dryRun) {
                $log[] = 'Database or migration table not exists; Creating migrations schema.';
            } else {
                $this->createSchema();
            }
        }
        
        $conn = $this->connection();

        // Existing migrations
        $list = $this->getList();
        $newest = current($list);

        if ($dryRun and $to === static::RESET) {
            $current = false;
        } else {
            $result = $conn
                        ->select()
                        ->from($this->tableName)
                        ->limit(1)
                        ->orderBy('timestamp', 'desc')
                        ->execute();

            if ($result->count() == 0) {
                $current = false;
            } else {
                $current = $result->current();
            }
        }

        if ($dryRun) {
            $log[] = 'Current version is: '.($current ? $current['name'] : '');
        }

        // Can't go down we not even init yet
        if ($current === false and $to === static::DOWN) {
            if ($dryRun) {
                $log[] = 'Can\'t go down.';
                return $log;
            } else {
                return 'Can\'t go down.';
            }
        }

        $list = $this->getList();
        reset($list);
        $found = false;

        // Migrations list empty
        if ( ! $list) {
            if ($dryRun) {
                $log[] = 'There\'s no migrations to run.';
                return $log;
            } else {
                return 'There\'s no migrations to run.';
            }
        }        

        // Can't go up
        if ($to === static::UP) {
            end($list);
            $key = key($list);

            if ($current !== false and $key == $current['name']) {
                if ($dryRun) {
                    $log[] = 'Migrations are up to date.';
                    return $log;
                } else {
                    return 'Migrations are up to date.';
                }
            }

            reset($list);
        }

        // We are on the first position, can't go down
        if ($to === static::DOWN and key($list) == $current['name']) {
            $this->createSchema();

            if ($dryRun) {
                $log[] = 'Migration done.';
                return $log;
            } else {
                return 'Migration done.';
            }
        }

        if ( ! $dryRun) {
            // Disable foreign key checks
            $conn->execute('SET foreign_key_checks=0', Db::PLAIN);
        }

        foreach ($list as $item) {
            if ($to === static::SYNC) {
                if ($current !== false and $current['name'] === $item['name']) {
                    $found = true;
                }

                if ($found or $current === false) {
                    if ($dryRun) {
                        $log[] = sprintf('Run migration %s (up)', $item['name']);
                    } else {
                        $this->_migrate($item, 'up');
                    }
                }
            } else if ($to === static::UP) {
                if ( ! $found and $item['name'] === $current['name']) {
                    $found = true;
                    continue;
                }

                if ($found or $current === false) {
                    if ($dryRun) {
                        $log[] = sprintf('Run migration %s (up)', $item['name']);
                    } else {
                        $this->_migrate($item, 'up');
                    }
                    break;
                }
            } else if ($to === static::DOWN) {
                if ($item['name'] === $current['name']) {
                    if ($dryRun) {
                        $log[] = sprintf('Run migration %s (down)', $item['name']);
                    } else {
                        $this->_migrate($item, 'down');
                    }
                    break;
                }
            }
        }

        if ( ! $dryRun) {
            // Enable foreigk key checks
            $conn->execute('SET foreign_key_checks=1', Db::PLAIN);
        }

        if ($dryRun) {
            return $log;
        } else {
            return 'Migration done.';
        }
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
            ->table('ana_migrations')
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
    }

    /**
     * Connection manager.
     * 
     * @param  boolean $database Choose database?
     * @return \Cabinet\DBAL\Connection
     */
    private function connection($database = true)
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
                'username'  => $this->connectionConfig['username'],
                'password'  => $this->connectionConfig['password'],
                'driver'    => $this->connectionConfig['driver'],
                'asObject'  => false
            );
            $this->connectionToDatabase = false;
        }

        return $this->connection = Db::connection($config);
    }

    private function databaseExists()
    {
        try {
            $this->connection();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function tableExists($table)
    {
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