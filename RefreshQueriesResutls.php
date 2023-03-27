<?php

namespace dokuwiki\plugin\sql2wiki;

use dokuwiki\Menu\Item\AbstractItem;

class RefreshQueriesResutls extends AbstractItem {

    /** @inheritdoc */
    public function __construct() {
        parent::__construct();

        $this->svg = DOKU_INC . 'lib/plugins/sql2wiki/arrows-rotate-solid.svg';
        $this->label = 'Refresh Queries Results';
    }
}