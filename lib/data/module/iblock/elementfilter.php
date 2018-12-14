<?php
namespace Intervolga\Migrato\Data\Module\Iblock;

use Bitrix\Main\Loader;
use CUserTypeEntity;
use Intervolga\Migrato\Data\BaseData;
use Bitrix\Main\Localization\Loc;
use Intervolga\Migrato\Data\Link;
use Intervolga\Migrato\Data\Module\Iblock\Iblock as MigratoIblock;
use CUserOptions;
use Intervolga\Migrato\Data\Record;
use Intervolga\Migrato\Data\RecordId;
use Intervolga\Migrato\Tool\ExceptionText;

Loc::loadMessages(__FILE__);

/**
 * Class ElementFilter - настройки фильтра для списка элементов инфоблока в административной части
 * (совместный и раздельный режимы просмотра).
 *
 * В рамках текущей сущности:
 *  - таблица БД - b_user_option,
 *  - настройка - запись таблицы БД,
 *  - название настройки - поле NAME настройки,
 *  - категория настройки - поле CATEGORY настройки,
 *
 * Название настройки фильтра: <FILTER_NAME_PREFIX><HASH> , где:
 *    - <FILTER_NAME_PREFIX> - одно из значений массива FILTER_NAME_PREFIXES,
 *    - <HASH> - md5(IBLOCK_TYPE_ID + "." + IBLOCK_ID)
 *
 *
 * @package Intervolga\Migrato\Data\Module\Iblock
 */
class ElementFilter extends BaseData
{
	/**
	 * Символ-разделитель логических блоков в строке с xmlId.
	 */
	const XML_ID_SEPARATOR = '.';

	/**
	 * Регулярное выражения для определения, является ли поле фильтра - фильтром свойства элемента ИБ.
	 */
	const IB_PROPERTY_NAME_REGEX = '/^([^_]*_?)PROPERTY_([^_\s]+)(_?.*)$/';

	/**
	 * Регулярное выражения для определения, является ли поле фильтра - фильтром UF-поля.
	 */
	const UF_NAME_REGEX = '/^(UF_[A-Z0-9_]+)(_?.*)$/';

	/**
	 * Соответствие типов фильтра названиям настроек.
	 * COMMON_VIEW - фильтр для ИБ (режим прссмотра - совместный).
	 * SEPARATE_VIEW_SECTION - фильтр для разделов ИБ (режим просмотра - раздельный)
	 * SEPARATE_VIEW_ELEMENT - фильтр для элементов ИБ (режим просмотра - раздельный)
	 */
	const FILTER_NAME_PREFIXES = array(
		'COMMON_VIEW' => 'tbl_iblock_list_',
		'SEPARATE_VIEW_SECTION' => 'tbl_iblock_section_',
		'SEPARATE_VIEW_ELEMENT' => 'tbl_iblock_element_',
	);

	/**
	 * Категории настроек фильтра.
	 */
	const FILTER_CATEGORIES = array(
		'main.ui.filter',
		'main.ui.filter.common',
		'main.ui.filter.common.presets',
	);

	/**
	 * Префикс свойства элемента ИБ в поле фильтра.
	 */
	const PROPERTY_FIELD_PREFIX = 'PROPERTY_';

	/**
	 * Префикс UF-поля в поле фильтра.
	 */
	const UF_FIELD_PREFIX = 'UF_';

	protected function configure()
	{
		Loader::includeModule('iblock');
		$this->setEntityNameLoc(Loc::getMessage('INTERVOLGA_MIGRATO.IBLOCK_ELEMENT_FILTER.ENTITY_NAME'));
		$this->setVirtualXmlId(true);
		$this->setFilesSubdir('/type/iblock/admin/');
		$this->setDependencies(array(
			'IBLOCK' => new Link(MigratoIblock::getInstance()),
			'PROPERTY' => new Link(Property::getInstance()),
			'ENUM' => new Link(Enum::getInstance()),
			'FIELD' => new Link(Field::getInstance()),
			'FIELDENUM' => new Link(FieldEnum::getInstance()),
		));
	}

