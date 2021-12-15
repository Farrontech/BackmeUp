#!/usr/bin/env php
<?php
/**
 * BackmeUp v1.2
 * Tools for remote backup and restore.
 *
 * Author: Riyan Widiyanto
 * Built: 2021.11.22
 * Copyright (C) 2021 Ridintek Industri.
 */

if ((float)phpversion() < 7.3) {
  die('Require PHP version 7.3 or above. You php version is ' . phpversion());
}

// We make sure the script cannot be run except in command line interface.
if (php_sapi_name() !== 'cli') die('This script must be run in command line interface.');

define('APPVERSION', '1.2');
define('FCPATH', __DIR__);

// Timezone.
date_default_timezone_set('Asia/Jakarta');

/**
 * BackmeUp main class.
 */
class BackmeUp
{
  // File mode.
  const MODE_DATABASE_BACKUP         = 1;
  const MODE_DATABASE_RESTORE        = 2;
  const MODE_DATABASE_BACKUP_RESTORE = 3;
  const MODE_FILE_BACKUP             = 4;
  const MODE_FILE_RESTORE            = 5;

  /**
   * Executable filename. You can change it.
   */
  const ARCHIVE_EXECUTABLE = "7za";
  /**
   * Password for archive. You can change it.
   */
  const PASSWORD = "DLlx0Q3NzVMwiAbE&!";

  /**
   * @var object
   */
  protected $config;
  /**
   * @var string
   */
  protected $configFile;
  /**
   * @var int
   */
  protected $mode;
  /**
   * @var bool
   */
  public $forceDownload = FALSE;
  /**
   * @var bool
   */
  public $verbose = FALSE;

  public function __construct()
  {
    $this->configFile = FCPATH . '/backmeup.json';
    $this->mode = 0;
  }

  /**
   * Execute external command.
   * @param string $command External command.
   */
  protected function execute(string $command)
  {
    if ($this->verbose) {
      echo "Execute: {$command}\r\n\r\n";
    }

    $resultCode = 0;
    passthru($command, $resultCode);
  }

  protected function log(string $message)
  {
    $date = date('Y-m-d H:i:s');
    echo "[{$date}] {$message}\r\n";
  }

  protected function getMainFileNames()
  {
    $files = [];
    $pi = pathinfo(__FILE__);
    $files[] = $pi['basename'];
    $pi = pathinfo($this->configFile);
    $files[] = $pi['basename'];
    return $files;
  }

  protected function getRandomName(int $length = 10)
  {
    return bin2hex(random_bytes($length));
  }

  /**
   * Set config file.
   */
  public function configFile(string $fileName = 'backmeup.json')
  {
    if (is_file($fileName)) {
      $this->configFile = $fileName;
    }
    return $this;
  }

  /**
   * Dump loaded config file as string.
   */
  public function dumpConfig()
  {
    return json_encode($this->config, JSON_PRETTY_PRINT);
  }

  /**
   * Dump config file.
   */
  public function dumpConfigFile()
  {
    $data =
'[
  {
    "name": "SchemaName",
    "databases": [
      {
        "filename": "database.sql",
        "backup": {
          "engine": "MySQL",
          "host": "localhost",
          "port": 3306,
          "db": "your_db",
          "username": "username",
          "password": "password"
        },
        "restore": {
          "engine": "MySQL",
          "host": "localhost",
          "port": 3306,
          "db": "your_db",
          "username": "username",
          "password": "password"
        }
      }
    ],
    "files": [
      {
        "filename": "backup.7z",
        "backup": {
          "path": "/home/user/public_html"
        },
        "restore": {
          "path": "/home/user",
          "url": "https://yoursite.com/backup.7z"
        }
      }
    ]
  }
]';

    if (is_file($this->configFile)) {
      echo "Are you sure to overwrite '{$this->configFile}'? (y/n): ";
      $confirm = trim(fgets(STDIN));
      if (strtolower($confirm) != 'y') die("Command aborted.\r\n");
    }

    $hFile = fopen($this->configFile, "w");
    fputs($hFile, $data);
    fclose($hFile);

