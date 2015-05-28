<?php

/**
 * Solr Model
 *
 * @author Borg
 * @version 0.1
 */
class Solr extends AppModel {
    // use our custom made SolrSource
    public $useTable = null;
    public $useDbConfig = 'solr';

    // define schema with type and source
    public $_schema = [
        'id' => ['type' => 'integer', 'source' => 'integer', 'null' => false, 'length' => 11, 'key' => 'primary'],
        'title' => ['type' => 'text', 'source' => 'text', 'null' => true, 'default' => null],
        'body' => ['type' => 'text', 'source' => 'text', 'null' => true, 'default' => null],
        'created' => ['type' => 'integer', 'source' => 'date', 'null' => true, 'default' => null],
        'modified' => ['type' => 'integer', 'source' => 'date', 'null' => true, 'default' => null]
    ];
}