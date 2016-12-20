<?php

namespace MapasCulturais;

use Doctrine\ORM\Query;

class ApiQuery {

    use Traits\MagicGetter;

    /**
     * Global counter used to name DQL alias
     * @var int
     */
    protected $__counter = 0;
    
    /**
     * The Entity Controller
     * @var MapasCulturais\Controllers\EntityController
     */    
    protected $controller;
    
    /**
     * The Entity Class Name
     * 
     * @example "MapasCulturais\Entities\Agent"
     * @var string 
     */
    protected $entityClassName;
    
    /**
     * The Entity Metadata Class Name
     * 
     * @example "MapasCulturais\Entities\AgentMeta"
     * @var string
     */
    protected $metadataClassName;
    
    
    /**
     * List of the entity properties
     * 
     * @var array
     */
    protected $entityProperties = [];
    
    
    /**
     * List of entity ralations
     * 
     * @var array
     */
    protected $entityRelations = [];
    
    /**
     * List of registered metadata to the requested entity for this context (subsite?)
     * @var array 
     */
    protected $registeredMetadata = [];
    
    /**
     * List of the registered taxonomies for this context
     * 
     * @var array 
     */
    protected $registeredTaxonomies = [];
    
    /**
     * the parameter of api query
     * 
     * @example ['@select' => 'id,name', '@order' => 'name ASC', 'id' => 'GT(10)', 'name' => 'ILIKE(fulano%)']
     * @var array 
     */
    protected $apiParams = [];
    
    /**
     * The SELECT part of the DQL that will be executed
     * 
     * @example "e.id, e.name"
     * 
     * @var string 
     */
    public $select = "";
    
    /**
     * The JOINs fo the DQL that will be executed
     * @var string 
     */
    public $joins = "";
    
    /**
     * The WHERE part of the DQL that will be executed
     * @example "e.id > 10"
     * @var string 
     */
    public $where = "";
    
    /**
     * List of expressions used to compose the where part of the DQL that will be executed
     * @var array
     */
    protected $_whereDqls = [];
    
    /**
     * Mapping of the api query params to dql params
     * @var type 
     */
    protected $_keys = [];
    
    /**
     * List of parameters that will be used to run the DQL
     * @var array 
     */
    protected $_dqlParams = [];
    
    /**
     * Fields that are being selected
     * 
     * @var type 
     */
    protected $_selecting = ['id'];
    
    /**
     * Slice of the fields that are being selected that are properties of the entity
     * 
     * @var type 
     */
    protected $_selectingProperties = [];
    
    /**
     * Slice of the fields that are being selected that are metadata of the entity
     * 
     * @var type 
     */
    protected $_selectingMetadata = [];
    
    /**
     * Files that are being selected
     * 
     * @var type 
     */
    protected $_selectingFiles = [];
    
    protected $_subqueriesSelect = [];
    
    protected $_order = 'id ASC';
    protected $_offset;
    protected $_page;
    protected $_limit;
    protected $_keyword;
    protected $_seals = [];
    protected $_permissions;
    protected $_op = ' AND ';
    
    protected $_templateJoinMetadata = "\n\t\tLEFT JOIN e.__metadata {ALIAS} WITH {ALIAS}.key = '{KEY}'";
    protected $_templateJoinTerm = "\n\t\tLEFT JOIN e.__termRelations {ALIAS_TR} LEFT JOIN {ALIAS_TR}.term {ALIAS_T} WITH {ALIAS_T}.taxonomy = {TAXO}";

    public function __construct(Controllers\EntityController $controller, $api_params) {
        $this->apiParams = $api_params;
        $this->controller = $controller;
        $this->entityClassName = $controller->entityClassName;

        $this->initialize();

        $this->parseQueryParams();
    }

    protected function initialize() {
        $app = App::i();
        $em = $app->em;
        $class = $this->entityClassName;

        if ($class::usesMetadata()) {
            $this->metadataClassName = $class::getMetadataClassName();

            foreach ($app->getRegisteredMetadata($class) as $meta) {
                $this->registeredMetadata[] = $meta->key;
            }
        }

        if ($class::usesTaxonomies()) {
            foreach ($app->getRegisteredTaxonomies($class) as $obj) {
                $this->registeredTaxonomies['term:' . $obj->slug] = $obj->id;
            }
        }
        
        $this->entityProperties = array_keys($em->getClassMetadata($class)->fieldMappings);
        $this->entityRelations = $em->getClassMetadata($class)->associationMappings;
    }
    
