<?php
defined('ABSPATH') && class_exists('wpdb') || exit;
final class Seed_Export_Read_Wpdb extends wpdb {
    public array $read_audit=[];
    public function db_connect($allow_bail=true) {
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        $this->dbh=mysqli_init(); $this->dbh->options(MYSQLI_OPT_CONNECT_TIMEOUT,20);
        $host=DB_HOST; $socket=null; $port=3306;
        if (preg_match('~^localhost:(/[^\x00]+)$~D',$host,$m)) { $host='localhost'; $socket=$m[1]; }
        elseif (str_contains($host,':')) { throw new RuntimeException('EXPORT_DB_HOST_NOT_CERTIFIED'); }
        if (!$socket) {
            $this->dbh->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT,true); $this->dbh->ssl_set(null,null,null,null,null);
        }
        $this->dbh->real_connect($host,DB_USER,DB_PASSWORD,DB_NAME,$port,$socket,$socket?0:MYSQLI_CLIENT_SSL);
        if (!$socket && empty($this->dbh->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch_row()[1])) { throw new RuntimeException('EXPORT_SQL_TLS_REQUIRED'); }
        $this->has_connected=true; $this->ready=true; $this->init_charset(); $this->set_charset($this->dbh);
        mysqli_report(MYSQLI_REPORT_OFF); return true;
    }
    public function query($query) {
        $trim=trim($query);
        $allowed=preg_match('/^(SELECT|SHOW)\s/i',$trim) && !preg_match('~;|/\*|--|#|\b(INTO|OUTFILE|DUMPFILE|SLEEP|BENCHMARK|LOAD_FILE|FOR\s+UPDATE)\b~i',$trim);
        $allowed=$allowed || $trim==='START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' || $trim==='ROLLBACK';
        if (!$allowed) { throw new RuntimeException('EXPORT_SQL_WRITE_REFUSED'); }
        $this->read_audit[]=hash('sha256',$trim);
        return parent::query($query);
    }
    public function close_read() { parent::query('ROLLBACK'); $this->close(); }
}
