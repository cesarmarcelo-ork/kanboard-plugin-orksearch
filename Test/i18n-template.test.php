<?php
/** Verify server-rendered button follows the same pt vs en fallback as browser JS. */
final class OFSSTestLang {
    public $code;
    public function __construct($code) { $this->code=$code; }
    public function jsLang() { return $this->code; }
}
final class OFSSTestText {
    public function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
final class OFSSTestRender {
    public $app; public $text;
    public function __construct($code) { $this->app=new OFSSTestLang($code); $this->text=new OFSSTestText(); }
    public function output() { $project=['id'=>1393]; ob_start(); include __DIR__.'/../Template/project/header.php'; return ob_get_clean(); }
}
foreach (['pt-BR'=>'Busca Inteligente', 'pt'=>'Busca Inteligente', 'pt-PT'=>'Busca Inteligente', 'en'=>'Smart Search', 'en-GB'=>'Smart Search', 'fr'=>'Smart Search'] as $language => $expected) {
    $output=(new OFSSTestRender($language))->output();
    foreach (['aria-label="'.$expected.'"', 'title="'.$expected.'"', 'ofss-launch-label">'.$expected.'</span>'] as $fragment) {
        if (strpos($output,$fragment) === false) { throw new Exception('Language '.$language.' did not render '.$fragment); }
    }
    if (strpos($output,'data-project-id="1393"') === false) { throw new Exception('Project ID lost'); }
}
$js = file_get_contents(__DIR__.'/../Assets/orksearch-v1.0.0.js');
foreach (["open: 'Busca Inteligente'", "open: 'Smart Search'", "startsWith('pt')"] as $part) {
    if (strpos($js,$part)===false) { throw new Exception('JS language contract regression: '.$part); }
}
echo "Portuguese / English HTML server labels: PASS\n";
