<?php
/*************************************************************************************************
 * Copyright 2019 Spike Associates -- This file is a part of coreBOS customizations.
 * You can copy, adapt and distribute the work under the "Attribution-NonCommercial-ShareAlike"
 * Vizsage Public License (the "License"). You may not use this file except in compliance with the
 * License. Roughly speaking, non-commercial users may share and modify this code, but must give credit
 * and share improvements. However, for proper details please read the full License, available at
 * http://vizsage.com/license/Vizsage-License-BY-NC-SA.html and the handy reference for understanding
 * the full license at http://vizsage.com/license/Vizsage-Deed-BY-NC-SA.html. Unless required by
 * applicable law or agreed to in writing, any software distributed under the License is distributed
 * on an  "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and limitations under the
 * License terms of Creative Commons Attribution-NonCommercial-ShareAlike 3.0 (the License).
 *************************************************************************************************
 *  Module       : Process Flow Alert Queue
 *  Version      : 5.4.0
 *  Author       : AT CONSULTING
 *************************************************************************************************/
$Vtiger_Utils_Log = false;
include_once 'vtlib/Vtiger/Module.php';
include_once 'include/Webservices/ExecuteWorkflow.php';
global $adb, $default_timezone, $current_user;

$admin = Users::getActiveAdminUser();
$adminTimeZone = $admin->time_zone;
@date_default_timezone_set($adminTimeZone);
$currentTimestamp = date('Y-m-d H:i:s');
@date_default_timezone_set($default_timezone);
$rsa = $adb->pquery(
	"select context, schtypeid, schtime, schdayofmonth, schdayofweek, schannualdates, schminuteinterval, crmid, alertid
	from vtiger_cbprocessalertqueue
	inner join vtiger_cbprocessalert on cbprocessalertid=alertid
	where nexttrigger_time='' OR nexttrigger_time IS NULL OR nexttrigger_time<=?",
	array($currentTimestamp)
);

$wf = new Workflow();
while ($alert=$adb->fetch_array($rsa)) {
	// do batch control here
	// process context map
	$context = array();
	$moduleName = getSalesEntityType($alert['crmid']);
	if (!empty($alert['context'])) {
		$cbfrom = CRMEntity::getInstance($moduleName);
		$cbfrom->retrieve_entity_info($alert['crmid'], $moduleName);
		$cbMap = cbMap::getMapByID($alert['context']);
		$context = $cbMap->Mapping($cbfrom->column_fields, $context);
	}
	// WFs
	$wfrs = $adb->pquery(
		'SELECT workflow_id
		FROM com_vtiger_workflows
		INNER JOIN vtiger_crmentityrel ON (vtiger_crmentityrel.relcrmid = workflow_id OR vtiger_crmentityrel.crmid = workflow_id)
		WHERE (vtiger_crmentityrel.crmid = ? OR vtiger_crmentityrel.relcrmid = ?)',
		array($alert['alertid'], $alert['alertid'])
	);
	while ($workflow=$adb->fetch_array($wfrs)) {
		if (strpos($alert['crmid'], 'x')===false) {
			$wsid = vtws_getEntityId($moduleName).'x'.$alert['crmid'];
		} else {
			$wsid = $alert['crmid'];
		}
		cbwsExecuteWorkflowWithContext($workflow['workflow_id'], $wsid, $context, $current_user);
	}
	// next trigger
	$alert['workflow_id'] = 0;
	$alert['module_name'] = $moduleName;
	$alert['summary'] = '';
	$alert['test'] = '';
	$alert['execution_condition'] = '';
	$alert['defaultworkflow'] = false;
	$wf->setup($alert);
	$next = $wf->getNextTriggerTime();
	if (empty($next)) {
		$adb->pquery('delete from vtiger_cbprocessalertqueue where crmid=? and alertid=?', array($alert['crmid'], $alert['alertid']));
	} else {
		$adb->pquery('update vtiger_cbprocessalertqueue set nexttrigger_time=? where crmid=? and alertid=?', array($next, $alert['crmid'], $alert['alertid']));
	}
}
unset($wf);
?>