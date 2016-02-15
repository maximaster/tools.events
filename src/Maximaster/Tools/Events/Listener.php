<?php

namespace Maximaster\Tools\Events;

use Bitrix\Main\ModuleManager;

class Listener
{
    private   $registered = false;
    protected $prefixes   = array();

    /**
     * Инициирует регистрацию всех событий
     */
    public function register()
    {
        $collection = array();
        foreach ($this->prefixes as $namespace => $directoryList) {
            foreach ($directoryList as $directory) {
                $collection += $this->collect($namespace, $directory);
            }
        }
        foreach ($collection as $handler) {
            $sort = $handler[ 'sort' ] ? $handler[ 'sort' ] : 100;
            $this->listen($handler[ 'moduleName' ], $handler[ 'eventType' ], $handler[ 'callback' ], $sort);
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
    private function listen($moduleId, $eventType, $callback, $sort = 100)
    {
        return \AddEventHandler($moduleId, $eventType, $callback, $sort);
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

            $relativeClass = str_replace(array($handlersDirectory, '.php'), '', $file);
            $nestedClass = explode('/', $relativeClass);

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

            $className = $ns . str_replace('/', '\\', $relativeClass);
            $class = new \ReflectionClass($className);

            foreach ($class->getMethods() as $method) {

                $className = $class->getName();

                if ( $method->class == $className ) {

                    $sortValue = 100;

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
                    if (strlen($doc) > 0)
                    {
                        preg_match('/@eventSort\s(\d+)/', $doc, $sortMatches);
                        if (isset($sortMatches[1]))
                        {
                            $sortValue = $sortMatches[1];
                        }
                    }

                    $collection[] = array(
                        'moduleName' => strtolower($moduleId),
                        'eventType' => $eventType,
                        'callback' => array($class->getName(), $method->name),
                        'sort' => $sortValue
                    );
                }
            }
        }

        return $collection;
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
    public function addNamespace($prefix, $base_dir, $prepend = false)
    {
        if ($this->registered === true) {
            throw new \LogicException('Необходимо зарегистрировать все пространства имен до того, как вызван метод register()');
        }

        // нормализуем префикс пространства имён
        $prefix = trim($prefix, '\\') . '\\';

        // нормализуем базовую директорию так, чтобы всегда присутствовал разделитель в конце
        $base_dir = rtrim($base_dir, DIRECTORY_SEPARATOR) . '/';

        // инициализируем массив префиксов пространства имён
        if (isset( $this->prefixes[ $prefix ] ) === false) {
            $this->prefixes[ $prefix ] = array();
        }

        // сохраняем базовую директорию для префикса пространства имён
        if ($prepend) {
            array_unshift($this->prefixes[ $prefix ], $base_dir);
        } else {
            array_push($this->prefixes[ $prefix ], $base_dir);
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