    public function getFindOneResult(){
        $em = App::i()->em;
        
        $dql = $this->getFindDQL();
        
        $q = $em->createQuery($dql);
        
        $q->setMaxResults(1);
        
        $q->setParameters($this->_dqlParams);

        $result = $q->getOneOrNullResult(Query::HYDRATE_ARRAY);
        
        return $result;
    }
    
    public function getFindResult(){
        $em = App::i()->em;
        
        $dql = $this->getFindDQL();
        
        $q = $em->createQuery($dql);
        
        if($offset = $this->getOffset()){
            $q->setFirstResult($offset);
        }
        
        if($limit = $this->getLimit()){
            $q->setMaxResults($limit);
        }
        
        $q->setParameters($this->_dqlParams);
        
        $result = $q->getResult(Query::HYDRATE_ARRAY);
        
        return $result;
    }
    
    public function getCountResult(){
        $em = App::i()->em;
        
        $dql = $this->getCountDQL();
        
        $q = $em->createQuery($dql);
        
        $q->setParameters($this->_dqlParams);
        
        $result = $q->getSingleScalarResult();
        
        return $result;
    }

    public function getFindDQL() {
        $select = $this->generateSelect();
        $where = $this->generateWhere();
        $joins = $this->generateJoins();

        $dql = "SELECT\n\t{$select}\nFROM {$this->entityClassName} e {$joins}";
        if ($where) {
            $dql .= "\nWHERE\n\t{$where}";
        }

        return $dql;
    }

    public function getCountDQL() {
        $where = $this->generateWhere();
        $joins = $this->generateJoins();

        $dql = "SELECT\n\tCOUNT(e.id)\nFROM {$this->entityClassName} e {$joins}";
        if ($where) {
            $dql .= "\nWHERE\n\t{$where}";
        }

        return $dql;
    }

    public function getSubDQL($prop = 'id') {
        $where = $this->generateWhere();
        $joins = $this->generateJoins();

        // @TODO: se estiver paginando, rodar a consulta pegando somente os ids e retornar uma lista de ids 
        $alias = 'e_' . uniqid();
        $dql = "SELECT\n\t{$alias}.{$prop}\nFROM {$this->entityClassName} {$alias} {$joins}";
        if ($where) {
            $dql .= "\nWHERE\n\t{$where}";
        }

        return preg_replace('#([^a-z0-9_])e\.#i', "{$alias}.", $dql);
    }
    
    function getLimit(){
        return $this->_limit;
    }
    
    function getOffset(){
        if($this->_offset){
            return $this->_offset;
        } else if($this->_page && $this->page > 1 && $this->_limit){
            return $this->_limit * ($this->_page - 1);
        } else {
            return 0;
        }
    }

    protected function generateWhere() {
        $where = $this->where;
        $where_dqls = $this->_whereDqls;

        $where .= implode(" $this->_op \n\t", $where_dqls);
        
        return $where;
    }

    protected function generateJoins() {
        $joins = $this->joins;

        return $joins;
    }

    protected function generateSelect() {
        $select = $this->select;
        
        if (in_array('publicLocation', $this->entityProperties) && !in_array('publicLocation', $this->_selectingProperties)) {
            $this->_selectingProperties[] = 'publicLocation';
        }
        
        $select .= implode(', ' , array_map(function ($e) { return "e.{$e}"; }, $this->_selectingProperties));

        return $select;
    }

    public function addMultipleParams(array $values) {
        $result = [];
        foreach ($values as $value) {
            $result[] = $this->addSingleParams($values);
        }

        return $result;
    }

    public function addSingleParam($value) {
        $app = App::i();
        if (trim($value) === '@me') {
            $value = $app->user->is('guest') ? null : $app->user;
        } elseif (strpos($value, '@me.') === 0) {
            $v = str_replace('@me.', '', $value);
            $value = $app->user->$v;
        } elseif (trim($value) === '@profile') {
            $value = $app->user->profile ? $app->user->profile : null;
        } elseif (preg_match('#@(\w+)[ ]*:[ ]*(\d+)#i', trim($value), $matches)) {
            $_repo = $app->repo($matches[1]);
            $_id = $matches[2];

            $value = ($_repo && $_id) ? $_repo->find($_id) : null;
        } elseif (strlen($value) && $value[0] == '@') {
            $value = null;
        }

        $uid = uniqid('v');
        $this->_dqlParams[$uid] = $value;

        $result = ':' . $uid;

        return $result;
    }