	/**
	 * @param string[] $filter
	 *
	 * @return \Intervolga\Migrato\Data\Record[]
	 */
	public function getList(array $filter = array())
	{
		$result = array();

		/**
		 * Типы мигрируемых фильтров:
		 * - фильтры для админа
		 * - общие фильтры
		 */
		$optionTypeFilters = array(
			'ADMIN_OPTIONS' => array('USER_ID' => '1'),
			'COMMON_OPTIONS' => array('COMMON' => 'Y', 'USER_ID' => '0'),
		);

		foreach ($optionTypeFilters as $optionTypeFilter)
		{
			$optionsFilter = array_merge($filter, $optionTypeFilter);
			$dbRes = CUserOptions::GetList(array(), $optionsFilter);
			while ($option = $dbRes->Fetch())
			{
				if (static::isFilter($option))
				{
					$record = $this->createRecordFromArray($option);
					$result[] = $record;
				}
			}
		}

		return $result;
	}

	/**
	 * @param Record $record
	 *
	 * @throws \Exception
	 */
	public function update(Record $record)
	{
		$filterId = 0;
		if ($record->getId())
		{
			$filterId = $this->saveFilterFromRecord($record);
		}

		if (!$filterId)
		{
			$exceptionMessage = ExceptionText::getFromString(
				Loc::getMessage('INTERVOLGA_MIGRATO.IBLOCK_ELEMENT_FILTER.UPDATE_ERROR')
			);
			throw new \Exception($exceptionMessage);
		}
	}

	/**
	 * @param Record $record
	 *
	 * @return RecordId
	 * @throws \Exception
	 */
	protected function createInner(Record $record)
	{
		$filterId = $this->saveFilterFromRecord($record);
		if ($filterId)
		{
			return $this->createId($filterId);
		}

		$exceptionMessage = ExceptionText::getFromString(
			Loc::getMessage('INTERVOLGA_MIGRATO.IBLOCK_ELEMENT_FILTER.ADD_ERROR')
		);
		throw new \Exception($exceptionMessage);
	}

	/**
	 * @param RecordId $id
	 *
	 * @throws \Exception
	 */
	protected function deleteInner(RecordId $id)
	{
		$success = false;

		$dbRes = CUserOptions::GetList(array(), array('ID' => $id->getValue()));
		if ($filter = $dbRes->Fetch())
		{
			$success = CUserOptions::DeleteOptionsByName($filter['CATEGORY'], $filter['NAME']);
		}

		if (!$success)
		{
			$exceptionMessage = ExceptionText::getFromString(
				Loc::getMessage('INTERVOLGA_MIGRATO.IBLOCK_ELEMENT_FILTER.DELETE_ERROR')
			);
			throw new \Exception($exceptionMessage);
		}
	}

	/**
	 * @param string $xmlId
	 *
	 * @return RecordId|null
	 */
	public function findRecord($xmlId)
	{
		// Получаем необходимые данные из xmlId
		$xmlFields = $this->getArrayFromXmlId($xmlId);
		$xmlFilterPrefix = $xmlFields[0];
		$xmlIsUserAdmin = $xmlFields[1];
		$xmlIsFilterCommon = $xmlFields[2];
		$xmlFilterCategory = $xmlFields[3];
		$xmlFilterName = $xmlFields[4];
		$xmlIblockXmlId = $xmlFields[5];

		// Данные инфблока
		$iblockInfo = array();
		$iblockRecord = MigratoIblock::getInstance()->findRecord($xmlIblockXmlId);
		if ($iblockRecord)
		{
			$iblockId = $iblockRecord->getValue();
			if ($iblockId && Loader::includeModule('iblock'))
			{
				$iblockInfo = \CIBlock::GetById($iblockId)->Fetch();
			}
		}

		// Формируем фильтр для запроса
		$arFilter = array(
			'COMMON' => $xmlIsFilterCommon,
			'CATEGORY' => $xmlFilterCategory,
		);

		if ($xmlIsUserAdmin === 'Y')
		{
			$arFilter['USER_ID'] = 1;
		}

		if ($iblockInfo)
		{
			$dbRes = CUserOptions::GetList(array(), $arFilter);
			while ($filter = $dbRes->Fetch())
			{
				$filterName = $filter['NAME'];
				if (strpos($filterName, $xmlFilterPrefix) === 0
					&& md5($filterName) === $xmlFilterName
				)
				{
					$hash = substr($filterName, strlen($xmlFilterPrefix));
					if (md5($iblockInfo['IBLOCK_TYPE_ID'] . '.' . $iblockInfo['ID']) === $hash)
					{
						return $this->createId($filter['ID']);
					}
				}
			}
		}

		return null;
	}

	/**
	 * @param \Intervolga\Migrato\Data\RecordId $id
	 *
	 * @return string
	 */
	public function getXmlId($id)
	{
		$dbRes = CUserOptions::GetList(array(), array('ID' => $id->getValue()));
		if ($filter = $dbRes->Fetch())
		{
			return $this->getXmlIdFromArray($filter);
		}

		return '';
	}

