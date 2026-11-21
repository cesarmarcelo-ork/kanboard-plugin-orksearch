<?php
// The anchor is moved inside the native search input-addon by an external JS asset.
// Its input-addon-item styling is the same as the neighboring Kanboard filter items.
// Render the initial label in the Kanboard user language, not only after JS runs.
$ofssLanguage = strtolower((string) $this->app->jsLang());
$ofssLabel = strpos($ofssLanguage, 'pt') === 0 ? 'Busca Inteligente' : 'Smart Search';
?>
<div class="ofss-anchor input-addon-item" data-project-id="<?= (int) $project['id'] ?>" hidden>
    <div class="dropdown">
        <a href="#" role="button" class="dropdown-menu dropdown-menu-link-icon ofss-launch"
           aria-label="<?= $this->text->e($ofssLabel) ?>" title="<?= $this->text->e($ofssLabel) ?>"
           aria-haspopup="dialog" aria-expanded="false">
            <i class="fa fa-search fa-fw" aria-hidden="true"></i>
            <span class="ofss-launch-label"><?= $this->text->e($ofssLabel) ?></span>
        </a>
    </div>
</div>
