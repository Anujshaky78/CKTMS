<?php
declare(strict_types=1);
require_once TMS_ROOT . '/database/migrations.php';

class Database {
  private ?PDO $pdo = null;
  public function connect(): PDO {
    if ($this->pdo) return $this->pdo;
    $c = require TMS_ROOT . '/config/database.php';
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}";
    $this->pdo = new PDO($dsn, (string)$c['user'], (string)$c['password'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    tms_migrate($this->pdo);
    return $this->pdo;
  }
}

if (!function_exists('db')) {
  function db(): PDO { static $d = null; if ($d) return $d; $d = (new Database())->connect(); return $d; }
}