	/**
	 * Создает запись миграции фильтра
	 * на основе $option.
	 *
	 * @param array $option массив данных фильтра.
	 *
	 * @return Record запись миграции фильтра.
	 */
	protected function createRecordFromArray(array $option)
	{
		$record = new Record($this);
		$record->setId($this->createId($option['ID']));
		$record->setXmlId($this->getXmlIdFromArray($option));
		$record->setFieldRaw('COMMON', $option['COMMON']);
		$record->setFieldRaw('CATEGORY', $option['CATEGORY']);
		$this->addPropertiesDependencies($record, $option);
		$this->setRecordDependencies($record, $option);

		return $record;
	}

	/**
	 * Создает массив данных фильтра
	 * на основе $record.
	 *
	 * @param Record $record запись миграции фильтра.
	 *
	 * @return string[] массив данных фильтра.
	 */
	protected function createArrayFromRecord(Record $record)
	{
		$arFilter = $record->getFieldsRaw();
		$xmlIdFields = $this->getArrayFromXmlId($record->getXmlId());
		$iblockInfo = $this->getIblockByXmlId($xmlIdFields[5]);

		// Формируем поля фильтра
		$arFilter['COMMON'] = $arFilter['COMMON'] === 'Y';
		$arFilter['USER_ID'] = ($xmlIdFields[1] === 'Y') ? 1 : 0;
		if ($iblockInfo)
		{
			$arFilter['NAME'] = $xmlIdFields[0] . md5($iblockInfo['IBLOCK_TYPE_ID'] . '.' . $iblockInfo['ID']);
		}
		$arFilter['FIELDS'] = $this->convertFieldsFromXml($arFilter);


		return $arFilter;
	}

	protected function addPropertiesDependencies(Record $record, $filter)
	{
		$dependencies = array();

		$arFields = $this->convertFieldsToXml($filter, $dependencies);
		$record->setFieldRaw('FIELDS', serialize($arFields));

		/**
		 * Зависимости от сторонних сущностей:
		 * - Списки свойств (ИБ)
		 * - Свойства (ИБ)
		 * - Списки UF-полей
		 * - UF-поля
		 */
		foreach ($dependencies as $dependencyName => $dependencyXmlIds)
		{
			if ($dependencyXmlIds)
			{
				$dependencyXmlIds = array_unique($dependencyXmlIds);
				$dependency = clone $this->getDependency($dependencyName);
				$dependency->setValues($dependencyXmlIds);
				$record->setDependency($dependencyName, $dependency);
			}
		}
	}

	/**
	 * Добавляет в запись миграции $record
	 * зависимости от сторонных сущностей.
	 *
	 * @param Record $record запись миграции фильтра.
	 * @param array $filter массив данных фильтра.
	 */
	public function setRecordDependencies(Record $record, array $filter)
	{
		//IBLOCK_ID
		if ($filter['NAME'])
		{
			$iblockId = $this->getIblockIdByFilterName($filter['NAME']);
			if ($iblockId)
			{
				$iblockIdObj = MigratoIblock::getInstance()->createId($iblockId);
				$iblockXmlId = MigratoIblock::getInstance()->getXmlId($iblockIdObj);

				$dependency = clone $this->getDependency('IBLOCK');
				$dependency->setValue($iblockXmlId);
				$record->setDependency('IBLOCK', $dependency);
			}
		}
	}

	/**
	 * Формирует настройку фильтра из $record и сохраняет ее в БД.
	 *
	 * @param Record $record запись миграции, получаемая на входе методов update(), createInner().
	 *
	 * @return int id сохраненной настройки фильтра или 0.
	 */
	protected function saveFilterFromRecord(Record $record)
	{
		$arFilter = $this->createArrayFromRecord($record);

		// Сохраняем фильтр
		$filterId = 0;
		$success = false;
		if ($arFilter['CATEGORY'] && $arFilter['NAME'] && $arFilter['FIELDS'])
		{
			$success = CUserOptions::SetOption(
				$arFilter['CATEGORY'],
				$arFilter['NAME'],
				$arFilter['FIELDS'],
				$arFilter['COMMON'],
				$arFilter['USER_ID']
			);
		}

		// Проверяем успешность сохранения фильтра
		if ($success)
		{
			$option = CUserOptions::GetList(
				array(),
				array(
					'CATEGORY' => $arFilter['CATEGORY'],
					'NAME' => $arFilter['NAME'],
					'USER_ID' => $arFilter['USER_ID'],
				)
			)->Fetch();

			$filterId = $option['ID'] ?: 0;
		}

		return $filterId;
	}