    if (is_file($this->configFile)) {
      echo "Config file has been created successfully.\r\n";
    }
  }

  /**
   * Load config file.
   */
  public function loadConfig()
  {
    $this->config = json_decode(file_get_contents($this->configFile));
    if ($this->config === NULL) {
      die("Config file '{$this->configFile}' is not valid.\r\n");
    }
    return $this;
  }

  /**
   * Backup mode.
   * @param int $mode Backup mode.
   */
  public function mode(int $mode)
  {
    if (!$mode) die("Mode value must be 1, 2, 3, 4 or 5.\r\n");
    $this->mode = $mode;
    return $this;
  }

  /**
   * Start backup or restore databases or files.
   */
  public function start()
  {
    if (empty($this->mode))   die("Mode is empty.\r\n");
    if (empty($this->config)) die("Schema is empty.\r\n");

    foreach ($this->config as $schema) {
      if (empty($schema->name)) die("Schema name must be present.\r\n");

      // FILE
      if ($this->mode == self::MODE_FILE_BACKUP || $this->mode == self::MODE_FILE_RESTORE) {
        if (empty($schema->files))      die("Files schema is empty.\r\n");
        if (!is_array($schema->files))  die("Files schema is not array data type.\r\n");

        foreach ($schema->files as $file) {
          // BACKUP
          if ($this->mode == self::MODE_FILE_BACKUP) {
            if (empty($file->backup))               die("Backup schema is empty.\r\n");
            if (!file_exists($file->backup->path))  die("Path is not found.\r\n");
            if (empty($file->filename))             die("File is empty.\r\n");

            $exe  = self::ARCHIVE_EXECUTABLE;
            $pass = self::PASSWORD;

            $exclude = '';
            foreach ($this->getMainFileNames() as $f) {
              $exclude .= "-x!{$f} ";
            }
            $exclude = trim($exclude);

            $this->log("Backing up '{$file->backup->path}'.");
            $command = "{$exe} a -mx=5 -p'{$pass}' -t7z {$exclude} -x!*.7z \"{$file->filename}\" \"{$file->backup->path}\"";

            $this->execute($command);
            $this->log("Backup finished.");
          }

          // RESTORE
          if ($this->mode == self::MODE_FILE_RESTORE) {
            if (empty($file->restore))        die("Restore schema is empty.\r\n");
            if (empty($file->restore->path))  die("Path is empty.\r\n");
            if (empty($file->filename))       die("Filename is empty.\r\n");

            $exe  = self::ARCHIVE_EXECUTABLE;
            $pass = self::PASSWORD;

            if (!empty($file->restore->url) && (!is_file($file->filename) || $this->forceDownload)) {
              $this->log("Downloading data from '{$file->restore->url}'");
              $this->execute("wget -c -O \"{$file->filename}\" {$file->restore->url}");
              $this->log("Download finished.");
            }

            if (!is_file($file->filename)) {
              die("Backup file '{$file->filename}' is not found.");
            }

            $this->log("Restoring '{$file->filename}' to '{$file->restore->path}'.");
            $command = "{$exe} x -o\"{$file->restore->path}\" -p'{$pass}' -aoa \"{$file->filename}\"";

            $this->execute($command);
            $this->log("Restore finished.");
          }
        }
      }

      // DATABASE
      if (
        $this->mode == self::MODE_DATABASE_BACKUP ||
        $this->mode == self::MODE_DATABASE_RESTORE ||
        $this->mode == self::MODE_DATABASE_BACKUP_RESTORE
      ) {
        if (empty($schema->databases)) {
          die("Databases schema is empty.\r\n");
        }

        if (!is_array($schema->databases)) {
          die("Databases schema is not array data type.\r\n");
        }

        foreach ($schema->databases as $database) {
          // BACKUP
          if (
            $this->mode == self::MODE_DATABASE_BACKUP ||
            $this->mode == self::MODE_DATABASE_BACKUP_RESTORE
          ) {
            if (empty($database->backup->engine)) {
              die("No database engine selected. Try 'MySQL' (case-insensitive).\r\n");
            }

            if (strtolower($database->backup->engine) == 'mysql') {
              $configFile = FCPATH . '/.' . $this->getRandomName() . '.ini';

              $hFile = fopen($configFile, 'w');
              fwrite($hFile, "[client]\n");
              fwrite($hFile, "host=\"{$database->backup->host}\"\n");
              fwrite($hFile, "user=\"{$database->backup->username}\"\n");
              fwrite($hFile, "password=\"{$database->backup->password}\"\n");
              fwrite($hFile, "[mysqldump]\n");
              fwrite($hFile, "column-statistics=0\n");
              fclose($hFile);

              $this->log("Backing up database '{$database->backup->db}' from '{$database->backup->host}'.");
              $command = "mysqldump --defaults-file=\"{$configFile}\" {$database->backup->db} > \"{$database->filename}\"";

              $this->execute($command);

              if (is_file($configFile)) unlink($configFile);

              if (is_file($database->filename) && filesize($database->filename) > 100) {
                $this->log("Database '{$database->backup->db}' has been backed up successfully.");
              } else {
                $this->log("Database '{$database->backup->db}' has failed to backup.");
              }
            }
          }

          // RESTORE
          if (
            $this->mode == self::MODE_DATABASE_RESTORE ||
            $this->mode == self::MODE_DATABASE_BACKUP_RESTORE
          ) {
            if (empty($database->restore->engine)) {
              die("No database engine selected. Try 'MySQL' (case-insensitive).\r\n");
            }

            if (!is_file($database->filename)) die("Database file '{$database->filename}' is not exist.\r\n");

            if (strtolower($database->restore->engine) == 'mysql') {
              $configFile = FCPATH . '/.' . $this->getRandomName() . '.ini';

              $hFile = fopen($configFile, 'w');
              fwrite($hFile, "[client]\n");
              fwrite($hFile, "host=\"{$database->restore->host}\"\n");
              fwrite($hFile, "user=\"{$database->restore->username}\"\n");
              fwrite($hFile, "password=\"{$database->restore->password}\"\n");
              fclose($hFile);

              $this->log("Restoring database '{$database->restore->db}' to '{$database->restore->host}'.");
              $command = "mysql --defaults-file=\"{$configFile}\" -f {$database->restore->db} < \"{$database->filename}\"";

              $this->execute($command);

              if (is_file($configFile)) unlink($configFile);

              $this->log("Database '{$database->restore->db}' has been restored successfully.");
            }
          }
        }
      }
    }
  }
}

