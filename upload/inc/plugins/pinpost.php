<?php

/**
 * @package Pin Post
 * @version 1.0.0
 * @category MyBB 1.8.x Plugin
 * @author effone <effone@mybb.com>
 * @license MIT
 *
 */

if (!defined('IN_MYBB')) {
	die('Direct access prohibited.');
}

$plugins->add_hook('global_start', 'pinpost_templates');
$plugins->add_hook('postbit', 'pinpost_populate');
$plugins->add_hook('showthread_start', 'pinpost_commit');

function pinpost_info()
{
	global $lang;
	$lang->load('pinpost');

	return array(
		'name' => 'Pin Post',
		'description' => $lang->pinpost_desc,
		'website' => 'https://github.com/mybbgroup/Pin-Post',
		'author' => 'effone</a> of <a href="https://mybb.group">MyBB Group</a>',
		'authorsite' => 'https://eff.one',
		'version' => '1.0.3',
		'compatibility' => '18*',
		'codename' => 'pinpost',
	);
}

function pinpost_install()
{
	global $db, $lang;
	$lang->load('pinpost');

	$stylesheet = @file_get_contents(MYBB_ROOT . 'inc/plugins/pinpost/pinpost.css');
	$attachedto = 'showthread.php';
	$name = 'pinpost.css';
	$css = array(
		'name' => $name,
		'tid' => 1,
		'attachedto' => $db->escape_string($attachedto),
		'stylesheet' => $db->escape_string($stylesheet),
		'cachefile' => $name,
		'lastmodified' => TIME_NOW,
	);
	$db->update_query('themestylesheets', array(
		"attachedto" => $attachedto,
	), "name='{$name}'");
	$query = $db->simple_select('themestylesheets', 'sid', "tid='1' AND name='{$name}'");
	$sid = (int) $db->fetch_field($query, 'sid');
	if ($sid) {
		$db->update_query('themestylesheets', $css, "sid='{$sid}'");
	} else {
		$sid = $db->insert_query('themestylesheets', $css);
		$css['sid'] = (int) $sid;
	}
	require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";
	if (!cache_stylesheet(1, $css['cachefile'], $stylesheet)) {
		$db->update_query("themestylesheets", array('cachefile' => "css.php?stylesheet={$sid}"), "sid='{$sid}'", 1);
	}
	update_theme_stylesheet_list(1, false, true);

	// Add database column
	$db->write_query("ALTER TABLE " . TABLE_PREFIX . "posts ADD pinned tinyint(1) NOT NULL DEFAULT '0'");

	// Insert Templates
	foreach (glob(MYBB_ROOT . 'inc/plugins/pinpost/*.htm') as $template) {
		$db->insert_query('templates', array(
			'title' => $db->escape_string(strtolower(basename($template, '.htm'))),
			'template' => $db->escape_string(@file_get_contents($template)),
			'sid' => -2,
			'version' => 100,
			'dateline' => TIME_NOW,
		));
	}

	// Build Plugin Settings
	$db->insert_query("settinggroups", array(
		"name" => "pinpost",
		"title" => "Pin Post",
		"description" => $lang->pinpost_desc,
		"disporder" => "9",
		"isdefault" => "0",
	));
	$gid = $db->insert_id();
	$disporder = 0;
	$pinpost_settings = array();
	$pinpost_opts = array(['forums', 'forumselect', '-1'], ['groups', 'groupselect', '3,4,6'], ['limit', 'numeric', '5'], ['author', 'yesno', '0'], ['force_redirect', 'yesno', '1']);

	foreach ($pinpost_opts as $pinpost_opt) {
		$pinpost_opt[0] = 'pinpost_' . $pinpost_opt[0];
		$pinpost_opt = array_combine(['name', 'optionscode', 'value'], $pinpost_opt);
		$pinpost_opt['title'] = $lang->{$pinpost_opt['name'] . "_title"};
		$pinpost_opt['description'] = $lang->{$pinpost_opt['name'] . "_desc"};
		$pinpost_opt['disporder'] = ++$disporder;
		$pinpost_opt['gid'] = intval($gid);
		$pinpost_settings[] = $pinpost_opt;
	}
	$db->insert_query_multiple('settings', $pinpost_settings);
	rebuild_settings();
}

