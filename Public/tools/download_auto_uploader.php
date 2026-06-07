<?php
declare(strict_types=1);

$files = [
  'installer' => [
    'path' => __DIR__ . '/TPCAutoUploaderSetup.exe',
    'name' => 'TPCAutoUploaderSetup.exe',
    'type' => 'application/octet-stream',
  ],
  'script' => [
    'path' => __DIR__ . '/../../tools/auto_uploader/tpc_auto_uploader.py',
    'name' => 'tpc_auto_uploader.py',
    'type' => 'text/x-python; charset=utf-8',
  ],
  'runner' => [
    'path' => __DIR__ . '/../../tools/auto_uploader/run_tpc_auto_uploader.cmd',
    'name' => 'run_tpc_auto_uploader.cmd',
    'type' => 'application/octet-stream',
  ],
  'readme' => [
    'path' => __DIR__ . '/../../tools/auto_uploader/README.md',
    'name' => 'TPC_Auto_Uploader_README.md',
    'type' => 'text/markdown; charset=utf-8',
  ],
];

$key = (string)($_GET['file'] ?? 'installer');
if (!isset($files[$key]) || !is_file($files[$key]['path'])) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo '找不到下載檔案';
  exit;
}

$file = $files[$key];
header('Content-Type: ' . $file['type']);
header('Content-Length: ' . (string)filesize($file['path']));
header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
header('Cache-Control: private, no-store, max-age=0');
readfile($file['path']);
