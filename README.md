# CakePHP Solr DataSource #
CakePHP DataSource for Solr implementing all CRUD methods.

## Dependencies ##
- PHP 5.4
- CakePHP 2.5.7
- Solr 5.0.0
- Solarium 3.3.0

## Instalation ##
* Assuming you have at least PHP 5.4 (for the array bracket annotation)
* Assuming you already have at least CakePHP 2.5.7 running (2.5.7 is what i've tested with, might also work for previous versions)
* Assuming you have already installed Solr somewhere (tested with 5.0.0)
* Run composer (as per **composer.json** included) to install [Solarium](www.solarium-project.org)

## Included Examples ##
I've included in **Config/Solr** my own simplified schema for my article core which includes id, title, body, created and modified. I am symlinking that into my Solr instalation. After that look into `Config/database.php` to set your Solr configuration for SolrSource.

I've also included the Solr model `Model/Solr.php` where the schema must match the Solr one with a simple difference. You will notice a new key called source in there which will transform into type automatically. For example if my type is integer and my source is date the data will be transformed from `2015-03-02 17:04:15` into `1425312255` (Solr cannot handle date formats, so I've opted for timestamps)

The function that does the source to type transformation is called `_metamorphosis` from `Model/Datasource/SolrSource.php` and is not complete, it can only handle string -> int, int -> string, date -> int. There might be more combinations there that I did not take into account.

## Actual Usage ##
First, open up `Model/Datasource/SolrSource.php` and look at line 4 to fix your path for Solarium

#### Find All with Pagination ####
Let's say we want to find the titles of all articles that have the keyword `home` in title and body and we want that paginated using CakePHP's Paginator Component:
```php
// set query
$this->paginate = [
    'limit' => 20,
    'fields' => ['score', 'id', 'title'],
    'conditions' => [
        'query' => 'home',
        'filters' => [
            'created' => 'created:[0 TO ' . time() . ']'
        ],
        'params' => [
            'defType' => 'edismax',
            'qf' => 'title_ls^8 body_ls'
        ]
    ],
    'order' => [
        'created' => 'desc',
        'score' => 'desc'
    ]
];

// paginate articles
$articles = $this->paginate('Solr', [], ['score']); // to make order work with score, either add it to whitelist (like i did here) or make a virtual field out of it in your Solr Model
```
#### Find First ####
How about a find first query. Get the first article based on `$articleId`:
```php
$moreLikeThis = $this->Solr->find('first', [
    'fields' => ['id', 'title'],
    'conditions' => [
        'query' => "id:{$articleId}",
        'filters' => [
            'created' => 'created:[0 TO ' . time() . ']'
        ]
    ]
]);
```
#### Save Solr document ####
You can save a Solr document. This is the same as update, any save will overwrite existing Solr documents based on id.

**Note:** You have to include the created and modified as date values because in schema we transform date into int, and because if you don't include these manually, cake will try to add them and throw some warnings complaining about the $columns translation missing from source.
```php
$this->Solr->save(['Solr' => [
    'id' => 5000,
    'title' => 'Test document',
    'body' => 'A test document for Solr',
    'created' => '2015-03-02 17:04:15',
    'modified' => '2015-03-02 17:04:15'
]], false);
```
#### Save multiple Solr documents ####
You can also save multiple Solr documents in a batch like this:
```php
$model = $this->Solr;
$model->getDataSource()->batch($model, [
    ['Solr' => [
        'id' => 5000,
        'title' => 'Test document',
        'body' => 'A test document 1 for Solr',
        'created' => '2015-03-02 17:04:15',
        'modified' => '2015-03-02 17:04:15'
    ]], ['Solr' => [
        'id' => 5001,
        'title' => 'Another document',
        'body' => 'The second document body',
        'created' => '2015-03-03 17:04:15',
        'modified' => '2015-03-03 17:04:15'
    ]], ['Solr' => [
        'id' => 5002,
        'title' => 'A third document',
        'body' => 'The third document body',
        'created' => '2015-03-04 17:04:15',
        'modified' => '2015-03-04 17:04:15'
    ]],
]);
```
#### Delete Solr Document ####
And last but not least, you can delete documents by id.
```php
$this->Solr->delete(5000);
```
