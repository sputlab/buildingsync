<?php
// includes/DB.php — PDO singleton + helper methods (PHP 7.4+)
// Tutte le tabelle hanno il prefisso "buildingsync_"
class DB {
    private static $pdo = null;

    public static function get() {
        if (self::$pdo === null) {
            $dsn  = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
                // Allinea il fuso orario di MySQL a quello di PHP (NOW(), CURDATE() coerenti con date())
                self::$pdo->exec("SET time_zone = '".(new DateTime())->format('P')."'");
            } catch (PDOException $e) {
                error_log('DB Error: '.$e->getMessage());
                http_response_code(503);
                die('Servizio temporaneamente non disponibile (errore di connessione al database).');
            }
        }
        return self::$pdo;
    }

    public static function q($sql, $params = []) {
        $s = self::get()->prepare($sql);
        $s->execute($params);
        return $s;
    }

    public static function one($sql, $params = []) {
        $r = self::q($sql, $params)->fetch();
        return $r ?: null;
    }

    public static function all($sql, $params = []) {
        return self::q($sql, $params)->fetchAll();
    }

    public static function insert($table, $data) {
        $keys = array_keys($data);
        $cols = implode(',', array_map(function($k){ return "`$k`"; }, $keys));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        self::q("INSERT INTO `$table` ($cols) VALUES ($ph)", array_values($data));
        return (int) self::get()->lastInsertId();
    }

    public static function update($table, $data, $where, $wp = []) {
        $keys = array_keys($data);
        $set  = implode(',', array_map(function($k){ return "`$k`=?"; }, $keys));
        $params = array_merge(array_values($data), $wp);
        return self::q("UPDATE `$table` SET $set WHERE $where", $params)->rowCount();
    }

    public static function delete($table, $where, $wp = []) {
        return self::q("DELETE FROM `$table` WHERE $where", $wp)->rowCount();
    }

    public static function lastId() {
        return (int) self::get()->lastInsertId();
    }
}