	/**
	 * Возвращает id ИБ по названию настройки фильтра.
	 *
	 * @param string $filterName название настройки фильтра.
	 *
	 * @return string id ИБ.
	 */
	protected function getIblockIdByFilterName($filterName)
	{
		$iblock = static::getIblockByFilterName($filterName);

		return $iblock['ID'] ?: '';
	}

	/**
	 * Возвращает тип ИБ по названию настройки фильтра.
	 *
	 * @param string $filterName название настройки фильтра.
	 *
	 * @return string тип ИБ.
	 */
	protected function getIblockTypeByFilterName($filterName)
	{
		$iblock = static::getIblockByFilterName($filterName);

		return $iblock['IBLOCK_TYPE_ID'] ?: '';
	}

	/**
	 * Возвращает ИБ по названию настройки фильтра.
	 *
	 * @param string $filterName название настройки фильтра.
	 *
	 * @return array данные ИБ.
	 */
	protected function getIblockByFilterName($filterName)
	{
		if (Loader::includeModule('iblock'))
		{
			$type = $this->getFilterTypeByName($filterName);
			$prefix = static::FILTER_NAME_PREFIXES[$type];
			if ($prefix)
			{
				$hash = substr($filterName, strlen($prefix));

				$res = \CIBlock::GetList();
				while ($iblock = $res->Fetch())
				{
					if (md5($iblock['IBLOCK_TYPE_ID'] . '.' . $iblock['ID']) == $hash)
					{
						return $iblock;
					}
				}
			}
		}

		return array();
	}

	/**
	 * Возвращает ИБ по его xmlId.
	 *
	 * @param string $iblockXmlId xmlId ИБ.
	 *
	 * @return array данные ИБ.
	 */
	protected function getIblockByXmlId($iblockXmlId)
	{
		$iblockInfo = array();
		if (Loader::includeModule('iblock'))
		{
			$iblockId = MigratoIblock::getInstance()->findRecord($iblockXmlId)->getValue();
			$iblockInfo = \CIBlock::GetByID($iblockId)->GetNext();
		}

		return $iblockInfo;
	}

	/**
	 * Возвращает свойства ИБ из БД.
	 *
	 * @param int $iblockId id инфоблока для фильтрации (опционально).
	 *
	 * @return array массив свойств ИБ.
	 */
	protected function getIbProperties($iblockId = 0)
	{
		$filter = array();
		if ($iblockId)
		{
			$filter = array('IBLOCK_ID' => $iblockId);
		}

		$dbProperties = array();
		$dbRes = \CIBlockProperty::GetList(array(), $filter);
		while ($prop = $dbRes->Fetch())
		{
			$dbProperties[$prop['ID']] = $prop;
		}

		return $dbProperties;
	}

	/**
	 * Возвращает UF-поля из БД.
	 *
	 * @param string $entityId id сущности для фильтрации (опционально).
	 *
	 * @return array данные UF-полей.
	 */
	protected function getUfFields($entityId = '')
	{
		$filter = array();
		if ($entityId)
		{
			$filter['ENTITY_ID'] = $entityId;
		}

		$ufFields = array();
		$dbRes = CUserTypeEntity::GetList(array(), $filter);
		while ($ufField = $dbRes->Fetch())
		{
			$ufFields[$ufField['FIELD_NAME']] = $ufField;
		}

		return $ufFields;
	}

	/**
	 * Возвращает свойства ИБ из БД, отфильтрованные по id.
	 *
	 * @param array $ibPropertyIds массив id свойств ИБ.
	 *
	 * @return array массив свойств ИБ.
	 */
	protected function getIbPropertiesById(array $ibPropertyIds)
	{
		$dbProperties = array();
		$dbRes = \CIBlockProperty::GetList();
		while ($prop = $dbRes->Fetch())
		{
			if (in_array($prop['ID'], $ibPropertyIds))
			{
				$dbProperties[$prop['ID']] = $prop;
			}
		}

		return $dbProperties;
	}

