<?php

// Minimal declarations of Unraid WebGUI helpers provided by the emhttp runtime
// (not shipped in this repo) so static analysis knows they exist. Loaded via
// PHPStan bootstrapFiles; bodies are dummies and are not analyzed.

if (!function_exists('mk_option')) {
    /** @param mixed $select */
    function mk_option($select, string $value, string $text, string $extra = ''): string { return ''; }
}

if (!function_exists('parse_plugin_cfg')) {
    /** @return array<string,string> */
    function parse_plugin_cfg(string $plugin, bool $sections = false): array { return []; }
}