function pinpost_is_installed()
{
	global $db;
	//return $db->fetch_field($db->simple_select("templates", "COUNT(title) AS tpl", "title LIKE '%pinpost%'"), "tpl");
	return $db->field_exists('pinned', 'posts');
}

function pinpost_uninstall()
{
	global $db;

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MYBB_ROOT . 'cache/themes')) as $file) {
		if (stripos($file, 'pinpost') !== false) {
			@unlink($file);
		}
	}

	$db->delete_query('themestylesheets', "name='pinpost.css'");
	require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";
	update_theme_stylesheet_list(1, false, true);

	$db->write_query("ALTER TABLE " . TABLE_PREFIX . "posts DROP COLUMN pinned");

	foreach (glob(MYBB_ROOT . 'inc/plugins/pinpost/*.htm') as $template) {
		$db->delete_query('templates', 'title = "' . strtolower(basename($template, '.htm')) . '"');
	}

	$db->delete_query("settings", "name LIKE '%pinpost%'");
	$db->delete_query("settinggroups", "name='pinpost'");

	rebuild_settings();
}

function pinpost_activate()
{
	require MYBB_ROOT . "inc/adminfunctions_templates.php";
	foreach (['postbit', 'postbit_classic'] as $tpl) {
		find_replace_templatesets($tpl, '#button_purgespammer\']}#', 'button_purgespammer\']}<!-- pinpost -->{\$post[\'button_pinpost\']}<!-- /pinpost -->');
		find_replace_templatesets($tpl, '~(.*)<\/div>~su', '${1}</div><!-- pinpost -->{\$post[\'pinpost\']}<!-- /pinpost -->');
	}
};

function pinpost_deactivate()
{
	require MYBB_ROOT . "inc/adminfunctions_templates.php";
	foreach (['postbit', 'postbit_classic'] as $tpl) {
		find_replace_templatesets($tpl, '#\<!--\spinpost\s--\>(.*?)\<!--\s\/pinpost\s--\>#is', '', 0);
	}
};

function pinpost_templates()
{
	global $templatelist;

	if(defined('THIS_SCRIPT') && THIS_SCRIPT == 'showthread.php')
	{
		if(!isset($templatelist))
		{
			$templatelist = '';
		}
		else
		{
			$templatelist .= ',';
		}

		$templatelist .= 'postbit_pinpost_button, postbit_pinpost_bit, postbit_pinpost';
	}
}

function pinpost_access($tid)
{
	global $db, $mybb;
	if ($mybb->settings['pinpost_author']) {
		if ($mybb->user['uid'] && $mybb->user['uid'] == $db->fetch_field($db->simple_select("threads", "uid", "tid='" . $tid . "'"), "uid")) {
			return true;
		}
	}
	return !empty(array_intersect(explode(',', $mybb->settings['pinpost_groups']), explode(',', $mybb->user['usergroup'] . ',' . $mybb->user['additionalgroups'])));
}

function pinpost_commit()
{
	global $mybb, $lang, $db;

	$mybb->input['action'] = $mybb->get_input('action');
	if ($mybb->input['action'] == "pin" || $mybb->input['action'] == "unpin") {
		$fid = $mybb->get_input('fid', MyBB::INPUT_INT);
		$tid = $mybb->get_input('tid', MyBB::INPUT_INT);
		$pid = $mybb->get_input('pid', MyBB::INPUT_INT);

		if (
			!$fid || !$tid || !$pid
			|| $pid == $db->fetch_field($db->simple_select("threads", "firstpost", "tid='" . $tid . "'"), "firstpost")
			|| ($mybb->input['action'] == "pin" && $mybb->settings['pinpost_limit'] <= $db->fetch_field($db->simple_select("posts", "COUNT(pinned) AS pin", "pinned='1' AND tid='" . $tid . "'"), "pin"))
		) {
			error_no_permission();
		}

		$allowed_forums = explode(',', $mybb->settings['pinpost_forums']);

		if ((in_array($fid, $allowed_forums) || in_array('-1', $allowed_forums)) && pinpost_access($tid)) {
			$lang->load('pinpost');
			$state = $mybb->input['action'] == 'pin' ? '1' : '0';
			$db->update_query("posts", ['pinned' => $state], "pid='{$pid}' AND tid='{$tid}'");

			redirect("showthread.php?tid={$tid}#pin{$tid}", $lang->sprintf($lang->pin_success_message, ($mybb->input['action'] == "pin" ? $lang->pinpost_pin : $lang->pinpost_unpin)), '', (bool)$mybb->settings['pinpost_force_redirect']);
		} else {
			error_no_permission();
		}
	}
}

