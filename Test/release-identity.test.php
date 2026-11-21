<?php
// Regression: stable release uses one plugin identity and preserves the historical index.
$root = dirname(__DIR__);
$p = file_get_contents($root.'/Plugin.php');
$c = file_get_contents($root.'/Controller/SmartSearchController.php');
$j = file_get_contents($root.'/Assets/orksearch-v1.0.0.js');
$i = file_get_contents($root.'/Service/DocumentIndex.php');
$expected = array(
    array($p, 'namespace Kanboard\\Plugin\\OrkSearch;', 'plugin namespace'),
    array($p, "return 'OrkSearch'", 'plugin name'),
    array($p, "return '1.0.0'", 'plugin version'),
    array($p, 'OrkSearch:project/header', 'template hook'),
    array($p, 'plugins/OrkSearch/Assets/orksearch-v1.0.0.js', 'JS asset'),
    array($c, 'namespace Kanboard\\Plugin\\OrkSearch\\Controller;', 'controller namespace'),
    array($j, "plugin:'OrkSearch'", 'API plugin parameter'),
    array($i, '/orkflow_smartsearch/documents.sqlite', 'historic index path'),
    array($j, 'ofss:search:v1:project:', 'persistent browser-tab state'),
);
foreach ($expected as $test) {
    if (strpos($test[0], $test[1]) === false) {
        fwrite(STDERR, 'Missing '.$test[2].PHP_EOL);
        exit(1);
    }
}
if (strpos($p.$c, 'Kanboard\\Plugin\\OrkFlowFilterStack') !== false) {
    fwrite(STDERR, "Legacy namespace still active\n");
    exit(1);
}
echo "OrkSearch identity & index migration: OK\n";
