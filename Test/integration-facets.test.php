<?php
namespace Kanboard\Core {
    class Base {
        public $db; public $helper; public $userSession;
        public function __construct($c = null) {
            if (is_array($c)) foreach ($c as $key => $value) $this->$key = $value;
        }
    }
}
namespace Kanboard\Core\Security {
    class Role {
        const APP_USER='app-user'; const APP_MANAGER='app-manager'; const APP_ADMIN='app-admin';
    }
}
namespace {
    require __DIR__.'/../Service/SearchEngine.php';
    use Kanboard\Plugin\OrkSearch\Service\SearchEngine;
    class FakeQuery {
        private $name, $data, $equals = array(), $in = array(), $limit = null;
        public function __construct($name, $data) {$this->name=$name;$this->data=$data;}
        public function __call($method, $args) {
            if ($method === 'eq') $this->equals[$args[0]]=$args[1];
            if ($method === 'in') $this->in[$args[0]]=$args[1];
            if ($method === 'limit') $this->limit=$args[0];
            return $this;
        }
        public function findAll() {
            $rows=array_filter($this->data, function($row) {
                foreach ($this->equals as $key=>$val) {
                    if (!array_key_exists($key,$row)) $key = substr(strrchr('.'.$key,'.'),1);
                    if (!array_key_exists($key,$row) || (string)$row[$key] !== (string)$val) return false;
                }
                foreach ($this->in as $key=>$vals) {
                    if (!array_key_exists($key,$row)) $key = substr(strrchr('.'.$key,'.'),1);
                    if (!array_key_exists($key,$row) || !in_array($row[$key],$vals)) return false;
                }
                return true;
            });
            $rows=array_values($rows);
            return $this->limit === null ? $rows : array_slice($rows,0,$this->limit);
        }
        public function count() {return count($this->findAll());}
    }
    class FakeDb {
        public $rows;
        public function __construct($r) {$this->rows=$r;}
        public function table($name) {return new FakeQuery($name,isset($this->rows[$name])?$this->rows[$name]:array());}
    }
    $make = function($id,$p,$score,$raia,$status) {
        return array('id'=>$id,'reference'=>'','title'=>'Cartão '.$id,'description'=>'',
            'project_id'=>41,'category_id'=>7,'category_name'=>'Financeira',
            'column_id'=>4,'column_name'=>'Em Andamento',
            'swimlane_id'=>$raia,'swimlane_name'=>'Receitas',
            'owner_id'=>$id === 1 ? 0 : 11,
            'assignee_name'=>$id === 1 ? null : 'Cesar',
            'assignee_username'=>$id === 1 ? null : 'cesar',
            'is_active'=>$status,'date_due'=>0,'date_modification'=>1000+$id,
            'priority'=>$p,'score'=>$score);
    };
    $db = new FakeDb(array('tasks'=>array(
        $make(1,0,0,9,1),$make(2,2,3,9,1),$make(3,10,10,10,0),$make(4,4,3,9,1)),
        'tags'=>array(), 'comments'=>array(), 'task_has_files'=>array(), 'subtasks'=>array()));
    $helper = (object)array('url'=>new class {function to($c,$a,$p) {return '/task/'.$p['task_id'];}});
    $session = new class {function getRole(){return 'app-user';}};
    $engine = new SearchEngine(array('db'=>$db,'helper'=>$helper,'userSession'=>$session));
    $empty=array_fill_keys(array('swimlane','column','category','tag','assignee','priority','complexity','status'),'');
    // Fail on the exact PHP 8.2+ regression reported by the maintainer:
    // LEFT JOIN users yields NULL names for unassigned tasks.
    set_error_handler(function ($severity, $message) {
        if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) {
            throw new \ErrorException($message, 0, $severity);
        }
        return false;
    });
    try {
        $all=$engine->run(41,11,'',$empty,0,'facets');
    } finally {
        restore_error_handler();
    }
    $unassigned = array_values(array_filter($all['facets']['assignee'], function ($item) {
        return $item['value'] === '0';
    }));
    if (count($unassigned) !== 1 || $unassigned[0]['label'] !== 'Unassigned' || $unassigned[0]['count'] !== 1) {
        throw new \Exception('Unassigned facet missing or incorrectly labeled');
    }
    $onlyUnassigned = $empty;
    $onlyUnassigned['assignee'] = '0';
    $unassignedHits = $engine->run(41,11,'',$onlyUnassigned,0,'results');
    if ($unassignedHits['total'] !== 1 || $unassignedHits['hits'][0]['id'] !== 1) {
        throw new \Exception('Filtering unassigned tasks failed');
    }
    if ($all['total'] !== 4) throw new \Exception('Expected 4 cards');
    foreach (array('priority'=>array('0','2','4','10'), 'complexity'=>array('0','3','10')) as $f=>$expected) {
        $values=array_column($all['facets'][$f],'value');
        if ($values !== $expected) throw new \Exception('Facet '.$f.' incorrect: '.json_encode($values));
    }
    $selected=$empty; $selected['priority']='2'; $selected['complexity']='3'; $selected['swimlane']='9'; $selected['status']='1';
    $one=$engine->run(41,11,'',$selected,0,'results');
    if ($one['total'] !== 1 || $one['hits'][0]['id'] !== 2) throw new \Exception('Facet intersection failed');
    if (count($one['facets']['priority']) !== 2) throw new \Exception('Self-excluding facet failed');
    $selected=$empty; $selected['priority']='0'; $selected['complexity']='0';
    $zero=$engine->run(41,11,'',$selected,0,'results');
    if ($zero['total'] !== 1 || $zero['hits'][0]['id'] !== 1) throw new \Exception('Zero-value facet failed');
    echo "PASS: integration mock validates native columns, dynamic counts, numeric sorting, combined filters, null assignees and zero values.\n";
}
