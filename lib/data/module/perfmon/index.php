<?php
namespace Intervolga\Migrato\Data\Module\perfmon;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Intervolga\Migrato\Data\BaseData;
use Intervolga\Migrato\Data\Record;
use Intervolga\Migrato\Tool\ExceptionText;

Loc::loadMessages(__FILE__);

class Index extends BaseData
{
	protected function configure()
	{
		$this->setVirtualXmlId(true);
		Loader::includeModule("perfmon");
		$this->setEntityNameLoc(Loc::getMessage('INTERVOLGA_MIGRATO.PERFMON_INDEX'));
	}

	public function getList(array $filter = array())
	{
		$result = array();
		$getList = \CPerfomanceIndexComplete::getList($filter);
		while ($index = $getList->fetch())
		{
			$record = new Record($this);
			$id = $this->createId($index['ID']);
			$record->setId($id);
			$record->setXmlId($index['INDEX_NAME']);
			$record->addFieldsRaw(array(
				"INDEX_NAME" => $index["INDEX_NAME"],
				"TABLE_NAME" => $index["TABLE_NAME"],
				"COLUMN_NAMES" => $index["COLUMN_NAMES"],
				"BANNED" => $index["BANNED"],
			));
			$result[] = $record;
		}
		return $result;
	}

	public function getXmlId($id)
	{
		$arFilter = array("ID" => $id);
		$getList = \CPerfomanceIndexComplete::getList($arFilter);
		if ($index = $getList->fetch())
		{
			return $index['INDEX_NAME'];
		}
		return '';
	}

	public function update(Record $record)
	{
		if (!$this->isEqual($record, $record->getId()->getValue()))
		{
			$this->deleteInner($record);
			$this->createInner($record);
		}
	}

	/**
	 * @param \Intervolga\Migrato\Data\Record $record
	 * @param int $id
	 */
	protected function isEqual(Record $record, $id)
	{
		$currentFields = \CPerfomanceIndexComplete::getList(['ID' => $id])->fetch();
		$fields = $record->getFieldsRaw();

		return $fields['BANNED'] == $currentFields['BANNED']
			&& $fields['INDEX_NAME'] == $currentFields['INDEX_NAME']
			&& $fields['TABLE_NAME'] == $currentFields['TABLE_NAME']
			&& $fields['COLUMN_NAMES'] == $currentFields['COLUMN_NAMES']
			;
	}

	/**
	 * @param Record $record
	 * @return array
	 */
	protected function recordToArray(Record $record)
	{
		$array = array(
			'INDEX_NAME' => $record->getXmlId(),
			'TABLE_NAME' => $record->getFieldRaw('TABLE_NAME'),
			'COLUMN_NAMES' => $record->getFieldRaw('COLUMN_NAMES'),
			'BANNED' => $record->getFieldRaw('BANNED'),
		);
		return $array;
	}

	protected function createInner(Record $record)
	{
		global $DB;
		$data = $this->recordToArray($record);
		$table = new \CPerfomanceTable();
		$query = $table->getCreateIndexDDL($data["TABLE_NAME"], $data["INDEX_NAME"], [$data["COLUMN_NAMES"]]);
		$DB->db_Error = '';
		$indexCreateResult = $DB->query($query);
		if ($indexCreateResult)
		{
			$result = \CPerfomanceIndexComplete::add($data);
			if ($result)
			{
				return $this->createId($result);
			}
		}
		throw new \Exception(ExceptionText::getFromString($DB->db_Error));
	}

	protected function deleteInner(Record $record)
	{
		global $DB;
		$data = $this->recordToArray($record);
		$DB->query('ALTER TABLE ' . $data['TABLE_NAME'] . ' DROP INDEX ' . $data["INDEX_NAME"]);
		$arFilter = array("INDEX_NAME" => $data["INDEX_NAME"]);
		$getList = \CPerfomanceIndexComplete::getList($arFilter);
		if ($index = $getList->fetch())
		{
			\CPerfomanceIndexComplete::delete($index['ID']);
		}
	}
}