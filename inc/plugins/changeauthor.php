<?php

/**
 * Lets certain usergroups change the author of a post or thread.
 *
 * @package Change Author on Edit
 * @author  Shade <shad3-@outlook.com>
 * @license MIT https://opensource.org/licenses/MIT
 * @version 1.1
 */

if (!defined('IN_MYBB')) {
	die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

if (!defined("PLUGINLIBRARY")) {
	define("PLUGINLIBRARY", MYBB_ROOT . "inc/plugins/pluginlibrary.php");
}

function changeauthor_info()
{
	return array(
		'name' => 'Change Author on Edit',
		'description' => 'Lets certain usergroups change the author of a post or thread.',
		'author' => 'Shade',
		'version' => '1.1',
		'compatibility' => '16*,18*'
	);
}

function changeauthor_is_installed()
{
	global $cache;

	$info      = changeauthor_info();
	$installed = $cache->read("shade_plugins");
	if ($installed[$info['name']]) {
		return true;
	}
}

function changeauthor_install()
{
	global $db, $lang, $mybb, $cache, $theme;

	if (!$lang->changeauthor) {
		$lang->load('changeauthor');
	}

	// Add template
	$query = $db->simple_select('templatesets', 'sid');
	while ($set = $db->fetch_field($query, 'sid')) {

		$insert_array[] = array(
			'title'		=> 'editpost_changeauthor',
			'template'	=> $db->escape_string('<tr>
	<td class="trow1" width="20%"><strong>{$lang->changeauthor}</strong><br /><span class="smalltext">{$lang->changeauthor_desc}</span></td>
	<td class="trow1"><input type="text" class="textbox" name="changeauthor" id="changeauthor" /></td>
</tr>'),
			'sid'		=> $set['sid'],
			'version'	=> '',
			'dateline'	=> TIME_NOW
		);

	}
	$db->insert_query_multiple("templates", $insert_array);

	// Add permissions column
	$db->add_column("usergroups", "canchangeauthor", "int(1) NOT NULL default '0'");

	// Set defaults for admin/global mod/mod usergroups
	$db->update_query("usergroups", array('canchangeauthor' => 1), "gid IN (3,4,6)");

	// Update usergroups cache
	$cache->update_usergroups();

	// Create cache
	$info                        = changeauthor_info();
	$shadePlugins                = $cache->read('shade_plugins');
	$shadePlugins[$info['name']] = array(
		'title' => $info['name'],
		'version' => $info['version']
	);

	$cache->update('shade_plugins', $shadePlugins);

	// Try to update templates
	require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
	find_replace_templatesets('editpost', '#' . preg_quote('{$loginbox}') . '#i', '{$loginbox}{$changeauthor}');

}

function changeauthor_uninstall()
{
	global $db, $cache, $lang;

	if (!$lang->changeauthor) {
		$lang->load('changeauthor');
	}

	if (!file_exists(PLUGINLIBRARY)) {
		flash_message($lang->changeauthor_pluginlibrary_missing, "error");
		admin_redirect("index.php?module=config-plugins");
	}

	// Delete our columns
	if ($db->field_exists("canchangeauthor", "usergroups")) {
		$db->drop_column("usergroups", "canchangeauthor");
	}

	// Update usergroups cache
	$cache->update_usergroups();

	// Delete the plugin from cache
	$info         = changeauthor_info();
	$shadePlugins = $cache->read('shade_plugins');
	unset($shadePlugins[$info['name']]);
	$cache->update('shade_plugins', $shadePlugins);

	// Delete templates
	$db->delete_query('templates', "title = 'editpost_changeauthor'");

	// Try to update templates
	require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
	find_replace_templatesets('editpost', '#' . preg_quote('{$changeauthor}') . '#i', '');

}

global $mybb, $plugins;

// Back end hooks
if (defined('IN_ADMINCP')) {

	$plugins->add_hook("admin_formcontainer_end", "changeauthor_usergroup_permission");
	$plugins->add_hook("admin_user_groups_edit_commit", "changeauthor_user_edit_commit");
}

// Front end hooks
if (THIS_SCRIPT == 'editpost.php') {
	$plugins->add_hook("pre_output_page", "changeauthor_load_scripts");
	$plugins->add_hook("editpost_action_start", "changeauthor_editpost");
	$plugins->add_hook("datahandler_post_update", "changeauthor_editpost_commit");
	$plugins->add_hook("datahandler_post_update_end", "changeauthor_editpost_commit_update");
}

// Admin CP permissions
function changeauthor_usergroup_permission()
{
	global $mybb, $lang, $form, $form_container, $run_module;

	if (!$lang->changeauthor) {
		$lang->load('changeauthor');
	}

	if ($run_module == 'user' and $form_container->_title and $lang->forums_posts and $form_container->_title == $lang->forums_posts) {

		$options = array(
	 		$form->generate_check_box('canchangeauthor', 1, $lang->can_change_author, array("checked" => $mybb->input['canchangeauthor'])),
		);

		$form_container->output_row($lang->changeauthor, "", "<div class=\"group_settings_bit\">".implode("</div><div class=\"group_settings_bit\">", $options)."</div>");

	}
}

// Input sanitization
function changeauthor_user_edit_commit()
{
	global $mybb, $updated_group;
	$updated_group['canchangeauthor'] = (int) $mybb->input['canchangeauthor'];
}

// Load scripts
function changeauthor_load_scripts(&$contents)
{
	global $mybb, $lang;

	if (!$mybb->usergroup['canchangeauthor']) {
		return;
	}

	// MyBB 1.8 has a different autocomplete engine
	if ($mybb->version_code >= 1700) {

		$lang->load('search');

		$scripts =
<<<EOF
<link rel="stylesheet" href="{$mybb->asset_url}/jscripts/select2/select2.css">
<script type="text/javascript" src="{$mybb->asset_url}/jscripts/select2/select2.min.js"></script>
<script type="text/javascript">
<!--
if(use_xmlhttprequest == "1")
{
	$("#changeauthor").select2({
		placeholder: "{$lang->search_user}",
		minimumInputLength: 3,
		maximumSelectionSize: 3,
		multiple: false,
		ajax: { // instead of writing the function to execute the request we use Select2's convenient helper
			url: "xmlhttp.php?action=get_users",
			dataType: 'json',
			data: function (term, page) {
				return {
					query: term, // search term
				};
			},
			results: function (data, page) { // parse the results into the format expected by Select2.
				// since we are using custom formatting functions we do not need to alter remote JSON data
				return {results: data};
			}
		},
		initSelection: function(element, callback) {
			var query = $(element).val();
			if (query !== "") {
				$.ajax("xmlhttp.php?action=get_users", {
					data: {
						query: query
					},
					dataType: "json"
				}).done(function(data) { callback(data); });
			}
		},
	});
}
// -->
</script>
EOF;

	}
	else {

		$scripts =
<<<EOF
<script type="text/javascript" src="jscripts/autocomplete.js?ver=1400"></script>
<script type="text/javascript">
<!--
	if(use_xmlhttprequest == "1")
	{
		new autoComplete("changeauthor", "xmlhttp.php?action=get_users", {valueSpan: "username"});
	}
// -->
</script>
EOF;

	}

	return str_replace('</body>', $scripts . '</body>', $contents);

}

// Editpost template
function changeauthor_editpost()
{
	global $mybb, $lang, $templates, $changeauthor;

	if (!$mybb->usergroup['canchangeauthor']) {
		return;
	}

	if (!$lang->changeauthor) {
		$lang->load('changeauthor');
	}

	eval("\$changeauthor = \"".$templates->get("editpost_changeauthor")."\";");

}

// Editpost commit controls
function changeauthor_editpost_commit(&$data)
{
	global $mybb;

	$username = htmlspecialchars_uni($mybb->input['changeauthor']);

	if (!$username) {
		return false;
	}

	$user = get_user_by_username($username);

	if (!$user['uid']) {
		return false;
	}

	// Same user
	if ($data->data['uid'] == $user['uid']) {
		return false;
	}

	// Store the old post uid in a special token
	$data->old_post_uid = $data->data['uid'];

	// Update the post data with the new ones
	$data->post_update_data['uid'] = (int) $user['uid'];
	$data->post_update_data['username'] = $username;

	return $data;

}

function changeauthor_editpost_commit_update(&$data)
{
	if (!$data->old_post_uid) {
		return false;
	}

	global $db;

	// Update thread and forum data
	update_thread_data($data->tid);
	update_forum_lastpost($data->data['fid']);

	// Get forum from cache
	$forum = get_forum($data->data['fid']);

	// Update both the users postnum and threadnum eventually
	$old_user = $new_user = [];

	// Update the post count if this forum allows post counts to be tracked
	if ($forum['usepostcounts'] != 0) {

		$new_user['postnum'] = "postnum+1";
		$old_user['postnum'] = "postnum-1";

		if ($data->return_values['first_post']) {

			$new_user['threadnum'] = "threadnum+1";
			$old_user['threadnum'] = "threadnum-1";

		}

	}

	// Only update the table if we need to
	if ($new_user) {
		$db->update_query("users", $new_user, "uid='{$data->post_update_data['uid']}'", 1, true);
	}

	if ($old_user) {
		$db->update_query("users", $old_user, "uid='{$data->old_post_uid}'", 1, true);
	}

	return $data;

}

// Get the uid from the username (introduced in 1.8)
if (!function_exists('get_user_by_username')) {

    function get_user_by_username($username, $options=array())
    {
    	global $mybb, $db;

    	$username = $db->escape_string(my_strtolower($username));

    	if(!isset($options['username_method']))
    	{
    		$options['username_method'] = 0;
    	}

    	switch($db->type)
    	{
    		case 'mysql':
    		case 'mysqli':
    			$field = 'username';
    			$efield = 'email';
    			break;
    		default:
    			$field = 'LOWER(username)';
    			$efield = 'LOWER(email)';
    			break;
    	}

    	switch($options['username_method'])
    	{
    		case 1:
    			$sqlwhere = "{$efield}='{$username}'";
    			break;
    		case 2:
    			$sqlwhere = "{$field}='{$username}' OR {$efield}='{$username}'";
    			break;
    		default:
    			$sqlwhere = "{$field}='{$username}'";
    			break;
    	}

    	$fields = array('uid');
    	if(isset($options['fields']))
    	{
    		$fields = array_merge((array)$options['fields'], $fields);
    	}

    	$query = $db->simple_select('users', implode(',', array_unique($fields)), $sqlwhere, array('limit' => 1));

    	if(isset($options['exists']))
    	{
    		return (bool)$db->num_rows($query);
    	}

    	return $db->fetch_array($query);
    }

}
