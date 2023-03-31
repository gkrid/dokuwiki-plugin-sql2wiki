<?php

use dokuwiki\plugin\sql2wiki\Csv;

/**
 * DokuWiki Plugin sql2wiki (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */
class syntax_plugin_sql2wiki_query extends \dokuwiki\Extension\SyntaxPlugin
{
    /** @inheritDoc */
    public function getType()
    {
        return 'protected';
    }

    /** @inheritDoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 0;
    }

    /** @inheritDoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<sql2wiki.*?>.*?</sql2wiki>',$mode,'plugin_sql2wiki_query');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($match);
        if ($xml === false) {
            msg('Syntax: "'.hsc($match) . '" is not valid xml', -1);
            return null;
        }
        $attributes = [];
        foreach($xml[0]->attributes() as $a => $b) {
            $attributes[$a] = (string) $b;
        }
        if (!isset($attributes['db']) || !isset($attributes['query'])) {
            msg('"db" and "query" attributes are required.', -1);
            return null;
        }

        // we use substr instead of simplexml to get the raw content
        $content_start = strpos($match, '>') + 1;
        $tag_value = substr($match, $content_start, -strlen('</sql2wiki>'));

        $parsers = [];
        $needle = 'parser_';
        foreach ($attributes as $name => $value) {
            $length = strlen($needle);
            if (substr($name, 0, $length) === $needle) {
                list($_, $col) = explode('_', $name);
                if (preg_match('/([[:alpha:]]+)\((.*)\)/', $value, $matches)) {
                    $class = $matches[1];
                    $config = json_decode($matches[2], true);
                    $parsers[$col] = ['class' => $class, 'config' => $config];
                } else {
                    $parsers[$col] = ['class' => $value, 'config' => null];
                }
            }
        }

        $args = [];
        if (isset($attributes['args'])) {
            $args = array_map('trim', explode(',', $attributes['args']));
        }

        $start = $pos + strpos($match, '>'); // closing char of the opening tag
        $end = $pos + strlen($match) - strlen('</sql2wiki>') - 1;
        $data = [
            'db' => $attributes['db'],
            'query_name' => $attributes['query'],
            'parsers' => $parsers,
            'args' => $args,
            'value' => $tag_value,
            'start' => $start,
            'end' => $end,
            'pos' => $pos,
            'match' => $match
        ];
        return $data;
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($data === null) return false;

        if ($mode == 'metadata') {
            if (!isset($renderer->meta['plugin_sql2wiki'])) {
                $renderer->meta['plugin_sql2wiki'] = [];
            }
            $renderer->meta['plugin_sql2wiki'][] = $data;
            return true;
        }
        if ($mode == 'xhtml') {
            $result = Csv::csv2arr($data['value']);

            if (count($result) == 0) {
                $renderer->p_open();
                $renderer->cdata($this->getLang('none'));
                $renderer->p_close();
                return true;
            }

            // check if we use any parsers
            $parsers = $data['parsers'];
            if (count($parsers) > 0) {
                $class_name = '\dokuwiki\plugin\struct\meta\Column';
                if (!class_exists($class_name)) {
                    msg('Install struct plugin to use parsers', -1);
                    return false;
                }
                $parser_types = $class_name::allTypes();
            }

            $renderer->table_open();
            $renderer->tablethead_open();
            $renderer->tablerow_open();
            $headers = array_shift($result);
            foreach ($headers as $header) {
                $renderer->tableheader_open();
                $renderer->cdata($header);
                $renderer->tableheader_close();
            }
            $renderer->tablerow_close();
            $renderer->tablethead_close();

            $renderer->tabletbody_open();
            foreach ($result as $row) {
                $renderer->tablerow_open();
                $tds = array_values($row);
                foreach ($tds as $i => $td) {
                    if ($td === null) $td = '␀';
                    if (isset($parsers[$i])) {
                        $parser_class = $parsers[$i]['class'];
                        $parser_config = $parsers[$i]['config'];
                        if (!isset($parser_types[$parser_class])) {
                            msg('Unknown parser: ' . $parser_class, -1);
                            $renderer->tablecell_open();
                            $renderer->cdata($td);
                            $renderer->tablecell_close();
                        } else {
                            /** @var \dokuwiki\plugin\struct\types\AbstractBaseType $parser */
                            $parser = new $parser_types[$parser_class]($parser_config);
                            $renderer->tablecell_open();
                            $parser->renderValue($td, $renderer, $mode);
                            $renderer->tablecell_close();
                        }
                    } else {
                        $renderer->tablecell_open();
                        $renderer->cdata($td);
                        $renderer->tablecell_close();
                    }
                }
                $renderer->tablerow_close();
            }
            $renderer->tabletbody_close();
            $renderer->table_close();
            return true;
        }
        return false;
    }
}

