<?php
// Smoke test of Kanboard's plugin-name/namespace/assets contract without modifying Kanboard.
namespace Kanboard\Core\Security {
    class Role { const PROJECT_VIEWER = 'project-viewer'; }
}
namespace Kanboard\Core\Plugin {
    class Base {
        public $projectAccessMap;
        public $template;
        public $hook;
    }
}
namespace {
    class OrkSearchRecorder {
        public $events = array();
        public function add($controller, $action, $role) { $this->events[] = array($controller,$action,$role); }
        public function attach($hook, $template) { $this->events[] = array($hook,$template); }
        public function on($hook, $array) { $this->events[] = array($hook,$array['template']); }
    }
    require dirname(__DIR__).'/Plugin.php';
    $reg = new OrkSearchRecorder();
    $p = new \Kanboard\Plugin\OrkSearch\Plugin();
    $p->projectAccessMap = $reg;
    $p->template = (object)array('hook'=>$reg);
    $p->hook = $reg;
    $p->initialize();
    $expected = array(
        array('SmartSearchController','search','project-viewer'),
        array('template:project:header:after','OrkSearch:project/header'),
        array('template:layout:js','plugins/OrkSearch/Assets/orksearch-v1.0.0.js'),
        array('template:layout:css','plugins/OrkSearch/Assets/orksearch-v1.0.0.css'),
    );
    if ($reg->events !== $expected || $p->getPluginName() !== 'OrkSearch'
        || $p->getPluginVersion() !== '1.0.0'
        || $p->getCompatibleVersion() !== '>=1.2.50') {
        fwrite(STDERR, "OrkSearch plugin registration mismatch\n"); exit(1);
    }
    foreach (array('Assets/orksearch-v1.0.0.js','Assets/orksearch-v1.0.0.css','Template/project/header.php') as $name) {
        if (!is_file(dirname(__DIR__).'/'.$name)) {
            fwrite(STDERR, "Missing plugin asset: $name\n"); exit(1);
        }
    }
    echo "OrkSearch plugin registration: OK\n";
}