function pinpost_populate(&$post)
{
	global $mybb;
	$allowed_forums = explode(',', $mybb->settings['pinpost_forums']);

	if (in_array($post['fid'], $allowed_forums) || in_array('-1', $allowed_forums)) {
		global $db, $templates, $lang, $thread, $ismod, $pinnedposts;
		$lang->load('pinpost');

		static $pin_cache = null;

		//Preserve pin count to use for every post build
		if (!isset($thread['pinned'])) {

			if($pin_cache === null)
			{
				$pin_cache = [];

				$tid = (int)$post['tid'];

				$where = ["tid='{$tid}'", "pinned='1'"];

				$visible_states = [1];

				if(!isset($ismod))
				{
					$ismod = is_moderator($post['fid']);
				}

				if($ismod)
				{
					if(is_moderator($post['fid'], 'canviewdeleted'))
					{
						$visible_states[] = -1;
					}

					if(is_moderator($post['fid'], 'canviewunapprove'))
					{
						$visible_states[] = 0;
					}
				}

				$visible_states = implode(',', $visible_states);

				$where[] = "visible IN ({$visible_states})";

				$query = $db->simple_select(
					"posts",
					"subject, pid, uid, username, dateline",
					implode(' AND ', $where),
					array("order_by" => "pid")
				);

				while ($pinned = $db->fetch_array($query)) {
					$pin_cache[] = $pinned;
				}
			}

			$thread['pinned'] = count($pin_cache);
		}

		if ($post['pid'] != $thread['firstpost'] && pinpost_access($post['tid'])) {
			$pinned['pid'] = $post['pid'];
			if ($post['pinned']) {
				$un =  'un';
				$lang->postbit_pintext = $lang->pinpost_unpin;
				$pintitle = $lang->sprintf($lang->postbit_pintitle, $lang->pinpost_unpin);
				eval("\$post['button_pinpost'] = \"" . $templates->get("postbit_pinpost_button") . "\";");
			} else if ($thread['pinned'] < $mybb->settings['pinpost_limit']) {
				$un =  '';
				$lang->postbit_pintext = $lang->pinpost_pin;
				$pintitle = $lang->sprintf($lang->postbit_pintitle, $lang->pinpost_pin);
				eval("\$post['button_pinpost'] = \"" . $templates->get("postbit_pinpost_button") . "\";");
			}
		}

		if ($post['pid'] == $thread['firstpost'] && $thread['pinned']) {
			$limit = (int)$mybb->settings['pinpost_limit'];
			$pinpost_bits = "";

			$count = 0;
			foreach ((array)$pin_cache as $pinned) {
				$pinpost_poster = build_profile_link($pinned['username'], $pinned['uid']);
				$un =  'un';
				$lang->postbit_pintext = '✖';
				$pinpost_stamp = my_date('relative', $pinned['dateline']);
				$pintitle = $lang->sprintf($lang->postbit_pintitle, $lang->pinpost_unpin);
				if (pinpost_access($post['tid'])) eval("\$pinpost_unpin = \"" . $templates->get("postbit_pinpost_button") . "\";");
				eval("\$pinpost_bits .= \"" . $templates->get("postbit_pinpost_bit") . "\";");

				++$count;
				if($count >= $limit)
				{
					break;
				}
			}

			eval("\$post['pinpost'] = \$pinnedposts = \"" . $templates->get("postbit_pinpost") . "\";");
		}
	}
};
