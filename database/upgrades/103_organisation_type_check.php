<?php
return static function(PDO $pdo): void {
    $rows=\EPejabat\Core\Database::query($pdo,"SELECT tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME='organisations' AND tc.CONSTRAINT_TYPE='CHECK'")->fetchAll();
    $maria=str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB');
    foreach($rows as $r) if(preg_match('/\btype\b/i',$r['CHECK_CLAUSE'])) {
        if(!preg_match('/\A[A-Za-z0-9_]+\z/',$r['CONSTRAINT_NAME'])) throw new RuntimeException('Unexpected constraint');
        $pdo->exec('ALTER TABLE organisations DROP '.($maria?'CONSTRAINT':'CHECK').' `'.$r['CONSTRAINT_NAME'].'`');
    }
};
