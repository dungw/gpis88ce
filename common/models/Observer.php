<?php
namespace common\models;

use \Yii;
use yii\db\Query;
use yii\helpers\VarDumper;
use common\components\helpers\Convert;
use common\models\Station;
use common\models\SensorStatus;
use common\models\PowerEquipment;
use common\models\PowerStatus;
use common\models\DcEquipmentStatus;
use common\models\DcEquipment;
use common\models\StationStatus;
use common\models\Snapshot;
use common\models\Warning;
use common\models\Client;
use common\models\StationStatusHandler;

//turn off all error reporting
error_reporting(0);

class Observer {

	const FUNCTION_STATUS = 'status';
	const FUNCTION_ALARM = 'alarm';
	const BINARY_LENGTH = 10;

	// begin start equipments
	const MSG_BEGIN = 'Begin';

	// received directive from server succesful
	const MSG_OK = 'OK';

	// received invalid data from server
	const MSG_ERROR = 'ERROR';

	// security mode off
	const MSG_DISARM = 'DISARM';

	// security mode on
	const MSG_ARMING = 'ARMING';

	// request info
	public $request;

	// send back to device flag
	public $sendBack = false;

	// station status handler
	public $handler = [];

	// Main function: handle all request from station
	public function handleRequest($requestString, $peer = [])
	{

		if (!trim($requestString)) return 'Invalid request string';

		if (!$this->analyzeRequestString($requestString)) return 'Cannot handle request string';

		// do update station status
		if ($this->request['function'] == self::FUNCTION_STATUS) $this->update();

		// do alarm
		if ($this->request['function'] == self::FUNCTION_ALARM) $this->alarm();

		// update station status
		$this->updateStationStatus();

		// update ip & port of station
		$this->updateConnectAddress($peer);

		// after handle request
		return $this->afterHandle();
	}

	public function bindCommandSendBack()
	{

		// send back to client status of station was changed
		$client = new Client();
		$command = $client->bindCommandStatus($this->request['id']);

		return $command;
	}

	public function afterHandle()
	{
		return true;
	}

	public function alarm($message = null)
	{

		// insert warning
		$id = $this->insertWarning($message);

		// get station info
		$station = $this->findStation($this->request['id']);

		// snapshot info
		$snapUrl = $station['picture_url'];

		// take pictures and save to database
		$batch = [];
		$snapshot = new Snapshot();
		$snapshot->init($snapUrl);
		$pics = $snapshot->takes($station['picture_warning_numb']);
		if (!empty($pics))
		{
			foreach ($pics as $pic)
			{
				$batch[] = [$id, $pic, time()];
			}
			Yii::$app->db->createCommand()
				->batchInsert('warning_picture', ['warning_id', 'picture', 'created_at'], $batch)
				->execute();
		}
	}

	public function update()
	{
		$station = $this->findStation($this->request['id']);
		if (!$station) return 'Cannot find station';

		// get station status handler before update
		$this->getStationStatusHandler();

		// update sensor
		$this->updateSensor();

		// update equipment status with output status and configure status
		$this->updateEquipmentStatus();

		// update power status
		$this->updatePowerStatus();

		// update dc status
		$this->updateDcStatus();

	}

	public function getStationStatusHandler()
	{

		// set handler
		$handles = StationStatusHandler::find()
			->where(['station_id' => $this->request['id'], 'updated' => StationStatusHandler::STATUS_NOT_UPDATE])
			->orderBy('created_at DESC')
			->all();

		if (!empty($handles))
		{
			foreach ($handles as $hand)
			{
				if ($hand['type'] == StationStatusHandler::TYPE_EQUIPMENT)
				{
					$this->handler['equip'][] = [
						'equip_id'   => $hand['equip_id'],
						'status'     => $hand['status'],
						'configure'  => $hand['configure'],
						'station_id' => $hand['station_id']
					];
				}
				if ($hand['type'] == StationStatusHandler::TYPE_SENSOR_SECURITY)
				{
					$this->handler['security'] = [
						'equip_id'   => Sensor::ID_SECURITY,
						'status'     => $hand['status'],
						'station_id' => $hand['station_id']
					];
				}
			}
		}
	}

