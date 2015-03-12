<?php

// load Solarium from composer
include(ROOT . DS . 'app/vendor/autoload.php');

/**
 * SolrSource DataSource
 * - Implements CRUD for Solarium
 * - Has a metamorphosis function that can read the Model schema and transform it based on type and source
 * - Contains a batch function to add multiple documents into Solarium
 *
 * @author Borg
 * @version 0.2
 */
class SolrSource extends DataSource
{
    // datasource description
    public $description = 'Apache Solr';

    // save config
    protected $host;
    protected $port;
    protected $path;

    /**
     * Create our Solarium instance and handle any config tweaks.
     * @param array $config
     */
    public function __construct($config) {
        parent::__construct($config);

        // set config params
        $this->host = $config['host'];
        $this->port = $config['port'];
        $this->path = $config['path'];

        // set Solarium instance
        $this->solr = new Solarium\Client(array(
            'endpoint' => array(
                'localhost' => array(
                    'host' => $this->host,
                    'port' => $this->port,
                    'path' => $this->path,
                    'timeout' => 15
                )
            )
        ));
    }

    /**
     * Describe the schema for Model::save()
     * @see DataSource::describe()
     */
    public function describe($model) {
        return $this->_schema;
    }

    /**
     * Default caching method, we don't use it for Solarium
     * @see DataSource::listSources()
     */
    public function listSources($data = null) {
        return null;
    }

    /**
     * Set the count field type for the read function
     * @return string
     */
    public function calculate(Model $model, $type, $params = []) {
        return 'COUNT';
    }

    /**
     * Implement the R in CRUD
     * @see DataSource::read()
     */
    public function read(Model $model, $queryData = [], $recursive = null) {
        // more like this?
        if(isset($queryData['morelikethis'])) {
            $query = $this->solr->createMoreLikeThis(['interestingTerms' => 'list']);
            if(strlen($queryData['morelikethis']) > 0)
                $query->setMltFields($queryData['morelikethis']);

        // normal query
        } else $query = $this->solr->createSelect();

        // set fields
        if(isset($queryData['fields']))
            $query->setFields(is_array($queryData['fields']) ? implode(',', $queryData['fields']) : $queryData['fields']);

        // set sorting
        if(isset($queryData['order']) && !is_null($queryData['order'][0]) && $queryData['order'][0] != false)
            $query->setSorts(is_array($queryData['order'][0]) ? $queryData['order'][0] : [explode(' ', $queryData['order'][0])[0] => explode(' ', $queryData['order'][0])[1]]);

        // set offset
        if(isset($queryData['offset']))
            $query->setStart($queryData['offset']);

        // set limit
        if($queryData['fields'] === 'COUNT') $queryData['limit'] = 1;
        if(isset($queryData['limit']))
            $query->setRows($queryData['limit']);

        // got conditions?
        if(isset($queryData['conditions'])) {
            // set main query
            if(isset($queryData['conditions']['query']) && strlen($queryData['conditions']['query']) > 0)
                $query->setQuery($queryData['conditions']['query']);

            // add filters
            if(isset($queryData['conditions']['filters']) && count($queryData['conditions']['filters']) > 0)
                foreach($queryData['conditions']['filters'] as $key => $value)
                    $query->createFilterQuery($key)->setQuery($value);
        }

        // create request
        $request = $this->solr->createRequest($query);

        // got conditions? (for extra params)
        if(isset($queryData['conditions']))
            if(isset($queryData['conditions']['params']) && count($queryData['conditions']['params']) > 0)
                foreach($queryData['conditions']['params'] as $key => $value)
                    $request->addParam($key, $value);

        // perform request
        try {
            $response = $this->solr->executeRequest($request);
            $result = $this->solr->createResult($query, $response);
        } catch (Exception $e) {
            CakeLog::write('error', $e->getMessage()); // Logs the solr errors message in app/tmp/logs/error.log file
            CakeLog::write('error', $query); // Logs the solr query in app/tmp/logs/error.log file
        }

        // set num found
        $model->numFound = $result->getNumFound();

        // format output
        $output = [];
        foreach($result->getData()['response']['docs'] as $doc)
            $output[] = [$model->alias => $doc];

        // return results
        return $queryData['fields'] === 'COUNT' ? [[['count' => $model->numFound]]] : $output;
    }

