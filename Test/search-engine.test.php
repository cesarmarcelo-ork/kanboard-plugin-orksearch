<?php
// Minimal stub for pure search logic tests; does not mock database integration.
namespace Kanboard\Core {
    class Base { }
}
namespace Kanboard\Core\Security {
    class Role {
        const APP_USER='app-user'; const APP_MANAGER='app-manager'; const APP_ADMIN='app-admin';
    }
}
namespace {
    require __DIR__ . '/../Service/SearchEngine.php';
    use Kanboard\Plugin\OrkSearch\Service\SearchEngine as S;
    use Kanboard\Core\Security\Role;

    function check($actual, $expected, $msg) {
        if ($actual !== $expected) {
            fwrite(STDERR, "FAIL $msg: ".var_export($actual,true)." vs ".var_export($expected,true)."\n");
            exit(1);
        }
        echo "OK $msg\n";
    }
    $t = array(
        'id'=>1393,'reference'=>'RMS-2026-004','title'=>'Plano de Garantia de Liquidez de Caixa',
        'description'=>'Acompanhar redução do consumo de reagentes',
        'category_id'=>3,'category_name'=>'Química Ambiental',
        'column_id'=>5,'column_name'=>'Em Andamento',
        'swimlane_id'=>9,'swimlane_name'=>'Geração de Receitas',
        'owner_id'=>4,'assignee'=>'Cesar',
        'is_active'=>1, 'priority'=>2, 'score'=>3,
        'tags'=>array('22'=>'DCN','34'=>'Liquidez'),
        'comments'=>array('Concluída coleta de amônia e fluoreto'),
        'filenames'=>array('laudo-amostra-fluoreto-2026.pdf'),
        'subtasks'=>array('Validar concentração de ferro')
    );
    foreach (array('Liquidez','fluoreto','amonia','Quimica','geracao','DCN','laudo','ferro','#1393','RMS-2026') as $word) {
        check(S::matchText($t,$word) !== null,true,'Search '.$word);
    }
    // A single task can match in several independent sources.
    $multi = S::matchText($t, 'fluoreto');
    $fields = array_column($multi['matches'], 'field');
    check(in_array('comment', $fields, true), true, 'Comment included in source list');
    check(in_array('filename', $fields, true), true, 'Filename included in source list');
    check(count($multi['matches']) >= 2, true, 'Multiple sources on one task');
    $documents = array('field'=>'attachment_content','snippet'=>'relatorio.docx: fluoreto',
        'score'=>6900,'matches'=>array(
          array('field'=>'attachment_content','filename'=>'relatorio.docx','snippet'=>'fluoreto','score'=>6900),
          array('field'=>'attachment_content','filename'=>'relatorio.txt','snippet'=>'fluoreto','score'=>6900),
          array('field'=>'attachment_content','filename'=>'relatorio.csv','snippet'=>'fluoreto','score'=>6900),
          array('field'=>'attachment_content','filename'=>'relatorio.md','snippet'=>'fluoreto','score'=>6900),
          array('field'=>'attachment_content','filename'=>'relatorio.markdown','snippet'=>'fluoreto','score'=>6900),
        ));
    $merged = S::mergeMatches($multi, $documents);
    check(count($merged['matches']) >= 7, true, 'Card and 5 attachments retain all matching origins');
    check($merged['field'], $multi['field'], 'Original relevance winner preserved');
    check(S::mergeMatches(null, $documents)['matches'][3]['filename'], 'relatorio.md', 'Document-only result preserves markdown');
    check(S::matchText($t,'fluoreto DCN') !== null,true,'AND across fields');
    check(S::matchText($t,'fluoreto cobre'),null,'AND excludes nonmatches');
    check(S::matchText($t,'amonia')['field'],'comment','Comment source');
    check(S::matchText($t,'laudo')['field'],'filename','Attachment filename source');
    check(S::matchText($t,'validar')['field'],'subtask','Subtask source');
    check(S::facetValues($t, 'priority'), array('2'=>'2'), 'Native priority (tasks.priority)');
    check(S::facetValues($t, 'complexity'), array('3'=>'3'), 'Native complexity (tasks.score)');
    check(S::matchesFilters($t,array('swimlane'=>'9','column'=>'5','category'=>'3','tag'=>'22','assignee'=>'4','priority'=>'2','complexity'=>'3','status'=>'1')), true, 'All eight refinements cumulatively');
    check(S::matchesFilters($t,array('priority'=>'1')),false,'Other priority excludes');
    check(S::matchesFilters($t,array('complexity'=>'2')),false,'Other complexity excludes');
    check(S::matchesFilters($t,array('priority'=>'2','complexity'=>'4'), 'complexity'),true,'Self-excluding complexity facet');
    $unassigned = $t;
    $unassigned['priority'] = 0;
    $unassigned['score'] = 0;
    check(S::facetValues($unassigned, 'priority'), array('0'=>'0'), 'Zero priority remains selectable');
    check(S::facetValues($unassigned, 'complexity'), array('0'=>'0 (Not specified)'), 'Unspecified complexity stays selectable');
    check(S::matchesFilters($unassigned,array('priority'=>'0','complexity'=>'0')),true,'Zero priority/complexity filters');
    check(array_column(S::sortFacetOptions(array(array('value'=>'10','label'=>'10'),array('value'=>'2','label'=>'2'),array('value'=>'0','label'=>'0')), 'complexity'), 'value'), array('0','2','10'), 'Numeric complexity sort');
    check(array_column(S::sortFacetOptions(array(array('value'=>'10','label'=>'10'),array('value'=>'2','label'=>'2')), 'priority'), 'value'), array('2','10'), 'Numeric priority sort');
    check(S::matchesFilters($t,array('category'=>'3','tag'=>'22','status'=>'1')),true,'Three facets');
    check(S::matchesFilters($t,array('category'=>'4')),false,'Category excludes');
    check(S::matchesFilters($t,array('tag'=>'99')),false,'Missing tag excludes');
    check(S::matchesFilters($t,array('status'=>'0')),false,'Closed excludes');
    $closed = $t;
    $closed['is_active'] = 0;
    check(S::facetValues($t,'status'), array('1'=>'Open'), 'Active is status 1');
    check(S::facetValues($closed,'status'), array('0'=>'Closed'), 'Closed is status 0');
    check(S::matchesFilters($closed,array('status'=>'0')),true,'Finalizados finds closed cards');
    check(S::matchesFilters($closed,array('status'=>'1')),false,'Abertos excludes closed cards');
    check(S::matchesFilters($closed,array('status'=>'')),true,'Todos accepts closed cards');
    check(S::matchesFilters($t,array('status'=>'')),true,'Todos accepts open cards');
    check(S::matchesFilters($t,array('status'=>'0'), 'status'),true,'Ignore current facet');
    check(S::canReadComment(Role::APP_USER,Role::APP_USER),true,'Public comment');
    check(S::canReadComment(Role::APP_MANAGER,Role::APP_USER),false,'Manager comment hidden from user');
    check(S::canReadComment(Role::APP_ADMIN,Role::APP_MANAGER),false,'Admin comment hidden from manager');
    check(S::canReadComment(Role::APP_MANAGER,Role::APP_ADMIN),true,'Manager comment visible to admin');
    check(S::canReadComment('unknown',Role::APP_ADMIN),false,'Unknown visibility fail closed');
    // Relevance source hierarchy: title > tag > description > comment > filename.
    $sourceScores = array();
    foreach (array('title', 'tag', 'description', 'comment', 'filename') as $source) {
        $candidate = $t;
        $candidate['title'] = '';
        $candidate['tags'] = array();
        $candidate['description'] = '';
        $candidate['comments'] = array();
        $candidate['filenames'] = array();
        $candidate['category_name'] = '';
        $candidate['column_name'] = '';
        $candidate['swimlane_name'] = '';
        $candidate['assignee'] = '';
        if ($source === 'title' || $source === 'description') $candidate[$source] = 'Projeto com xenon';
        elseif ($source === 'tag') $candidate['tags'] = array('2' => 'xenon');
        elseif ($source === 'comment') $candidate['comments'] = array('xenon');
        else $candidate['filenames'] = array('xenon.pdf');
        $match = S::matchText($candidate, 'xenon');
        check($match['field'], $source, 'Source detected: '.$source);
        $sourceScores[] = $match['score'];
    }
    check($sourceScores, array_values(array_unique($sourceScores)), 'Distinct source scores');
    for ($i=0; $i<count($sourceScores)-1; $i++) {
        check($sourceScores[$i] > $sourceScores[$i+1], true, 'Relevance priority '.$i);
    }
    $ranked = array(
        array('id'=>1,'date_modification'=>50,'_match'=>array('score'=>12000)),
        array('id'=>2,'date_modification'=>200,'_match'=>array('score'=>11000)),
        array('id'=>3,'date_modification'=>100,'_match'=>array('score'=>12000)),
    );
    usort($ranked, array(S::class, 'compareRelevance'));
    check(array_column($ranked, 'id'), array(3,1,2), 'Rank then newest');
    check(S::matchText($t, '')['score'], 0, 'Empty search carries no relevance');
    // Count-only mode must not invoke the expensive relation-search path.
    $engineSource = file_get_contents(__DIR__.'/../Service/SearchEngine.php');
    check(strpos($engineSource, "if (\$mode === 'summary')") !== false, true, 'Lightweight summary endpoint');
    check(strpos($engineSource, 'MAX_RESULTS = 20') !== false, true, '20 results per page');
    check(strpos($engineSource, 'array_slice($filtered, $offset, self::MAX_RESULTS)') !== false, true, 'Offset pagination');
    echo "All assertions passed.\n";
}
