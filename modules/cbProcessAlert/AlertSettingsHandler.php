<?php
/*************************************************************************************************
 * Copyright 2019 JPL TSolucio, S.L. -- This file is a part of TSOLUCIO coreBOS Customizations.
 * Licensed under the vtiger CRM Public License Version 1.1 (the "License"); you may not use this
 * file except in compliance with the License. You can redistribute it and/or modify it
 * under the terms of the License. JPL TSolucio, S.L. reserves all rights not expressly
 * granted by the License. coreBOS distributed by JPL TSolucio S.L. is distributed in
 * the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. Unless required by
 * applicable law or agreed to in writing, software distributed under the License is
 * distributed on an "AS IS" BASIS, WITHOUT ANY WARRANTIES OR CONDITIONS OF ANY KIND,
 * either express or implied. See the License for the specific language governing
 * permissions and limitations under the License. You may obtain a copy of the License
 * at <http://corebos.org/documentation/doku.php?id=en:devel:vpl11>
 *************************************************************************************************/

class cbProcessAlertSettingsHandler extends VTEventHandler {

	public function handleEvent($handlerType, $entityData) {
		global $adb, $log;
		$log->debug('> Process Alert After Save');
		$moduleName = $entityData->getModuleName();
		$rs = $adb->pquery(
			'select cbprocessflowid, pffield, pfcondition
			from vtiger_cbprocessflow
			inner join vtiger_crmentity on crmid=cbprocessflowid
			where deleted=0 and pfmodule=? and active=?',
			array($moduleName, '1')
		);
		if ($rs && $adb->num_rows($rs)>0) {
			$crmid = $entityData->getId();
			$entityDelta = new VTEntityDelta();
			$pffield = $adb->query_result($rs, 0, 'pffield');
			$hasChanged = $entityDelta->hasChanged($moduleName, $crmid, $pffield);
			if ($hasChanged || $entityData->isNew()) {
				$pfcondition = $adb->query_result($rs, 0, 'pfcondition');
				if (empty($pfcondition) || coreBOS_Rule::evaluate($pfcondition, $crmid)) {
					$val = $entityData->get($pffield);
					$rsa = $adb->pquery(
						'select *
						from vtiger_cbprocessalert
						inner join vtiger_crmentity on crmid=cbprocessalertid
						where deleted=0 and processflow=? and active=? and whilein=?',
						array($adb->query_result($rs, 0, 'cbprocessflowid'), '1', $val)
					);
					if ($rsa && $adb->num_rows($rsa)>0) {
						// calculate next trigger time
						$wf = new Workflow();
						$row = $rsa->fields;
						$row['workflow_id'] = 0;
						$row['module_name'] = $moduleName;
						$row['summary'] = '';
						$row['test'] = '';
						$row['execution_condition'] = '';
						$row['defaultworkflow'] = false;
						$wf->setup($row);
						$next = $wf->getNextTriggerTime();
						// insert into queue
						$checkpresence = $adb->pquery(
							'SELECT 1 FROM vtiger_cbprocessalertqueue WHERE crmid=? AND alertid=?',
							array($crmid, $adb->query_result($rsa, 0, 'cbprocessalertid'))
						);
						if ($checkpresence && $adb->num_rows($checkpresence)==0) {
							$adb->pquery(
								'insert into vtiger_cbprocessalertqueue (crmid, whenarrived, alertid, nexttrigger_time) values (?,NOW(),?,?)',
								array($crmid, $adb->query_result($rsa, 0, 'cbprocessalertid'), $next)
							);
						}
					}
				}
			}
		}
		$log->debug('< Process Alert After Save');
	}
}