function showHelp($argv)
{
  echo "BackmeUp v" . APPVERSION . "\r\n";
  echo "Tools for remote backup and restore.\r\n\r\n";
  echo "Copyright (C) 2021 Ridintek Industri.\r\n\r\n";
  echo "Usage:\r\n";
  echo " {$argv[0]} [options] [config_file.json]\r\n\r\n";
  echo "Options:\r\n";
  echo " -db, --database-backup\t\t\tDatabase backup.\r\n";
  echo " -dr, --database-restore\t\tDatabase restore.\r\n";
  echo " -dbr, --database-backup-restore\tDatabase backup and restore.\r\n";
  echo " -fb, --file-backup\t\t\tFile backup.\r\n";
  echo " -fr, --file-restore\t\t\tFile restore.\r\n";
  echo " -f, --force\t\t\t\tForce download for restoring file.\r\n";
  echo " -i, --init\t\t\t\tCreate new config file (JSON).\r\n";
  echo " -v, --verbose\t\t\t\tVerbose mode.\r\n";
  echo "\r\n";
  echo "Default config file is \"backmeup.json\" if parameter is not present.\r\n";
}

if ($argc > 1) {
  $mode = '';
  $backup = new BackmeUp();
  $verbose = FALSE;
  $x = 1;

  while ($x < $argc) {
    switch ($argv[$x]) {
      case '-db':
      case '--database-backup':
        $mode = BackmeUp::MODE_DATABASE_BACKUP;
        break;
      case '-dr':
      case '--database-restore':
        $mode = BackmeUp::MODE_DATABASE_RESTORE;
        break;
      case '-dbr':
      case '--database-backup-restore':
        $mode = BackmeUp::MODE_DATABASE_BACKUP_RESTORE;
        break;
      case '-fb':
      case '--file-backup':
        $mode = BackmeUp::MODE_FILE_BACKUP;
        break;
      case '-fr':
      case '--file-restore':
        $mode = BackmeUp::MODE_FILE_RESTORE;
        break;
      case '-f':
      case '--force':
        $backup->forceDownload = TRUE;
        break;
      case '-i':
      case '--init':
        $backup->dumpConfigFile();
        break;
      case '-v':
      case '--verbose':
        $backup->verbose = TRUE;
        break;
      default:
        if (is_file($argv[$x])) {
          $backup->configFile($argv[$x]);
        } else {
          echo "Invalid command '{$argv[$x]}'.\r\n\r\n";
          showHelp($argv);
          die();
        }
    }
    $x++;
  }

  if ($mode) $backup->loadConfig()->mode($mode)->start();
} else {
  showHelp($argv);
}
