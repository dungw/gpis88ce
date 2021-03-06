<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;
use common\components\helpers\Show;

$attributeLabels = $model->attributeLabels();
?>

<div class="message-form">

    <?php $form = ActiveForm::begin(); ?>

    <?= Show::activeDropDownList($model, 'sensor_id', $attributeLabels, $sensors) ?>

    <?= $form->field($model, 'message_0')->textInput(['maxlength' => 255]) ?>

    <?= $form->field($model, 'message_1')->textInput(['maxlength' => 255]) ?>

    <?= Show::activeDropDownList($model, 'active', $attributeLabels, $model->_statusData) ?>

    <?= Show::submitButton($model) ?>

    <?php ActiveForm::end(); ?>

</div>
