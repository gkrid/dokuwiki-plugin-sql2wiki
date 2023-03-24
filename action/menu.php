<?php

use dokuwiki\plugin\sql2wiki\RegenerateSqlSiteTools;

/**
 * DokuWiki Plugin sql2wiki (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

class action_plugin_sql2wiki_menu extends DokuWiki_Action_Plugin
{
    /** @inheritdoc */
    function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'handle_menu_items_assembly');
    }

    public function handle_menu_items_assembly(Doku_Event $event)
    {
        global $ID, $auth;

        if ($event->data['view'] != 'page') return false;

        array_splice($event->data['items'], -1, 0, [new RegenerateSqlSiteTools()]);

        return true;
    }

}