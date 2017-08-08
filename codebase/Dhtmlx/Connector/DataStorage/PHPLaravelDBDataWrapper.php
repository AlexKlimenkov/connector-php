<?php
namespace Dhtmlx\Connector\DataStorage;
use Dhtmlx\Connector\DataProcessor\DataProcessor;
use \Exception;

class PHPLaravelDBDataWrapper extends ArrayDBDataWrapper {

	public function select($source) {
		$sourceData = $source->get_source();
		if(is_array($sourceData))	//result of find
			$res = $sourceData;
		else if ($sourceData){
			if (is_string($sourceData))
				$sourceData = new $sourceData();

			if(is_a($sourceData,  "Illuminate\\Database\\Eloquent\\Model")){
				$sourceData = $sourceData->newQuery();
			}

			$sourceData = $this->apply_sorts($source, $sourceData);

			if (is_a($sourceData, "Illuminate\\Database\\Eloquent\\Collection")){
				$res = $sourceData->toArray();
			} else if (is_a($sourceData, "Illuminate\\Database\\Eloquent\\Builder")){
				$res = $sourceData->get();
			} else {
				$res = $sourceData->all();
			}
		}

		if (!is_array($res)) 
			$res = $res->toArray();

		return new ArrayQueryWrapper($res);
	}

	private function apply_sorts($source, $dataset){
		$sorts = $source->get_sort_by();

		if(count($sorts) && (method_exists($dataset, "orderBy") || (get_class($dataset) == "Illuminate\Database\Eloquent\Builder"))){
			foreach($sorts as $rule){
				$dataset = $dataset->orderBy($rule);
			}
		}
		return $dataset;
	}

	protected function getErrorMessage() {
		$errors = $this->connection->getErrors();
		$text = array();
		foreach($errors as $key => $value)
			$text[] = $key." - ".$value[0];

		return implode("\n", $text);
	}

	public function insert($data, $source) {
		$obj = null;
		if(method_exists($source->get_source(), 'getModel')){
			$obj = $source->get_source()->getModel()->newInstance();
		} else {
			$className = get_class($source->get_source());
			$obj = new $className();
		}

		$this->fill_model($obj, $data)->save();

		$fieldPrimaryKey = $this->config->id["db_name"];
		$data->success($obj->$fieldPrimaryKey);
	}

	public function delete($data, $source) {
		if(method_exists($source->get_source(), 'getModel')){
			$source->get_source()->getModel()->find($data->get_id())->delete();
		} else {
			$className = get_class($source->get_source());
			$className::destroy($data->get_id());
		}
		$data->success();
	}

	public function update($data, $source) {
		$obj = null;
		if(method_exists($source->get_source(), 'getModel')){
			$obj = $source->get_source()->getModel()->find($data->get_id());
		} else {
			$className = get_class($source->get_source());
			$obj = $className::find($data->get_id());
		}

		$this->fill_model($obj, $data)->save();
		$data->success();
	}

	private function fill_model($obj, $data) {
		$dataArray = $data->get_data();
		unset($dataArray[DataProcessor::$action_param]);
		unset($dataArray[$this->config->id["db_name"]]);

		$columnMap = [];
		foreach($this->config->text as $mapping){
			$columnMap[$mapping['name']] = $mapping['db_name'];
		}


		foreach($dataArray as $key => $value){
			if(isset($columnMap[$key])){
				$obj->$columnMap[$key] = $value;
			}
		}

		return $obj;
	}

	public function new_record_order($action, $source)
	{
		$order = $source->get_order();

		if(method_exists($source->get_source(), 'getModel')){
			$model = $source->get_source()->getModel()->newInstance();
		} else {
			$className = get_class($source->get_source());
			$model = new $className();
		}

		if ($order) {
			$id = $this->config->id["db_name"];

			$idvalue = $action->get_new_id();
			$max = $model->selectRaw("MAX($order) as dhx_maxvalue")->first();

			$dhx_maxvalue = $max["dhx_maxvalue"] + 1;

			$model->where($id, '=', $idvalue)
				->update(array($order =>($dhx_maxvalue)));
		}
	}

	public function order($data, $source)
	{
		if(method_exists($source->get_source(), 'getModel')){
			$model = $source->get_source()->getModel()->newInstance();
		} else {
			$className = get_class($source->get_source());
			$model = new $className();
		}


		//id of moved item
		$id1 = $data->get_value("id");
		//id of target item
		$target = $data->get_value("target");

		$dropnext = false;
		if (strpos($target, "next:") !== false) {
			$dropnext = true;
			$id2 = str_replace("next:", "", $target);
		} else {
			$id2 = $target;
		}

		$parentColumn = $this->config->relation_id["db_name"];

		$orderColumn = $source->get_order();
		$idColumn = $this->config->id["db_name"];

		$source = $model->find($data->get_id());
		$source_index = $source[$orderColumn] ? $source[$orderColumn] : 0;

		$newParentValue =  $data->get_value($this->config->relation_id["name"]);

		if($newParentValue) {
			$model->where($parentColumn, '=', $source[$parentColumn])
				->where($orderColumn, ">=", $source_index)
				->decrement($orderColumn);
		}

		if ($id2 !== "") {
			$target = $model->find($id2);

			$target_index = $target[$orderColumn];
			if (!$target_index)
				$target_index = 0;
			if ($dropnext)
				$target_index += 1;

			$query = $model->where($orderColumn, '>=', $target_index);
			if($parentColumn){
				$query = $query->where($parentColumn, "=", $newParentValue);
			}

			$query->increment($orderColumn);

		} else {

			$target = $model->selectRaw("MAX($orderColumn) as dhx_index")
				->first();

			$target_index = ($target[$orderColumn] ? $target[$orderColumn] : 0) + 1;
		}

		$query = $model->find($id1);
		if($parentColumn){
			$query = $query->update($parentColumn, '=', $newParentValue);
		}
		$query->update(array($orderColumn => $target_index));
	}


	protected function errors_to_string($errors) {
		$text = array();
		foreach($errors as $value)
			$text[] = implode("\n", $value);

		return implode("\n",$text);
	}

	public function escape($str) {
		throw new Exception("Not implemented");
	}

	public function query($str) {
		throw new Exception("Not implemented");
	}

	public function get_new_id() {
		throw new Exception("Not implemented");
	}

}
