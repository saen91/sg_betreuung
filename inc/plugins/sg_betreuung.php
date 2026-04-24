<?php
//Fehler einblenden, nur "einfügen" wenn auch wirklich nötig
//error_reporting ( -1 );
//ini_set ( 'display_errors', true );

// Direktzugriff auf die Datei aus Sicherheitsgründen sperren
if (!defined("IN_MYBB")) {
  die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

//Hooks, das Plugin führt das was man schreibt auch genau dort aus. Geht auch am Anfang der Datei 
//https://docs.mybb.com/1.8/development/plugins/hooks/

$plugins->add_hook('admin_config_settings_change', 'sg_betreuung_settings_change'); //Ausklappbaren Einstellungen
$plugins->add_hook('admin_settings_print_peekers', 'sg_betreuung_settings_peek'); //Ausklappbaren Einstellungen
$plugins->add_hook("forumdisplay_thread", "sg_betreuung_showforen"); //Zum Darstellen in der Forendisplay
$plugins->add_hook("forumdisplay_threadlist", "sg_betreuung_showforen");
$plugins->add_hook("misc_start", "sg_betreuung_misc"); //was passiert, wenn Button gedrückt wird
$plugins->add_hook('showthread_start', 'sg_betreuung_showthread'); //anzeige im Thema
$plugins->add_hook("modcp_start", "sg_betreuung_modcp"); //Für die Anzeige von allen Themen übers MODCP 
$plugins->add_hook("datahandler_post_insert_post_end", "sg_betreuung_alert_newreply"); //der Hook, damit die Alerts bei neuen Antworten gehen

//Das hässliche MyAlerts-Hook Zeug hinzufügen
if(class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
	$plugins->add_hook("global_start", "sg_betreuung_myalerts");
}

//Infos für das Plugin 
function sg_betreuung_info()
{
  return array(
    "name" => "Betreuungs Plugin",
    "description" => "Ermöglicht das Betreuen oder'Beobachten' von Themen.",
    "author" => "saen",
    "authorsite"	=> "https://github.com/saen91",
    "version" => "1.0",
    "compatibility" => "18*"
  );
}


function sg_betreuung_settings_change(){
    global $db, $mybb, $sg_betreuung_settings_peeker;

    $result = $db->simple_select('settinggroups', 'gid', "name='sg_betreuung'", array("limit" => 1));
    $group = $db->fetch_array($result);
    $sg_betreuung_settings_peeker = ($mybb->input['gid'] == $group['gid']) && ($mybb->request_method != 'post');
}

//in setting_sg_yes kommt der Name der Einstellung, wo etwas ausgewählt werden muss damit etwas anderes erscheint
//in row setting kommt der Name der Einstellung, die erscheinen soll
//,/1/,true kommt das rein, was vorher ausgewählt werden muss 1= ja, 0=nein
function sg_betreuung_settings_peek(&$peekers){
    global $mybb, $sg_betreuung_settings_peeker;
    if ($sg_betreuung_settings_peeker) {
        $peekers[] = 'new Peeker($(".setting_sg_betreuung_yes"), $("#row_setting_sg_betreuung_name"),/1/,true)';
    }
	
}

// Diese Funktion installiert das Plugin

function sg_betreuung_install()
{
    global $db, $cache, $mybb;

    //Wir legen jetzt eine Tabelle in der DB an
    $db->write_query("
    CREATE TABLE " . TABLE_PREFIX . "sg_betreuung (
        `bid` int(11) NOT NULL auto_increment,
        `tid` int(11) NOT NULL,
        `uid` int(11) NOT NULL,
        `last_seen` int(11) NOT NULL default 0,
        PRIMARY KEY (`bid`)
    )
        ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci AUTO_INCREMENT=1
    ");

    //Einstellung hinzufügen 
    //Gruppe erstellen 
    $setting_group = array(
        'name' => 'sg_betreuung',
        'title' => 'Betreuen/Beobachten vom Thema',
        'description' => 'Einstellungen für das Plugin zum Betreuen/Beobachten von Themen für Admins',
        'disporder' => 7, // The order your setting group will display
        'isdefault' => 0
    );
    $gid = $db->insert_query("settinggroups", $setting_group);


    //Die dazugehörigen Einstellungen 
    $setting_array = array(

        //Einstellung welche Gruppe Button klicken kann und somit betreuen/beobachten
        'sg_betreuung_groups' => array(
            'title' => 'Gruppen',
            'description' => 'Welche Gruppen soll es möglich sein zu Betreuen/Beobachten?',
            'optionscode' => 'groupselect',
            'value' => '2,4',
            'disporder' => 1
        ),

        //In welchen Foren soll die Anzeige gemacht werden?
        'sg_betreuung_foren' => array (
            'title' => 'Auswahl der Foren',
            'description' => 'In welchen Foren soll die Anzeige zu sehen sein?',
            'optionscode' => 'forumselect',
            'value' => -1,
            'disporder' => 2
        ),

        //Soll im Thema und in der Forenübersicht angezeigt werden, wer betreut/beobachtet?
        'sg_betreuung_yes' => array (
            'title' => 'Anzeige im Thema/Forenübersicht',
            'description' => 'Soll im Thema/Forenübersicht angezeigt werden, wer betreut/beobachtet?',
            'optionscode' => 'yesno',
            'value' => '1',
            'disporder' => 23
        ),

        //Was soll im Thread erscheinen? 
        'sg_betreuung_name' => array(
            'title' => 'Bezeichnung',
            'description' => 'Gib hier die Bezeichnung an, was die User im Thread sehen sollen. ',
            'optionscode' => 'text',
            'value' => 'betreut von: ',
            'disporder' => 4
        ),

    );

    //Einstellungen hinzufügen 
    foreach ($setting_array as $name => $setting)
	    {
		$setting['name'] = $name;
		$setting['gid'] = $gid;

		$db->insert_query('settings', $setting);
		}

	rebuild_settings();


    //Template hinzufügen -> sid sagt ob global oder Gruppe 1 -> Global -2 -> Gruppe

    //Gruppe anlegen
    $templategrouparray = array(
        'prefix' => 'betreuung',
        'title'  => $db->escape_string('Betreuung von Themen'),
        'isdefault' => 1
    );
    $db->insert_query("templategroups", $templategrouparray);

    $gid = $db->insert_id();


    //Template für die Darstellung im showthread
     $insert_array = array(
        'title' => 'betreuung_box',
        'template' => $db->escape_string(' {$button}
    {$info}'),
        'sid' => '-2',
        'version' => '',
        'dateline' => TIME_NOW
    );
    $db->insert_query("templates", $insert_array); //hinter jedes einzelne Template

    //Template für button add
     $insert_array = array(
        'title' => 'betreuung_button_add',
        'template' => $db->escape_string('<a href="misc.php?action=sg_betreuung_add&amp;tid={$thread[\'tid\']}&amp;my_post_key={$mybb->post_code}" class="button small_button">
            Betreuen
        </a>'),
        'sid' => '-2',
        'version' => '',
        'dateline' => TIME_NOW
    );
    $db->insert_query("templates", $insert_array); //hinter jedes einzelne Template

    //Template für button weg
     $insert_array = array(
        'title' => 'betreuung_button_remove',
        'template' => $db->escape_string('<a href="misc.php?action=sg_betreuung_remove&amp;tid={$thread[\'tid\']}&amp;my_post_key={$mybb->post_code}" class="button small_button">
    Nicht mehr betreuen
</a>'),
        'sid' => '-2',
        'version' => '',
        'dateline' => TIME_NOW
    );
    $db->insert_query("templates", $insert_array); //hinter jedes einzelne Template

    //Template für den Namen
     $insert_array = array(
        'title' => 'betreuung_name',
        'template' => $db->escape_string('{$looked_text}'),
        'sid' => '-2',
        'version' => '',
        'dateline' => TIME_NOW
    );
    $db->insert_query("templates", $insert_array); //hinter jedes einzelne Template

    //Template für die Ansicht im MOD CP
     $insert_array = array(
        'title' => 'betreuung_threads',
        'template' => $db->escape_string('<html>
            <head>
                <title>{$mybb->settings[\'bbname\']} - Betreute/Beobachtete Themen</title>
                    {$headerinclude}
            </head>
            <body>
            {$header}
                <table width="100%" border="0" align="center">
                    <tr>
                        <td valign="top">
                            <table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
                                <tr>
                                    <td class="thead" colspan="{$colspan}"><strong>Betreute/Beobachtete Themen </strong></td>
                                </tr>
                                <tr>
                                    <td class="trow2">
                                        <h1>Eigene Themen</h1>	
                                        <table width="100%">
                                            <tr>
                                                <td width="30%"><h2>Thema</h2></td>
                                                <td width="20%"><h2>in welchem Forum</h2></td>
                                                <td width="30%"><h2>beobachtet von</h2></td>
                                                <td width="10%"><h2>Letzter Post am</h2></td>
                                                <td width="10%"><h2>Abgeben?</h2></td>
                                            </tr>
                                                {$ownthreads_bit}									
                                        </table>
                                        
                                        <h1>Alle Themen von anderen</h1>
                                        <table width="100%">
                                            <tr>
                                                <td width="30%"><h2>Thema</h2></td>
                                                <td width="20%"><h2>in welchem Forum</h2></td>
                                                <td width="30%"><h2>beobachtet von</h2></td>
                                                <td width="20%"><h2>Letzter Post am</h2></td>
                                            </tr>
                                                {$allthreads_bit}									
                                        </table>
                                        
                                        <h1>User darf nicht mehr betreuen</h1>
                                        <table width="100%">
                                            <tr>
                                                <td width="30%"><h2>Thema</h2></td>
                                                <td width="20%"><h2>in welchem Forum</h2></td>
                                                <td width="30%"><h2>beobachtet von</h2></td>
                                                <td width="10%"><h2>User von Thema trennen</h2></td>
                                                <td width="10%"><h2>übernehmen?</h2></td>
                                        </tr>
                                            {$notthreads_bit}
                                            </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                {$footer}
            </body>
        </html>'),
        'sid' => '-2',
        'version' => '',
        'dateline' => TIME_NOW
    );
    $db->insert_query("templates", $insert_array); //hinter jedes einzelne Template

    //Template für die eigenen 
    $insert_array = array(
        'title' => 'betreuung_threads_own',
        'template' => $db->escape_string('<tr>
            <td width="30%"><a href="{$threadlink}">{$subject}</a></td>
            <td width="20%">{$forumname}</td>
            <td width="30%">{$looked_text}</td>
            <td width="15%">{$lastpost}</td>
            <td width="5%"><center><a href="misc.php?action=sg_betreuung_remove&amp;tid={$tid}&amp;my_post_key={$post_key}"><i class="fa-solid fa-right-from-bracket"></i></a></center></td>
        </tr>'),
        'sid' => '-2',
        'version' => '',
        'dateline' => TIME_NOW
    );
    $db->insert_query("templates", $insert_array); //hinter jedes einzelne Template

    //Template für alle
    $insert_array = array(
        'title' => 'betreuung_threads_all',
        'template' => $db->escape_string('<tr>
            <td width="30%"><a href="{$threadlink}">{$subject}</a></td>
            <td width="20%">{$forumname}</td>
            <td width="30%">{$looked_text}</td>
            <td width="20%">{$lastpost}</td>
        </tr>'),
        'sid' => '-2',
        'version' => '',
        'dateline' => TIME_NOW
    );
    $db->insert_query("templates", $insert_array); //hinter jedes einzelne Template

    //Template für alte
    $insert_array = array(
        'title' => 'betreuung_notthreads_bit',
        'template' => $db->escape_string('<tr>
            <td width="30%"><a href="{$threadlink}">{$subject}</a></td>
            <td width="20%">{$forumname}</td>
            <td width="30%">{$username}</td>
            <td width="10%"><center><a href="misc.php?action=sg_betreuung_remove_invalid&amp;bid={$bid}&amp;my_post_key={$mybb->post_code}"><i class="fa-solid fa-trash"></i></a></center></td>
            <td width="10%"><center><a href="misc.php?action=sg_betreuung_takeover&amp;bid={$bid}&amp;tid={$tid}&amp;my_post_key={$mybb->post_code}"><i class="fa-solid fa-person-walking-dashed-line-arrow-right"></i></a></center></td>
        </tr>'),
        'sid' => '-2',
        'version' => '',
        'dateline' => TIME_NOW
    );
    $db->insert_query("templates", $insert_array); //hinter jedes einzelne Template
    
}

//Prüfung, ob schon installiert
function sg_betreuung_is_installed()
{
    global $db;

    if ($db->table_exists("sg_betreuung")) {
        return true;
    }

    return false;
}

//Die Deinstallation 
function sg_betreuung_uninstall()
{
    global $db, $cache;

    if ($db->table_exists("sg_betreuung")) {
        $db->drop_table("sg_betreuung");
    }

    //Templates löschen 
    $db->delete_query("templates", "title LIKE 'betreuung%'");
    $db->delete_query("templategroups", "prefix = 'betreuung'");

    //Einstellungen löschen 
    $db->delete_query('settings', "name LIKE 'sg_betreuung_%'");
    $db->delete_query('settinggroups', "name = 'sg_betreuung'");
  
    rebuild_settings();
}

//Aktivieren des Plugins
function sg_betreuung_activate()
{
    global $db, $mybb, $cache;

    include MYBB_ROOT . "/inc/adminfunctions_templates.php";
    // find_replace_templatesets("newreply", "#" . preg_quote('{$posticons}') . "#i", '{$scenetrackerreply}{$scenetrackeredit}{$posticons}');

    //My Alert Stuff
    if(class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
	    $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

		if (!$alertTypeManager) {
			$alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
		}

        $alertType = new MybbStuff_MyAlerts_Entity_AlertType();
		$alertType->setCode('sg_betreuung_alert_newreply'); // der "codename" für den Alert Kram, kann irgendwas sein. 
		$alertType->setEnabled(true);
		$alertType->setCanBeUserDisabled(true);
        $alertType->setDefaultUserEnabled(true);
		$alertTypeManager->add($alertType);

        //das hier ist der Alert für den Themeneröffner, wenn sich jemand als Betreuer eingetragen hat.
        $alertType = new MybbStuff_MyAlerts_Entity_AlertType();
        $alertType->setCode('sg_betreuung_alert_assigned');
        $alertType->setEnabled(true);
        $alertType->setCanBeUserDisabled(true);
        $alertType->setDefaultUserEnabled(true);
        $alertTypeManager->add($alertType);

        // MyAlerts-Typen-Cache neu aufbauen
        if (function_exists('reload_mybbstuff_myalerts_alert_types')) {
            reload_mybbstuff_myalerts_alert_types();
        }
    }

}

//Deaktivieren des Plugins
function sg_betreuung_deactivate()
{
    global $db, $mybb, $cache;
    include MYBB_ROOT . "/inc/adminfunctions_templates.php";
    // find_replace_templatesets("newreply", "#" . preg_quote('{$scenetrackerreply}{$scenetrackeredit}') . "#i", '');

    // MyALERT STUFF
    if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
		$alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

		if (!$alertTypeManager) {
			$alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
		}

		$alertTypeManager->deleteByCode('sg_betreuung_alert_newreply');
        $alertTypeManager->deleteByCode('sg_betreuung_alert_assigned'); //für Info an Themenersteller

        if (function_exists('reload_mybbstuff_myalerts_alert_types')) {
            reload_mybbstuff_myalerts_alert_types();
        }
	}

}


//Helper alle betreuernamen aus dem Thema holen
function sg_betreuung_get_usernames_by_tid($tid)
{
    global $db, $mybb;

    $looked_usernames = array();
    $looked_groups = array_map('trim', explode(',', $mybb->settings['sg_betreuung_groups']));

    $q_names = $db->write_query("
        SELECT u.uid, u.username, u.usergroup, u.displaygroup, u.additionalgroups
        FROM " . TABLE_PREFIX . "sg_betreuung b
        LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid = b.uid)
        WHERE b.tid = '{$tid}'
        ORDER BY u.username ASC
    ");

    while ($row = $db->fetch_array($q_names)) {

        $is_allowed = false;

        //Die Gruppenabfragen
        if (in_array($row['usergroup'], $looked_groups)) {
            $is_allowed = true;
        }

        if (!$is_allowed && in_array($row['displaygroup'], $looked_groups)) {
            $is_allowed = true;
        }

        if (!$is_allowed && !empty($row['additionalgroups'])) {
            $additional = array_map('trim', explode(',', $row['additionalgroups']));
            foreach ($additional as $gid) {
                if (in_array($gid, $looked_groups)) {
                    $is_allowed = true;
                    break;
                }
            }
        }

        //Wenn User nicht darf, dann überspringen
        if (!$is_allowed) {
            continue;
        }

        $formatted_name = format_name(
            htmlspecialchars_uni($row['username']),
            $row['usergroup'],
            $row['displaygroup']
        );

        $profile_link = get_profile_link($row['uid']);

        $looked_usernames[] = "<a href=\"{$profile_link}\">{$formatted_name}</a>";
    }

    return $looked_usernames;
}

//Helper, prüfung ob bereits betreut
function sg_betreuung_user_looks_after($tid, $uid)
{
    global $db;

    $tid = $db->escape_string($tid);
    $uid = $db->escape_string($uid);

    $looked_exists = $db->fetch_field(
        $db->simple_select("sg_betreuung", "bid", "tid = '{$tid}' AND uid = '{$uid}'"),"bid");

    if ($looked_exists) {
        return true;
    }

    return false;
}


//Die Anzeige im Forumdisplay
function sg_betreuung_showforen()
{
    global $db, $mybb, $thread, $lang, $templates;

    //Einstellungen holen 
    $anzeigebetreuung = $mybb->settings['sg_betreuung_yes'];
    $anzeigename = $mybb->settings['sg_betreuung_name'];
    $anzeigeforum = $mybb->settings['sg_betreuung_foren'];
    $looked_group = $mybb->settings['sg_betreuung_groups'];

    //intialisieren    
    $thread['looked_output'] = "";

    //Themen und Foren ID
    $fid = $thread['fid'];
    $tid = $thread['tid'];

    //last seen aktualisieren, damit der Pfeil kommt
    if ($mybb->user['uid'] > 0 && sg_betreuung_user_looks_after($tid, $mybb->user['uid'])) {
        $db->update_query(
            "sg_betreuung",
            array("last_seen" => $thread['lastpost']),
            "tid = '{$tid}' AND uid = '{$mybb->user['uid']}'"
        );
    }

    //Wenn nicht anzeigeforum, dann nichts ausgeben.
    if ($anzeigeforum != -1) {
        $looked_foren = array_map('trim', explode(',', $anzeigeforum));

        if (!in_array($fid, $looked_foren)) {
            return;
        }
    }

    $button = "";
    $info = "";

    //Button anzeigen
    if (is_member($looked_group)) {

        //Prüfen, ob der aktuelle User den Thread bereits betreut
        $looked_by_user = sg_betreuung_user_looks_after($tid, $mybb->user['uid']);

        //Je nach Status anderen Button anzeigen
        if ($looked_by_user) {
            eval("\$button = \"".$templates->get("betreuung_button_remove")."\";");
        } else {
            eval("\$button = \"".$templates->get("betreuung_button_add")."\";");
        }
    }

    //Text wer betreut anzeigen?
    if ($anzeigebetreuung == 1) {

        //Betreuer holen
        $looked_usernames = sg_betreuung_get_usernames_by_tid($tid);

        if (!empty($looked_usernames)) {
            $looked_text = $anzeigename . " " . implode(", ", $looked_usernames);
            eval("\$info = \"".$templates->get("betreuung_name")."\";");
        }
    }

    if (!empty($button) || !empty($info)) {
        eval("\$thread['looked_output'] .= \"" . $templates->get("betreuung_box") . "\";");
    }
}


//Wenn auf den Button gedrückt wird, dann wird der User dem Thread zugeordnet
function sg_betreuung_misc()
{
    global $db, $mybb, $cache;

    $action = $mybb->get_input('action');

    if (
        $action !== 'sg_betreuung_add'
        && $action !== 'sg_betreuung_remove'
        && $action !== 'sg_betreuung_remove_invalid'
        && $action !== 'sg_betreuung_takeover'
    ) {
        return;
    }

    if ($mybb->user['uid'] <= 0) {
        error_no_permission();
    }

    verify_post_check($mybb->get_input('my_post_key'));

    $looked_group = $mybb->settings['sg_betreuung_groups'];

    if (!is_member($looked_group)) {
        error_no_permission();
    }

    if ($action === 'sg_betreuung_add' || $action === 'sg_betreuung_remove') {

        $tid = $mybb->get_input('tid');

        $thread = $db->fetch_array(
            $db->simple_select("threads", "tid, fid, uid, subject, lastpost", "tid = '{$tid}'")
        );

        if (empty($thread['tid'])) {
            error("Das angegebene Thema existiert nicht.");
        }

        $redirect_url = $_SERVER['HTTP_REFERER'];
        if (empty($redirect_url)) {
            $redirect_url = get_thread_link($tid);
        }

        $uid = $mybb->user['uid'];

        if ($action === 'sg_betreuung_add') {
            $exists = $db->fetch_field(
                $db->simple_select("sg_betreuung", "bid", "tid = '{$tid}' AND uid = '{$uid}'"),
                "bid"
            );

            if (!$exists) {
                $insert = array(
                    'tid' => $tid,
                    'uid' => $uid,
                    'last_seen' => $thread['lastpost']
                );

                $db->insert_query("sg_betreuung", $insert);

                //MyAlert an den Themenersteller schicken
                if (
                    class_exists('MybbStuff_MyAlerts_AlertManager')
                    && $thread['uid'] > 0
                ) {
                    $alertManager = MybbStuff_MyAlerts_AlertManager::getInstance();

                    if (!$alertManager) {
                        $alertManager = MybbStuff_MyAlerts_AlertManager::createInstance($db, $cache);
                    }

                    $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

                    if (!$alertTypeManager) {
                        $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
                    }

                    $alertType = $alertTypeManager->getByCode('sg_betreuung_alert_assigned');

                    if ($alertType != null && $alertType->getEnabled()) {

                        $alert = new MybbStuff_MyAlerts_Entity_Alert(
                            $thread['uid'],
                            $alertType,
                            $tid,
                            $uid
                        );

                        $alert->setExtraDetails(array(
                            'tid' => $tid,
                            'subject' => $thread['subject']
                        ));

                        $alertManager->addAlert($alert);
                    }
                }
            }

            redirect($redirect_url, "Du betreust dieses Thema jetzt.");
        }

        if ($action === 'sg_betreuung_remove') {
            $db->delete_query("sg_betreuung", "tid = '{$tid}' AND uid = '{$uid}'");
            redirect($redirect_url, "Du betreust dieses Thema nicht mehr.");
        }
    }

    if ($action === 'sg_betreuung_remove_invalid') {
        $bid = $mybb->get_input('bid');

        if (empty($bid)) {
            error("Kein Betreuungseintrag übergeben.");
        }

        $db->delete_query("sg_betreuung", "bid = '{$bid}'");
        redirect("modcp.php?action=sg_betreuung", "Der ungültige Betreuungseintrag wurde entfernt.");
    }

    if ($action === 'sg_betreuung_takeover') {
        $bid = $mybb->get_input('bid');
        $tid = $mybb->get_input('tid');
        $uid = $mybb->user['uid'];

        if (empty($bid) || empty($tid)) {
            error("Es wurden nicht alle Daten übergeben.");
        }

        $thread = $db->fetch_array(
            $db->simple_select("threads", "tid, lastpost", "tid = '{$tid}'")
        );

        if (empty($thread['tid'])) {
            error("Das angegebene Thema existiert nicht.");
        }

        $db->delete_query("sg_betreuung", "bid = '{$bid}'");

        $exists = $db->fetch_field(
            $db->simple_select("sg_betreuung", "bid", "tid = '{$tid}' AND uid = '{$uid}'"),
            "bid"
        );

        if (!$exists) {
            $insert = array(
                'tid' => $tid,
                'uid' => $uid,
                'last_seen' => $thread['lastpost']
            );

            $db->insert_query("sg_betreuung", $insert);
        }

        redirect("modcp.php?action=sg_betreuung", "Die Betreuung wurde übernommen.");
    }
}


//Der Button und die Anzeige im Thema selbst
function sg_betreuung_showthread()
{
    global $db, $mybb, $thread, $lang, $templates;

    //Einstellungen holen 
    $anzeigebetreuung = $mybb->settings['sg_betreuung_yes']; //soll angezeigt werden
    $anzeigename = $mybb->settings['sg_betreuung_name']; //die Bezeichnung vor dem Betreuer
    $anzeigeforum = $mybb->settings['sg_betreuung_foren']; //in welchen Foren
    $looked_group = $mybb->settings['sg_betreuung_groups']; //welche Gruppen können Button drücken

    //intialisieren für PHP 8
    $button = "";
    $info = "";
    $caretaker_output = ""; //komplettes TPL
    $caretaker_button = ""; //variable für nur Button 
    $caretaker_name = ""; //variable für nur name

    //Themen und Foren ID
    $fid = $thread['fid'];
    $tid = $thread['tid'];

    //Wenn nicht anzeigeforum, dann nichts ausgeben.
    if ($anzeigeforum != -1) {
        $looked_foren = array_map('trim', explode(',', $anzeigeforum));

        if (!in_array($fid, $looked_foren)) {
            return;
        }
    }

    //Button anzeigen 
    if (is_member($looked_group)) {

        $looked_by_user = sg_betreuung_user_looks_after($tid, $mybb->user['uid']);

        if ($looked_by_user) {
            eval("\$caretaker_button = \"".$templates->get("betreuung_button_remove")."\";");
        } else {
            eval("\$caretaker_button = \"".$templates->get("betreuung_button_add")."\";");
        }
    }

    //Name anzeigen 
    $looked_usernames = sg_betreuung_get_usernames_by_tid($tid);
    if (!empty($looked_usernames)) {
        $looked_text = $anzeigename . " " . implode(", ", $looked_usernames);
        eval("\$caretaker_name = \"".$templates->get("betreuung_name")."\";");
    }

    //Kombi anzeigen 
    if (!empty($caretaker_button) || !empty($caretaker_name)) {
        $button = $caretaker_button;
        $info = $caretaker_name;

        eval("\$caretaker_output = \"".$templates->get("betreuung_box")."\";");
    }

    //Wenn Textanzeige deaktiviert ist, nur Button ausgeben
    if ($anzeigebetreuung != 1) {
        return;
    }

    //Betreuer des Themas holen
    $looked_usernames = sg_betreuung_get_usernames_by_tid($tid);

    //Wenn Betreuer da ist, dann Text bauen
    if (!empty($looked_usernames)) {
        $looked_text = $anzeigename . " " . implode(", ", $looked_usernames);
        eval("\$info = \"".$templates->get("betreuung_name")."\";");
    }

    //Gemeinsames Template
    if (!empty($button) || !empty($info)) {
        eval("\$caretaker_output = \"".$templates->get("betreuung_box")."\";");
        $GLOBALS['caretaker_button'] = $caretaker_button; //einzelvariable für Button 
        $GLOBALS['caretaker_name'] = $caretaker_name; //einzelvariable für Name
        $GLOBALS['caretaker_output'] = $caretaker_output; //kombi aus beidem
    }
}

//Die Ausgabe von der Übersicht, wer was betreut. 
function sg_betreuung_modcp() {

    global $db, $mybb, $thread,$theme, $header, $headerinclude, $footer, $page, $templates, $newpost_icon;

    if ($mybb->get_input('action') != 'sg_betreuung') {
        return;
    }

    //Einstellung holen 
    $looked_group = $mybb->settings['sg_betreuung_groups']; //welche Gruppen erscheinen in der liste?

    //Navigation bauen 
    add_breadcrumb("Übersicht Themenbetreuung", "modcp.php?action=sg_betreuung");


    if(!is_member($looked_group)) {
        error_no_permission();
    }

    //wir holen erst mal die eigenen Themen 
    //modcp.php?action=sg_betreuung

    //intialisieren 
    $ownthreads_bit = "";
    $allthreads_bit = "";
    

    //erst mal holen wir UNSERE uid 
    $uid = $db->escape_string($mybb->user['uid']);

    //dann bauen wir den abruf
    $own_threads = $db->query("
    SELECT b.bid, b.tid, b.uid, b.last_seen, 
    t.subject, t.fid, t.lastpost AS thread_lastpost, t.lastposter, t.lastposteruid, 
    f.name AS forumname FROM  " . TABLE_PREFIX . "sg_betreuung b
    LEFT JOIN " . TABLE_PREFIX . "threads t ON t.tid = b.tid
    LEFT JOIN " . TABLE_PREFIX . "forums f ON f.fid = t.fid
    WHERE b.uid = '$uid'
    ORDER BY t.lastpost DESC");

    while ($ownt = $db->fetch_array($own_threads)) {

        //wir holen erst mal die Themen & die Titel & in welchem forum es ist
        $tid = $ownt['tid'];
        $threadlink = get_thread_link($ownt['tid']);
        $subject = htmlspecialchars_uni($ownt['subject']);
        $forumname = htmlspecialchars_uni($ownt['forumname']);

        //dann holen wir den usernamen von uns aus dem Helper
        $looked_usernames = sg_betreuung_get_usernames_by_tid($ownt['tid']);
        $looked_text = implode(", ", $looked_usernames);

        //brauchen wir zum abgeben
        $post_key = $mybb->post_code;        

        //Datum des letzten Beitrags
        $lastpost = my_date('relative', $ownt['thread_lastpost']);

        //damit der Pfeil kommt bei neuste Beiträge:
        $newpost_icon = "";
        if ($ownt['thread_lastpost'] > $ownt['last_seen']) {
            $newpost_icon = '<i class="far fa-folder-open" title="Neue Beiträge" style="font-size:14px;"></i> ';
        }

        eval("\$ownthreads_bit .= \"" . $templates->get("betreuung_threads_own") . "\";"); 

    }

    //dann bauen wir das Ding für alle Übersicht aber nur mit Usern in der eingestellten Gruppe
    $all_threads = $db->query("
    SELECT DISTINCT t.tid, t.subject, t.lastpost AS thread_lastpost, t.fid,
    f.name AS forumname
    FROM " . TABLE_PREFIX . "sg_betreuung b 
    LEFT JOIN " . TABLE_PREFIX . "threads t ON t.tid = b.tid
    LEFT JOIN " . TABLE_PREFIX . "forums f ON f.fid = t.fid
    LEFT JOIN " . TABLE_PREFIX . "users u ON u.uid = b.uid
    WHERE b.tid NOT IN (
        SELECT tid
        FROM mybb_sg_betreuung
        WHERE uid = '$uid'
    )
    AND (
        FIND_IN_SET(u.usergroup, '{$looked_group}')
        OR FIND_IN_SET(u.displaygroup, '{$looked_group}')
    )
    GROUP BY b.tid
    ORDER BY t.lastpost DESC
    ");

    while ($allt = $db->fetch_array($all_threads)) {
        //wir holen alle Themen und Titel und das Forum 
        $threadlink = get_thread_link($allt['tid']);
        $subject = htmlspecialchars_uni($allt['subject']);
        $forumname = htmlspecialchars_uni($allt['forumname']);

        //Hier ist es anders als oben, wir verwenden die 2 Helper um die Leute aufzulisten
        $looked_usernames = sg_betreuung_get_usernames_by_tid($allt['tid']);
        $looked_text = implode(", ", $looked_usernames);

        $lastpost = my_date('relative', $allt['thread_lastpost']);

        eval("\$allthreads_bit .= \"" . $templates->get("betreuung_threads_all") . "\";"); 
    }

    
    $notthreads_bit = "";
    //Jetzt bauen wir die Übersicht mit den leuten, die nicht mehr in der Gruppe sind!!
    $nogroup_threads = $db->query("
    SELECT b.bid, b.tid, b.uid,
    t.subject, t.fid, t.lastpost AS thread_lastpost, t.lastposter,
    f.name AS forumname,
    u.username, u.usergroup, u.additionalgroups, u.displaygroup
    FROM " . TABLE_PREFIX . "sg_betreuung b 
    LEFT JOIN " . TABLE_PREFIX . "threads t ON t.tid = b.tid
    LEFT JOIN " . TABLE_PREFIX . "forums f ON f.fid = t.fid
     LEFT JOIN " . TABLE_PREFIX . "users u ON u.uid = b.uid
    ORDER BY t.lastpost DESC
    ");

    while ($not= $db->fetch_array($nogroup_threads)) {

        //wir holen noch mal die Einstellungen, für welche Gruppen 
        $looked_groups = array_map('trim', explode(',', $looked_group));
        $is_allowed = false;

        //die Gruppe prüfen 
        if (in_array($not['usergroup'], $looked_groups)) {
            $is_allowed = true;
        }

        //und die Zusatzgruppen ICH VERGESS SIE IMMER WIEDER!!!
        if (!$is_allowed && !empty($not['additionalgroups'])) {
        $additionalgroups = array_map('trim', explode(',', $not['additionalgroups']));

            foreach ($additionalgroups as $gid) {
                if (in_array($gid, $looked_groups)) {
                    $is_allowed = true;
                    break;
                }
            }
        }

        // wenn User erlaubt istdann überspringen 
        if ($is_allowed) {
            continue;
        }

        //Dinge für die Ausgabe
        $bid = $not['bid'];
        $tid = $not['tid'];
        $threadlink = get_thread_link($tid);
        $subject = htmlspecialchars_uni($not['subject']);
        $forumname = htmlspecialchars_uni($not['forumname']);
        $username = format_name(
            htmlspecialchars_uni($not['username']),
            $not['usergroup'],
            $not['displaygroup']
        );
        $lastpost = my_date('relative', $not['thread_lastpost']);

        
        eval("\$notthreads_bit .= \"" . $templates->get("betreuung_notthreads_bit") . "\";"); 
        
    }

    if (empty($ownthreads_bit)) {
        $ownthreads_bit = "<center><b>Du hast aktuell noch kein Thema unter deinen Fittichen.</b></center>";
    }

    if (empty($allthreads_bit)) {
        $allthreads_bit = "<center><b>Du bist die einzige Person, die aktuell hier seine Augen auf hat. </b></center>";
    }

    if (empty($notthreads_bit)) {
        $notthreads_bit = "<center><b>Aktuell sind alle Themen richtig vergeben. Aus diesem Grund ist die Auflistung unten leer.</b></center>";
    }

    
    eval("\$page = \"" . $templates->get("betreuung_threads") . "\";"); 
    output_page($page);

    
}

//Das hier ist die Funktion für Antworten und den shit
function sg_betreuung_alert_newreply(&$datahandler)
{
    global $db, $mybb, $cache;

    if (!class_exists('MybbStuff_MyAlerts_AlertManager')) {
        return;
    }

    $tid = $datahandler->data['tid'];
    $pid = $datahandler->return_values['pid'];
    $poster_uid = $datahandler->data['uid'];

    if (empty($tid) || empty($pid)) {
        return;
    }

    $thread = $db->fetch_array(
        $db->simple_select("threads", "tid, subject, lastpost", "tid = '{$tid}'")
    );

    if (empty($thread['tid'])) {
        return;
    }

    //Hier ist der Alert kram
    $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

        if (!$alertTypeManager) {
            $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
        }

        $alertType = $alertTypeManager->getByCode('sg_betreuung_alert_newreply');

        if (!$alertType || !$alertType->getEnabled()) {
            return;
        }

        $alertManager = MybbStuff_MyAlerts_AlertManager::getInstance();

        if (!$alertManager) {
            $alertManager = MybbStuff_MyAlerts_AlertManager::createInstance($db, $cache);
        }

    //Wer betreut aus der DB holen?
    $betreuer = $db->simple_select("sg_betreuung", "uid, last_seen", "tid = '{$tid}'");

    while ($row = $db->fetch_array($betreuer)) {

        //nicht den Antwortschreiber selbst benachrichtigen
        if ($row['uid'] == $poster_uid) {
            continue;
        }

        //nur benachrichtigen, wenn seit last_seen wirklich etwas neu ist
        if ($thread['lastpost'] <= $row['last_seen']) {
            continue;
        }

        //nur, wenn eine neue Antwort einen Alert... nicht bei mehr
        $type_id = $alertType->getId();

        $already_alerted = $db->fetch_field(
            $db->simple_select(
                "alerts",
                "id",
                "uid = '{$row['uid']}' AND alert_type_id = '{$type_id}' AND object_id = '{$tid}' AND unread = '1'"
            ),
            "id"
        );

        if ($already_alerted) {
            continue;
        }

        $alert = new MybbStuff_MyAlerts_Entity_Alert(
            $row['uid'],
            $alertType,
            $tid,
            $poster_uid
        );

        $alert->setExtraDetails(array(
            'tid' => $tid,
            'pid' => $pid,
            'subject' => $thread['subject'],
            'recipient_uid' => $row['uid']
        ));

        $alertManager->addAlert($alert);
    }
}



//MyAlerts einfügen 
function sg_betreuung_myalerts() {
    global $mybb, $lang;

    //Alert Formatierer für meinen eigenen Alert Typ
    class MybbStuff_MyAlerts_Formatter_sg_betreuung_newpostFormatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
    {
        public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
        {
            global $db;

            $alertContent = $alert->getExtraDetails();

            $tid = $alertContent['tid'];
            $recipient_uid = $alertContent['recipient_uid'];

            // wann war der betreuer zuletzt im Thema?
            $last_seen = $db->fetch_field(
                $db->simple_select(
                    "sg_betreuung",
                    "last_seen",
                    "tid = '{$tid}' AND uid = '{$recipient_uid}'"
                ),
                "last_seen"
            );

            // Neue Beiträge seit last_seen
            $count = $db->fetch_field(
                $db->simple_select(
                    "posts",
                    "COUNT(pid) AS count_posts",
                    "tid = '{$tid}' AND dateline > '{$last_seen}'"
                ),
                "count_posts"
            );

            if ($count > 1) {
                return $this->lang->sprintf(
                    $this->lang->sg_betreuung_alert_newreply_multiple,
                    $count,
                    htmlspecialchars_uni($alertContent['subject'])
                );
            }

            return $this->lang->sprintf(
                $this->lang->sg_betreuung_alert_newreply_single,
                htmlspecialchars_uni($alertContent['subject'])
            );
        }

        public function init()
        {
            $this->lang->load('sg_betreuung');
        }

        public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
        {
            $alertContent = $alert->getExtraDetails();

            return $this->mybb->settings['bburl'] . '/showthread.php?tid=' . $alertContent['tid'] . '&action=newpost';
        }
    }

    //das ist die eigene Klasse für "Nachricht an Themenersteller"
    class MybbStuff_MyAlerts_Formatter_sg_betreuung_assignedFormatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
    {
        public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
        {
            global $db;

            $alertContent = $alert->getExtraDetails();

            $userid = $alert->getFromUserId();
            $user = get_user($userid);
            $username = format_name($user['username'], $user['usergroup'], $user['displaygroup']);

            return $this->lang->sprintf(
                $this->lang->sg_betreuung_alert_assigned,
                $username,
                $alertContent['subject']
            );
        }

        public function init()
        {
            $this->lang->load('sg_betreuung');
        }

        public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
        {
            $alertContent = $alert->getExtraDetails();
            return $this->mybb->settings['bburl'] . '/showthread.php?tid=' . $alertContent['tid'];
        }
    }

    if (class_exists('MybbStuff_MyAlerts_AlertFormatterManager')) {
		$formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();

		if (!$formatterManager) {
			$formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);
		}

		$formatterManager->registerFormatter(
			new MybbStuff_MyAlerts_Formatter_sg_betreuung_newpostFormatter($mybb, $lang, 'sg_betreuung_alert_newreply')
		);

        $formatterManager->registerFormatter(
            new MybbStuff_MyAlerts_Formatter_sg_betreuung_assignedFormatter($mybb, $lang, 'sg_betreuung_alert_assigned')
        );
    }
}
