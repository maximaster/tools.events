<?php

namespace Maximaster\Tools\Events;

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class Listener
{
    private   $registered = false;
    protected $prefixes   = array();

    const SORT_PARAM = '@eventSort';
    const LINKED_PARAM = '@eventLink';
    const VERSION_PARAM = '@eventVersion';

    /**
     * Инициирует регистрацию всех событий
     */
    public function register()
    {
        $collection = array();
        foreach ($this->prefixes as $namespace => $directoryList) {
            foreach ($directoryList as $directory) {
                $directoryPath = $directory['dir'];
                $directoryVersion = $directory['version'];
                $collected = $this->collect($namespace, $directoryPath);
                foreach ($collected as &$collectionItem) {
                    if (!$collectionItem['version'] && $directoryVersion) {
                        $collectionItem['version'] = $directoryVersion;
                    }
                }
                $collection = array_merge($collection, $collected);
            }
        }
        foreach ($collection as $handler) {
            $sort = $handler[ 'sort' ] ? $handler[ 'sort' ] : 100;
            $version = $handler['version'] ? $handler['version'] : 1;
            $this->listen(
                $handler[ 'moduleName' ], $handler[ 'eventType' ], $handler[ 'callback' ], $sort, (int)$version
            );
        }

        $this->registered = true;
    }

    /**
     * Регистрирует событие с заданными параметрами
     * @param string         $moduleId
     * @param string         $eventType
     * @param array|callable $callback
     * @param int            $sort
     * @return int
     */
    private function listen($moduleId, $eventType, $callback, $sort = 100, $version = 1)
    {
        switch ($version) {
            case 2:
                $result = EventManager::getInstance()->addEventHandler($moduleId, $eventType, $callback, false, $sort);
                break;
            case 1:
            default:
                $result = \AddEventHandler($moduleId, $eventType, $callback, $sort);
                break;
        }
        return $result;
    }

    /**
     * На основании пространства имен собирает все обработчики в массив
     * @param string $namespace
     * @param string $handlersDirectory
     * @return array
     */
    private function collect($namespace, $handlersDirectory)
    {
        $ns = $namespace;
        $collection = array();
        if (!is_dir($handlersDirectory)) {
            return $collection;
        }

        $dirIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($handlersDirectory));
        $regexIterator = new \RegexIterator($dirIterator, '/^.+\.php$/i', \RecursiveRegexIterator::GET_MATCH);

        foreach ($regexIterator as $file) {
            $file = current($file);
            if (!$this->requireFile($file)) {
                continue;
            }

            if (DIRECTORY_SEPARATOR === '\\') {
                $handlersDirectory = str_replace('/', DIRECTORY_SEPARATOR, $handlersDirectory);
                $file = str_replace('/', DIRECTORY_SEPARATOR, $file);
            }

            $relativeClass = str_replace(array($handlersDirectory, '.php'), '', $file);
            $nestedClass = explode(DIRECTORY_SEPARATOR, $relativeClass);

            $moduleId = $vendor = $module = '';

            $nestingLevel = count($nestedClass);
            switch ($nestingLevel) {
                case 1:
                    $eventType = $nestedClass[ 0 ];
                    break;
                case 2:
                    $vendor = 'bitrix';
                    $module = $nestedClass[ 0 ];
                    $eventType = $nestedClass[ 1 ];
                    break;
                case 3:
                    $vendor = $nestedClass[ 0 ];
                    $module = $nestedClass[ 1 ];
                    $eventType = $nestedClass[ 2 ];
                    break;
                case 4:
                    $vendor = $nestedClass[ 0 ];
                    $module = $nestedClass[ 1 ];
                    $entityType = $nestedClass[ 2 ];
                    $eventType = $nestedClass[ 3 ];

                    $eventType = "\\$vendor\\$module\\$entityType::$eventType";
                    break;
                default:
                    throw new \LogicException("Некорректно описан класс {$file}");
            }

            if (strlen($vendor) > 0 && strlen($module) > 0) {
                $moduleId = $this->normalizeModuleId($vendor, $module);
            }

            if ($moduleId === null) {
                continue;
            }

            if (!$eventType) {
                continue;
            }

            $moduleId = strtolower($moduleId);

            $className = $ns . str_replace('/', '\\', $relativeClass);
            $class = new \ReflectionClass($className);

            foreach ($class->getMethods() as $method) {

                $className = $class->getName();

                if ( $method->class == $className && $method->isPublic()) {

                    $sortValue = $this->getHandlerSort($method);
                    $linkedEvents = $this->getLinkedHandlers($method);
                    $version = $this->getVersion($method);

                    $collectionItem = array(
                        'moduleName' => $moduleId,
                        'eventType' => $eventType,
                        'callback' => array($class->getName(), $method->name),
                        'sort' => $sortValue,
                    );

                    if ($version !== null) {
                        $collectionItem['version'] = $version;
                    }

                    $collection[] = $collectionItem;

                    if (!empty($linkedEvents)) {

                        foreach ($linkedEvents as $linkedEvent) {

                            $collectionItem['eventType'] = $linkedEvent;
                            $collection[] = $collectionItem;
                        }
                    }
                }
            }
        }

        return $collection;
    }

    /**
     * Парсит из документации список привязанных обработчиков и возвращает их в виде массива
     *
     * @param \ReflectionMethod $method
     * @return array
     */
    private function getLinkedHandlers(\ReflectionMethod $method)
    {
        $linkedEvents = $linkedMatches = array();
        //$regPartClassName = '[a-zA-Z_\x7f-\xff\\\\][a-zA-Z0-9_\x7f-\xff\\\\]*';
        //$regPartMethodName = '[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*';
        $regexp = '/' . self::LINKED_PARAM . '\s([a-zA-Z_\-\d]+)\s/';

        preg_match_all($regexp, $method->getDocComment(), $linkedMatches);

        if (!empty($linkedMatches[1])) {

            foreach ($linkedMatches[1] as $eventName) {
                $linkedEvents[] = trim($eventName);
            }
        }

        return $linkedEvents;
    }

    /**
     * Вытаскивает номер версии для обработчика события
     *
     * @param \ReflectionMethod $method
     *
     * @return null|int
     */
    private function getVersion(\ReflectionMethod $method)
    {
        $versionValue = null;
        $doc = $method->getDocComment();
        if (strlen($doc) > 0) {
            preg_match('/' . self::VERSION_PARAM . '\s(\d+)/', $doc, $versionMatches);
            if (isset($versionMatches[1])) {
                $versionValue = (int)$versionMatches[1];
            }
        }
        return $versionValue;
    }

    /**
     * Парсит из документации значение сортировки. Также обрабатывает устаревший вариант обработки сортировки
     *
     * @param \ReflectionMethod $method
     * @return int
     */
    private function getHandlerSort(\ReflectionMethod $method)
    {
        $sortValue = 100;
        $className = $method->class;

        $sortValueDeprecated = 0;
        if (method_exists($className, 'getSort'))
        {
            $sortValueDeprecated = $className::getSort($method->getName());
            if ($sortValueDeprecated > 0)
            {
                $sortValue = $sortValueDeprecated;
            }
        }

        if ($sortValueDeprecated > 0) {
            $sortValue = $sortValueDeprecated;
        }

        $doc = $method->getDocComment();
        if (strlen($doc) > 0) {

            preg_match('/' . self::SORT_PARAM . '\s(\d+)/', $doc, $sortMatches);
            if (isset($sortMatches[1]))
            {
                $sortValue = $sortMatches[1];
            }

        }

        return $sortValue;
    }

    /**
     * Если файл существует, загружеаем его.
     *
     * @param string $file файл для загрузки.
     * @return bool true если файл существует, false если нет.
     */
    protected function requireFile($file)
    {
        if (file_exists($file)) {
            /** @noinspection PhpIncludeInspection */
            require_once $file;
            return true;
        }
        return false;
    }

    /**
     * Добавляет базовую директорию к префиксу пространства имён.
     *
     * @param string $prefix Префикс пространства имён.
     * @param string $base_dir Базовая директория для файлов классов из пространства имён.
     * @param bool   $prepend Если true, добавить базовую директорию в начало стека. В этом случае она будет
     * проверяться первой.
     * @return void
     */
    public function addNamespace($prefix, $base_dir, $prepend = false, $version = 1)
    {
        if ($this->registered === true) {
            throw new \LogicException('Необходимо зарегистрировать все пространства имен до того, как вызван метод register()');
        }

        // нормализуем префикс пространства имён
        $prefix = trim($prefix, '\\') . '\\';

        // нормализуем базовую директорию так, чтобы всегда присутствовал разделитель в конце
        $base_dir = rtrim($base_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // инициализируем массив префиксов пространства имён
        if (isset( $this->prefixes[ $prefix ] ) === false) {
            $this->prefixes[ $prefix ] = array();
        }

        $collectionItem = [
            'dir' => $base_dir,
            'version' => $version
        ];
        // сохраняем базовую директорию для префикса пространства имён
        if ($prepend) {
            array_unshift($this->prefixes[ $prefix ], $collectionItem);
        } else {
            array_push($this->prefixes[ $prefix ], $collectionItem);
        }
    }

	/**
	 * Добавляет обработку нескольких пространств имён
	 *
	 * @param array $namespaces Список сопоставлений пространств имён и директорий в которых они находятся
	 * 		key		=> string Пространство имён
	 * 		value	=> array|string Путь к директории или массив путей
	 * @param string $rootDir Базовая директория для всех переданных путей
	 */
	function addNamespaces($namespaces, $rootDir = '')
	{
		foreach($namespaces as $prefix => $dirs) {
			if ( ! is_array($dirs) ) {
				$dirs = array($dirs);
			}

			foreach($dirs as $dir) {
				$this->addNamespace($prefix, $rootDir.$dir);
			}
		}
	}

    /**
     * Преобразует пару vendor и module в имя битриксового модуля. Если метод вернул не null значение, значит можно быть
     * уверенным, что модуль существует и установлен
     *
     * @param string $vendor
     * @param string $module
     * @return string|null
     */
    private function normalizeModuleId($vendor, $module)
    {
        if (strlen($vendor) === 0 || strlen($module) === 0) {
            return null;
        }

        $loweredVendor = strtolower($vendor);
        $loweredModule = strtolower($module);

        $candidates = array();

        if ($loweredVendor !== 'bitrix') {
            $candidates[] = "{$loweredVendor}.{$loweredModule}";
            $candidates[] = "{$vendor}.{$loweredModule}";
            $candidates[] = "{$vendor}.{$module}";
            $candidates[] = "{$loweredVendor}.{$module}";
        } else {
            $candidates[] = $loweredModule;
            $candidates[] = "{$loweredVendor}.{$loweredModule}";
        }

        foreach ($candidates as $moduleId) {
            if (ModuleManager::isModuleInstalled($moduleId)) {
                return $moduleId;
                break;
            }
        }

        return null;
    }
}
