<?php
namespace SevenCupsMigratos;


class Migration{
    const DIRECTION_UP='u';
    const DIRECTION_DOWN='d';

    /**
     * @var \PDO
     */
    private $database;
    /** @var string */
    private $migration_folder='migration';

    /** @var string */
    private $base_migration_version='base';

    const VERSION_TABLE_NAME='migratos_migration_versions';


    public function __construct(\PDO $database=null) {
       $this->setDatabase($database); 
    }

    public function setDatabase(\PDO $database):self {
        $this->database = $database;
        $this->database->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        return $this;
    }

    public function getDatabase():?\PDO{
        return $this->database; 
    }

    public function setMigrationFolder(string $migration_folder):self {
        if(!is_dir($migration_folder)) {
            throw new \Exception('You cannot set nonexisting folder as migration folder. Please create '.$migration_folder);
        }
        $this->migration_folder=$migration_folder;
       return $this; 
    }

    public function getMigrationFolder():string{
        return $this->migration_folder;
    }

    public function setBaseMigrationVersion(string $version):self{
        $this->base_migration_version = $version;
        return $this;
    }

    public function getBaseMigrationVersion():string{
        return $this->base_migration_version;
    }
    public function purgeDatabase():bool {
        if(method_exists($this->getDatabase(),'purgeDatabase')) {
            $this->getDatabase()->purgeDatabase();
            return true;
        }

        throw new \Exception('Your database object is not support purgeDatabase method');
        return true;
    }
    public function init() {
        $base_migration = $this->getMigration($this->getBaseMigrationVersion());
        return $this->runMigration($base_migration,'base');
    }

    public function runMigration(string $migration,string $version) {
        $this->getDatabase()->query($migration);

        $this->getDatabase()->query("INSERT INTO ".self::VERSION_TABLE_NAME." VALUES(null,'$version',CURRENT_TIMESTAMP(), '$version')");
        return true;
    }


    public function getMigration(string $migration,string $direction=null):?string {
        $filename = $migration.'.sql';
        if($direction) $filename = $direction . '_' . $filename;

        $filepath = $this->getMigrationFolder().DIRECTORY_SEPARATOR.$filename;

        if(!file_exists($filepath)) throw new \Exception('Migration file not found! File path:'.$filepath);

        $content = file_get_contents($filepath);

        return $content;
    }

    public function investigateExistingMigrations():?array{
        $files = [self::DIRECTION_UP=>[], self::DIRECTION_DOWN=>[]];

        foreach(glob($this->getMigrationFolder().DIRECTORY_SEPARATOR.'[ud]_*.sql') as $file) {
            $file = str_replace([$this->getMigrationFolder(),DIRECTORY_SEPARATOR,'.sql'],'',$file);
            list($direction,$version) = explode('_',$file);

            $files[$direction][$version]=$version;
        }

        return $files;
    }

    public function validateSchema():bool {
        $query = $this->database->prepare("show tables like ?"); 
        $query->execute([self::VERSION_TABLE_NAME]);
        $result = $query->fetchColumn();
        
        if($result===false) throw new \Exception('You have run command with init option in first run.');

        return true;
    }

    public function findRunnedMigrations():?array{
        $this->validateSchema();
        $query = $this->database->prepare("SELECT version FROM ".self::VERSION_TABLE_NAME);
        $query->execute();
        $result = $query->fetchAll();
        return $result;
    }

    public function findNewMigrations():array {
        $runnedMigrations = $this->findRunnedMigrations();
        $existingMigrations = $this->investigateExistingMigrations();

        foreach($runnedMigrations as $runnedVersion) {
            unset($existingMigrations[self::DIRECTION_UP][$runnedVersion['version']]);
            unset($existingMigrations[self::DIRECTION_DOWN][$runnedVersion['version']]);
        }

        return $existingMigrations;
    }

    public function generateBase(): string {
        $tablename = self::VERSION_TABLE_NAME;
        $sql = <<<SQL
CREATE TABLE $tablename (
    id int(11) NOT NULL AUTO_INCREMENT, 
    version varchar(25),
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    filename varchar(255),
    PRIMARY KEY (id)
)
SQL;
        return $sql;
    }

    public function generateUpTemplate(string $ts): string {
        $template = <<<SQL
/*
--Version $ts
--add your up queries to this file
----------------------------------
*/

SQL;
        return $template; 
    }
    public function generateDownTemplate(string $ts): string {
        $template = <<<SQL
/*
--Version $ts
--add your down queries to this file
------------------------------------
*/

SQL;
        return $template; 
    }


    public function writeMigrationFile(string $version, string $content,string $direction = null):bool {
        $filename = $version.'.sql';
        if($direction) $filename = $direction.'_'.$filename;

        $filepath = $this->getMigrationFolder().DIRECTORY_SEPARATOR.$filename;
        return boolval(file_put_contents($filepath,$content));
    }



    public function transactionStart():bool{
        return $this->database->beginTransaction();
    }

    public function transactionCommit():bool{
        return $this->database->commit();
    }
}
