<?php
class Cache {
    private static $prefix = 'pos_';
    
    public static function get($key) {
        if (!extension_loaded('apcu')) return null;
        return apcu_fetch(self::$prefix . $key);
    }
    
    public static function set($key, $value, $ttl = 3600) {
        if (!extension_loaded('apcu')) return false;
        return apcu_store(self::$prefix . $key, $value, $ttl);
    }
    
    public static function delete($key) {
        if (!extension_loaded('apcu')) return false;
        return apcu_delete(self::$prefix . $key);
    }
    
    public static function flush() {
        if (!extension_loaded('apcu')) return false;
        return apcu_clear_cache();
    }
}