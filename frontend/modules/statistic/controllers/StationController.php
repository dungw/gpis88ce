<?php
namespace frontend\modules\statistic\controllers;

use \Yii;
use yii\db\Query;
use common\controllers\BaseController;
use common\components\helpers\Convert;
use common\models\Station;
use common\models\Sensor;
use common\models\Area;
use common\models\Role;

class StationController extends BaseController {

    public $layout = '//main';

    public function actionIndex() {

        // default time duration
        $timePoints = Convert::currentTimePoints();
        $getBy = Yii::$app->request->get('get_by');
        if ($getBy == 'week') {
            $timePoints = Convert::currentWeekTimePoints();
        } else if ($getBy == 'month') {
            $timePoints = Convert::currentMonthTimePoints();
        }

        $whereClause = ['sst.sensor_id' => Sensor::ID_SECURITY];

        // get station ids by role
        $role = new Role();
        if (!$role->isAdministrator) {
            $position = $role->getPosition();
            $stationIds = Station::getByRole($position, Yii::$app->user->id);
            $whereClause['s.id'] = $stationIds;
        }

        // get data
        $query = new Query();
        $stations = $query->select('s.area_id, s.status AS station_status, sst.value AS sensor_value, count(w.id) AS total_warning, max(w.warning_time) AS last_warning_time')
            ->from('station s')
            ->leftJoin('sensor_status sst', 'sst.station_id = s.id')
            ->leftJoin('warning w', 'w.station_id = s.id')
            ->where($whereClause)
            ->groupBy('s.id')
            ->all();

        // data
        $parseData = [];
        $data = [];

        // get areas
        $areas = Area::find()->all();
        $parseData['areas'] = $areas;
        if (!empty($areas)) {
            $notHave = [];
            foreach ($areas as $area) {
                $have = 0;

                $data[$area['id']]['has_warning'] = 0;
                $data[$area['id']]['no_warning'] = 0;
                $data[$area['id']]['security_on'] = 0;
                $data[$area['id']]['security_off'] = 0;
                $data[$area['id']]['connected'] = 0;
                $data[$area['id']]['lost_connect'] = 0;
                $data[$area['id']]['last_warning_time'] = 0;

                // security and connect stations
                if (!empty($stations)) {
                    foreach ($stations as $station) {
                        if ($station['area_id'] == $area['id']) {
                            $have = 1;

                            // warnings
                            if ($station['total_warning'] > 0 && $station['last_warning_time'] >= $timePoints['start'] && $station['last_warning_time'] <= $timePoints['end']) {
                                $data[$area['id']]['has_warning']++;
                                $data[$area['id']]['last_warning_time'] = ($station['last_warning_time'] > $data[$area['id']]['last_warning_time']) ? $station['last_warning_time'] : $data[$area['id']]['last_warning_time'];
                            } else {
                                $data[$area['id']]['no_warning']++;
                            }

                            // status
                            if ($station['station_status'] == Station::STATUS_CONNECTED) {
                                $data[$area['id']]['connected']++;
                            } else if ($station['station_status'] == Station::STATUS_LOST) {
                                $data[$area['id']]['lost_connect']++;
                            }

                            // security mode
                            if ($station['sensor_value'] == Sensor::SECURITY_ON) {
                                $data[$area['id']]['security_on']++;
                            } else if ($station['sensor_value'] == Sensor::SECURITY_OFF) {
                                $data[$area['id']]['security_off']++;
                            }

                        }

                    }
                }

                if ($have == 0) $notHave[] = $area['id'];
            }

            if (!empty($notHave)) {
                foreach ($notHave as $not) {
                    unset($data[$not]);
                }
            }

        }

        $parseData['data'] = $data;

        return $this->render('station', $parseData);
    }

}