    protected function parseParam($key, $expression) {
        if (is_string($expression) && !preg_match('#^[ ]*(!)?([a-z]+)[ ]*\((.*)\)$#i', $expression, $match)) {
            throw new Exceptions\Api\InvalidExpression($expression);
        } else {
            $dql = '';

            $not = $match[1];
            $operator = strtoupper($match[2]);
            $value = $match[3];

            if ($operator == 'OR' || $operator == 'AND') {
                $expressions = $this->parseExpression($value);

                foreach ($expressions as $expression) {
                    $sub_dql = $this->parseParam($key, $expression);
                    $dql .= $dql ? " $operator $sub_dql" : "($sub_dql";
                }
                if ($dql) {
                    $dql .= ')';
                }
            } elseif ($operator == "IN") {
                $values = $this->splitParam($value);

                $values = $this->addMultipleParams($values);

                if (count($values) < 1) {
                    throw new Exceptions\Api\InvalidArgument('expression IN expects at last one value');
                }

                $dql = $not ? "$key NOT IN (" : "$key IN (";
                $dql .= implode(', ', $values) . ')';
            } elseif ($operator == "BET") {
                $values = $this->splitParam($value);

                if (count($values) !== 2) {
                    throw new Exceptions\Api\InvalidArgument('expression BET expects 2 arguments');
                } elseif ($values[0][0] === '@' || $values[1][0] === '@') {
                    throw new Exceptions\Api\InvalidArgument('expression BET expects 2 string or integer arguments');
                }

                $values = $this->addMultipleParams($values);

                $dql = $not ?
                        "$key NOT BETWEEN {$values[0]} AND {$values[1]}" :
                        "$key BETWEEN {$values[0]} AND {$values[1]}";
            } elseif ($operator == "LIKE") {
                $value = str_replace('*', '%', $value);
                $value = $this->addSingleParam($value);
                $dql = $not ?
                        "unaccent($key) NOT LIKE unaccent($value)" :
                        "unaccent($key) LIKE unaccent($value)";
            } elseif ($operator == "ILIKE") {
                $value = str_replace('*', '%', $value);
                $value = $this->addSingleParam($value);
                $dql = $not ?
                        "unaccent(lower($key)) NOT LIKE unaccent(lower($value))" :
                        "unaccent(lower($key)) LIKE unaccent(lower($value))";
            } elseif ($operator == "EQ") {
                $value = $this->addSingleParam($value);
                $dql = $not ?
                        "$key <> $value" :
                        "$key = $value";
            } elseif ($operator == "GT") {
                $value = $this->addSingleParam($value);
                $dql = $not ?
                        "$key <= $value" :
                        "$key > $value";
            } elseif ($operator == "GTE") {
                $value = $this->addSingleParam($value);
                $dql = $not ?
                        "$key < $value" :
                        "$key >= $value";
            } elseif ($operator == "LT") {
                $value = $this->addSingleParam($value);
                $dql = $not ?
                        "$key >= $value" :
                        "$key < $value";
            } elseif ($operator == "LTE") {
                $value = $this->addSingleParam($value);
                $dql = $not ?
                        "$key > $value" :
                        "$key <= $value";
            } elseif ($operator == 'NULL') {
                $dql = $not ?
                        "($key IS NOT NULL)" :
                        "($key IS NULL)";
            } elseif ($operator == 'GEONEAR') {
                $values = $this->splitParam($value);

                if (count($values) !== 3) {
                    throw new Exceptions\Api\InvalidArgument('expression GEONEAR expects 3 arguments: longitude, latitude and radius in meters');
                }

                list($longitude, $latitude, $radius) = $this->addSingleParam($values);


                $dql = $not ?
                        "ST_DWithin($key, ST_MakePoint($longitude,$latitude), $radius) <> TRUE" :
                        "ST_DWithin($key, ST_MakePoint($longitude,$latitude), $radius) = TRUE";
            }

            /*
             * location=GEO_NEAR([long,lat]) //
             */
            return $dql;
        }
    }

