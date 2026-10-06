<?php
if (!function_exists('cs_config') && function_exists('cf_config')) {
    function cs_config(): array
    {
        return cf_config();
    }
}
if (!function_exists('cf_config') && function_exists('cs_config')) {
    function cf_config(): array
    {
        return cs_config();
    }
}
if (!function_exists('cs_db') && function_exists('cf_db')) {
    function cs_db(): ?PDO
    {
        return cf_db();
    }
}
if (!function_exists('cf_db') && function_exists('cs_db')) {
    function cf_db(): ?PDO
    {
        return cs_db();
    }
}
if (!function_exists('cs_hash') && function_exists('cf_hash')) {
    function cs_hash(string $password): string
    {
        return cf_hash($password);
    }
}
if (!function_exists('cf_hash') && function_exists('cs_hash')) {
    function cf_hash(string $password): string
    {
        return cs_hash($password);
    }
}
