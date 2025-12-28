<?php
declare(strict_types=1);
spl_autoload_register(function (string $class): void {
    $vendor = __DIR__ . '/../../vendor/';
    $map = [
        'PhpOffice\\PhpSpreadsheet\\' => [
            $vendor . 'src/PhpSpreadsheet/',
            $vendor . 'phpoffice/phpspreadsheet/src/PhpSpreadsheet/',
        ],
        'Psr\\SimpleCache\\' => [
            $vendor . 'Psr/SimpleCache/',
            $vendor . 'psr/simple-cache/src/',
        ],
        'ZipStream\\' => [
            $vendor . 'ZipStream/',
            $vendor . 'maennchen/zipstream-php/src/',
        ],
    ];
    foreach ($map as $prefix => $dirs) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) continue;
        $rel = str_replace('\\','/',substr($class,$len)).'.php';
        foreach ($dirs as $d) { $f=$d.$rel; if (is_file($f)) { require $f; return; } }
    }
    $composer = $vendor . 'autoload.php';
    if (is_file($composer)) require_once $composer;
});
