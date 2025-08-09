<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/local/modules/dev.site/include.php';
AddEventHandler('dev.site', 'OnAfterIBlockElementAdd', Array("Iblock", "addLog"));
AddEventHandler('dev.site', 'OnAfterIBlockElementUpdate', Array("Iblock", "addLog"));

if (\Bitrix\Main\Loader::includeModule('dev.site')) {
    $agentName = '\Only\Site\Agents\Iblock::clearOldLogs();';

    $rsAgent = \CAgent::GetList([], ['NAME' => $agentName]);
    if (!$rsAgent->Fetch()) {
        \CAgent::Add([
            'NAME' => $agentName,
            'MODULE_ID' => 'dev.site',
            'ACTIVE' => 'Y',
            'NEXT_EXEC' => ConvertTimeStamp(time() + 60, 'FULL'),
            'AGENT_INTERVAL' => 86400,
            'IS_PERIOD' => 'Y'
        ]);
    }
}
?>