	public function updateEquipmentStatus()
	{

		$outputBin = Convert::powOf2($this->request['output_status']);
		$configureBin = Convert::powOf2($this->request['configure_status']);

		// get all equipment status of this station
		$query = new Query();
		$equips = $query->select('e.binary_pos, es.id, es.equipment_id')
			->from('equipment_status es')
			->leftJoin('equipment e', 'e.id = es.equipment_id')
			->where(['station_id' => $this->request['id']])
			->all();

		if (!empty($equips))
		{
			foreach ($equips as $eq)
			{

				// device status
				$deviceStatus = in_array($eq['binary_pos'], $outputBin) ? 1 : 0;
				$deviceConfigure = in_array($eq['binary_pos'], $configureBin) ? 1 : 0;

				// value will be update
				$updatedStatus = $deviceStatus;
				$updatedConfigure = $deviceConfigure;

				if (!empty($this->handler['equip']))
				{
					foreach ($this->handler['equip'] as $handler)
					{

						if ($handler['equip_id'] == $eq['equipment_id'])
						{

							// handler status
							$handlerStatus = $handler['status'];
							$handlerConfigure = $handler['configure'];

							// if value of handler different with device
							if ($deviceStatus != $handlerStatus)
							{
								$updatedStatus = $handlerStatus;
								$this->sendBack = true;
							}
							if ($deviceConfigure != $handlerConfigure)
							{
								$updatedConfigure = $handlerConfigure;
								$this->sendBack = true;
							}
						}
					}
				}

				$eq['binary_pos'] = intval($eq['binary_pos']);
				Yii::$app->db->createCommand()
					->update('equipment_status', [
						'status'    => $updatedStatus,
						'configure' => $updatedConfigure,
					], ['id' => $eq['id']])->execute();
			}
		}
	}

	public function insertWarning($message = null)
	{
		$warning = new Warning();

		$warning->station_id = $this->request['id'];
		if ($message)
		{
			$warning->message = $message;
		} else
		{
			$warning->message = $this->request['message'];
		}

		$warning->warning_time = time();
		if ($warning->validate())
		{
			$warning->save();
		} else
		{
			var_dump($warning->getErrors());
			die;
		}

		return Yii::$app->db->lastInsertID;
	}

	public function updateStationStatus()
	{
		$model = new StationStatus();
		$model->station_id = $this->request['id'];
		$model->request_string = $this->request['data'];
		$model->time_update = time();
		$model->save();
	}

	public function updateConnectAddress($peer)
	{
		if (isset($peer['ip']))
		{
			Yii::$app->db->createCommand()
				->update('station', ['ip' => $peer['ip']], ['id' => $this->request['id']])
				->execute();
		}

		return true;
	}

	public function updateDcStatus()
	{

		// get all dc equipment of this station
		$dcEquips = DcEquipmentStatus::findAll(['station_id' => $this->request['id']]);
		if (!empty($dcEquips))
		{
			foreach ($dcEquips as $equip)
			{
				$value = [];

				// if this is dc 1
				if ($equip['equipment_id'] == DcEquipment::ID_DC1)
				{
					$value = [
						'voltage'  => $this->request['dc1_voltage'],
						'amperage' => $this->request['dc1_ampe'],
					];
				}

				// if this is dc 2
				if ($equip['equipment_id'] == DcEquipment::ID_DC2)
				{
					$value = [
						'voltage'  => $this->request['dc2_voltage'],
						'amperage' => $this->request['dc2_ampe'],
					];
				}

				Yii::$app->db->createCommand()
					->update('dc_equipment_status', $value, ['id' => $equip['id']])
					->execute();
			}
		}
	}

	public function updatePowerStatus()
	{

		// get all power equipments of this station
		$powerEquips = PowerStatus::findAll(['station_id' => $this->request['id']]);
		if (!empty($powerEquips))
		{
			$i = 0;
			foreach ($powerEquips as $equip)
			{
				$value = $this->request['power'][$i];

				Yii::$app->db->createCommand()
					->update('power_status', ['status' => $value], ['id' => $equip['id']])
					->execute();
				$i++;
			}
		}
	}

