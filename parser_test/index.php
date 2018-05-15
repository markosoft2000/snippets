<?php


// Resource section
interface ResourceInterface
{
    public function getFormat();
    public function getPath();
}

abstract class Resource implements ResourceInterface
{
    protected $format;
    protected $path;

    public function __construct($format, $path)
    {
        $this->format = $format;
        $this->path = $path;
    }

    public function getFormat()
    {
        return $this->format;
    }

    public function getPath()
    {
        return $this->path;
    }
}

class MyJsonResource extends Resource
{
    CONST FORMAT_JSON = 'json';

    public function __construct($path)
    {
        parent::__construct(self::FORMAT_JSON, $path);
    }
}

// Low level loader section
interface ResourceLoaderInterface
{
    public function load(ResourceInterface $resource);
}

abstract class ResourceLoader implements ResourceLoaderInterface
{
}


class StringerResourceLoader extends ResourceLoader
{
    public function load(ResourceInterface $resource)
    {
        return file_get_contents($resource->getPath());
    }
}



// Parser section
interface ParserInterface
{
    public function getFormat();

    /**
     * @param string $content
     * @return mixed
     */
    public function parse($content);
}

abstract class Parser implements ParserInterface
{
    protected $format;

    public function __construct($format)
    {
        $this->format = $format;
    }

    public function getFormat()
    {
        return $this->format;
    }
}

class JsonParser extends Parser
{
    CONST FORMAT_JSON = 'json';

    public function parse($content)
    {
        $jsonData = json_decode($content);

        if (json_last_error()) {
            throw new Exception('json_decode error appeared');
        }

        return $jsonData;
    }
}

// DataStorage section
interface DataStorageInterface
{
    public function add($item);
    public function set($index, $item);
    public function get($index);
    public function getAll();
    public function delete($index);
    public function sort($fieldName, $orderToIncrease = true);
    public function resetSort();
}

abstract class DataStorage implements DataStorageInterface
{
    protected $data;

    public function __construct()
    {
        $this->data = [];
    }

    public function add($item)
    {
        $this->data[] = $item;
    }

    public function set($index, $item)
    {
        $this->data[$index] = $item;
    }

    public function get($index)
    {
        if (!array_key_exists($index, $this->data)) {
            throw new Exception('Key not found');
        }

        return $this->data[$index];
    }

    public function getAll()
    {
        foreach ($this->data as $key=>$item) {
            yield $key => $item;
        }
    }

    public function delete($index)
    {
        unset($this->data[$index]);
    }
}

class ItemDataStorage extends DataStorage
{
    public function sort($fieldName, $orderToIncrease = true)
    {
        return usort($this->data, function ($item1, $item2) use ($fieldName, $orderToIncrease) {
            if (!property_exists($item1, $fieldName) || !property_exists($item2, $fieldName)) {
                throw new Exception('Invalid field name for sorting.');
            }

            return $orderToIncrease ? $item1->{$fieldName} > $item2->{$fieldName} : $item1->{$fieldName} < $item2->{$fieldName};
        });
    }

    public function resetSort()
    {
        return usort($this->data, function ($item1, $item2){
            echo spl_object_id($item1) . ' & ' . spl_object_id($item2);
            return spl_object_id($item1) < spl_object_id($item2);
        });
    }
}

// Sorter section
interface SorterInterface
{
    public function sort();
}

// High level loader section
interface DataLoaderInterface
{
    /**
     * @return mixed
     */
    public function load();
}

abstract class DataLoader implements DataLoaderInterface
{
    protected $resource;
    protected $resourceLoader;
    protected $dataStorage;

    public function __construct(ResourceInterface $resource, ResourceLoaderInterface $resourceLoader, DataStorageInterface $dataStorage)
    {
        $this->resource = $resource;
        $this->resourceLoader = $resourceLoader;
        $this->dataStorage = $dataStorage;
    }
}

class ItemDataLoader extends DataLoader
{
    protected $parser;

    public function __construct(ResourceInterface $resource, ResourceLoaderInterface $resourceLoader, ParserInterface $parser, DataStorageInterface $dataStorage)
    {
        parent::__construct($resource, $resourceLoader, $dataStorage);
        $this->parser = $parser;
    }

    protected $format = 'json';

    public function getFormat()
    {
        return $this->format;
    }

    public function load()
    {
        $content = $this->resourceLoader->load($this->resource);
        $rawData = $this->parser->parse($content);

        foreach ($rawData as $item) {
            $someItem = SomeItemFactory::create();
            $someItem->id = (int) $item->ID;
            $someItem->color = (string) $item->Color;
            $someItem->cost = (string) $item->Cost;
            $someItem->date = DateTimeImmutable::createFromFormat('Y-m-d', $item->Date);
            $this->dataStorage->add($someItem);
        }
    }
}

// Items DTO section
class SomeItem
{
    public $id;
    public $color;
    public $cost;
    public $date;
}

interface ItemFactoryInterface
{
    public static function create();
}

class SomeItemFactory implements ItemFactoryInterface
{
    public static function create()
    {
        return new SomeItem();
    }
}

//view section


interface ViewInterface
{
    public function render($template, DataStorageInterface $dataStorage, $caption = '');
}


abstract class View implements ViewInterface
{
}


class ItemTableView extends View
{
    public function render($template, DataStorageInterface $dataStorage, $caption = '')
    {
        $tableHeaders = array_keys(get_class_vars('SomeItem'));
        include $template;
    }
}

// Service Locator
interface ServiceLocatorInterface
{
    public static function get($name);
    public static function set($name, $object);
    public static function delete($name);
}

class ServiceLocator implements ServiceLocatorInterface
{
    protected static $services = [];

    public static function set($name, $object)
    {
        self::$services[$name] = $object;
    }

    public static function get($name)
    {
        if (!array_key_exists($name, self::$services)) {
            throw new Exception('Service key name not found');
        }

        return self::$services[$name];
    }

    public static function delete($name)
    {
        unset(self::$services[$name]);
    }
}


// Controller section

class Controller
{
    public function __construct()
    {
    }

    public function __call($name, $arguments)
    {
        $name .= 'Action';
        $this->$name($arguments);
    }
}

class SiteController extends Controller
{
    public function indexAction()
    {
        $myJsonResource = new MyJsonResource('http://localhost/data.json');
        $resourceLoader = new StringerResourceLoader($myJsonResource);
        $jsonParser = ServiceLocator::get('jsonParser');
        $itemDataStorage = new ItemDataStorage();

        $jsonDataLoader = new ItemDataLoader($myJsonResource, $resourceLoader, $jsonParser, $itemDataStorage);
        $jsonDataLoader->load();

        $render = new ItemTableView();

        $render->render('table.tmpl', $itemDataStorage, 'original data');

        $itemDataStorage->sort('color');
        $render->render('table.tmpl', $itemDataStorage, 'data sorted by color');

        $itemDataStorage->sort('id');
        $render->render('table.tmpl', $itemDataStorage, 'data sorted by id');

        $itemDataStorage->sort('cost', false);
        $render->render('table.tmpl', $itemDataStorage, 'data sorted by cost <');

        $itemDataStorage->sort('date', false);
        $render->render('table.tmpl', $itemDataStorage, 'data sorted by date <');
    }
}

// Main ========================================================

$services = new ServiceLocator();
ServiceLocator::set('jsonParser', new JsonParser(JsonParser::FORMAT_JSON));
$controller = new SiteController();
$controller->index();