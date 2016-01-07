<?php

namespace Maximaster\Tools\Events;

class BaseEvent
{
    /**
     * @var array Переменная для хранения данных, которыми будут обмениваться разные методы наследников класса
     */
    private static $dataExchanger = array();

    /**
     * Для определения порядка вызова хендлера необходимо использовать данный массив. В качестве ключа нужно использовать
     * имя метода, а в качестве значения - числовой порядок сортировки
     * @var array
     */
    protected static $sort = array();

    /**
     * Метод, который получает список возможных сортировок для вызванного хендлера
     * @return array
     */
    private static function sortList()
    {
        $methods = get_class_methods(get_called_class());
        $arSort = array();
        foreach ($methods as $method)
        {
            $sort = array_key_exists($method, static::$sort) ? static::$sort[ $method ] : 100;
            $arSort[ $method ] = $sort;
        }

        return $arSort;
    }

    /**
     * Получает сортировку для выбранного хендлера
     * @param $method
     * @return mixed
     */
    public static function getSort($method)
    {
        $sortList = self::sortList();
        return $sortList[ $method ];
    }

    /**
     * Устанавливает данные для хранения
     * @param $key
     * @param $value
     */
    protected static function setData($key, $value)
    {
        self::$dataExchanger[ $key ] = $value;
    }

    /**
     * Получает сохраненные данные
     * @param $key
     * @return null
     */
    protected static function getData($key)
    {
        return isset(self::$dataExchanger[ $key ]) ? self::$dataExchanger[ $key ] : null;
    }

}