	/**
	 * update sensor status with input status
	 * - update temperature
	 * - update humidity
	 * - update security mode
	 * - update all sensor equipment
	 */
	public function updateSensor()
	{
		$inputBin = Convert::powOf2($this->request['input_status']);

		// get all sensor status of this station
		$query = new Query();
		$query->select('s.binary_pos, s.type, st.id, st.sensor_id, st.value');
		$query->from('sensor_status st');
		$query->leftJoin('sensor s', 'st.sensor_id = s.id');
		$query->where('station_id = ' . $this->request['id']);

		$sensors = $query->all();

		if (!empty($sensors))
		{
			foreach ($sensors as $sensor)
			{
				$vs = in_array($sensor['binary_pos'], $inputBin) ? 1 : 0;
				if ($sensor['type'] == Sensor::TYPE_CONFIGURE)
				{
					Yii::$app->db->createCommand()
						->update('sensor_status', ['value' => $vs], ['id' => $sensor['id']])
						->execute();
				} else if ($sensor['type'] == Sensor::TYPE_VALUE)
				{
					$value = '';

					// if this is security mode
					if ($sensor['sensor_id'] == Sensor::ID_SECURITY)
					{

						// security mode handler
						if ($this->request['message'] == self::MSG_ARMING) $value = 1;
						if ($this->request['message'] == self::MSG_DISARM) $value = 0;

						// compare with handler status
						if (isset($this->handler['security']['status']) && $value != $this->handler['security']['status'])
						{
							$value = $this->handler['security']['status'];
							$this->sendBack = true;
						}

						// if security has been turn off, create an alarm
						if ($sensor['value'] != $value)
						{
							if ($value == 0)
							{
								// create turn off security mode alarm
								$message = 'Tat bao dong';
							} elseif ($value == 1)
							{
								// create turn on security mode alarm
								$message = 'Bat bao dong';
							}
							$this->alarm($message);
						}
					}

					// if this is temperature
					if ($sensor['sensor_id'] == Sensor::ID_TEMPERATURE)
					{
						$value = $this->request['temp'];
					}

					// if this is humidity
					if ($sensor['sensor_id'] == Sensor::ID_HUMIDITY)
					{
						$value = $this->request['humi'];
					}

					Yii::$app->db->createCommand()
						->update('sensor_status', ['value' => $value], ['id' => $sensor['id']])
						->execute();
				}
			}
		}
	}

	public function analyzeRequestString($requestString)
	{
		$temp = explode('&', $requestString);
		if (!empty($temp))
		{
			$last = count($temp) - 1;
			$this->request['data'] = $requestString;
			$this->request['id'] = $this->findStationId(trim($temp[0]));

			if ($this->request['id'] <= 0)
			{
				return false;
			}

			$this->request['name'] = $temp[1];
			$this->request['function'] = $temp[2];
			$this->request['message'] = $temp[3];
			$this->request['input_status'] = isset($temp[4]) ? $temp[4] : '';
			$this->request['output_status'] = isset($temp[5]) ? $temp[5] : '';
			$this->request['configure_status'] = isset($temp[6]) ? $temp[6] : '';
			$this->request['temp'] = isset($temp[7]) ? $temp[7] : '';
			$this->request['humi'] = isset($temp[8]) ? $temp[8] : '';

			//power equipments
			for ($i = 9; $i <= ($last-4); $i++)
			{
				$this->request['power'][] = isset($temp[$i]) ? $temp[$i] : '';
			}

			//DC
			$this->request['dc1_voltage'] = isset($temp[$last-3]) ? $temp[$last-3] : '';
			$this->request['dc2_voltage'] = isset($temp[$last-2]) ? $temp[$last-2] : '';
			$this->request['dc1_ampe'] = isset($temp[$last-1]) ? $temp[$last-1] : '';
			$this->request['dc2_ampe'] = isset($temp[$last]) ? $temp[$last] : '';

			return true;
		} else
		{
			return false;
		}
	}

	public function findStationId($code)
	{
		$station = Station::findOne(['code' => $code]);
		if (!$station) return false;

		return $station['id'];
	}

	public function findStation($id)
	{
		$station = Station::findOne($id);
		if (!$station) return false;

		return $station;
	}
}