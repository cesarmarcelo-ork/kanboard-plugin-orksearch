<?php
/** Schema names as declared by the official Kanboard 1.2.57 Model classes.
 * CategoryModel::TABLE === 'project_has_categories'.
 */
$engine = file_get_contents(__DIR__.'/../Service/SearchEngine.php');
$expected = [
    'tasks', 'project_has_categories', 'columns', 'swimlanes', 'users',
    'tags', 'task_has_tags', 'comments', 'task_has_files', 'subtasks',
];
foreach ($expected as $table) {
    $ok = strpos($engine, "->table('".$table."')") !== false
        || strpos($engine, "->join('".$table."'") !== false;
    if (!$ok) {
        fwrite(STDERR, "FAIL: expected table ".$table." not referenced\n");
        exit(1);
    }
}
if (strpos($engine, "->join('categories'") !== false ||
    strpos($engine, "'categories.name") !== false) {
    fwrite(STDERR, "FAIL: invalid categories table reintroduced\n");
    exit(1);
}
if (strpos($engine, "'project_has_categories.name AS category_name'") === false) {
    fwrite(STDERR, "FAIL: category select must match table name\n");
    exit(1);
}
echo "OK: Kanboard 1.2.57 table names in search engine\n";
