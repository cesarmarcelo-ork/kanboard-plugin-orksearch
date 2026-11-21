<?php
namespace {
    require __DIR__.'/../Service/DocumentIndex.php';
    use Kanboard\Plugin\OrkSearch\Service\DocumentIndex as D;
    function ok($bool, $label) {
        if (!$bool) {fwrite(STDERR,"FAIL $label\n");exit(1);}
        echo "PASS $label\n";
    }
    ok(D::makeMatchExpression('fluoreto') === '"fluoreto"*', 'single word');
    ok(D::makeMatchExpression('fluoreto amônia') === '"fluoreto"* AND "amônia"*', 'multiple words');
    ok(D::makeMatchExpression('foo OR bar') === '"foo"* AND "OR"* AND "bar"*', 'FTS operators are literal words');
    ok(D::makeMatchExpression('(){}') === '', 'no control syntax');
    ok(substr_count(D::makeMatchExpression(str_repeat('teste ', 40)), '"*') === 12, 'term count bounded');
    $d = new D('/path/not/present/do-not-create.sqlite');
    ok($d->search(1,'fluoreto',[1],[1=>1]) === [], 'absent index never blocks search');
    ok(!file_exists('/path/not/present/do-not-create.sqlite'), 'does not create index');
}