	/**
	 * Возвращает xmlId фильтра
	 * на основе $filter.
	 *
	 * @param array $filter массив данных фильтра.
	 *
	 * @return string xmlId фильтра.
	 */
	protected function getXmlIdFromArray(array $filter)
	{
		$result = '';
		$iblockId = $this->getIblockIdByFilterName($filter['NAME']);
		if ($iblockId)
		{
			$iblockXmlId = MigratoIblock::getInstance()->getXmlId(MigratoIblock::getInstance()->createId($iblockId));
			if ($iblockXmlId)
			{
				$filterType = $this->getFilterTypeByName($filter['NAME']);
				$result = (
					$filterType
					. static::XML_ID_SEPARATOR .
					($filter['USER_ID'] == 1 ? 'Y' : 'N')
					. static::XML_ID_SEPARATOR .
					$filter['COMMON']
					. static::XML_ID_SEPARATOR .
					str_replace('.', '_', $filter['CATEGORY'])
					. static::XML_ID_SEPARATOR .
					md5($filter['NAME'])
					. static::XML_ID_SEPARATOR
					. $iblockXmlId
				);
			}
		}

		return $result;
	}

	/**
	 * Возвращает массив данных фильтра
	 * на основе $xmlId.
	 *
	 * @param string $xmlId xmlId фильтра.
	 *
	 * @return array массив данных фильтра.
	 */
	protected function getArrayFromXmlId($xmlId)
	{
		$filterCategoryIndex = 3;
		$filterPrefixIndex = 0;

		$xmlFields = explode(static::XML_ID_SEPARATOR, $xmlId);
		$xmlFields[$filterPrefixIndex] = static::FILTER_NAME_PREFIXES[$xmlFields[$filterPrefixIndex]];
		$xmlFields[$filterCategoryIndex] = str_replace('_', '.', $xmlFields[$filterCategoryIndex]);

		return $xmlFields;
	}

	/**
	 * Возвращает тип настройки фильтра по имени фильтра $filterName.
	 *
	 * @param string $filterName название фильтра (поле NAME настройки фильтра).
	 *
	 * @return string тип настройки - ключ массива FILTER_NAME_PREFIXES.
	 */
	protected function getFilterTypeByName($filterName)
	{
		$type = '';
		foreach (static::FILTER_NAME_PREFIXES as $key => $tableName)
		{
			if (strpos($filterName, $tableName) === 0)
			{
				$type = $key;
			}
		}

		return $type;
	}

	/**
	 * Проверяет, является ли $option настройкой фильтра:
	 *  - поле CATEGORY должно соответствовать одному из FILTER_CATEGORIES
	 *    - префикс поля NAME должен соответствовать одному на FILTER_NAME_PREFIXES.
	 *
	 * @param array $option настройка.
	 *
	 * @return bool true, если $option - настройка фильтра, иначе - false.
	 */
	protected function isFilter(array $option)
	{
		return static::isFilterCategory($option['CATEGORY'])
			   && static::isFilterName($option['NAME']);
	}

	/**
	 * Проверяет, является ли категория настройки $optionCategory
	 * категорией настроек фильтра.
	 *
	 * Необходим для проверки принадлежности к настройке фильтра.
	 *
	 * @param string $optionCategory категория настройки (поле CATEGORY настройки).
	 *
	 * @return bool true, если $optionCategory - категория настроек фильтра, иначе - false.
	 */
	protected function isFilterCategory($optionCategory)
	{
		return in_array($optionCategory, static::FILTER_CATEGORIES);
	}