    private function splitParam($val) {
        $result = explode("\n", str_replace('\\,', ',', preg_replace('#(^[ ]*|([^\\\]))\,#', "$1\n", $val)));

        if (count($result) === 1 && !$result[0]) {
            return [];
        } else {
            $_result = [];
            foreach ($result as $r)
                if ($r)
                    $_result[] = $r;
            return $_result;
        }
    }

    protected function parseExpression($val) {

        $open = false;
        $nopen = 0;
        $counter = 0;

        $results = [];
        $last_char = '';

        foreach (str_split($val) as $index => $char) {
            $next_char = strlen($val) > $index + 1 ? $val[$index + 1] : '';

            if (!key_exists($counter, $results))
                $results[$counter] = '';

            if ($char !== '\\' || $next_char === '(' || $next_char === ')')
                if ($open || $char !== ',' || $last_char === '\\')
                    $results[$counter] .= $char;

            if ($char === '(' && $last_char !== '\\') {
                $open = true;
                $nopen++;
            }

            if ($char === ')' && $last_char !== '\\' && $open) {
                $nopen--;
                if ($nopen === 0) {
                    $open = false;
                    $counter++;
                }
            }

            $last_char = $char;
        }

        return $results;
    }

    protected function parseQueryParams() {
        foreach ($this->apiParams as $key => $value) {
            $value = trim($value);
            if (strtolower($key) == '@select') {
                $this->_parseSelect($value);
            } elseif (strtolower($key) == '@order') {
                $this->_order = $value;
            } elseif (strtolower($key) == '@offset') {
                $this->_offset = $value;
            } elseif (strtolower($key) == '@page') {
                $this->_page = $value;
            } elseif (strtolower($key) == '@limit') {
                $this->_limit = $value;
            } elseif (strtolower($key) == '@keyword') {
                $this->_keyword = $value;
            } elseif (strtolower($key) == '@permissions') {
                $this->_permissions = explode(',', $value);
            } elseif (strtolower($key) == '@seals') {
                $this->_seals = explode(',', $value);
            } elseif (strtolower($key) == '@verified') {
                $this->_seals = $app->config['app.verifiedSealsIds'];
            } elseif (strtolower($key) == '@or') {
                $this->_op = ' OR ';
            } elseif (strtolower($key) == '@files') {
                $this->_parseFiles($value);
            } elseif ($key === 'user' && $class::usesOwnerAgent()) {
                $this->_addFilterByOwnerUser();
            } elseif (key_exists($key, $this->entityRelations) && $this->entityRelations[$key]['isOwningSide']) {
                $this->_addFilterByEntityProperty($key, $value);
            } elseif (in_array($key, $this->entityProperties)) {
                $this->_addFilterByEntityProperty($key, $value);
            } elseif ($class::usesTypes() && $key === 'type') {
                $this->_addFilterByEntityProperty($key, $value, '_type');
            } elseif ($class::usesTaxonomies() && isset($this->registeredTaxonomies[$key])) {
                $this->_addFilterByTermTaxonomy($key, $value);
            } elseif ($class::usesMetadata() && in_array($key, $this->registeredMetadata)) {
                $this->_addFilterByMetadata($key, $value);
            } elseif ($key[0] != '_' && $key != 'callback') {
                $this->apiErrorResponse("property $key does not exists");
            } 
        }
    }

    protected function _addFilterByOwnerUser() {
        $this->_keys['user'] = '__user_agent__.user';

        $this->joins .= '\n\t\tLEFT JOIN e.owner __user_agent__';

        $this->_whereDqls[] = $this->parseParam($this->_keys[$key], $value);
    }

    protected function _addFilterByEntityProperty($key, $value, $propery_name = null) {
        $this->_keys[$key] = $propery_name ? "e.{$propery_name}" : "e.{$key}";

        $this->_whereDqls[] = $this->parseParam($this->_keys[$key], $value);
    }

    protected function _addFilterByMetadata($key, $value) {
        $count = $this->__counter++;
        $meta_alias = "m{$count}";

        $this->_keys[$key] = "$meta_alias.value";

        $dql_joins .= str_replace(['{ALIAS}', '{KEY}'], [$meta_alias, $key], $dql_join_template);

        $this->_whereDqls[] = $this->parseParam($this->_keys[$key], $value);
    }

