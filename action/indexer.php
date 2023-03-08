<?php
/**
 * DokuWiki Plugin sql2wiki (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */
class action_plugin_sql2wiki_indexer extends \dokuwiki\Extension\ActionPlugin
{

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('INDEXER_TASKS_RUN', 'FIXME', $this, 'handle_indexer_tasks_run');        $controller->register_hook('INDEXER_VERSION_GET', 'FIXME', $this, 'handle_indexer_version_get');        $controller->register_hook('INDEXER_PAGE_ADD', 'FIXME', $this, 'handle_indexer_page_add');
   
    }

    /**
     * FIXME Event handler for
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  optional parameter passed when event was registered
     * @return void
     */
    public function handle_indexer_tasks_run(Doku_Event $event, $param)
    {
    }
    public function handle_indexer_version_get(Doku_Event $event, $param)
    {
    }
    public function handle_indexer_page_add(Doku_Event $event, $param)
    {
    }

}