	/**
	 * Проверяет, является ли название настройки $optionName
	 * названием настроек фильтра.
	 *
	 * Необходим для проверки принадлежности к настройке фильтра.
	 *
	 * @param string $optionName название настройки (поле NAME настройки).
	 *
	 * @return bool true, если $optionName - название настроек фильтра, иначе - false.
	 */
	protected function isFilterName($optionName)
	{
		foreach (static::FILTER_NAME_PREFIXES as $filterNamePrefix)
		{
			if (strpos($optionName, $filterNamePrefix) === 0)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Проверяет, что название поля фильтра $filterRowName, является
	 * свойством элемента ИБ.
	 *
	 * @param string $filterRowName название поля фильтра.
	 *
	 * @return bool true, если $filterRowName - фильтр свойства элемента ИБ, иначе - false.
	 */
	protected function isIbPropertyFilterRow($filterRowName)
	{
		$isMatch = false;
		$matches = array();

		$this->testStringAgainstIbPropertyRegex($filterRowName, $isMatch, $matches);

		return ($isMatch && $matches[2]);
	}

	/**
	 * Проверяет, что название поля фильтра $filterRowName, является
	 * UF-полем.
	 *
	 * @param string $filterRowName название поля фильтра.
	 *
	 * @return bool true, если $filterRowName - фильтр UF-поля, иначе - false.
	 */
	protected function isUfFilterRow($filterRowName)
	{
		$length = strlen(static::UF_FIELD_PREFIX);

		return (substr($filterRowName, 0, $length) === static::UF_FIELD_PREFIX);
	}

	/**
	 * Возвращает id свойства элемента ИБ по названию поля фильтра.
	 *
	 * @param string $filterRowName название поля фильтра.
	 *
	 * @return string id свойства элемента ИБ.
	 */
	protected function getIbPropertyIdByFilterRow($filterRowName)
	{
		$isMatch = false;
		$matches = array();

		$this->testStringAgainstIbPropertyRegex($filterRowName, $isMatch, $matches);

		return ($isMatch && $matches[2]) ? $matches[2] : '';
	}

	/**
	 * Возвращает название UF-поля по названию поля фильтра.
	 *
	 * @param string $filterRowName название поля фильтра.
	 *
	 * @return string название UF-поля.
	 */
	protected function getUfNameByFilterRow($filterRowName)
	{
		$ufName = '';

		$isMatch = preg_match(static::UF_NAME_REGEX, $filterRowName, $matches);
		if ($isMatch && $matches[1])
		{
			$ufName = rtrim($matches[1], '_');
		}

		return $ufName;
	}

	/**
	 * Возвращает id свойств ИБ, используемых в полях фильтра $filterFields.
	 *
	 * @param array $filterFields поля фильтра.
	 *
	 * @return array id свойств ИБ, используемых в фильтре.
	 */
	protected function getIbPropertiesUsedInFilter(array $filterFields)
	{
		$propertyIds = array();
		foreach ($filterFields['filters'] as $filter)
		{
			$filterRows = explode(',', $filter['filter_rows']);
			foreach ($filterRows as $filterRow)
			{
				$isMatch = false;
				$matches = array();

				$this->testStringAgainstIbPropertyRegex($filterRow, $isMatch, $matches);
				if ($isMatch && $matches[2])
				{
					$propertyIds[] = $matches[2];
				}
			}
		}

		return array_unique($propertyIds);
	}

	/**
	 * Проверяет строку $string на соответсвие регулярному выражению
	 * для названия фильтра свойства элемента ИБ.
	 *
	 * @param string $string проверяемая строка.
	 * @param bool $isMatch признак соответствия проверяемой строки регулярному выражению.
	 * @param array $matches массив совпадений.
	 */
	protected function testStringAgainstIbPropertyRegex($string, &$isMatch, &$matches)
	{
		$isMatch = preg_match(static::IB_PROPERTY_NAME_REGEX, $string, $matches);
	}

	/**
	 * Проверяет строку $string на соответсвие регулярному выражению
	 * для названия фильтра UF-полей.
	 *
	 * @param string $string проверяемая строка.
	 * @param bool $isMatch признак соответствия проверяемой строки регулярному выражению.
	 * @param array $matches массив совпадений.
	 */
	protected function testStringAgainstUFRegex($string, &$isMatch, &$matches)
	{
		$isMatch = preg_match(static::UF_NAME_REGEX, $string, $matches);
	}

	/**
	 * Конвертирует поля фильтра в формат (xml), пригодный для выгрузки.
	 *
	 * @param array $filterData данные фильтра.
	 * @param array $dependencies xmlId зависимостей сущности.
	 *
	 * @return array поля фильтра в форме, пригодном для выгрузки.
	 */
	protected function convertFieldsToXml(array $filterData, array &$dependencies)
	{
		$filterFields = unserialize($filterData['VALUE']);
		$iblockId = $this->getIblockIdByFilterName($filterData['NAME']);
		$propertyIds = $this->getIbPropertiesUsedInFilter($filterFields);
		$properties = $this->getIbPropertiesById($propertyIds);
		$ufFields = $this->getUfFields('IBLOCK_' . $iblockId . '_SECTION');

		// Конвертируем данные фильтров
		foreach ($filterFields['filters'] as &$filter)
		{
			// Конвертируем названия Свойств в подмассиве filter_rows
			$arFilterRows = explode(',', $filter['filter_rows']);
			foreach ($arFilterRows as &$filterRow)
			{
				if ($this->isIbPropertyFilterRow($filterRow))
				{
					$this->convertIbPropertyFilterRowToXml($filterRow);
				}
			}
			unset($filterRow);
			$filter['filter_rows'] = implode(',', $arFilterRows);


			// Конвертируем названия Свойств, Списков свойств, Списков UF-полей в подмассиве fields,
			$newFields = array();
			foreach ($filter['fields'] as $fieldName => $fieldVal)
			{
				$newFieldName = $fieldName;
				$newFieldVal = $fieldVal;

				if ($this->isIbPropertyFilterRow($newFieldName))
				{
					// Конвертируем название поля фильтра
					$propertyXmlId = $this->convertIbPropertyFilterRowToXml($newFieldName);

					// Конфертируем значение поля фильтра
					if ($propertyXmlId)
					{
						$propertyId = static::getIbPropertyIdByFilterRow($fieldName);
						$propertyData = $properties[$propertyId];
						$dependencies['PROPERTY'][] = $propertyXmlId;

						// Значение списочных свойств
						if ($propertyData['PROPERTY_TYPE'] === 'L')
						{
							if ($propertyData['MULTIPLE'] === 'Y')
							{
								foreach ($newFieldVal as &$fieldValId)
								{
									$enumPropId = Enum::getInstance()->createId($fieldValId);
									$enumPropXmlId = Enum::getInstance()->getXmlId($enumPropId);
									if ($enumPropXmlId)
									{
										$fieldValId = $enumPropXmlId;
										$dependencies['ENUM'][] = $enumPropXmlId;
									}
								}
							}
							else
							{
								$enumPropId = Enum::getInstance()->createId($newFieldVal);
								$enumPropXmlId = Enum::getInstance()->getXmlId($enumPropId);
								if ($enumPropXmlId)
								{
									$newFieldVal = $enumPropXmlId;
									$dependencies['ENUM'][] = $enumPropXmlId;
								}
							}
						}
					}
				}
				elseif ($this->isUfFilterRow($newFieldName))
				{
					$ufName = $this->getUfNameByFilterRow($newFieldName);
					$ufField = $ufFields[$ufName];
					if ($ufField)
					{
						// Зависимости от UF-полей
						$ufFieldId = $ufField['ID'];
						$ufFieldIdObj = Field::getInstance()->createId($ufFieldId);
						$dependencies['FIELD'][] = Field::getInstance()->getXmlId($ufFieldIdObj);

						// Обработка значений фильтров Списоков UF-полей
						if ($ufField['USER_TYPE_ID'] === 'enumeration' && is_array($newFieldVal))
						{
							foreach ($newFieldVal as &$ufFieldValId)
							{
								$ufFieldValIdObj = FieldEnum::getInstance()->createId($ufFieldValId);
								$ufFieldValXmlId = FieldEnum::getInstance()->getXmlId($ufFieldValIdObj);
								if ($ufFieldValXmlId)
								{
									$dependencies['FIELDENUM'][] = $ufFieldValXmlId;
									$ufFieldValId = $ufFieldValXmlId;
								}
							}
						}
					}
				}

				$newFields[$newFieldName] = $newFieldVal;
			}
			$filter['fields'] = $newFields;
		}

		return $filterFields;
	}

	/**
	 * Конвертирует название поля фильтра, являющегося фильтром свойства ИБ,
	 * в формат (xml), пригодный для выгрузки.
	 *
	 * @param string $filterRow название поля фильтра (свойство ИБ).
	 *
	 * @return string xml_id свойства элемента ИБ
	 *                или
	 *                  пустая строка, если конвертация не производилась.
	 */
	protected function convertIbPropertyFilterRowToXml(&$filterRow)
	{
		$propertyXmlId = '';
		$isMatch = false;
		$matches = array();

		/**
		 * Проверка, что поле фильтра является фильтром свойства элемента ИБ
		 */
		$this->testStringAgainstIbPropertyRegex($filterRow, $isMatch, $matches);
		if ($isMatch && $matches[2])
		{
			$filterRowPrefix = $matches[1];
			$filterRowPostfix = $matches[3];
			$propertyId = $matches[2];

			$propertyIdObj = Property::getInstance()->createId($propertyId);
			$propertyXmlId = Property::getInstance()->getXmlId($propertyIdObj);

			$filterRow = static::PROPERTY_FIELD_PREFIX . $propertyXmlId;
			if ($filterRowPrefix)
			{
				$filterRow = $filterRowPrefix . $filterRow;
			}
			if ($filterRowPostfix)
			{
				$filterRow = $filterRow . $filterRowPostfix;
			}
		}

		return $propertyXmlId;
	}

	/**
	 * Конвертирует название поля фильтра, являющегося фильтром свойства ИБ,
	 * в формат, пригодный для сохранения в БД.
	 *
	 * @param string $filterRow название поля фильтра (свойство ИБ).
	 *
	 * @return int id свойства элемента ИБ
	 *             или
	 *             0, если конвертация не производилась.
	 */
	protected function convertIbPropertyFilterRowFromXml(&$filterRow)
	{
		$propertyId = 0;
		$isMatch = false;
		$matches = array();

		/**
		 * Проверка, что поле фильтра является фильтром свойства элемента ИБ
		 */
		$this->testStringAgainstIbPropertyRegex($filterRow, $isMatch, $matches);
		if ($isMatch && $matches[2])
		{
			$filterRowPrefix = $matches[1];
			$filterRowPostfix = $matches[3];
			$propertyXmlId = $matches[2];

			$propertyId = Property::getInstance()->findRecord($propertyXmlId);
			if ($propertyId = $propertyId->getValue())
			{
				$filterRow = static::PROPERTY_FIELD_PREFIX . $propertyId;
				if ($filterRowPrefix)
				{
					$filterRow = $filterRowPrefix . $filterRow;
				}
				if ($filterRowPostfix)
				{
					$filterRow = $filterRow . $filterRowPostfix;
				}
			}
		}

		return $propertyId;
	}

	/**
	 * Конвертирует поля фильтра в формат, пригодный для сохранения в БД.
	 *
	 * @param array $filterData данные фильтра.
	 *
	 * @return array поля фильтра в формате, пригодном для сохранения в БД.
	 */
	protected function convertFieldsFromXml(array $filterData)
	{
		$filterFields = unserialize($filterData['FIELDS']);
		$iblockId = $this->getIblockIdByFilterName($filterData['NAME']);
		$dbProperties = $this->getIbProperties($iblockId);
		$ufFields = $this->getUfFields('IBLOCK_' . $iblockId . '_SECTION');

		// Конвертируем данные фильтров
		foreach ($filterFields['filters'] as &$filter)
		{
			// Конвертируем названия Свойств в подмассиве filter_rows
			$filterRows = explode(',', $filter['filter_rows']);
			foreach ($filterRows as &$filterRowName)
			{
				if ($this->isIbPropertyFilterRow($filterRowName))
				{
					$this->convertIbPropertyFilterRowFromXml($filterRowName);
				}

			}
			$filter['filter_rows'] = implode(',', $filterRows);
			unset($filterRowName);

			// Конвертируем названия Свойств, Списков свойств, Списков UF-полей в подмассиве fields,
			$newFilterFields = array();
			foreach ($filter['fields'] as $filterRowName => $filterRowValue)
			{
				$newFilterRowName = $filterRowName;
				$newFilterRowValue = $filterRowValue;

				if ($this->isIbPropertyFilterRow($newFilterRowName))
				{
					// Конвертируем название поля фильтра
					$propertyId = $this->convertIbPropertyFilterRowFromXml($newFilterRowName);

					// Конфертируем значение поля фильтра
					if ($propertyId)
					{
						// Значение списочных свойств
						$propertyData = $dbProperties[$propertyId];
						if ($propertyData['PROPERTY_TYPE'] === 'L')
						{
							if ($propertyData['MULTIPLE'] === 'Y')
							{
								foreach ($newFilterRowValue as &$filterRowValueId)
								{
									if ($filterRowValueId && $filterRowValueId !== 'NOT_REF')
									{
										$filterRowValueId = Enum::getInstance()->findRecord($filterRowValueId)->getValue();
										$filterRowValueId = strval($filterRowValueId);
									}
								}
							}
							else
							{
								$newFilterRowValue = Enum::getInstance()->findRecord($newFilterRowValue)->getValue();
								$newFilterRowValue = strval($newFilterRowValue);
							}
						}
					}
				}
				elseif($this->isUfFilterRow($newFilterRowName))
				{
					$ufName = $this->getUfNameByFilterRow($newFilterRowName);
					$ufField = $ufFields[$ufName];
					if ($ufField)
					{
						// Обработка значений фильтров Списоков UF-полей
						if ($ufField['USER_TYPE_ID'] === 'enumeration' && is_array($newFilterRowValue))
						{
							foreach ($newFilterRowValue as &$ufFieldValXmlId)
							{
								$ufFieldValXmlId = FieldEnum::getInstance()->findRecord($ufFieldValXmlId)->getValue();
								$ufFieldValXmlId = strval($ufFieldValXmlId);
							}
						}
					}
				}

				$newFilterFields[$newFilterRowName] = $newFilterRowValue;
			}
			$filter['fields'] = $newFilterFields;
		}

		return $filterFields;
	}
}