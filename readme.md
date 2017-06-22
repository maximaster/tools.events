# maximaster/tools.events

Библиотека предоставляет функционал для автоматической загрузки обработчиков событий 1С-Битрикс.

Все события битрикс привязаны к модулям, исключением являются события созданных Higload-блоков, которые не связаны ни с одним из модулей. Каждый модуль так или иначе связан с вендором, который создал этот модуль. Для встроенных модулей в качестве вендора выступает Bitrix.

Для начала необходимо определиться, в какой директории будут храниться все обработчики событий. Это может быть, например, директория /local/EventHandlers. Принцип именования имен классов, их неймспейсов и директорий должен следовать PSR-4 стандарту, что означает, что для директории EventHandlers нужно определить соответствие какому-то пространству имен, например Maximaster\EventHandlers. При поиске обработчика событий имя вендора и модуля преобразовывается к нижнему регистру, а также используются разные вариации написания модуля, что позволяет главный модуль обозначить как Main вместо main. 
Если в названии вендора или модуля есть символ подчеркивания "_", то этот символ должен присутствовать в пространстве имен. Например вендор maxi_master можно записать как Maxi_Master, но не MaxiMaster. 

Чтобы зарегистрировать эту директорию в качестве хранилища обработчиков, нужно написать примерно следующее:

```php
    //Создаем инстанс нашего загрузчика
    $eventListener = new \Maximaster\Tools\Events\Listener();
    
    //Добавляем соответствие между пространством имен и директорией, в которой будет производиться поиск обработчиков
    //с этим пространством имен
    $eventListener->addNamespace('Maximaster\\EventHandlers', $_SERVER['DOCUMENT_ROOT']. '/local/EventHandlers');
    
    //Вызываем метод регистрации, который соберет все классы и вызовет для всех функцию AddEventHandler
    $eventListener->register();
```

После этого для каждого события нужно создать класс, который будет содержать обработчики этого события. Этот класс должен находиться в пространстве имен, которое содержит имена вендора, модуля и типа события. Например, если вам необходимо создать обработчик события OnPageStart модуля main, то нужно создать класс, полное имя которого (с пространством имен) оканчивается на Bitrix\Main\OnPageStart, а путь до файла этого класса должен быть /local/EventHandlers/Bitrix/Main/OnPageStart.php. Полное имя этого класса в нашем случае примет вид \Maximaster\EventHandlers\Bitrix\Main\OnPageStart. Здесь Bitrix - это вендор, Main - это модуль, а OnPageStart - название события.

Если в пространстве имен отсутствует имя вендора, то считается, что вендором является Bitrix. Пример выше равнозначен имени класса \Maximaster\EventHandlers\Main\OnPageStart.

Если в пространстве имен отсутствует имя модуля, то при привязке обработчика к событию модуль использован не будет, т.е. грубо говоря будет вызвана функция AddEventHandler, где в качестве первого параметра будет передана пустая строка.

Каждый метод этого класса будет являться обработчиком события, с которым связан этот класс. Имена методов не имеют значения. Чтобы изменить порядок вызова методов одного класса, нужно добавить phpDoc блок, в котором для параметра @eventSort использовать числовое значение. Обрабатывается только одно, первое значение @eventSort. Для примера выше:


```php
    namespace Maximaster\EventHandlers\Bitrix\Main;
    
    use Maximaster\Tools\Events\BaseEvent;
    
    class OnPageStart extends BaseEvent
    {
        /**
         * @eventSort 100
         */
        public static function myEventHandler() 
        {
            //код первого обработчика
        }
        
         /**
         * @eventSort 200
         */
        public static function myEventHandler2() 
        {
            //код второго обработчика
        }
    }
```

В библиотеке присутствует базовый класс обработчиков событий Maximaster\Tools\Events\BaseEvent. 
Этот класс также умеет управлять порядком вызова обработчиков. Чтобы изменить порядок вызова, нужно унаследовать свой класс с обработчиками от класса Maximaster\Tools\Events\BaseEvent и определить protected static свойство $sort, в котором должен быть массив соответствий 'имяМетода' => 'порядок сортировки'. 
**Данная возможность помечена как устаревшая** и будет удалена в ближайшей мажорной версии.
Для примера выше:

```php
    namespace Maximaster\EventHandlers\Bitrix\Main;
    
    use Maximaster\Tools\Events\BaseEvent;
    
    class OnPageStart extends BaseEvent
    {
    
        protected static $sort = array(
            'myEventHandler' => 100,
            'myEventHandler2' => 200,
        );
        
        public static function myEventHandler() 
        {
            //код первого обработчика
        }
        
        public static function myEventHandler2() 
        {
            //код второго обработчика
        }
    }
```

Также данный класс позволяет разным обработчикам события обмениваться данными между собой. Например, если вы хотите передать данные от события OnPageStart к событию OnEndEpilog, вам нужно в одном классе эти данные сохранить с помощью метода setData(), а в другом - получить с помощью getData().

```php

    class OnPageStart extends BaseEvent
    {
        public static function myEventHandler() {
            
            self::setData('uniqueDataKey', 'dataValue');
        }        
    }
    
    //.......
    
    class OnEndEpilog extends BaseEvent
    {
        public static function anotherEventHandler() {
            
            $data = self::getData('uniqueDataKey');
        }        
    }
```

Иногда бывают ситуации, когда необходимо один обработчик привязать к нескольким разным событиям. Например, часто случается, что один и тот же код используется для добавления и обновления элементов инфоблока. Для решения данной задачи нужно использовать phpDoc блок @eventlink. В его значении необходимо указать событие, для которого будет вызван текущий метод помимо того события, которое было определено по пространству имен. Можно привязать несколько разных событий. 
Например:

```php
/**
 * @eventLink OnAfterIblockElementUpdate
 * @eventLink OnAfterIblockElementDelete
 * @eventLink OnPageStart
 */
public static function OnAfterIblockElementAdd()
{
    //Здесь код, который будет обрабатывать и обновление, добавление элемента инфоблока
}
```

В связи с появлением d7, появилась новая система навешивания событий. Модуль умеет работать как со старыми событиями, так и с новыми.
Для регистрации событий разных версий можно использовать один из следующих способов:
- указание докблока `@eventVersion`
- указание параметра `$version` при регистрации директории с помощью метода `\Maximaster\Tools\Events\Listener::addNamespace`

Примеры:

```php
/**
 * @eventVersion 2
 * Данный обработчик будет зарегистрирован как новый,
 */
public function saveOrder(Bitrix\Main\Event $event)
{
}
```

```php
$eventListener = new \Maximaster\Tools\Events\Listener();

// Все обработчики событий, находящиеся в директоири BitrixD7 будут зарегистрированы как новые
$eventListener->addNamespace(
    '\\Maximaster\\EventHandlers\\BitrixD7',
    __DIR__ . '/../classes/Maximaster/EventHandlers/BitrixD7',
    false, 2
);

// А в директории Bitrix - как старые
$eventListener->addNamespace(
    '\\Maximaster\\EventHandlers\\Bitrix',
    __DIR__ . '/../classes/Maximaster/EventHandlers/Bitrix',
    false, 1
);

```