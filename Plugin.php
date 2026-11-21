<?php

namespace Kanboard\Plugin\OrkSearch;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;

/**
 * Compact project-wide search, including card metadata, comments and filenames.
 * No migrations, external services, or Kanboard core modifications.
 */
class Plugin extends Base
{
    public function initialize()
    {
        $this->projectAccessMap->add('SmartSearchController', 'search', Role::PROJECT_VIEWER);

        $this->template->hook->attach(
            'template:project:header:after',
            'OrkSearch:project/header'
        );

        $this->hook->on('template:layout:js', array(
            'template' => 'plugins/OrkSearch/Assets/orksearch-v1.0.0.js',
        ));
        $this->hook->on('template:layout:css', array(
            'template' => 'plugins/OrkSearch/Assets/orksearch-v1.0.0.css',
        ));
    }

    public function getPluginName()
    {
        return 'OrkSearch';
    }

    public function getPluginDescription()
    {
        return 'Advanced Kanboard search across tasks, comments, subtasks, metadata and attachment names, with optional indexed document contents and advanced filters.';
    }

    public function getPluginAuthor()
    {
        return 'OrkFlowTech';
    }

    public function getPluginVersion()
    {
        return '1.0.0';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/cesarmarcelo-ork/kanboard-plugin-orksearch';
    }

    public function getCompatibleVersion()
    {
        return '>=1.2.50';
    }
}
