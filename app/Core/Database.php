<?php
declare(strict_types=1);
namespace EPejabat\Core;
final class Database {
    public static function connect(array $c): \PDO {
        if (!preg_match('/\A[a-zA-Z0-9_.:\-]+\z/',$c['host']??'') || !preg_match('/\A[a-zA-Z0-9_\-]+\z/',$c['name']??'') || !is_int($c['port']??null) || $c['port']<1 || $c['port']>65535) throw new \InvalidArgumentException('Konfigurasi database tidak sah.');
        $pdo=new \PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_TIMEOUT=>5]);
        $pdo->exec("SET time_zone = '+00:00'"); return $pdo;
    }
    public static function query(\PDO $pdo, string $sql, array $args=[]): \PDOStatement { $s=$pdo->prepare($sql); $s->execute($args); return $s; }
}