    /**
     * Transform data based on schema
     * Looks at schema source and type to convert data appropriately
     *
     * @param array $data
     * @param array $schema
     * @return array
     */
    private function _metamorphosis($data = [], $schema = []) {
        // figure out which ones are different
        $changes = [];
        foreach($schema as $key => $value)
            if($value['type'] != $value['source'])
                $changes[$key] = $value;

        // apply changes TODO: not all possible options are explored
        foreach($changes as $key => $value) {
            if($value['type'] == 'string' && $value['source'] == 'integer')
                $data[$key] = (string)$data[$key];

            if($value['type'] == 'integer' && $value['source'] == 'string')
                $data[$key] = (int)$data[$key];

            if($value['type'] == 'integer' && $value['source'] == 'date')
                $data[$key] = strlen($data[$key]) > 0 && $data[$key] !== '0000-00-00 00:00:00' ? strtotime($data[$key]) : 0;
        }

        // return metamorphosed data
        return $data;
    }

    /**
     * Similar to Create, this will insert multiple documents at once
     * Useful for batch jobs (used in Model::batch)
     *
     * @param Model $model
     * @param array $data
     * @return boolean
     */
    public function batch(Model $model, $data = []) {
        // loop
        $docs = [];
        foreach($data as $value) {
            // run metamorphosis and create doc
            $value = $this->_metamorphosis($value[$model->alias], $model->_schema);
            $doc = new Solarium\QueryType\Update\Query\Document\Document();

            // set Solarium document fields
            foreach($value as $key => $value)
                $doc->{$key} = $value;

            // set document
            $docs[] = $doc;
        }

        // insert / update
        $update = $this->solr->createUpdate();
        $update->addDocuments($docs, true);
        $update->addCommit();

        // perform request
        try {
            $result = $this->solr->update($update);
        } catch (Exception $e) {
            CakeLog::write('error', $e->getMessage()); // Logs the solr errors message in app/tmp/logs/error.log file
            return false;
        }

        // solr returns 0 for success
        return $result->getStatus() == 0 ? true : false;
    }

    /**
     * Implements the C in CRUD
     * @see DataSource::create()
     */
    public function create(Model $model, $fields = null, $values = null) {
        // assign values to keys
        $data = array_combine($fields, $values);

        // transform source into type as per schema
        $data = $this->_metamorphosis($data, $model->_schema);

        // create the Solarium document
        $doc = new Solarium\QueryType\Update\Query\Document\Document();

        // set Solarium document fields
        foreach($data as $key => $value)
            $doc->{$key} = $value;

        // insert / update
        $update = $this->solr->createUpdate();
        $update->addDocument($doc, true);
        $update->addCommit();

        // perform request
        try {
            $result = $this->solr->update($update);
        } catch (Exception $e) {
            CakeLog::write('error', $e->getMessage()); // Logs the solr errors message in app/tmp/logs/error.log file
            return false;
        }

        // solr returns 0 for success
        return $result->getStatus() == 0 ? true : false;
    }

    /**
     * Implements the U in CRUD
     * @see DataSource::update()
     */
    public function update(Model $model, $fields = null, $values = null, $conditions = null) {
        return $this->create($model, $fields, $values);
    }

    /**
     * Implements the D in CRUD
     * @see DataSource::delete()
     */
    public function delete(Model $model, $id = null) {
        // insert / update
        $update = $this->solr->createUpdate();
        $update->addDeleteById($id[$model->alias . '.' . $model->primaryKey]);
        $update->addCommit();

        // perform request
        try {
            $result = $this->solr->update($update);
        } catch (Exception $e) {
            CakeLog::write('error', $e->getMessage()); // Logs the solr errors message in app/tmp/logs/error.log file
            return false;
        }

        // solr returns 0 for success
        return $result->getStatus() == 0 ? true : false;
    }
}