    protected function _addFilterByTermTaxonomy($key, $value) {
        $count = $this->__counter++;
        $tr_alias = "tr{$count}";
        $t_alias = "t{$count}";
        $taxonomy_id = $this->registeredTaxonomies[$key];

        $this->_keys[$key] = "$t_alias.term";

        $this->joins .= str_replace(['{ALIAS_TR}', '{ALIAS_T}', '{TAXO}'], [$tr_alias, $t_alias, $taxonomy_id], $this->_templateJoinTerm);

        $this->_whereDqls[] = $this->parseParam($this->_keys[$key], $value);
    }

    protected function _parseSelect($select) {
        $select = str_replace(' ', '', $select);

        // create subquery to format entity.* or entity.{id,name}
        while (preg_match('#([^,\.]+)\.(\{[^\{\}]+\})#', $select, $matches)) {
            $_subquery_entity_class = $matches[1];
            $_subquery_select = substr($matches[2], 1, -1);

            $replacement = $this->_preCreateSelectSubquery($_subquery_entity_class, $_subquery_select);

            $select = str_replace($matches[0], $replacement, $select);
        }

        // create subquery to format entity.id or entity.name        
        while (preg_match('#([^,\.]+)\.([^,\.]+)#', $select, $matches)) {
            $_subquery_entity_class = $matches[1];
            $_subquery_select = $matches[2];

            $replacement = $this->_preCreateSelectSubquery($_subquery_entity_class, $_subquery_select);

            $select = str_replace($matches[0], $replacement, $select);
        }

        $this->_selecting = explode(',', $select);

        foreach ($this->_selecting as $i => $prop) {
            if (in_array($prop, $this->entityProperties)) {
                $this->_selectingProperties[] = $prop;
            } elseif (in_array($prop, $this->registeredMetadata)) {
                $this->_selectingMetadata[] = $prop;
            } elseif (in_array($prop, $this->_entityRelations)) {
                $this->_selecting[$i] = $this->_preCreateSelectSubquery($prop, 'id');
            }
        }
    }

    protected function _preCreateSelectSubquery($entity, $select) {
        $uid = uniqid('#sq:');

        $this->_subqueriesSelect[$uid] = [$entity, $select];

        return $uid;
    }

    protected function _parseFiles($value) {
        if (preg_match('#^\(([\w\., ]+)\)[ ]*(:[ ]*([\w, ]+))?#i', $val, $imatch)) {
            return;
            // example:
            // @files=(avatar.smallAvatar,header.header):name,url

            $cfg = [
                'files' => explode(',', $imatch[1]),
                'props' => key_exists(3, $imatch) ? explode(',', $imatch[3]) : ['url']
            ];

            $_join_in = [];

            foreach ($cfg['files'] as $_f) {
                if (strpos($_f, '.') > 0) {
                    list($_f_group, $_f_transformation) = explode('.', $_f);
                    $_join_in[] = $_f_group;
                    $_join_in[] = 'img:' . $_f_transformation;
                } else {
                    $_join_in[] = $_f;
                }
            }

            $_join_in = array_unique($_join_in);

            $dql_select[] = ", files, fparent";
            $dql_select_joins[] = "
                        LEFT JOIN e.__files files WITH files.group IN ('" . implode("','", $_join_in) . "')
                        LEFT JOIN files.parent fparent";

            $extract_data_cb = function($file, $ipath, $props) {
                $result = [];
                if ($ipath) {
                    $path = explode('.', $ipath);
                    foreach ($path as $transformation) {
                        $file = $file->transform($transformation);
                    }
                }
                if (is_object($file)) {
                    foreach ($props as $prop) {
                        $result[$prop] = $file->$prop;
                    }
                }

                return $result;
            };

            $append_files_cb = function(&$result, $entity) use($cfg, $extract_data_cb) {

                $files = $entity->files;

                foreach ($cfg['files'] as $im) {
                    $im = trim($im);

                    list($igroup, $ipath) = explode('.', $im, 2) + [null, null];

                    if (!key_exists($igroup, $files))
                        continue;

                    if (is_array($files[$igroup])) {
                        $result["@files:$im"] = [];
                        foreach ($files[$igroup] as $file)
                            $result["@files:$im"][] = $extract_data_cb($file, $ipath, $cfg['props']);
                    } else {
                        $result["@files:$im"] = $extract_data_cb($files[$igroup], $ipath, $cfg['props']);
                    }
                }
            };
        }
    }
}
